<?php
/**
 * Téléchargement d'une archive gardée sur le serveur.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Admin;

use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Plugin;
use Oueb\WpBackup\Security\Capabilities;
use Oueb\WpBackup\Storage\Storage_Repository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Envoie au navigateur l'archive d'une exécution.
 *
 * Le dossier des archives refuse tout accès direct : le téléchargement passe
 * par admin-post.php, qui vérifie la capacité et un nonce propre à l'exécution.
 *
 * @since 0.1.0
 */
final class Download {

	/**
	 * Action d'admin-post.php.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const ACTION = 'oueb_wp_backup_download';

	/**
	 * Taille des morceaux relayés depuis un stockage distant.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 8388608;

	/**
	 * Branche l'action.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Construit l'adresse de téléchargement d'une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 * @return string Adresse, avec nonce.
	 */
	public static function url( Run $run ): string {
		// Pas de wp_nonce_url() : elle échappe « & » pour le HTML, et l'adresse
		// passe par l'API REST avant d'arriver dans l'attribut href.
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'run'      => $run->id,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $run->id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Vérifie la demande et envoie le fichier.
	 *
	 * @since 0.1.0
	 */
	public static function handle(): void {
		$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download backups.', 'oueb-wp-backup' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION . '_' . $run_id );

		$run = Plugin::runs()->find( $run_id );
		if ( null === $run || '' === $run->archive_file ) {
			wp_die( esc_html__( 'This archive no longer exists.', 'oueb-wp-backup' ), '', array( 'response' => 404 ) );
		}

		$name  = basename( $run->archive_file );
		$local = Plugin::workspace()->root() . '/archives/' . $name;
		if ( is_file( $local ) ) {
			self::headers( $name, (int) filesize( $local ) );
			readfile( $local );
			exit;
		}

		// L'archive n'est plus sur le serveur : elle est relayée depuis un stockage distant.
		foreach ( (array) ( $run->state['stored'] ?? array() ) as $id ) {
			$storage = Plugin::storages()->instance( (string) $id );
			if ( null === $storage || Storage_Repository::LOCAL === $id ) {
				continue;
			}

			try {
				$chunk = $storage->read( $name, 0, self::CHUNK );
			} catch ( Throwable $error ) {
				continue;
			}

			self::headers( $name, $run->archive_size );
			$output = fopen( 'php://output', 'wb' );
			$offset = 0;
			while ( '' !== $chunk && false !== $output ) {
				fwrite( $output, $chunk );
				flush();
				$offset += strlen( $chunk );
				if ( $offset >= $run->archive_size ) {
					break;
				}
				try {
					$chunk = $storage->read( $name, $offset, self::CHUNK );
				} catch ( Throwable $error ) {
					break;
				}
			}
			exit;
		}

		wp_die( esc_html__( 'This archive is no longer available in any storage.', 'oueb-wp-backup' ), '', array( 'response' => 404 ) );
	}

	/**
	 * Envoie les en-têtes d'un téléchargement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom du fichier.
	 * @param int    $size Taille.
	 */
	private static function headers( string $name, int $size ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		if ( $size > 0 ) {
			header( 'Content-Length: ' . (string) $size );
		}
		header( 'X-Content-Type-Options: nosniff' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
	}
}
