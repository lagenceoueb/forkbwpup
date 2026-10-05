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
use Oueb\WpBackup\Security\Key_Ring;
use Oueb\WpBackup\Settings\Settings;
use Oueb\WpBackup\Storage\Storage_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Construit la liste ordonnée des étapes d'une tâche.
 *
 * @since 0.1.0
 */
class Step_Factory {

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

	/**
	 * Clés de chiffrement.
	 *
	 * @since 0.1.0
	 * @var Key_Ring
	 */
	private Key_Ring $keys;

	/**
	 * Crée la fabrique.
	 *
	 * @since 0.1.0
	 *
	 * @param Storage_Repository $storages Stockages.
	 * @param Key_Ring           $keys     Clés de chiffrement.
	 */
	public function __construct( Storage_Repository $storages, Key_Ring $keys ) {
		$this->storages = $storages;
		$this->keys     = $keys;
	}

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
		if ( $job->encrypt ) {
			$steps[] = new Encrypt( $this->keys );
		}
		$steps[] = new Store( $this->storages, (int) Settings::get( 'step_retries' ) );
		$steps[] = new Finish( $this->storages );

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
