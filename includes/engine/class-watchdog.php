<?php
/**
 * Surveillance des exécutions bloquées.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Reprend une exécution restée sans activité.
 *
 * Tant qu'une exécution est active, un événement WP-Cron revient toutes les
 * deux minutes. S'il trouve une exécution sans activité depuis 90 secondes et
 * sans verrou, il lui donne un passage lui-même.
 *
 * @since 0.1.0
 */
final class Watchdog {

	/**
	 * Événement WP-Cron.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const HOOK = 'oueb_wp_backup_watchdog';

	/**
	 * Délai sans activité au-delà duquel une exécution est reprise, en secondes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const STALE_AFTER = 90;

	/**
	 * Programme le prochain passage de surveillance, s'il ne l'est pas déjà.
	 *
	 * @since 0.1.0
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 120, self::HOOK );
		}
	}

	/**
	 * Indique si une exécution semble bloquée.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 * @param int $now Horodatage courant.
	 * @return bool Vrai si elle est active et sans activité depuis STALE_AFTER secondes.
	 */
	public static function is_stale( Run $run, int $now ): bool {
		return $run->is_active() && $now - $run->updated_at >= self::STALE_AFTER;
	}

	/**
	 * Reprend l'exécution active si elle est bloquée, puis se reprogramme.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Repository $runs   Exécutions.
	 * @param Runner         $runner Moteur.
	 */
	public static function check( Run_Repository $runs, Runner $runner ): void {
		$run = $runs->active();
		if ( null === $run ) {
			return;
		}

		if ( self::is_stale( $run, time() ) ) {
			$runner->logger( $run )->info( __( 'The backup was idle: the watchdog resumes it.', 'oueb-wp-backup' ) );
			$runner->process( $run->id );
		}

		$run = $runs->find( $run->id );
		if ( null !== $run && $run->is_active() ) {
			self::schedule();
		}
	}
}
