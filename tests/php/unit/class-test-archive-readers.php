<?php
/**
 * Tests de lecture des archives zip, tar et tar.gz.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Archive\Archive_Reader;
use Oueb\WpBackup\Archive\Tar_Gz_Writer;
use Oueb\WpBackup\Archive\Tar_Reader;
use Oueb\WpBackup\Archive\Zip_Reader;
use Oueb\WpBackup\Archive\Zip_Writer;
use RuntimeException;

/**
 * Relit les archives de l'extension et celles de GNU tar, avec reprise au milieu.
 */
final class Test_Archive_Readers extends Test_Case {

	/**
	 * Dossier de travail du test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Contenu attendu, par nom dans l'archive.
	 *
	 * @var array<string, string>
	 */
	private array $contents = array();

	/**
	 * Crée des fichiers variés : texte, binaire, vide, nom long et accentué.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/oueb-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/src', 0777, true );

		$this->contents = array(
			'oueb-wp-backup-data/manifest.json' => '{"format":1}',
			'oueb-wp-backup-data/database.sql'  => str_repeat( "INSERT INTO t VALUES (1);\n", 5000 ),
			'wp-content/uploads/photo.jpg'      => random_bytes( 300000 ),
			'wp-content/uploads/empty.txt'      => '',
			'wp-content/plugins/' . str_repeat( 'long-name/', 12 ) . 'é.php' => '<?php echo 1;',
			'wp-content/themes/t/style.css'     => str_repeat( 'a', 1500 ),
		);
	}

	/**
	 * Supprime le dossier de travail.
	 */
	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tearDown();
	}

	/**
	 * Écrit une archive avec l'écrivain de l'extension, un lot validé par fichier.
	 *
	 * @param object $writer Écrivain.
	 * @param string $path   Chemin de l'archive.
	 */
	private function write( object $writer, string $path ): void {
		$writer->open( $path, array() );
		$index = 0;
		foreach ( $this->contents as $name => $content ) {
			$source = $this->dir . '/src/' . $index;
			++$index;
			file_put_contents( $source, $content );
			$writer->add_file( $source, $name );
			$writer->commit();
		}
		$writer->finish();
	}

	/**
	 * Lit toute une archive, en rouvrant le lecteur au milieu d'un fichier.
	 *
	 * @param callable $factory Crée un lecteur.
	 * @param string   $path    Archive.
	 * @param int      $stop_at Numéro de l'entrée où simuler la coupure.
	 * @return array<string, string> Contenu lu, par nom.
	 */
	private function read_with_resume( callable $factory, string $path, int $stop_at ): array {
		$found  = array();
		$reader = $factory();
		$reader->open( $path, array() );
		$index = 0;
		while ( null !== ( $entry = $reader->next() ) ) {
			if ( 'file' !== $entry['type'] ) {
				continue;
			}
			if ( $index === $stop_at ) {
				// Coupure après quelques octets : la reprise relit l'entrée depuis son début.
				$partial  = $reader->read( 100 );
				$position = $reader->position();
				$reader->close();
				$reader = $factory();
				$reader->open( $path, json_decode( (string) json_encode( $position ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				$again = $reader->next();
				$this->assertSame( $entry['name'], $again['name'] );
				$this->assertSame( $partial, $reader->read( strlen( $partial ) ) );
				$found[ $entry['name'] ] = $partial . $this->drain( $reader );
			} else {
				$found[ $entry['name'] ] = $this->drain( $reader );
			}
			$this->assertSame( strlen( $found[ $entry['name'] ] ), $entry['size'] );
			++$index;
		}
		$reader->close();

		return $found;
	}

	/**
	 * Lit les données de l'entrée en cours jusqu'au bout.
	 *
	 * @param Archive_Reader $reader Lecteur.
	 * @return string Données.
	 */
	private function drain( Archive_Reader $reader ): string {
		$data = '';
		while ( '' !== ( $chunk = $reader->read( 65536 ) ) ) {
			$data .= $chunk;
		}
		return $data;
	}

	/**
	 * Une archive zip de l'extension se relit, reprise comprise.
	 */
	public function test_zip_reader_roundtrip(): void {
		$path = $this->dir . '/backup.zip';
		$this->write( new Zip_Writer(), $path );

		$this->assertSame( $this->contents, $this->read_with_resume( fn() => new Zip_Reader(), $path, 2 ) );
	}

	/**
	 * Une archive tar.gz de l'extension, en plusieurs membres gzip, se relit.
	 *
	 * La coupure tombe dans un membre qui n'est pas le premier.
	 */
	public function test_tar_gz_reader_roundtrip_across_members(): void {
		$path = $this->dir . '/backup.tar.gz';
		$this->write( new Tar_Gz_Writer(), $path );

		$this->assertSame( $this->contents, $this->read_with_resume( fn() => new Tar_Reader( true ), $path, 2 ) );
	}

	/**
	 * Une archive tar.gz de GNU tar, en un seul membre, se relit ; ses
	 * dossiers ressortent comme tels.
	 */
	public function test_tar_gz_reader_reads_gnu_tar(): void {
		$src = $this->dir . '/tree';
		foreach ( $this->contents as $name => $content ) {
			if ( ! is_dir( dirname( $src . '/' . $name ) ) ) {
				mkdir( dirname( $src . '/' . $name ), 0777, true );
			}
			file_put_contents( $src . '/' . $name, $content );
		}

		foreach ( array( 'gnu', 'posix' ) as $format ) {
			$path = $this->dir . '/gnu-' . $format . '.tar.gz';
			exec( 'tar --format=' . $format . ' -czf ' . escapeshellarg( $path ) . ' -C ' . escapeshellarg( $src ) . ' .', $output, $status );
			$this->assertSame( 0, $status );

			$read = $this->read_with_resume( fn() => new Tar_Reader( true ), $path, 3 );
			$read = array_combine( array_map( fn( $name ) => substr( $name, 2 ), array_keys( $read ) ), $read );
			ksort( $read );
			$expected = $this->contents;
			ksort( $expected );
			$this->assertSame( $expected, $read, $format );
		}

		$reader = new Tar_Reader( true );
		$reader->open( $this->dir . '/gnu-gnu.tar.gz', array() );
		$types = array();
		while ( null !== ( $entry = $reader->next() ) ) {
			$types[ $entry['type'] ] = true;
		}
		$reader->close();
		$this->assertArrayHasKey( 'dir', $types );
	}

	/**
	 * Une archive tar non compressée se relit aussi.
	 */
	public function test_plain_tar_reader(): void {
		$gz   = $this->dir . '/backup.tar.gz';
		$path = $this->dir . '/backup.tar';
		$this->write( new Tar_Gz_Writer(), $gz );
		// gzdecode() ne lit que le premier membre : gzip les lit tous.
		exec( 'gzip -dc ' . escapeshellarg( $gz ) . ' > ' . escapeshellarg( $path ) );

		$this->assertSame( $this->contents, $this->read_with_resume( fn() => new Tar_Reader( false ), $path, 3 ) );
	}

	/**
	 * Une archive tronquée est signalée, pas lue en silence.
	 */
	public function test_truncated_tar_gz_is_reported(): void {
		$path = $this->dir . '/backup.tar.gz';
		$this->write( new Tar_Gz_Writer(), $path );
		$data = (string) file_get_contents( $path );
		file_put_contents( $path, substr( $data, 0, (int) ( strlen( $data ) * 0.6 ) ) );

		$this->expectException( RuntimeException::class );
		$reader = new Tar_Reader( true );
		$reader->open( $path, array() );
		while ( null !== $reader->next() ) {
			$this->drain( $reader );
		}
	}

	/**
	 * Un en-tête modifié est refusé par sa somme de contrôle.
	 */
	public function test_damaged_header_is_reported(): void {
		$path = $this->dir . '/backup.tar';
		$this->write( new Tar_Gz_Writer(), $path . '.gz' );
		exec( 'gzip -dc ' . escapeshellarg( $path . '.gz' ) . ' > ' . escapeshellarg( $path ) );
		$data = substr_replace( (string) file_get_contents( $path ), 'X', 10, 1 );
		file_put_contents( $path, $data );

		$this->expectException( RuntimeException::class );
		$reader = new Tar_Reader( false );
		$reader->open( $path, array() );
		$reader->next();
	}
}
