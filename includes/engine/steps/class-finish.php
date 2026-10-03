<?php
/**
 * Étape de rangement de l'archive.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Range l'archive terminée dans le dossier des archives et applique la rotation.
 *
 * Le nom de l'archive porte le site, la tâche et la date :
 * exemple-fr_main_2026-10-03_020000.zip. La rotation ne touche que les
 * archives de la même tâche.
 *
 * @since 0.1.0
 */
final class Finish implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'finish';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Storing the archive', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws \RuntimeException Si l'archive ne peut pas être rangée.
	 */
	public function run( Run_Context $context ): bool {
		$archives = $context->workspace->archives();
		$name     = (string) $context->get( 'name', '' );

		if ( '' === $name ) {
			$name = self::archive_name( $context->job->id, $context->run->started_at, $context->job->archive_format );
			$context->set( 'name', $name );
		}

		$source = Archive::path( $context );
		$target = $archives . '/' . $name;

		if ( is_file( $source ) && ! rename( $source, $target ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot move the archive to the archives folder.', 'oueb-wp-backup' ) );
		}
		if ( ! is_file( $target ) ) {
			throw new \RuntimeException( esc_html__( 'The archive is missing.', 'oueb-wp-backup' ) );
		}

		clearstatcache( true, $target );
		$context->run->archive_file = $name;
		$context->run->archive_size = (int) filesize( $target );

		$removed = self::rotate( $archives, $context->job->id, $context->job->keep );
		if ( $removed > 0 ) {
			$context->logger->info(
				sprintf(
					/* translators: %d: number of archives. */
					_n( '%d old archive deleted from the server.', '%d old archives deleted from the server.', $removed, 'oueb-wp-backup' ),
					$removed
				)
			);
		}

		return true;
	}

	/**
	 * Construit le nom d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id    Tâche.
	 * @param int    $timestamp Début de l'exécution.
	 * @param string $format    Format : zip ou tar.gz.
	 * @return string Nom du fichier.
	 */
	public static function archive_name( string $job_id, int $timestamp, string $format ): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );

		return ( '' === $site ? 'site' : $site ) . '_' . $job_id . '_' . gmdate( 'Y-m-d_His', $timestamp ) . '.' . $format;
	}

	/**
	 * Garde les archives les plus récentes d'une tâche et supprime les autres.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir    Dossier des archives.
	 * @param string $job_id Tâche.
	 * @param int    $keep   Nombre d'archives gardées.
	 * @return int Nombre d'archives supprimées.
	 */
	public static function rotate( string $dir, string $job_id, int $keep ): int {
		$files = array();
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( preg_match( '/^[a-z0-9-]+_' . preg_quote( $job_id, '/' ) . '_\d{4}-\d{2}-\d{2}_\d{6}\.(zip|tar\.gz)$/', (string) $file ) ) {
				$files[] = (string) $file;
			}
		}

		// Le nom contient la date : l'ordre alphabétique inverse met les plus récentes en premier.
		rsort( $files );
		$old = array_slice( $files, max( 1, $keep ) );
		foreach ( $old as $file ) {
			wp_delete_file( $dir . '/' . $file );
		}

		return count( $old );
	}
}
