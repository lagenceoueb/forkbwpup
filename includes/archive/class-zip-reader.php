<?php
/**
 * Lecture d'une archive zip.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Archive;

use RuntimeException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Lit une archive zip avec ZipArchive, entrée par entrée.
 *
 * Le point de reprise est le numéro de l'entrée dans le répertoire central.
 *
 * @since 0.1.0
 */
final class Zip_Reader implements Archive_Reader {

	/**
	 * Archive ouverte.
	 *
	 * @since 0.1.0
	 * @var ZipArchive|null
	 */
	private ?ZipArchive $zip = null;

	/**
	 * Nombre d'entrées.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Prochaine entrée à rendre.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $cursor = 0;

	/**
	 * Entrée en cours, -1 avant le premier next().
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $current = -1;

	/**
	 * Flux des données de l'entrée en cours.
	 *
	 * @since 0.1.0
	 * @var resource|null
	 */
	private $stream = null;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string             $path     Chemin de l'archive.
	 * @param array<string, int> $position Point de reprise.
	 *
	 * @throws RuntimeException Si l'archive ne s'ouvre pas.
	 */
	public function open( string $path, array $position ): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( esc_html__( 'The PHP zip extension is missing: zip archives cannot be read.', 'oueb-wp-backup' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			throw new RuntimeException( esc_html__( 'The archive is damaged: it cannot be opened as a zip file.', 'oueb-wp-backup' ) );
		}

		$this->zip     = $zip;
		$this->count   = $zip->count();
		$this->cursor  = max( 0, (int) ( $position['index'] ?? 0 ) );
		$this->current = $this->cursor;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @throws RuntimeException Si une entrée ne se lit pas.
	 */
	public function next(): ?array {
		$this->close_stream();
		if ( null === $this->zip || $this->cursor >= $this->count ) {
			return null;
		}

		$this->current = $this->cursor;
		++$this->cursor;

		$stat = $this->zip->statIndex( $this->current );
		if ( false === $stat ) {
			throw new RuntimeException( esc_html__( 'The archive is damaged: a file entry cannot be read.', 'oueb-wp-backup' ) );
		}

		$name = (string) $stat['name'];

		return array(
			'name'  => rtrim( $name, '/' ),
			'type'  => '/' === substr( $name, -1 ) ? 'dir' : 'file',
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param int $length Nombre d'octets au plus.
	 *
	 * @throws RuntimeException Si les données ne se lisent pas.
	 */
	public function read( int $length ): string {
		if ( null === $this->zip || $this->current < 0 || $this->current >= $this->cursor ) {
			return '';
		}

		if ( null === $this->stream ) {
			$name   = (string) $this->zip->getNameIndex( $this->current );
			$stream = $this->zip->getStream( $name );
			if ( false === $stream ) {
				/* translators: %s: file name in the archive. */
				throw new RuntimeException( esc_html( sprintf( __( 'The archive is damaged: %s cannot be read.', 'oueb-wp-backup' ), $name ) ) );
			}
			$this->stream = $stream;
		}

		$data = '';
		$left = $length;
		while ( $left > 0 && ! feof( $this->stream ) ) {
			$chunk = fread( $this->stream, $left );
			if ( false === $chunk ) {
				throw new RuntimeException( esc_html__( 'The archive is damaged: a file cannot be decompressed.', 'oueb-wp-backup' ) );
			}
			if ( '' === $chunk ) {
				break;
			}
			$data .= $chunk;
			$left -= strlen( $chunk );
		}

		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function position(): array {
		return array( 'index' => max( 0, $this->current ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function close(): void {
		$this->close_stream();
		if ( null !== $this->zip ) {
			$this->zip->close();
		}
		$this->zip = null;
	}

	/**
	 * Ferme le flux de l'entrée en cours.
	 *
	 * @since 0.1.0
	 */
	private function close_stream(): void {
		if ( null !== $this->stream ) {
			fclose( $this->stream );
		}
		$this->stream = null;
	}
}
