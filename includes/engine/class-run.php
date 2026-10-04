<?php
/**
 * Exécution d'une tâche.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Une exécution de tâche : son état, sa progression, son résultat.
 *
 * L'état détaillé des étapes vit dans $state, enregistré en JSON. Il suffit
 * à reprendre l'exécution après un redémarrage.
 *
 * @since 0.1.0
 */
final class Run {

	/**
	 * En attente du premier passage.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const QUEUED = 'queued';

	/**
	 * En cours.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const RUNNING = 'running';

	/**
	 * Terminée sans avertissement.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SUCCESS = 'success';

	/**
	 * Terminée avec des avertissements : l'archive existe, mais il y manque quelque chose.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const WARNING = 'warning';

	/**
	 * Échouée : pas d'archive utilisable.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const FAILED = 'failed';

	/**
	 * Arrêtée à la demande d'un administrateur.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const ABORTED = 'aborted';

	/**
	 * Identifiant.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Tâche exécutée.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $job_id = '';

	/**
	 * État : une des constantes de la classe.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $status = self::QUEUED;

	/**
	 * Origine : manual, schedule, link ou cli.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $trigger = 'manual';

	/**
	 * Début, horodatage UTC.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $started_at = 0;

	/**
	 * Dernière activité, horodatage UTC.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $updated_at = 0;

	/**
	 * Fin, horodatage UTC, 0 tant qu'elle tourne.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $finished_at = 0;

	/**
	 * Étape en cours.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $step = '';

	/**
	 * Progression globale, de 0 à 100.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $progress = 0;

	/**
	 * État détaillé : plan des étapes, index courant, état propre à chaque étape.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	public array $state = array();

	/**
	 * Nom du fichier d'archive, dans le dossier des archives.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $archive_file = '';

	/**
	 * Taille de l'archive en octets.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $archive_size = 0;

	/**
	 * Nombre d'avertissements.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $warnings = 0;

	/**
	 * Nombre d'erreurs.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $errors = 0;

	/**
	 * Jeton qui rend le nom du fichier journal impossible à deviner.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $log_token = '';

	/**
	 * Empreinte SHA-256 du jeton de relance en attente, vide s'il n'y en a pas.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $continue_token = '';

	/**
	 * Indique si l'exécution n'est pas terminée.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai en attente ou en cours.
	 */
	public function is_active(): bool {
		return in_array( $this->status, array( self::QUEUED, self::RUNNING ), true );
	}

	/**
	 * Convertit l'exécution en tableau pour l'API, sans jeton ni état interne.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Exécution.
	 */
	public function to_public_array(): array {
		return array(
			'id'           => $this->id,
			'job_id'       => $this->job_id,
			'status'       => $this->status,
			'trigger'      => $this->trigger,
			'started_at'   => gmdate( 'c', $this->started_at ),
			'updated_at'   => gmdate( 'c', $this->updated_at ),
			'finished_at'  => $this->finished_at ? gmdate( 'c', $this->finished_at ) : null,
			'step'         => $this->step,
			'progress'     => $this->progress,
			'archive_file' => $this->archive_file,
			'archive_size' => $this->archive_size,
			'warnings'     => $this->warnings,
			'errors'       => $this->errors,
			'contents'     => $this->state['contents'] ?? array(),
		);
	}
}
