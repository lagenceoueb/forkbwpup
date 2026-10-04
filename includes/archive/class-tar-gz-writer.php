<?php
/**
 * Archive tar.gz.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Archive;

use Oueb\WpBackup\Storage\Resumable_File;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Écrit une archive tar compressée en gzip, en flux.
 *
 * Chaque lot de fichiers forme un membre gzip à part : la norme gzip permet
 * d'en enchaîner plusieurs dans un fichier, et tous les outils les lisent à la
 * suite. Une reprise tronque donc l'archive à la fin du dernier membre validé,
 * puis en ouvre un nouveau.
 *
 * Les noms de plus de 100 octets passent par une entrée GNU « LongLink », les
 * tailles de plus de 8 Go par l'encodage binaire de GNU tar.
 *
 * @since 0.1.0
 */
final class Tar_Gz_Writer implements Archive_Writer {

	/**
	 * Taille d'un bloc tar.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const BLOCK = 512;

	/**
	 * Taille des morceaux lus dans les fichiers.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 1048576;

	/**
	 * Chemin de l'archive.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $path = '';

	/**
	 * Fichier de l'archive, ouvert en écriture.
	 *
	 * @since 0.1.0
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Flux de compression du membre gzip en cours.
	 *
	 * @since 0.1.0
	 * @var resource|null
	 */
	private $deflate = null;

	/**
	 * Données en attente de compression, pour écrire par gros morceaux.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string             $path       Chemin de l'archive.
	 * @param array<string, int> $checkpoint Point de reprise, vide pour une archive neuve.
	 */
	public function open( string $path, array $checkpoint ): void {
		$this->path   = $path;
		$this->handle = Resumable_File::open( $path, (int) ( $checkpoint['size'] ?? 0 ) );
		$this->start_member();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $source Chemin du fichier.
	 * @param string $name   Nom dans l'archive.
	 *
	 * @throws RuntimeException Si le fichier ne peut pas être lu.
	 */
	public function add_file( string $source, string $name ): void {
		$size   = (int) filesize( $source );
		$reader = fopen( $source, 'rb' );
		if ( false === $reader ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $source ) ) );
		}

		try {
			$this->write_header( $name, $size, (int) filemtime( $source ) );

			// La taille annoncée dans l'en-tête fait foi : un fichier qui grandit
			// est coupé, un fichier qui rétrécit est complété par des zéros.
			$left = $size;
			while ( $left > 0 && ! feof( $reader ) ) {
				$data = fread( $reader, (int) min( self::CHUNK, $left ) );
				if ( false === $data || '' === $data ) {
					break;
				}
				$left -= strlen( $data );
				$this->write( $data );
			}
			if ( $left > 0 ) {
				$this->write( str_repeat( "\0", $left ) );
			}

			$this->pad( $size );
		} finally {
			fclose( $reader );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function commit(): array {
		$this->end_member();
		$size = Resumable_File::commit( $this->handle );
		$this->start_member();

		return array( 'size' => $size );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function finish(): int {
		// Deux blocs vides marquent la fin d'une archive tar.
		$this->write( str_repeat( "\0", 2 * self::BLOCK ) );
		$this->end_member();
		$size = Resumable_File::commit( $this->handle );
		fclose( $this->handle );
		$this->handle = null;

		return $size;
	}

	/**
	 * Écrit l'en-tête d'un fichier, précédé d'une entrée LongLink si le nom est long.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Nom dans l'archive.
	 * @param int    $size  Taille en octets.
	 * @param int    $mtime Date de modification.
	 */
	private function write_header( string $name, int $size, int $mtime ): void {
		if ( strlen( $name ) > 100 ) {
			$this->write( self::header( '././@LongLink', strlen( $name ) + 1, 0, 'L' ) );
			$this->write( $name . "\0" );
			$this->pad( strlen( $name ) + 1 );
		}

		$this->write( self::header( substr( $name, 0, 100 ), $size, $mtime, '0' ) );
	}

	/**
	 * Construit un en-tête tar GNU.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Nom, 100 octets au plus.
	 * @param int    $size  Taille.
	 * @param int    $mtime Date de modification.
	 * @param string $type  Type : « 0 » fichier, « L » nom long.
	 * @return string Bloc de 512 octets.
	 */
	public static function header( string $name, int $size, int $mtime, string $type ): string {
		$header = str_pad( $name, 100, "\0" )
			. sprintf( '%07o', 0644 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. self::size_field( $size )
			. sprintf( '%011o', max( 0, $mtime ) ) . "\0"
			. '        '
			. $type
			. str_repeat( "\0", 100 )
			. "ustar  \0"
			. str_pad( 'root', 32, "\0" )
			. str_pad( 'root', 32, "\0" )
			. str_repeat( "\0", 183 );

		$sum = 0;
		for ( $i = 0; $i < self::BLOCK; $i++ ) {
			$sum += ord( $header[ $i ] );
		}

		return substr_replace( $header, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	/**
	 * Encode la taille : octal jusqu'à 8 Go, binaire GNU au-delà.
	 *
	 * @since 0.1.0
	 *
	 * @param int $size Taille.
	 * @return string Champ de 12 octets.
	 */
	private static function size_field( int $size ): string {
		if ( $size < 8589934592 ) {
			return sprintf( '%011o', $size ) . "\0";
		}

		$bytes = '';
		for ( $i = 0; $i < 11; $i++ ) {
			$bytes = chr( $size & 0xFF ) . $bytes;
			$size  = $size >> 8;
		}

		return "\x80" . $bytes;
	}

	/**
	 * Complète le dernier bloc avec des zéros.
	 *
	 * @since 0.1.0
	 *
	 * @param int $size Taille des données écrites.
	 */
	private function pad( int $size ): void {
		$rest = $size % self::BLOCK;
		if ( 0 !== $rest ) {
			$this->write( str_repeat( "\0", self::BLOCK - $rest ) );
		}
	}

	/**
	 * Ajoute des données au membre gzip en cours.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data Données non compressées.
	 */
	private function write( string $data ): void {
		$this->buffer .= $data;
		if ( strlen( $this->buffer ) >= self::CHUNK ) {
			$this->flush( ZLIB_NO_FLUSH );
		}
	}

	/**
	 * Compresse les données en attente et les écrit dans l'archive.
	 *
	 * @since 0.1.0
	 *
	 * @param int $mode Mode de vidage de zlib.
	 *
	 * @throws RuntimeException Si la compression échoue.
	 */
	private function flush( int $mode ): void {
		$compressed = deflate_add( $this->deflate, $this->buffer, $mode );
		if ( false === $compressed ) {
			throw new RuntimeException( esc_html__( 'Cannot compress the archive.', 'oueb-wp-backup' ) );
		}
		$this->buffer = '';
		Resumable_File::write( $this->handle, $compressed );
	}

	/**
	 * Ouvre un membre gzip.
	 *
	 * @since 0.1.0
	 */
	private function start_member(): void {
		$this->deflate = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 6 ) );
		$this->buffer  = '';
	}

	/**
	 * Ferme le membre gzip en cours.
	 *
	 * @since 0.1.0
	 */
	private function end_member(): void {
		$this->flush( ZLIB_FINISH );
		$this->deflate = null;
	}
}
