<?php
/**
 * Plan des étapes d'une tâche.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Construit la liste ordonnée des étapes d'une tâche.
 *
 * @since 0.1.0
 */
class Step_Factory {

	/**
	 * Renvoie les étapes d'une tâche, dans l'ordre d'exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 * @return array<string, Step> Étapes, par identifiant.
	 */
	public function for_job( Job $job ): array {
		$steps = array();
		if ( $job->include_database ) {
			$steps[] = new Database_Dump();
		}
		if ( $job->includes_files() ) {
			$steps[] = new File_List();
		}
		$steps[] = new Manifest();
		$steps[] = new Archive();
		$steps[] = new Finish();

		/**
		 * Filtre les étapes d'une tâche.
		 *
		 * @since 0.1.0
		 *
		 * @param Step[] $steps Étapes, dans l'ordre.
		 * @param Job    $job   Tâche.
		 */
		$steps = (array) apply_filters( 'oueb_wp_backup_steps', $steps, $job );

		$plan = array();
		foreach ( $steps as $step ) {
			if ( $step instanceof Step ) {
				$plan[ $step->id() ] = $step;
			}
		}

		return $plan;
	}
}
