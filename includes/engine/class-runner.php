<?php
/**
 * Moteur d'exécution des sauvegardes.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

use Oueb\WpBackup\Engine\Steps\Step_Factory;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Settings\Settings;
use Oueb\WpBackup\Storage\Workspace;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lance une exécution, puis la fait avancer passage après passage.
 *
 * Chaque passage prend le verrou de l'exécution, enchaîne les étapes jusqu'à
 * la limite de temps, enregistre l'état, rend le verrou, puis demande un
 * nouveau passage. Une étape qui échoue est retentée au passage suivant, dans
 * la limite du réglage step_retries.
 *
 * @since 0.1.0
 */
final class Runner {

	/**
	 * Exécutions.
	 *
	 * @since 0.1.0
	 * @var Run_Repository
	 */
	private Run_Repository $runs;

	/**
	 * Tâches.
	 *
	 * @since 0.1.0
	 * @var Job_Repository
	 */
	private Job_Repository $jobs;

	/**
	 * Dossiers de travail.
	 *
	 * @since 0.1.0
	 * @var Workspace
	 */
	private Workspace $workspace;

	/**
	 * Fabrique des étapes.
	 *
	 * @since 0.1.0
	 * @var Step_Factory
	 */
	private Step_Factory $steps;

	/**
	 * Relance des passages.
	 *
	 * @since 0.1.0
	 * @var Continuation
	 */
	private Continuation $continuation;

	/**
	 * Construit le moteur.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Repository $runs         Exécutions.
	 * @param Job_Repository $jobs         Tâches.
	 * @param Workspace      $workspace    Dossiers de travail.
	 * @param Step_Factory   $steps        Fabrique des étapes.
	 * @param Continuation   $continuation Relance des passages.
	 */
	public function __construct( Run_Repository $runs, Job_Repository $jobs, Workspace $workspace, Step_Factory $steps, Continuation $continuation ) {
		$this->runs         = $runs;
		$this->jobs         = $jobs;
		$this->workspace    = $workspace;
		$this->steps        = $steps;
		$this->continuation = $continuation;
	}

	/**
	 * Lance une exécution de tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param Job    $job     Tâche.
	 * @param string $trigger Origine : manual, schedule, link ou cli.
	 * @return Run|WP_Error Exécution créée, ou erreur 409 si une autre tourne.
	 */
	public function start( Job $job, string $trigger ) {
		$active = $this->runs->active();
		if ( null !== $active ) {
			return new WP_Error(
				'oueb_wp_backup_run_in_progress',
				__( 'A backup is already running. Wait until it ends, or stop it.', 'oueb-wp-backup' ),
				array(
					'status' => 409,
					'run_id' => $active->id,
				)
			);
		}

		$plan = array_keys( $this->steps->for_job( $job ) );
		$run  = $this->runs->create(
			$job->id,
			$trigger,
			array(
				'plan'     => $plan,
				'index'    => 0,
				'attempts' => 0,
				'contents' => self::contents( $job ),
				'steps'    => array(),
			)
		);

		$this->logger( $run )->info(
			sprintf(
				/* translators: 1: job name, 2: trigger, such as manual. */
				__( 'Backup “%1$s” started (%2$s).', 'oueb-wp-backup' ),
				$job->name,
				$trigger
			)
		);

		Watchdog::schedule();
		$this->continuation->spawn( $run );

		return $run;
	}

