<?php
/**
 * Planification des tâches.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Schedule;

use DateTimeZone;
use Oueb\WpBackup\Engine\Runner;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Remote\Cronjob_Org_Client;
use Oueb\WpBackup\Settings\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Programme les tâches dans WP-Cron ou chez cron-job.org, et les lance à l'heure dite.
 *
 * WP-Cron reçoit un événement unique par tâche, reprogrammé à chaque
 * passage : la prochaine date suit l'expression cron dans le fuseau du site.
 * Avec le lien de déclenchement ou cron-job.org, c'est un service extérieur
 * qui appelle le site.
 *
 * @since 0.1.0
 */
class Scheduler {

	/**
	 * Action WP-Cron d'une tâche planifiée.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const HOOK = 'oueb_wp_backup_scheduled';

	/**
	 * Tâches.
	 *
	 * @since 0.1.0
	 * @var Job_Repository
	 */
	private Job_Repository $jobs;

	/**
	 * Moteur.
	 *
	 * @since 0.1.0
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Crée le planificateur.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Repository $jobs   Tâches.
	 * @param Runner         $runner Moteur.
	 */
	public function __construct( Job_Repository $jobs, Runner $runner ) {
		$this->jobs   = $jobs;
		$this->runner = $runner;
	}

	/**
	 * Applique la planification d'une tâche à WP-Cron et à cron-job.org.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche, déjà enregistrée.
	 * @return string Message d'erreur de cron-job.org, vide si tout va bien.
	 */
	public function sync( Job $job ): string {
		wp_clear_scheduled_hook( self::HOOK, array( $job->id ) );
		if ( 'wpcron' === $job->trigger ) {
			$next = $this->next_run( $job );
			if ( null !== $next ) {
				wp_schedule_single_event( $next, self::HOOK, array( $job->id ) );
			}
		}

		return $this->sync_cronjob_org( $job );
	}

	/**
	 * Reprogramme les tâches WP-Cron dont l'événement a disparu.
	 *
	 * Un événement peut se perdre, par exemple quand une extension vide la
	 * liste de WP-Cron. Appelée à chaque passage de WP-Cron et en administration.
	 *
	 * @since 0.1.0
	 */
	public function ensure(): void {
		foreach ( $this->jobs->all() as $job ) {
			if ( 'wpcron' === $job->trigger && false === wp_next_scheduled( self::HOOK, array( $job->id ) ) ) {
				$next = $this->next_run( $job );
				if ( null !== $next ) {
					wp_schedule_single_event( $next, self::HOOK, array( $job->id ) );
				}
			}
		}
	}

	/**
	 * Lance une tâche planifiée, appelée par WP-Cron.
	 *
	 * La prochaine date est programmée avant le lancement : une exécution
	 * qui échoue ne casse pas la planification.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id Tâche.
	 */
	public function run( string $job_id ): void {
		$job = $this->jobs->get( $job_id );
		if ( null === $job || 'wpcron' !== $job->trigger ) {
			return;
		}

		$next = $this->next_run( $job, time() + 60 );
		if ( null !== $next ) {
			wp_schedule_single_event( $next, self::HOOK, array( $job->id ) );
		}

		$this->runner->start( $job, 'wpcron' );
	}

	/**
	 * Calcule la prochaine exécution d'une tâche planifiée.
	 *
	 * @since 0.1.0
	 *
	 * @param Job      $job   Tâche.
	 * @param int|null $after Instant de départ, maintenant par défaut.
	 * @return int|null Horodatage, ou null pour une tâche manuelle ou sans date possible.
	 */
	public function next_run( Job $job, ?int $after = null ): ?int {
		if ( 'manual' === $job->trigger ) {
			return null;
		}
		if ( 'wpcron' === $job->trigger && null === $after ) {
			$scheduled = wp_next_scheduled( self::HOOK, array( $job->id ) );
			if ( false !== $scheduled ) {
				return (int) $scheduled;
			}
		}

		try {
			return ( new Cron_Expression( $job->schedule ) )->next( $after ?? time(), wp_timezone() );
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/**
	 * Renvoie le lien de déclenchement d'une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 * @return string Adresse, clé comprise.
	 */
	public static function trigger_url( Job $job ): string {
		return add_query_arg( 'key', (string) Settings::get( 'trigger_key' ), rest_url( 'oueb-wp-backup/v1/trigger/' . $job->id ) );
	}

	/**
	 * Renvoie le fuseau du site sous un nom que cron-job.org comprend.
	 *
	 * @since 0.1.0
	 *
	 * @return string Fuseau IANA, ou Etc/GMT±N pour un décalage fixe.
	 */
	public static function iana_timezone(): string {
		$timezone = wp_timezone_string();
		if ( false !== strpos( $timezone, '/' ) || 'UTC' === $timezone ) {
			return $timezone;
		}

		$offset = (float) get_option( 'gmt_offset' );
		if ( floor( $offset ) !== $offset || 0.0 === $offset ) {
			return 'UTC';
		}

		// Les fuseaux Etc/GMT ont un signe inversé : Etc/GMT-2 vaut UTC+2.
		return sprintf( 'Etc/GMT%+d', -1 * (int) $offset );
	}

	/**
	 * Crée, met à jour ou supprime la tâche distante chez cron-job.org.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 * @return string Message d'erreur, vide si tout va bien.
	 */
	private function sync_cronjob_org( Job $job ): string {
		$key = (string) Settings::get( 'cronjob_org_key' );

		try {
			if ( 'cronjoborg' === $job->trigger ) {
				if ( '' === $key ) {
					return __( 'Enter your cron-job.org API key in the settings, then save the schedule again.', 'oueb-wp-backup' );
				}

				$remote = ( new Cronjob_Org_Client( $key ) )->save_job(
					$job->cronjob_org_id,
					wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) . ' : ' . $job->name,
					self::trigger_url( $job ),
					( new Cron_Expression( $job->schedule ) )->to_cronjob_org(),
					self::iana_timezone()
				);
			} elseif ( $job->cronjob_org_id > 0 && '' !== $key ) {
				( new Cronjob_Org_Client( $key ) )->delete_job( $job->cronjob_org_id );
				$remote = 0;
			} else {
				return '';
			}
		} catch ( Throwable $error ) {
			return wp_specialchars_decode( $error->getMessage(), ENT_QUOTES );
		}

		if ( $remote !== $job->cronjob_org_id ) {
			$job->cronjob_org_id = $remote;
			$this->jobs->save( $job );
		}

		return '';
	}
}
