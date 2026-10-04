<?php
/**
 * Archive zip écrite en flux.
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
 * Écrit une archive zip en ajoutant toujours à la fin du fichier.
 *
 * ZipArchive réécrit toute l'archive à chaque fermeture : sur un site de
 * plusieurs gigaoctets validé par lots, cela ferait des centaines de
 * gigaoctets d'écriture. Cette classe écrit chaque fichier une seule fois.
 * Les entrées du répertoire central s'accumulent dans un fichier voisin
 * (.cd), recopié en fin d'archive par finish().
 *
 * Chaque fichier est compressé en flux (deflate brut). Son en-tête local est
 * complété après coup avec le CRC et les tailles réelles. Les fichiers déjà
 * compressés sont stockés tels quels. Zip64 prend le relais au-delà de
 * 4 Go ou de 65 535 entrées. Les noms sont marqués UTF-8.
 *
 * @since 0.1.0
 */
final class Zip_Writer implements Archive_Writer {

	/**
	 * Extensions stockées sans compression.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const STORED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp3', 'mp4', 'm4a', 'mov', 'webm', 'ogg', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf' );

	/**
	 * Taille des morceaux lus dans les fichiers.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 1048576;

	/**
	 * Valeur qui signale un champ Zip64.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX32 = 0xFFFFFFFF;

	/**
	 * Fichier de l'archive.
	 *
	 * @since 0.1.0
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Fichier du répertoire central en cours.
	 *
	 * @since 0.1.0
	 * @var resource|null
	 */
	private $directory = null;

