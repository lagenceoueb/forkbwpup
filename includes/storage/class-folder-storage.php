<?php
/**
 * Stockage dans un dossier du serveur.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Range les archives dans un dossier du serveur du site.
 *
 * Une copie sur le même serveur ne protège ni d'une panne du disque ni
 * d'un piratage : l'interface la présente comme une copie complémentaire.
 *
 * @since 0.1.0
 */
final class Folder_Storage implements Storage {

	/**
	 * Taille des morceaux copiés.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 8388608;

	/**
	 * Dossier.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $dir;

	/**
	 * Crée le stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages : « path ».
	 */
	public function __construct( array $settings ) {
		$this->dir = untrailingslashit( wp_normalize_path( (string) ( $settings['path'] ?? '' ) ) );
	}

	/**
	 * Libellé du type.
	 *
	 * @since 0.1.0
	 *
	 * @return string Libellé.
	 */
	public static function label(): string {
		return __( 'Folder on this server', 'oueb-wp-backup' );
	}

	/**
	 * Champs des réglages.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Champs.
	 */
	public static function fields(): array {
		return array(
			'path' => array(
				'type'     => 'string',
				'required' => true,
				'max'      => 500,
			),
		);
	}

	/**
	 * Vérifie des réglages complets.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return array<string, string> Erreurs, par champ.
	 */
	public static function validate( array $settings ): array {
		$path = wp_normalize_path( (string) $settings['path'] );
		if ( ! path_is_absolute( $path ) || false !== strpos( $path, '..' ) ) {
			return array( 'path' => __( 'Enter an absolute path, such as /home/site/backups.', 'oueb-wp-backup' ) );
		}

		return array();
	}

	/**
	 * Décrit l'emplacement en une ligne.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return string Description.
	 */
	public static function describe( array $settings ): string {
		return (string) $settings['path'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string   $file     Archive locale.
	 * @param string   $name     Nom.
	 * @param Transfer $transfer État.
	 *
	 * @throws RuntimeException Si la copie échoue.
	 */
	public function upload( string $file, string $name, Transfer $transfer ): bool {
		$this->ensure_dir();
		$target = $this->dir . '/' . basename( $name );
		$total  = (int) filesize( $file );

		if ( wp_normalize_path( dirname( $file ) ) === $this->dir && basename( $file ) === basename( $name ) ) {
			return true;
		}

		if ( $transfer->may_move() && rename( $file, $target ) ) {
			$transfer->checkpoint( $total, $total );
			return true;
		}

		$part = $target . '.part';
		$sent = (int) $transfer->get( 'sent', 0 );
		clearstatcache( true, $part );
		clearstatcache( true, $target );
		if ( $sent > 0 && is_file( $target ) && (int) filesize( $target ) === $total ) {
			return true;
		}
		$reader = fopen( $file, 'rb' );
		$writer = Resumable_File::open( $part, is_file( $part ) ? min( $sent, (int) filesize( $part ) ) : 0 );
		if ( false === $reader ) {
			fclose( $writer );
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $file ) ) );
		}

		try {
			$sent = (int) ftell( $writer );
			fseek( $reader, $sent );
			while ( ! feof( $reader ) ) {
				$data = Resumable_File::read( $reader, self::CHUNK );
				if ( '' === $data ) {
					break;
				}
				Resumable_File::write( $writer, $data );
				$sent = Resumable_File::commit( $writer );
				$transfer->checkpoint( $sent, $total );
				if ( $sent < $total && $transfer->should_pause() ) {
					return false;
				}
			}
		} finally {
			fclose( $reader );
			fclose( $writer );
		}

		if ( ! rename( $part, $target ) ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot create %s.', 'oueb-wp-backup' ), $target ) ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function files(): array {
		$files = array();
		foreach ( is_dir( $this->dir ) ? (array) scandir( $this->dir ) : array() as $entry ) {
			$path = $this->dir . '/' . $entry;
			if ( '.' !== $entry[0] && is_file( $path ) && ! is_link( $path ) ) {
				$files[] = array(
					'name' => (string) $entry,
					'size' => (int) filesize( $path ),
					'time' => (int) filemtime( $path ),
				);
			}
		}

		return $files;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   Nom.
	 * @param int    $offset Position.
	 * @param int    $length Longueur.
	 *
	 * @throws RuntimeException Si le fichier est illisible.
	 */
	public function read( string $name, int $offset, int $length ): string {
		$data = file_get_contents( $this->path( $name ), false, null, $offset, $length );
		if ( false === $data ) {
			/* translators: %s: file name. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $name ) ) );
		}

		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 */
	public function delete( string $name ): void {
		wp_delete_file( $this->path( $name ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @throws RuntimeException Si le dossier n'est pas utilisable.
	 */
	public function test(): string {
		$this->ensure_dir();
		$probe = $this->dir . '/oueb-wp-backup-test.txt';
		if ( false === file_put_contents( $probe, 'test' ) ) {
			/* translators: %s: folder path. */
			throw new RuntimeException( esc_html( sprintf( __( 'The folder %s is not writable.', 'oueb-wp-backup' ), $this->dir ) ) );
		}
		wp_delete_file( $probe );

		return sprintf(
			/* translators: %s: free disk space, such as 12 GB. */
			__( 'The folder is writable. Free space: %s.', 'oueb-wp-backup' ),
			size_format( (int) disk_free_space( $this->dir ) )
		);
	}

	/**
	 * Renvoie le chemin d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 * @return string Chemin.
	 */
	public function path( string $name ): string {
		return $this->dir . '/' . basename( $name );
	}

	/**
	 * Crée le dossier et le protège de l'accès web.
	 *
	 * @since 0.1.0
	 *
	 * @throws RuntimeException Si le dossier ne peut pas être créé.
	 */
	private function ensure_dir(): void {
		if ( ! is_dir( $this->dir ) && ! wp_mkdir_p( $this->dir ) ) {
			/* translators: %s: folder path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot create the folder %s.', 'oueb-wp-backup' ), $this->dir ) ) );
		}
		Workspace::protect_dir( $this->dir );
	}
}
