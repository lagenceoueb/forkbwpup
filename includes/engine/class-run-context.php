<?php
/**
 * Contexte passé aux étapes.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Storage\Workspace;

defined( 'ABSPATH' ) || exit;

/**
 * Donne à une étape ce dont elle a besoin : la tâche, l'exécution, ses
 * dossiers, le journal, la limite de temps et son propre état.
 *
 * @since 0.1.0
 */
final class Run_Context {

	/**
	 * Exécution.
	 *
	 * @since 0.1.0
	 * @var Run
	 */
	public Run $run;

	/**
	 * Tâche exécutée.
	 *
	 * @since 0.1.0
	 * @var Job
	 */
	public Job $job;

	/**
	 * Dossiers de travail.
	 *
	 * @since 0.1.0
	 * @var Workspace
	 */
	public Workspace $workspace;

	/**
	 * Journal.
	 *
	 * @since 0.1.0
	 * @var Logger
	 */
	public Logger $logger;

	/**
	 * Limite de temps du passage.
	 *
	 * @since 0.1.0
	 * @var Deadline
	 */
	public Deadline $deadline;

	/**
	 * Étape en cours.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $step_id = '';

	/**
	 * Vérification de la demande d'arrêt.
	 *
	 * @since 0.1.0
	 * @var callable|null
	 */
	private $abort_check = null;

	/**
	 * Dernière lecture de la demande d'arrêt (microtime).
	 *
	 * @since 0.1.0
	 * @var float
	 */
	private float $abort_checked_at = 0.0;

	/**
	 * Enregistrement de l'exécution, pour les points de sauvegarde.
	 *
	 * @since 0.1.0
	 * @var callable|null
	 */
	private $saver = null;

	/**
	 * Dernier point de sauvegarde (microtime).
	 *
	 * @since 0.1.0
	 * @var float
	 */
	private float $saved_at = 0.0;

	/**
	 * Demande d'arrêt déjà constatée.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $abort_seen = false;

	/**
	 * Construit le contexte.
	 *
	 * @since 0.1.0
	 *
	 * @param Run       $run       Exécution.
	 * @param Job       $job       Tâche.
	 * @param Workspace $workspace Dossiers de travail.
	 * @param Logger    $logger    Journal.
	 * @param Deadline  $deadline  Limite de temps.
	 */
	public function __construct( Run $run, Job $job, Workspace $workspace, Logger $logger, Deadline $deadline ) {
		$this->run       = $run;
		$this->job       = $job;
		$this->workspace = $workspace;
		$this->logger    = $logger;
		$this->deadline  = $deadline;
	}

	/**
	 * Désigne l'étape dont get() et set() lisent l'état.
	 *
	 * @since 0.1.0
	 *
	 * @param string $step_id Identifiant de l'étape.
	 */
	public function for_step( string $step_id ): void {
		$this->step_id = $step_id;
	}

	/**
	 * Lit une valeur de l'état de l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key      Clé.
	 * @param mixed  $fallback Valeur si la clé est absente.
	 * @return mixed Valeur.
	 */
	public function get( string $key, $fallback = null ) {
		return $this->run->state['steps'][ $this->step_id ][ $key ] ?? $fallback;
	}

	/**
	 * Écrit une valeur dans l'état de l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   Clé.
	 * @param mixed  $value Valeur, sérialisable en JSON.
	 */
	public function set( string $key, $value ): void {
		$this->run->state['steps'][ $this->step_id ][ $key ] = $value;
	}

	/**
	 * Indique l'avancement de l'étape, pour la barre de progression.
	 *
	 * @since 0.1.0
	 *
	 * @param float $fraction Part faite, de 0 à 1.
	 */
	public function progress( float $fraction ): void {
		$this->set( '_progress', max( 0.0, min( 1.0, $fraction ) ) );
	}

	/**
	 * Renvoie le dossier temporaire de l'exécution.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	public function tmp(): string {
		return $this->workspace->tmp( $this->run->id );
	}

	/**
	 * Indique si un administrateur a demandé l'arrêt.
	 *
	 * Au milieu d'une étape, la base n'est relue que toutes les cinq secondes
	 * au plus. Entre deux étapes, le moteur demande une lecture immédiate.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $fresh Vrai pour relire la base sans attendre.
	 * @return bool Vrai si l'arrêt est demandé.
	 */
	public function abort_requested( bool $fresh = false ): bool {
		if ( null === $this->abort_check ) {
			return false;
		}

		$now = microtime( true );
		if ( ! $this->abort_seen && ( $fresh || $now - $this->abort_checked_at >= 5.0 ) ) {
			$this->abort_checked_at = $now;
			$this->abort_seen       = (bool) call_user_func( $this->abort_check, $this->run->id );
		}

		return $this->abort_seen;
	}

	/**
	 * Indique si l'étape doit rendre la main : temps écoulé ou arrêt demandé.
	 *
	 * L'étape valide son lot en cours puis renvoie faux. Le moteur arrête
	 * l'exécution ou la reprend au passage suivant.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai s'il faut rendre la main.
	 */
	public function should_pause(): bool {
		return $this->deadline->reached() || $this->abort_requested();
	}

	/**
	 * Fournit l'enregistrement de l'exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $saver Fonction qui reçoit l'exécution et l'enregistre.
	 */
	public function save_with( callable $saver ): void {
		$this->saver    = $saver;
		$this->saved_at = microtime( true );
	}

	/**
	 * Enregistre l'exécution au milieu d'une étape, toutes les deux secondes au plus.
	 *
	 * Une étape l'appelle juste après avoir validé un lot, quand son état et
	 * ses fichiers sont cohérents. Si l'hébergeur coupe PHP plus tôt que prévu,
	 * le travail déjà fait n'est pas perdu.
	 *
	 * @since 0.1.0
	 */
	public function checkpoint(): void {
		$now = microtime( true );
		if ( null === $this->saver || $now - $this->saved_at < 2.0 ) {
			return;
		}

		$this->saved_at = $now;
		call_user_func( $this->saver, $this->run );
	}

	/**
	 * Fournit la vérification de la demande d'arrêt.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $check Fonction qui reçoit l'identifiant de l'exécution et renvoie vrai si l'arrêt est demandé.
	 */
	public function watch_abort( callable $check ): void {
		$this->abort_check      = $check;
		$this->abort_checked_at = 0.0;
	}
}