	/**
	 * Nombre d'entrées écrites.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $entries = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string             $path       Chemin de l'archive.
	 * @param array<string, int> $checkpoint Point de reprise, vide pour une archive neuve.
	 */
	public function open( string $path, array $checkpoint ): void {
		$this->handle    = Resumable_File::open( $path, (int) ( $checkpoint['size'] ?? 0 ) );
		$this->directory = Resumable_File::open( $path . '.cd', (int) ( $checkpoint['directory'] ?? 0 ) );
		$this->entries   = (int) ( $checkpoint['entries'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $source Chemin du fichier.
	 * @param string $name   Nom dans l'archive.
	 *
	 * @throws RuntimeException Si le fichier ne peut pas être lu ou écrit.
	 */
	public function add_file( string $source, string $name ): void {
		$reader = fopen( $source, 'rb' );
		if ( false === $reader ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $source ) ) );
		}

		try {
			$size    = (int) filesize( $source );
			$stored  = in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::STORED, true ) || 0 === $size;
			$method  = $stored ? 0 : 8;
			$zip64   = $size >= self::MAX32 - self::CHUNK * 16;
			$offset  = (int) ftell( $this->handle );
			$dos     = self::dos_time( (int) filemtime( $source ) );
			$version = $zip64 ? 45 : 20;

			// En-tête local provisoire : CRC et tailles sont complétés après les données.
			$extra = $zip64 ? pack( 'vv', 0x0001, 16 ) . str_repeat( "\0", 16 ) : '';
			Resumable_File::write(
				$this->handle,
				pack( 'VvvvvvVVVvv', 0x04034b50, $version, 0x0800, $method, $dos[0], $dos[1], 0, 0, 0, strlen( $name ), strlen( $extra ) ) . $name . $extra
			);

			$crc        = hash_init( 'crc32b' );
			$deflate    = $stored ? null : deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) );
			$read       = 0;
			$compressed = 0;

			while ( ! feof( $reader ) ) {
				$data = fread( $reader, self::CHUNK );
				if ( false === $data || '' === $data ) {
					break;
				}
				$read += strlen( $data );
				hash_update( $crc, $data );
				$out         = null === $deflate ? $data : deflate_add( $deflate, $data, ZLIB_NO_FLUSH );
				$compressed += strlen( $out );
				Resumable_File::write( $this->handle, $out );
			}
			if ( null !== $deflate ) {
				$out         = deflate_add( $deflate, '', ZLIB_FINISH );
				$compressed += strlen( $out );
				Resumable_File::write( $this->handle, $out );
			}

			if ( ! $zip64 && ( $read >= self::MAX32 || $compressed >= self::MAX32 ) ) {
				/* translators: %s: file path. */
				throw new RuntimeException( esc_html( sprintf( __( 'The file %s grew past 4 GB while it was archived.', 'oueb-wp-backup' ), $source ) ) );
			}

			$checksum = (int) hexdec( hash_final( $crc ) );
			$end      = (int) ftell( $this->handle );

			// Complète l'en-tête local.
			fseek( $this->handle, $offset + 14 );
			Resumable_File::write( $this->handle, pack( 'VVV', $checksum, $zip64 ? self::MAX32 : $compressed, $zip64 ? self::MAX32 : $read ) );
			if ( $zip64 ) {
				fseek( $this->handle, $offset + 30 + strlen( $name ) + 4 );
				Resumable_File::write( $this->handle, pack( 'PP', $read, $compressed ) );
			}
			fseek( $this->handle, $end );

			Resumable_File::write( $this->directory, self::central_entry( $name, $method, $dos, $checksum, $compressed, $read, $offset ) );
			++$this->entries;
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
		return array(
			'size'      => Resumable_File::commit( $this->handle ),
			'directory' => Resumable_File::commit( $this->directory ),
			'entries'   => $this->entries,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function finish(): int {
		$start = Resumable_File::commit( $this->handle );
		$size  = Resumable_File::commit( $this->directory );

		// Recopie le répertoire central à la fin de l'archive.
		rewind( $this->directory );
		while ( ! feof( $this->directory ) ) {
			$data = fread( $this->directory, self::CHUNK );
			if ( false === $data || '' === $data ) {
				break;
			}
			Resumable_File::write( $this->handle, $data );
		}

		$end   = $start + $size;
		$zip64 = $this->entries >= 0xFFFF || $start >= self::MAX32 || $size >= self::MAX32;
		if ( $zip64 ) {
			Resumable_File::write(
				$this->handle,
				pack( 'VPvvVVPPPP', 0x06064b50, 44, ( 3 << 8 ) | 45, 45, 0, 0, $this->entries, $this->entries, $size, $start )
				. pack( 'VVPV', 0x07064b50, 0, $end, 1 )
			);
		}
		Resumable_File::write(
			$this->handle,
			pack(
				'VvvvvVVv',
				0x06054b50,
				0,
				0,
				$zip64 ? 0xFFFF : $this->entries,
				$zip64 ? 0xFFFF : $this->entries,
				$zip64 ? self::MAX32 : $size,
				$zip64 ? self::MAX32 : $start,
				0
			)
		);

		// Le fichier .cd reste en place : une reprise après une coupure ici
		// en a encore besoin. Le nettoyage du dossier temporaire le supprime.
		$total = Resumable_File::commit( $this->handle );
		fclose( $this->handle );
		fclose( $this->directory );
		$this->handle    = null;
		$this->directory = null;

		return $total;
	}

	/**
	 * Construit l'entrée d'un fichier dans le répertoire central.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name       Nom.
	 * @param int    $method     Méthode : 0 stocké, 8 deflate.
	 * @param int[]  $dos        Heure et date au format DOS.
	 * @param int    $crc        CRC-32.
	 * @param int    $compressed Taille compressée.
	 * @param int    $size       Taille d'origine.
	 * @param int    $offset     Position de l'en-tête local.
	 * @return string Entrée binaire.
	 */
	private static function central_entry( string $name, int $method, array $dos, int $crc, int $compressed, int $size, int $offset ): string {
		$extra = '';
		if ( $size >= self::MAX32 ) {
			$extra .= pack( 'P', $size );
		}
		if ( $compressed >= self::MAX32 ) {
			$extra .= pack( 'P', $compressed );
		}
		if ( $offset >= self::MAX32 ) {
			$extra .= pack( 'P', $offset );
		}
		if ( '' !== $extra ) {
			$extra = pack( 'vv', 0x0001, strlen( $extra ) ) . $extra;
		}
		$version = '' === $extra ? 20 : 45;

		return pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			( 3 << 8 ) | $version,
			$version,
			0x0800,
			$method,
			$dos[0],
			$dos[1],
			$crc,
			min( $compressed, self::MAX32 ),
			min( $size, self::MAX32 ),
			strlen( $name ),
			strlen( $extra ),
			0,
			0,
			0,
			0100644 << 16,
			min( $offset, self::MAX32 )
		) . $name . $extra;
	}

	/**
	 * Convertit un horodatage en heure et date DOS.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timestamp Horodatage UTC.
	 * @return int[] Heure puis date, au format DOS.
	 */
	private static function dos_time( int $timestamp ): array {
		$parts = explode( ' ', gmdate( 'Y n j G i s', max( 315532800, $timestamp ) ) );

		return array(
			( (int) $parts[3] << 11 ) | ( (int) $parts[4] << 5 ) | ( (int) $parts[5] >> 1 ),
			( ( (int) $parts[0] - 1980 ) << 9 ) | ( (int) $parts[1] << 5 ) | (int) $parts[2],
		);
	}
}
