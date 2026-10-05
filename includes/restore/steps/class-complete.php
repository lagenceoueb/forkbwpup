<?php
/**
 * Dernière étape d'une restauration.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Restore\Maintenance;
use Oueb\WpBackup\Restore\Restore_State;

defined( 'ABSPATH' ) || exit;

/**
 * Sort de la maintenance et range ce qui a servi.
 *
 * Une archive envoyée depuis le navigateur est supprimée : elle n'a plus
 * d'usage une fois restaurée. Le moteur vide ensuite le dossier temporaire.
 *
 * @since 0.1.0
 */
final class Complete implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_complete';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Ending the restore', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 */
	public function run( Run_Context $context ): bool {
		Maintenance::end();

		$source = (array) Restore_State::get( $context, 'source', array() );
		if ( ! empty( $source['upload'] ) && is_file( (string) ( $source['path'] ?? '' ) ) ) {
			wp_delete_file( (string) $source['path'] );
		}

		wp_cache_flush();
		$context->logger->info( __( 'The site is out of maintenance mode.', 'oueb-wp-backup' ) );

		return true;
	}
}
