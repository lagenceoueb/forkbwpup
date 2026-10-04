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
use Oueb\WpBackup\Storage\Storage_Repository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Note où se trouve l'archive et applique la rotation dans chaque stockage.
 *
 * Le nom de l'archive porte le site, la tâche et la date :
 * exemple-fr_main_2026-10-03_020000.zip. La rotation ne touche que les
 * archives du même site et de la même tâche : plusieurs sites peuvent
 * partager un bucket ou un dossier.
 *
 * @since 0.1.0
 */
final class Finish implements Step {

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

	/**
	 * Crée l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param Storage_Repository $storages Stockages.
	 */
	public function __construct( Storage_Repository $storages ) {
		$this->storages = $storages;
	}

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
		return __( 'Deleting old backups', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 */
	public function run( Run_Context $context ): bool {
		$store  = (array) ( $context->run->state['steps']['store'] ?? array() );
		$name   = (string) ( $store['name'] ?? '' );
		$stored = array_values( (array) ( $store['stored'] ?? array() ) );

		$context->run->archive_file    = $name;
		$context->run->archive_size    = (int) ( $store['size'] ?? 0 );
		$context->run->state['stored'] = $stored;
		$context->run->state['forget'] = array();
		$prefix                        = self::prefix( $context->job->id );
		$deleted_everywhere            = null;

		foreach ( $stored as $id ) {
			$storage = $this->storages->instance( (string) $id );
			$record  = $this->storages->get( (string) $id );
			if ( null === $storage || null === $record ) {
				continue;
			}

			try {
				$old = self::outdated( array_column( $storage->files(), 'name' ), $prefix, $context->job->keep );
				foreach ( $old as $file ) {
					$storage->delete( $file );
				}
			} catch ( Throwable $error ) {
				$old = array();
				$context->logger->warning(
					sprintf(
						/* translators: 1: storage name, 2: error message. */
						__( 'Old backups were not deleted from %1$s: %2$s', 'oueb-wp-backup' ),
						$record['name'],
						$error->getMessage()
					)
				);
			}

			if ( array() !== $old ) {
				$context->logger->info(
					sprintf(
						/* translators: 1: number of backups, 2: storage name. */
						_n( '%1$d old backup deleted from %2$s.', '%1$d old backups deleted from %2$s.', count( $old ), 'oueb-wp-backup' ),
						count( $old ),
						$record['name']
					)
				);
			}

			$deleted_everywhere = null === $deleted_everywhere ? $old : array_values( array_intersect( $deleted_everywhere, $old ) );
		}

		// Ces archives n'existent plus nulle part : leur exécution n'a plus rien à télécharger.
		$context->run->state['forget'] = $deleted_everywhere ?? array();

		return true;
	}

	/**
	 * Renvoie le début du nom des archives d'une tâche sur ce site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id Tâche.
	 * @return string Préfixe, par exemple « exemple-fr_main_ ».
	 */
	public static function prefix( string $job_id ): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );

		return ( '' === $site ? 'site' : $site ) . '_' . $job_id . '_';
	}

	/**
	 * Choisit les archives à supprimer : celles qui dépassent le nombre gardé.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $names  Noms des fichiers du stockage.
	 * @param string   $prefix Préfixe des archives de la tâche.
	 * @param int      $keep   Nombre d'archives gardées, une au moins.
	 * @return string[] Noms à supprimer.
	 */
	public static function outdated( array $names, string $prefix, int $keep ): array {
		$pattern  = '/^' . preg_quote( $prefix, '/' ) . '\d{4}-\d{2}-\d{2}_\d{6}\.(zip|tar\.gz)(\.enc)?$/';
		$archives = array_values( preg_grep( $pattern, array_map( 'strval', $names ) ) );

		// Le nom contient la date : l'ordre alphabétique inverse met les plus récentes en premier.
		rsort( $archives );

		return array_slice( $archives, max( 1, $keep ) );
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
		return self::prefix( $job_id ) . gmdate( 'Y-m-d_His', $timestamp ) . '.' . $format;
	}
}
