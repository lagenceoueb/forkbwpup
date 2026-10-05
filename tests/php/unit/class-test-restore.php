<?php
/**
 * Tests des briques de la restauration.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Archive\Tar_Gz_Writer;
use Oueb\WpBackup\Archive\Zip_Writer;
use Oueb\WpBackup\Engine\Deadline;
use Oueb\WpBackup\Engine\Logger;
use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Restore\Extractor;
use Oueb\WpBackup\Restore\Maintenance;
use Oueb\WpBackup\Restore\Steps\Restore_Files;
use Oueb\WpBackup\Restore\Upload_Repository;
use Oueb\WpBackup\Storage\Workspace;

/**
 * Noms d'entrées, placement des fichiers, extraction reprise, maintenance, envois.
 */
final class Test_Restore extends Test_Case {

	/**
	 * Dossier de travail du test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Prépare un dossier temporaire et les fonctions de fichiers.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/oueb-restore-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );

		Functions\when( 'untrailingslashit' )->alias( fn( $path ) => rtrim( $path, '/\\' ) );
		Functions\when( 'wp_normalize_path' )->alias( fn( $path ) => str_replace( '\\', '/', (string) $path ) );
		Functions\when( 'wp_mkdir_p' )->alias( fn( $dir ) => is_dir( $dir ) || mkdir( $dir, 0777, true ) );
		Functions\when( 'wp_delete_file' )->alias( fn( $file ) => is_file( $file ) && unlink( $file ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'sanitize_file_name' )->alias( fn( $name ) => preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name ) );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 8388608 );
	}

	/**
	 * Supprime le dossier temporaire.
	 */
	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tearDown();
	}

	/**
	 * Crée un contexte d'exécution dont chaque passage s'arrête après une entrée.
	 *
	 * @param Run $run Exécution, gardée d'un passage à l'autre.
	 * @return Run_Context Contexte.
	 */
	private function context( Run $run ): Run_Context {
		$context = new Run_Context( $run, Job::main(), new Workspace( $this->dir . '/ws' ), new Logger( $run, $this->dir ), new Deadline( 0 ) );
		$context->for_step( 'restore_files' );

		return $context;
	}

	/**
	 * Les noms dangereux sont refusés, les autres nettoyés.
	 */
	public function test_safe_name(): void {
		$this->assertSame( 'wp-content/uploads/a.jpg', Extractor::safe_name( 'wp-content/uploads/a.jpg' ) );
		$this->assertSame( 'wp-content/a.jpg', Extractor::safe_name( './wp-content//a.jpg' ) );
		$this->assertSame( 'wp-content/a.jpg', Extractor::safe_name( 'wp-content\\a.jpg' ) );
		$this->assertNull( Extractor::safe_name( '../wp-config.php' ) );
		$this->assertNull( Extractor::safe_name( 'wp-content/../../etc/passwd' ) );
		$this->assertNull( Extractor::safe_name( '/etc/passwd' ) );
		$this->assertNull( Extractor::safe_name( 'C:/Windows/a.dll' ) );
		$this->assertNull( Extractor::safe_name( "a\0b" ) );
		$this->assertNull( Extractor::safe_name( './' ) );
	}

	/**
	 * Les formats reconnus, chiffrés ou non.
	 */
	public function test_supported_archives(): void {
		$this->assertTrue( Extractor::supports( 'site.zip' ) );
		$this->assertTrue( Extractor::supports( 'site.tar.gz.enc' ) );
		$this->assertTrue( Extractor::supports( 'SITE.TGZ' ) );
		$this->assertTrue( Extractor::supports( 'site.tar' ) );
		$this->assertFalse( Extractor::supports( 'site.sql' ) );
		$this->assertFalse( Extractor::supports( 'site.enc' ) );
	}

	/**
	 * Chaque fichier retrouve sa place, même quand wp-content est rangé ailleurs.
	 */
	public function test_files_go_to_the_matching_location(): void {
		$prefixes = array(
			'core'    => '',
			'content' => 'wp-content',
			'plugins' => 'wp-content/plugins',
			'themes'  => 'wp-content/themes',
			'uploads' => 'wp-content/uploads',
		);
		$here     = array(
			'core'    => '/srv/www',
			'content' => '/srv/content',
			'plugins' => '/srv/content/plugins',
			'themes'  => '/srv/content/themes',
			'uploads' => '/data/media',
		);
		$map      = Restore_Files::map( $prefixes, $here );

		$this->assertSame( '/data/media/2026/10/a.jpg', Restore_Files::target( 'wp-content/uploads/2026/10/a.jpg', $map ) );
		$this->assertSame( '/srv/content/plugins/akismet/a.php', Restore_Files::target( 'wp-content/plugins/akismet/a.php', $map ) );
		$this->assertSame( '/srv/content/languages/fr_FR.mo', Restore_Files::target( 'wp-content/languages/fr_FR.mo', $map ) );
		$this->assertSame( '/srv/www/index.php', Restore_Files::target( 'index.php', $map ) );
		// « wp-content-old » n'est pas sous le préfixe « wp-content ».
		$this->assertSame( '/srv/www/wp-content-old/a.txt', Restore_Files::target( 'wp-content-old/a.txt', $map ) );

		// Archive d'avant les préfixes : tout part de la racine.
		$old = Restore_Files::map( array( 'core' => '' ), $here );
		$this->assertSame( '/srv/www/wp-content/uploads/a.jpg', Restore_Files::target( 'wp-content/uploads/a.jpg', $old ) );
	}

	/**
	 * L'extraction reprend entrée par entrée, et au milieu d'un gros fichier.
	 *
	 * Chaque passage s'arrête dès que possible : l'extraction en demande
	 * beaucoup, et le résultat doit rester identique à la source.
	 */
	public function test_extraction_resumes(): void {
		$sources = array(
			'wp-content/uploads/big.bin'   => random_bytes( 20 * 1048576 ),
			'wp-content/uploads/small.txt' => 'petit',
			'wp-content/uploads/empty.txt' => '',
			'index.php'                    => '<?php // racine',
		);

		foreach ( array(
			'zip'    => new Zip_Writer(),
			'tar.gz' => new Tar_Gz_Writer(),
		) as $format => $writer ) {
			$archive = $this->dir . '/backup.' . $format;
			$writer->open( $archive, array() );
			$index = 0;
			foreach ( $sources as $name => $content ) {
				$source = $this->dir . '/src-' . $index;
				++$index;
				file_put_contents( $source, $content );
				$writer->add_file( $source, $name );
			}
			$writer->commit();
			$writer->finish();

			$out    = $this->dir . '/out-' . $format;
			$run    = new Run();
			$passes = 0;
			do {
				++$passes;
				$done = Extractor::extract(
					$this->context( $run ),
					$archive,
					basename( $archive ),
					static fn( array $entry ): string => $out . '/' . $entry['name']
				);
				// L'état passe par JSON, comme en base.
				$run->state = json_decode( (string) json_encode( $run->state ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			} while ( ! $done && $passes < 50 );

			$this->assertTrue( $done, $format );
			$this->assertGreaterThan( count( $sources ), $passes, $format . ' : la reprise doit servir' );
			foreach ( $sources as $name => $content ) {
				$this->assertSame( $content, (string) file_get_contents( $out . '/' . $name ), $format . ' ' . $name );
			}
			$this->assertSame( array(), glob( $out . '/wp-content/uploads/*' . Extractor::PART ), $format );
		}
	}

	/**
	 * Le fichier .maintenance laisse passer le cookie de l'administrateur et la relance, pas le reste.
	 */
	public function test_maintenance_file_lets_through_the_restore_only(): void {
		$token = Maintenance::new_token();
		$file  = $this->dir . '/maintenance.php';
		file_put_contents( $file, Maintenance::contents( $token['hash'] ) );

		$upgrading = static function ( array $cookie, string $uri ) use ( $file ): int {
			$_COOKIE                = $cookie;
			$_SERVER['REQUEST_URI'] = $uri;
			$upgrading              = -1;
			include $file;
			return $upgrading;
		};

		$this->assertGreaterThan( 0, $upgrading( array(), '/' ) );
		$this->assertGreaterThan( 0, $upgrading( array( Maintenance::COOKIE => 'mauvais' ), '/wp-admin/' ) );
		$this->assertSame( 0, $upgrading( array( Maintenance::COOKIE => $token['token'] ), '/wp-json/oueb-wp-backup/v1/runs/4' ) );
		$this->assertSame( 0, $upgrading( array(), '/wp-json/oueb-wp-backup/v1/runs/12/continue' ) );
		$this->assertSame( 0, $upgrading( array(), '/?rest_route=%2Foueb-wp-backup%2Fv1%2Fruns%2F12%2Fcontinue' ) );
		$this->assertGreaterThan( 0, $upgrading( array(), '/wp-json/oueb-wp-backup/v1/runs/12/abort' ) );
		$this->assertStringContainsString( Maintenance::MARK, (string) file_get_contents( $file ) );

		unset( $_COOKIE, $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Un envoi accepte les morceaux dans l'ordre seulement, et pas au-delà de la taille annoncée.
	 */
	public function test_upload_by_chunks(): void {
		$uploads = new Upload_Repository( new Workspace( $this->dir . '/ws' ) );

		$this->assertInstanceOf( \WP_Error::class, $uploads->create( 'notes.txt', 10 ) );
		$this->assertInstanceOf( \WP_Error::class, $uploads->create( 'site.zip', 0 ) );

		$upload = $uploads->create( 'site.zip', 10 );
		$this->assertSame( 0, $upload['received'] );

		$this->assertSame( 4, $uploads->append( $upload['id'], 0, 'abcd' )['received'] );
		$out_of_order = $uploads->append( $upload['id'], 0, 'abcd' );
		$this->assertInstanceOf( \WP_Error::class, $out_of_order );
		$this->assertSame( 4, $out_of_order->get_error_data()['received'] );
		$this->assertInstanceOf( \WP_Error::class, $uploads->append( $upload['id'], 4, 'efghijklmn' ) );
		$this->assertSame( 10, $uploads->append( $upload['id'], 4, 'efghij' )['received'] );
		$this->assertSame( 'abcdefghij', (string) file_get_contents( $uploads->find( $upload['id'] )['path'] ) );

		$this->assertNull( $uploads->find( '../../etc/passwd' ) );
		$uploads->delete( $upload['id'] );
		$this->assertNull( $uploads->find( $upload['id'] ) );
	}

	/**
	 * Une exécution de restauration résume ses réglages pour l'interface.
	 */
	public function test_public_array_of_a_restore(): void {
		$run        = new Run();
		$run->kind  = Run::KIND_RESTORE;
		$run->state = array(
			'restore' => array(
				'source'     => array(
					'name' => 'site.zip',
					'path' => '/secret/path',
				),
				'database'   => true,
				'files'      => false,
				'committed'  => true,
				'token_hash' => 'abc',
				'safety_run' => 12,
			),
		);

		$data = $run->to_public_array();
		$this->assertSame( 'restore', $data['kind'] );
		$this->assertSame(
			array(
				'archive'    => 'site.zip',
				'database'   => true,
				'files'      => false,
				'safety'     => false,
				'committed'  => true,
				'safety_run' => 12,
			),
			$data['restore']
		);
		$this->assertStringNotContainsString( 'secret', (string) json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringNotContainsString( 'abc', (string) json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