	/**
	 * Fait avancer une exécution pendant un passage.
	 *
	 * Sans effet si l'exécution est terminée ou si un autre processus tient
	 * son verrou.
	 *
	 * @since 0.1.0
	 *
	 * @param int $run_id Exécution.
	 * @return bool Vrai si un passage a eu lieu.
	 */
	public function process( int $run_id ): bool {
		$run = $this->runs->find( $run_id );
		if ( null === $run || ! $run->is_active() ) {
			return false;
		}

		$max   = (int) Settings::get( 'max_execution_time' );
		$owner = wp_generate_password( 16, false, false );
		if ( ! $this->runs->acquire( $run->id, $owner, $max + 60 ) ) {
			return false;
		}

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $max + 30 );
		}
		// Une partie d'envoi S3 tient en mémoire : 8 Mo au moins.
		wp_raise_memory_limit( 'admin' );

		$again = false;
		try {
			$again = $this->advance( $run, $max, $owner );
		} finally {
			$this->runs->release( $run->id, $owner );
		}

		if ( $again ) {
			$this->continuation->spawn( $run );
		}

		return true;
	}

	/**
	 * Demande l'arrêt d'une exécution.
	 *
	 * L'exécution s'arrête à la prochaine vérification d'une étape, ou tout
	 * de suite si aucun passage n'est en cours.
	 *
	 * @since 0.1.0
	 *
	 * @param int $run_id Exécution.
	 * @return Run|WP_Error Exécution, ou erreur 404 ou 409.
	 */
	public function abort( int $run_id ) {
		$run = $this->runs->find( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'oueb_wp_backup_run_not_found', __( 'This backup does not exist.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
		}
		if ( ! $run->is_active() ) {
			return new WP_Error( 'oueb_wp_backup_run_finished', __( 'This backup is already finished.', 'oueb-wp-backup' ), array( 'status' => 409 ) );
		}

		$this->runs->request_abort( $run->id );

		// Personne ne tient le verrou : l'arrêt est immédiat.
		$owner = wp_generate_password( 16, false, false );
		if ( $this->runs->acquire( $run->id, $owner, 60 ) ) {
			$run = $this->runs->find( $run->id );
			$this->stop( $run, $this->logger( $run ) );
			$this->runs->release( $run->id, $owner );
		}

		return $this->runs->find( $run->id );
	}

	/**
	 * Enchaîne les étapes jusqu'à la fin ou jusqu'à la limite de temps.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run   Exécution, verrou pris.
	 * @param int    $max   Durée maximale du passage, en secondes.
	 * @param string $owner Détenteur du verrou.
	 * @return bool Vrai s'il faut un autre passage.
	 */
	private function advance( Run $run, int $max, string $owner ): bool {
		$logger = $this->logger( $run );
		$job    = $this->jobs->get( $run->job_id );
		if ( null === $job ) {
			$this->fail( $run, $logger, __( 'The backup job was deleted while it was running.', 'oueb-wp-backup' ) );
			return false;
		}

		$steps   = $this->steps->for_job( $job );
		$plan    = (array) ( $run->state['plan'] ?? array() );
		$context = new Run_Context( $run, $job, $this->workspace, $logger, new Deadline( max( 5, $max - 3 ) ) );
		$context->watch_abort( array( $this->runs, 'abort_requested' ) );
		$context->save_with( array( $this->runs, 'save' ) );
		$context->keep_alive_with(
			function ( Run $current ) use ( $owner, $max ): void {
				$this->runs->acquire( $current->id, $owner, $max + 60 );
				$this->runs->save( $current );
				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( $max + 30 );
				}
			}
		);

		$run->status = Run::RUNNING;
		$this->runs->save( $run );

		$total = count( $plan );
		while ( (int) $run->state['index'] < $total ) {
			if ( $context->abort_requested( true ) ) {
				$this->stop( $run, $logger );
				return false;
			}

			$step_id = (string) $plan[ (int) $run->state['index'] ];
			if ( ! isset( $steps[ $step_id ] ) ) {
				$this->fail( $run, $logger, __( 'The backup plan changed while it was running. Start the backup again.', 'oueb-wp-backup' ) );
				return false;
			}

			$step      = $steps[ $step_id ];
			$run->step = $step_id;
			$context->for_step( $step_id );

			try {
				$done = $step->run( $context );
			} catch ( Step_Failure $error ) {
				$this->fail( $run, $logger, $error->getMessage() );
				return false;
			} catch ( Throwable $error ) {
				return $this->retry_or_fail( $run, $logger, $step, $error );
			}

			if ( $context->abort_requested( true ) ) {
				$this->stop( $run, $logger );
				return false;
			}

			if ( ! $done ) {
				$this->update_progress( $run, $plan );
				$this->runs->save( $run );
				return true;
			}

			$run->state['index']    = (int) $run->state['index'] + 1;
			$run->state['attempts'] = 0;
			$this->update_progress( $run, $plan );
			$this->runs->save( $run );

			if ( $context->deadline->reached() && (int) $run->state['index'] < count( $plan ) ) {
				return true;
			}
		}

		$this->complete( $run, $logger );

		return false;
	}

	/**
	 * Retente une étape échouée au prochain passage, ou fait échouer l'exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param Run       $run    Exécution.
	 * @param Logger    $logger Journal.
	 * @param Step      $step   Étape en échec.
	 * @param Throwable $error  Erreur.
	 * @return bool Vrai s'il faut un autre passage.
	 */
	private function retry_or_fail( Run $run, Logger $logger, Step $step, Throwable $error ): bool {
		$attempts               = (int) ( $run->state['attempts'] ?? 0 ) + 1;
		$run->state['attempts'] = $attempts;
		$retries                = (int) Settings::get( 'step_retries' );

		$logger->error(
			sprintf(
				/* translators: 1: step label, 2: attempt number, 3: maximum attempts, 4: error message. */
				__( '%1$s failed (attempt %2$d of %3$d): %4$s', 'oueb-wp-backup' ),
				$step->label(),
				$attempts,
				$retries,
				$error->getMessage()
			)
		);

		if ( $attempts >= $retries ) {
			$this->fail(
				$run,
				$logger,
				/* translators: %s: step label. */
				sprintf( __( 'The backup stopped: %s kept failing.', 'oueb-wp-backup' ), $step->label() )
			);
			return false;
		}

		$this->runs->save( $run );

		return true;
	}

	/**
	 * Termine une exécution réussie.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run    Exécution.
	 * @param Logger $logger Journal.
	 */
	private function complete( Run $run, Logger $logger ): void {
		$run->status = $run->warnings > 0 || $run->errors > 0 ? Run::WARNING : Run::SUCCESS;
		$logger->info(
			Run::SUCCESS === $run->status
				? __( 'Backup finished.', 'oueb-wp-backup' )
				/* translators: %d: number of warnings. */
				: sprintf( _n( 'Backup finished with %d warning.', 'Backup finished with %d warnings.', $run->warnings, 'oueb-wp-backup' ), $run->warnings )
		);
		$this->finish( $run );
	}

	/**
	 * Fait échouer une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run     Exécution.
	 * @param Logger $logger  Journal.
	 * @param string $message Cause, pour le journal.
	 */
	private function fail( Run $run, Logger $logger, string $message ): void {
		$logger->error( $message );
		$run->status = Run::FAILED;
		$this->finish( $run );
	}

	/**
	 * Arrête une exécution à la demande d'un administrateur.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run    Exécution.
	 * @param Logger $logger Journal.
	 */
	private function stop( Run $run, Logger $logger ): void {
		$logger->warning( __( 'Backup stopped by an administrator.', 'oueb-wp-backup' ) );
		$run->status = Run::ABORTED;
		$this->finish( $run );
	}

	/**
	 * Clôt une exécution : date de fin, nettoyage, historique borné.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 */
	private function finish( Run $run ): void {
		$run->finished_at    = time();
		$run->step           = '';
		$run->continue_token = '';
		if ( Run::SUCCESS === $run->status || Run::WARNING === $run->status ) {
			$run->progress = 100;
		}
		$this->runs->save( $run );

		$forget = array_map( 'strval', (array) ( $run->state['forget'] ?? array() ) );
		if ( array() !== $forget ) {
			$this->runs->forget_archives( $run->job_id, $forget );
		}

		$this->workspace->clean_tmp( $run->id );
		$this->runs->prune( (int) Settings::get( 'max_logs' ), $this->workspace->logs() );
	}

	/**
	 * Calcule la progression globale.
	 *
	 * @since 0.1.0
	 *
	 * @param Run      $run  Exécution.
	 * @param string[] $plan Étapes prévues.
	 */
	private function update_progress( Run $run, array $plan ): void {
		$total = max( 1, count( $plan ) );
		$index = (int) $run->state['index'];
		$part  = 0.0;
		if ( $index < $total ) {
			$part = (float) ( $run->state['steps'][ $plan[ $index ] ]['_progress'] ?? 0.0 );
		}

		$run->progress = (int) min( 99, floor( ( $index + $part ) / $total * 100 ) );
	}

	/**
	 * Renvoie le journal d'une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 * @return Logger Journal.
	 */
	public function logger( Run $run ): Logger {
		return new Logger( $run, $this->workspace->logs() );
	}

	/**
	 * Résume le contenu d'une tâche, pour l'historique.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 * @return array<string, bool> Contenu sauvegardé.
	 */
	private static function contents( Job $job ): array {
		return array(
			'database'      => $job->include_database,
			'uploads'       => $job->include_uploads,
			'themes'        => $job->include_themes,
			'plugins'       => $job->include_plugins,
			'other_content' => $job->include_other_content,
			'core'          => $job->include_core,
		);
	}
}
