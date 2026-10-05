<?php
/**
 * Tests du chiffrement des archives.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Engine\Deadline;
use Oueb\WpBackup\Engine\Logger;
use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step_Failure;
use Oueb\WpBackup\Engine\Steps\Encrypt;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Security\Archive_Cipher;
use Oueb\WpBackup\Security\Key_Ring;
use Oueb\WpBackup\Storage\Workspace;
use RuntimeException;

/**
 * Chiffrement en flux, reprise après coupure, clés et déchiffrement.
 */
final class Test_Encryption extends Test_Case {

	/**
	 * Dossier temporaire.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Prépare un dossier et les fonctions de fichiers.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/oueb-enc-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/workspace/tmp/run-5', 0777, true );

		Functions\when( 'untrailingslashit' )->alias( fn( $path ) => rtrim( $path, '/\\' ) );
		Functions\when( 'wp_delete_file' )->alias( fn( $file ) => is_file( $file ) && unlink( $file ) );
		Functions\when( 'wp_specialchars_decode' )->alias( fn( $text ) => html_entity_decode( (string) $text, ENT_QUOTES ) );
		Functions\when( 'size_format' )->alias( fn( $bytes ) => $bytes . ' B' );
	}

	/**
	 * Supprime le dossier.
	 */
	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tearDown();
	}

	/**
	 * Déchiffre un fichier en mémoire.
	 *
	 * @param string   $file Fichier chiffré.
	 * @param Key_Ring $keys Clés.
	 * @return string Contenu en clair.
	 */
	private function decrypt( string $file, Key_Ring $keys ): string {
		$in    = fopen( $file, 'rb' );
		$plain = '';
		Archive_Cipher::decrypt(
			fn( int $length ): string => (string) fread( $in, $length ),
			function ( string $chunk ) use ( &$plain ): void {
				$plain .= $chunk;
			},
			array( $keys, 'find' )
		);
		fclose( $in );

		return $plain;
	}

	/**
	 * Une clé créée devient active, les anciennes restent trouvables.
	 */
	public function test_key_ring(): void {
		$keys = new Key_Ring();
		$this->assertNull( $keys->active() );

		$first  = $keys->generate();
		$second = $keys->generate();

		$this->assertSame( $second['id'], $keys->active()['id'] );
		$this->assertSame( $first['key'], $keys->find( $first['id'] ) );
		$this->assertStringNotContainsString( base64_encode( $first['key'] ), serialize( $this->site_options ) );
		$this->assertSame( array( false, true ), array_column( $keys->summary(), 'active' ) );

		// Une clé importée de nouveau passe en dernier, sans doublon.
		$keys->add( $first['key'] );
		$this->assertSame( $first['id'], $keys->active()['id'] );
		$this->assertCount( 2, $keys->summary() );

		$this->assertSame( $first['key'], Key_Ring::decode( Key_Ring::encode( $first['key'] ) ) );
		$this->assertNull( Key_Ring::decode( 'pas une clé' ) );
		$this->assertNull( Key_Ring::decode( base64_encode( 'trop court' ) ) );
	}

	/**
	 * Le chiffrement reprend après des coupures, même quand l'état enregistré a du retard.
	 */
	public function test_encrypt_resumes_after_crashes(): void {
		$keys = new Key_Ring();
		$keys->generate();
		$plain = random_bytes( Archive_Cipher::CHUNK * 3 + 12345 );
		file_put_contents( $this->dir . '/workspace/tmp/run-5/archive.zip', $plain );

		$context = $this->context();
		$step    = new Encrypt( $keys );

		$this->assertFalse( $step->run( $context ) );
		$snapshot = $context->run->state;
		$this->assertFalse( $step->run( $context ) );

		// Coupure : le passage suivant repart de l'état enregistré plus tôt.
		$context->run->state = $snapshot;
		$passes              = 0;
		while ( ! $step->run( $context ) ) {
			++$passes;
		}

		$this->assertGreaterThan( 0, $passes );
		$this->assertFileDoesNotExist( $this->dir . '/workspace/tmp/run-5/archive.zip', 'The plain archive is deleted.' );
		$this->assertSame( $plain, $this->decrypt( Encrypt::path( $context ), $keys ) );
		$this->assertTrue( $step->run( $context ), 'A pass after the end finds the work done.' );
	}

	/**
	 * Une archive vide se chiffre et se déchiffre.
	 */
	public function test_empty_archive(): void {
		$keys = new Key_Ring();
		$keys->generate();
		file_put_contents( $this->dir . '/workspace/tmp/run-5/archive.zip', '' );
		$context = $this->context();

		$this->assertTrue( ( new Encrypt( $keys ) )->run( $context ) );
		$this->assertSame( '', $this->decrypt( Encrypt::path( $context ), $keys ) );
	}

	/**
	 * Sans clé, l'étape échoue sans nouvel essai.
	 */
	public function test_missing_key_fails(): void {
		file_put_contents( $this->dir . '/workspace/tmp/run-5/archive.zip', 'x' );

		$this->expectException( Step_Failure::class );
		( new Encrypt( new Key_Ring() ) )->run( $this->context() );
	}

	/**
	 * Une archive modifiée, tronquée ou rallongée est refusée.
	 *
	 * @dataProvider tamperings
	 *
	 * @param callable $tamper Modifie le contenu chiffré.
	 */
	public function test_tampering_is_detected( callable $tamper ): void {
		$keys = new Key_Ring();
		$keys->generate();
		file_put_contents( $this->dir . '/workspace/tmp/run-5/archive.zip', random_bytes( Archive_Cipher::CHUNK + 100 ) );
		$context = $this->context();
		( new Encrypt( $keys ) )->run( $context );

		$file = Encrypt::path( $context );
		file_put_contents( $file, $tamper( (string) file_get_contents( $file ) ) );

		$this->expectException( RuntimeException::class );
		$this->decrypt( $file, $keys );
	}

	/**
	 * Altérations à détecter.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function tamperings(): array {
		return array(
			'octet modifié'    => array( fn( string $data ): string => substr_replace( $data, chr( ord( $data[100] ) ^ 1 ), 100, 1 ) ),
			'dernier bloc ôté' => array( fn( string $data ): string => substr( $data, 0, Archive_Cipher::HEADER_BYTES + Archive_Cipher::sealed_chunk() ) ),
			'données ajoutées' => array( fn( string $data ): string => $data . 'x' ),
			'autre format'     => array( fn( string $data ): string => 'PK' . substr( $data, 2 ) ),
		);
	}

	/**
	 * Sans la bonne clé, le message nomme la clé attendue.
	 */
	public function test_unknown_key(): void {
		$keys = new Key_Ring();
		$key  = $keys->generate();
		file_put_contents( $this->dir . '/workspace/tmp/run-5/archive.zip', 'secret' );
		$context = $this->context();
		( new Encrypt( $keys ) )->run( $context );

		update_site_option( Key_Ring::OPTION, array() );
		try {
			$this->decrypt( Encrypt::path( $context ), $keys );
			$this->fail( 'Decryption without the key must fail.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( $key['id'], $error->getMessage() );
		}
	}

	/**
	 * Construit un contexte dont chaque bloc épuise le temps du passage.
	 *
	 * @return Run_Context Contexte.
	 */
	private function context(): Run_Context {
		$run     = new Run();
		$run->id = 5;
		$job     = Job::main();
		$context = new Run_Context( $run, $job, new Workspace( $this->dir . '/workspace' ), new Logger( $run, $this->dir ), new Deadline( 0 ) );
		$context->for_step( 'encrypt' );

		return $context;
	}
}
