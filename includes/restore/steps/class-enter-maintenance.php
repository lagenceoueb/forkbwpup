<?php
/**
 * Étape de mise en maintenance.
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
 * Met le site en maintenance juste avant de le modifier.
 *
 * À partir d'ici, la restauration ne s'arrête plus à la demande : un arrêt
 * laisserait un site à moitié restauré.
 *
 * @since 0.1.0
 */
final class Enter_Maintenance implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_maintenance';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Maintenance mode', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 */
	public function run( Run_Context $context ): bool {
		Maintenance::start( (string) Restore_State::get( $context, 'token_hash', '' ) );
		Restore_State::set( $context, 'committed', true );
		$context->checkpoint( true );
		$context->logger->info( __( 'The site is in maintenance mode until the end of the restore.', 'oueb-wp-backup' ) );

		return true;
	}
}
