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
use Oueb\WpBackup\Security\Archive_Cipher;
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
	 * @param Run  $run     Exécution.
	 * @param bool $decrypt Vrai pour télécharger l'archive déchiffrée.
	 * @return string Adresse, avec nonce.
	 */
	public static function url( Run $run, bool $decrypt = false ): string {
		// Pas de wp_nonce_url() : elle échappe « & » pour le HTML, et l'adresse
		// passe par l'API REST avant d'arriver dans l'attribut href.
		$args = array(
			'action'   => self::ACTION,
			'run'      => $run->id,
			'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $run->id ),
		);
		if ( $decrypt ) {
			$args['decrypt'] = 1;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
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

		$name   = basename( $run->archive_file );
		$reader = self::reader( $run, $name );
		if ( null === $reader ) {
			wp_die( esc_html__( 'This archive is no longer available in any storage.', 'oueb-wp-backup' ), '', array( 'response' => 404 ) );
		}

		$decrypt = ! empty( $_GET['decrypt'] ) && '.enc' === substr( $name, -4 );
		if ( $decrypt ) {
			self::send_decrypted( $reader, substr( $name, 0, -4 ) );
		}

		self::headers( $name, $run->archive_size );
		$output = fopen( 'php://output', 'wb' );
		$offset = 0;
		while ( false !== $output && $offset < $run->archive_size ) {
			try {
				$chunk = call_user_func( $reader, $offset, self::CHUNK );
			} catch ( Throwable $error ) {
				break;
			}
			if ( '' === $chunk ) {
				break;
			}
			fwrite( $output, $chunk );
			flush();
			$offset += strlen( $chunk );
		}
		exit;
	}

	/**
	 * Trouve où lire l'archive : sur le serveur, sinon dans un stockage distant qui répond.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run  Exécution.
	 * @param string $name Nom de l'archive.
	 * @return callable|null Lecture : reçoit une position et une longueur, renvoie des octets. Null si l'archive est introuvable.
	 */
	private static function reader( Run $run, string $name ): ?callable {
		$local = Plugin::workspace()->root() . '/archives/' . $name;
		if ( is_file( $local ) ) {
			return static fn( int $offset, int $length ): string => (string) file_get_contents( $local, false, null, $offset, $length );
		}

		foreach ( (array) ( $run->state['stored'] ?? array() ) as $id ) {
			$storage = Storage_Repository::LOCAL === $id ? null : Plugin::storages()->instance( (string) $id );
			if ( null === $storage ) {
				continue;
			}
			try {
				$storage->read( $name, 0, 1 );
			} catch ( Throwable $error ) {
				continue;
			}

			return static fn( int $offset, int $length ): string => $storage->read( $name, $offset, $length );
		}

		return null;
	}

	/**
	 * Envoie l'archive déchiffrée, puis s'arrête.
	 *
	 * La clé est vérifiée avant l'envoi des en-têtes. Une erreur plus tardive
	 * coupe le téléchargement : l'archive reçue est alors incomplète, et
	 * l'outil d'extraction le signale.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $reader Lecture de l'archive chiffrée.
	 * @param string   $name   Nom du fichier déchiffré.
	 */
	private static function send_decrypted( callable $reader, string $name ): void {
		$keys = Plugin::keys();
		$id   = Archive_Cipher::key_id( (string) call_user_func( $reader, 0, Archive_Cipher::HEADER_BYTES ) );
		if ( null === $id ) {
			wp_die( esc_html__( 'This file is not an encrypted backup.', 'oueb-wp-backup' ), '', array( 'response' => 409 ) );
		}
		if ( null === $keys->find( $id ) ) {
			/* translators: %s: key identifier. */
			wp_die( esc_html( sprintf( __( 'The key %s is missing. Add it in the encryption settings, or decrypt the archive with the offline tool.', 'oueb-wp-backup' ), $id ) ), '', array( 'response' => 409 ) );
		}

		self::headers( $name, 0 );
		$output = fopen( 'php://output', 'wb' );
		$offset = 0;
		$buffer = '';
		try {
			Archive_Cipher::decrypt(
				static function ( int $length ) use ( $reader, &$offset, &$buffer ): string {
					// Lectures distantes par gros morceaux, découpées ensuite à la demande.
					if ( strlen( $buffer ) < $length ) {
						$chunk   = (string) call_user_func( $reader, $offset, max( $length, self::CHUNK ) );
						$offset += strlen( $chunk );
						$buffer .= $chunk;
					}
					$data   = (string) substr( $buffer, 0, $length );
					$buffer = (string) substr( $buffer, strlen( $data ) );
					return $data;
				},
				static function ( string $plain ) use ( $output ): void {
					fwrite( $output, $plain );
					flush();
				},
				array( $keys, 'find' )
			);
		} catch ( Throwable $error ) {
			// Les en-têtes sont partis : l'archive reçue reste incomplète.
			exit;
		}
		exit;
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
