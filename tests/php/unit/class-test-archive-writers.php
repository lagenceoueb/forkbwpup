<?php
/**
 * Tests des archives zip et tar.gz.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Archive\Tar_Gz_Writer;
use Oueb\WpBackup\Archive\Zip_Writer;
use ZipArchive;

/**
 * Écrit de vraies archives, avec reprise, puis les relit avec d'autres outils.
 */
final class Test_Archive_Writers extends Test_Case {

	/**
	 * Dossier de travail du test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Fichiers sources, par nom dans l'archive.
	 *
	 * @var array<string, string>
	 */
	private array $sources = array();

	/**
	 * Crée des fichiers variés : texte, binaire, vide, nom long et accentué.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/oueb-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/src', 0777, true );

		$files = array(
			'database.sql'                           => str_repeat( "INSERT INTO t VALUES (1);\n", 5000 ),
			'uploads/photo.jpg'                      => random_bytes( 70000 ),
			'uploads/empty.txt'                      => '',
			'plugins/' . str_repeat( 'long-name/', 12 ) . 'é.php' => '<?php echo 1;',
		);
		$index = 0;
		foreach ( $files as $name => $content ) {
			$path = $this->dir . '/src/' . $index;
			++$index;
			file_put_contents( $path, $content );
			$this->sources[ $name ] = $path;
		}
	}

	/**
	 * Supprime le dossier de travail.
	 */
	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tearDown();
	}

	/**
	 * Écrit l'archive en deux passages, avec une coupure simulée au milieu.
	 *
	 * Le passage interrompu ajoute un fichier sans le valider : la reprise
	 * doit le faire disparaître, puis l'ajouter de nouveau.
	 *
	 * @param callable $factory Crée un nouvel écrivain.
	 * @param string   $path    Chemin de l'archive.
	 * @return int Taille finale.
	 */
	private function write_with_resume( callable $factory, string $path ): int {
		$names = array_keys( $this->sources );

		$first = $factory();
		$first->open( $path, array() );
		$first->add_file( $this->sources[ $names[0] ], $names[0] );
		$first->add_file( $this->sources[ $names[1] ], $names[1] );
		$checkpoint = $first->commit();
		$first->add_file( $this->sources[ $names[2] ], $names[2] );
		unset( $first );

		$second = $factory();
		$second->open( $path, $checkpoint );
		foreach ( array_slice( $names, 2 ) as $name ) {
			$second->add_file( $this->sources[ $name ], $name );
		}
		$second->commit();

		return $second->finish();
	}

	/**
	 * L'archive zip reprise est lisible et contient chaque fichier une fois.
	 */
	public function test_zip_roundtrip_after_resume(): void {
		$path = $this->dir . '/backup.zip';
		$size = $this->write_with_resume( fn() => new Zip_Writer(), $path );

		clearstatcache();
		$this->assertSame( filesize( $path ), $size );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CHECKCONS ) );
		$this->assertSame( count( $this->sources ), $zip->count() );
		foreach ( $this->sources as $name => $source ) {
			$this->assertSame( file_get_contents( $source ), $zip->getFromName( $name ), $name );
		}
		$this->assertSame( ZipArchive::CM_STORE, $zip->statName( 'uploads/photo.jpg' )['comp_method'] );
		$this->assertSame( ZipArchive::CM_DEFLATE, $zip->statName( 'database.sql' )['comp_method'] );
		$zip->close();
	}

	/**
	 * L'archive tar.gz reprise se lit avec tar, noms longs compris.
	 */
	public function test_tar_gz_roundtrip_after_resume(): void {
		$path = $this->dir . '/backup.tar.gz';
		$size = $this->write_with_resume( fn() => new Tar_Gz_Writer(), $path );

		clearstatcache();
		$this->assertSame( filesize( $path ), $size );

		$out = $this->dir . '/out';
		mkdir( $out );
		exec( 'tar -xzf ' . escapeshellarg( $path ) . ' -C ' . escapeshellarg( $out ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		exec( 'tar --quoting-style=literal -tzf ' . escapeshellarg( $path ), $listed );
		$this->assertSame( array_keys( $this->sources ), $listed );
		foreach ( $this->sources as $name => $source ) {
			$this->assertFileEquals( $source, $out . '/' . $name, $name );
		}
	}

	/**
	 * L'en-tête tar fait 512 octets et sa somme de contrôle est juste.
	 */
	public function test_tar_header_checksum(): void {
		$header = Tar_Gz_Writer::header( 'a.txt', 10, 0, '0' );

		$this->assertSame( 512, strlen( $header ) );
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ( $i >= 148 && $i < 156 ) ? 32 : ord( $header[ $i ] );
		}
		$this->assertSame( $sum, octdec( trim( substr( $header, 148, 6 ) ) ) );
		$this->assertSame( '00000000012', substr( $header, 124, 11 ) );
	}

	/**
	 * Au-delà de 8 Go, la taille passe à l'encodage binaire de GNU tar.
	 */
	public function test_tar_header_large_size(): void {
		$header = Tar_Gz_Writer::header( 'big.bin', 10 * 1024 ** 3, 0, '0' );
		$field  = substr( $header, 124, 12 );

		$this->assertSame( "\x80", $field[0] );
		$this->assertSame( 10 * 1024 ** 3, (int) hexdec( bin2hex( substr( $field, 1 ) ) ) );
	}
}
