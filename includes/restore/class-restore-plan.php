<?php
/**
 * Plan des étapes d'une restauration.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore;

use Oueb\WpBackup\Engine\Run_Repository;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Engine\Steps\Archive;
use Oueb\WpBackup\Engine\Steps\Database_Dump;
use Oueb\WpBackup\Engine\Steps\File_List;
use Oueb\WpBackup\Engine\Steps\Manifest;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Restore\Steps\Check;
use Oueb\WpBackup\Restore\Steps\Complete;
use Oueb\WpBackup\Restore\Steps\Decrypt;
use Oueb\WpBackup\Restore\Steps\Enter_Maintenance;
use Oueb\WpBackup\Restore\Steps\Fetch;
use Oueb\WpBackup\Restore\Steps\Import_Database;
use Oueb\WpBackup\Restore\Steps\Keep_Safety_Backup;
use Oueb\WpBackup\Restore\Steps\Restore_Files;
use Oueb\WpBackup\Security\Key_Ring;
use Oueb\WpBackup\Storage\Storage_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Construit la liste ordonnée des étapes d'une restauration.
 *
 * L'ordre protège le site : l'archive est récupérée, déchiffrée et vérifiée,
 * puis l'état actuel est sauvegardé, avant toute modification. La
 * maintenance ne commence qu'ensuite.
 *
 * La sauvegarde préalable reprend les étapes d'une sauvegarde ordinaire, avec
 * une tâche décrite dans l'état de l'exécution.
 *
 * @since 0.1.0
 */
class Restore_Plan {

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
	 * Exécutions.
	 *
	 * @since 0.1.0
	 * @var Run_Repository
	 */
	private Run_Repository $runs;

	/**
	 * Crée la fabrique.
	 *
	 * @since 0.1.0
	 *
	 * @param Storage_Repository $storages Stockages.
	 * @param Key_Ring           $keys     Clés de chiffrement.
	 * @param Run_Repository     $runs     Exécutions.
	 */
	public function __construct( Storage_Repository $storages, Key_Ring $keys, Run_Repository $runs ) {
		$this->storages = $storages;
		$this->keys     = $keys;
		$this->runs     = $runs;
	}

	/**
	 * Renvoie les étapes d'une restauration, dans l'ordre d'exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $options Réglages : source, database, files, safety.
	 * @return array<string, Step> Étapes, par identifiant.
	 */
	public function steps( array $options ): array {
		$name  = (string) ( $options['source']['name'] ?? '' );
		$steps = array( new Fetch( $this->storages ) );
		if ( '.enc' === strtolower( substr( $name, -4 ) ) ) {
			$steps[] = new Decrypt( $this->keys );
		}
		$steps[] = new Check();

		if ( ! empty( $options['safety'] ) ) {
			if ( ! empty( $options['database'] ) ) {
				$steps[] = new Database_Dump();
			}
			if ( ! empty( $options['files'] ) ) {
				$steps[] = new File_List();
			}
			$steps[] = new Manifest();
			$steps[] = new Archive();
			$steps[] = new Keep_Safety_Backup( $this->runs );
		}

		$steps[] = new Enter_Maintenance();
		if ( ! empty( $options['database'] ) ) {
			$steps[] = new Import_Database();
		}
		if ( ! empty( $options['files'] ) ) {
			$steps[] = new Restore_Files();
		}
		$steps[] = new Complete();

		$plan = array();
		foreach ( $steps as $step ) {
			$plan[ $step->id() ] = $step;
		}

		return $plan;
	}

	/**
	 * Construit la tâche de la sauvegarde préalable.
	 *
	 * Elle part de la tâche principale, pour le format et les exclusions,
	 * n'utilise que le dossier local et ne chiffre pas : l'archive reste sur
	 * ce serveur. L'étape de vérification ajuste ensuite son contenu.
	 *
	 * @since 0.1.0
	 *
	 * @param Job                  $main    Tâche principale.
	 * @param array<string, mixed> $options Réglages de la restauration.
	 * @return Job Tâche.
	 */
	public static function safety_job( Job $main, array $options ): Job {
		$job                   = new Job( Steps\Keep_Safety_Backup::JOB_ID, __( 'Before restore', 'oueb-wp-backup' ) );
		$job->archive_format   = $main->archive_format;
		$job->exclude          = $main->exclude;
		$job->include_database = ! empty( $options['database'] );
		$job->storages         = array( Storage_Repository::LOCAL );
		$job->encrypt          = false;
		$job->keep             = Steps\Keep_Safety_Backup::KEEP;

		return $job;
	}
}
