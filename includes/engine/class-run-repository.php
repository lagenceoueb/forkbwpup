<?php
/**
 * Enregistrement des exécutions.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Lit et enregistre les exécutions dans leur table, et porte leur verrou.
 *
 * Le verrou tient dans deux colonnes, lock_owner et lock_until. Une seule
 * requête UPDATE conditionnelle le prend : deux processus qui arrivent en même
 * temps ne peuvent pas tous deux réussir, quelle que soit la charge.
 *
 * @since 0.1.0
 */
final class Run_Repository {

	/**
	 * Crée une exécution en attente.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $job_id  Tâche exécutée.
	 * @param string               $trigger Origine : manual, schedule, link ou cli.
	 * @param array<string, mixed> $state   État initial.
	 * @return Run Exécution enregistrée.
	 */
	public function create( string $job_id, string $trigger, array $state ): Run {
		global $wpdb;

		$run             = new Run();
		$run->job_id     = $job_id;
		$run->trigger    = $trigger;
		$run->status     = Run::QUEUED;
		$run->started_at = time();
		$run->updated_at = $run->started_at;
		$run->state      = $state;
		$run->log_token  = strtolower( wp_generate_password( 20, false, false ) );

		$wpdb->insert( Schema::table(), $this->to_row( $run ) );
		$run->id = (int) $wpdb->insert_id;

		return $run;
	}

	/**
	 * Renvoie une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Identifiant.
	 * @return Run|null Exécution, ou null si elle n'existe pas.
	 */
	public function find( int $id ): ?Run {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table(), $id ), ARRAY_A );

		return is_array( $row ) ? $this->from_row( $row ) : null;
	}

	/**
	 * Renvoie l'exécution en attente ou en cours, s'il y en a une.
	 *
	 * @since 0.1.0
	 *
	 * @return Run|null Exécution active la plus ancienne.
	 */
	public function active(): ?Run {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s, %s) ORDER BY id ASC LIMIT 1',
				Schema::table(),
				Run::QUEUED,
				Run::RUNNING
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->from_row( $row ) : null;
	}

	/**
	 * Renvoie les dernières exécutions, les plus récentes en premier.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $limit  Nombre maximal.
	 * @param int    $offset Nombre d'exécutions à sauter.
	 * @param string $job_id Tâche, ou chaîne vide pour toutes.
	 * @return Run[] Exécutions.
	 */
	public function latest( int $limit = 20, int $offset = 0, string $job_id = '' ): array {
		global $wpdb;

		$rows = '' === $job_id
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Schema::table(), $limit, $offset ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s ORDER BY id DESC LIMIT %d OFFSET %d', Schema::table(), $job_id, $limit, $offset ), ARRAY_A );

		return array_map( array( $this, 'from_row' ), (array) $rows );
	}

	/**
	 * Compte les exécutions.
	 *
	 * @since 0.1.0
	 *
	 * @return int Nombre d'exécutions.
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table() ) );
	}

	/**
	 * Enregistre l'état d'une exécution.
	 *
	 * Les colonnes du verrou ne sont pas touchées : seuls acquire() et
	 * release() les modifient.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 */
	public function save( Run $run ): void {
		global $wpdb;

		$run->updated_at = time();
		$wpdb->update( Schema::table(), $this->to_row( $run ), array( 'id' => $run->id ) );
	}

	/**
	 * Tente de prendre le verrou d'une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id    Exécution.
	 * @param string $owner Identifiant du processus.
	 * @param int    $ttl   Durée du verrou en secondes.
	 * @return bool Vrai si le verrou est pris.
	 */
	public function acquire( int $id, string $owner, int $ttl ): bool {
		global $wpdb;

		$now      = time();
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET lock_owner = %s, lock_until = %d WHERE id = %d AND (lock_until < %d OR lock_owner = %s)',
				Schema::table(),
				$owner,
				$now + $ttl,
				$id,
				$now,
				$owner
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Rend le verrou d'une exécution, s'il appartient au processus.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id    Exécution.
	 * @param string $owner Identifiant du processus.
	 */
	public function release( int $id, string $owner ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET lock_owner = '', lock_until = 0 WHERE id = %d AND lock_owner = %s",
				Schema::table(),
				$id,
				$owner
			)
		);
	}

	/**
	 * Oublie des archives supprimées par la rotation : leurs exécutions n'ont plus rien à télécharger.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $job_id Tâche.
	 * @param string[] $names  Noms des archives.
	 */
	public function forget_archives( string $job_id, array $names ): void {
		global $wpdb;

		foreach ( $names as $name ) {
			$wpdb->update(
				Schema::table(),
				array(
					'archive_file' => '',
					'archive_size' => 0,
				),
				array(
					'job_id'       => $job_id,
					'archive_file' => $name,
				)
			);
		}
	}

	/**
	 * Demande l'arrêt d'une exécution.
	 *
	 * La demande vit dans sa propre colonne : le processus en cours, qui
	 * enregistre sa copie de l'exécution, ne peut pas l'écraser.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Exécution.
	 */
	public function request_abort( int $id ): void {
		global $wpdb;

		$wpdb->update( Schema::table(), array( 'abort_requested' => 1 ), array( 'id' => $id ) );
	}

	/**
	 * Indique si l'arrêt d'une exécution est demandé, en relisant la base.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Exécution.
	 * @return bool Vrai si l'arrêt est demandé.
	 */
	public function abort_requested( int $id ): bool {
		global $wpdb;

		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT abort_requested FROM %i WHERE id = %d', Schema::table(), $id ) );
	}

	/**
	 * Supprime les exécutions terminées au-delà des plus récentes, avec leur journal.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $keep     Nombre d'exécutions gardées.
	 * @param string $logs_dir Dossier des journaux.
	 * @return int Nombre d'exécutions supprimées.
	 */
	public function prune( int $keep, string $logs_dir ): int {
		global $wpdb;

		$old = $this->latest( 100, max( 0, $keep ) );
		$old = array_filter( $old, static fn( Run $run ): bool => ! $run->is_active() );

		foreach ( $old as $run ) {
			wp_delete_file( Logger::path( $run, $logs_dir ) );
			$wpdb->delete( Schema::table(), array( 'id' => $run->id ) );
		}

		return count( $old );
	}

	/**
	 * Convertit une exécution en ligne de table.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 * @return array<string, mixed> Colonnes.
	 */
	private function to_row( Run $run ): array {
		return array(
			'job_id'         => $run->job_id,
			'status'         => $run->status,
			'trigger_type'   => $run->trigger,
			'started_at'     => $run->started_at,
			'updated_at'     => $run->updated_at,
			'finished_at'    => $run->finished_at,
			'step'           => $run->step,
			'progress'       => $run->progress,
			'state'          => (string) wp_json_encode( $run->state ),
			'archive_file'   => $run->archive_file,
			'archive_size'   => $run->archive_size,
			'warnings'       => $run->warnings,
			'errors'         => $run->errors,
			'log_token'      => $run->log_token,
			'continue_token' => $run->continue_token,
		);
	}

	/**
	 * Reconstruit une exécution depuis sa ligne.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row Colonnes.
	 * @return Run Exécution.
	 */
	public function from_row( array $row ): Run {
		$run                 = new Run();
		$run->id             = (int) $row['id'];
		$run->job_id         = (string) $row['job_id'];
		$run->status         = (string) $row['status'];
		$run->trigger        = (string) $row['trigger_type'];
		$run->started_at     = (int) $row['started_at'];
		$run->updated_at     = (int) $row['updated_at'];
		$run->finished_at    = (int) $row['finished_at'];
		$run->step           = (string) $row['step'];
		$run->progress       = (int) $row['progress'];
		$state               = json_decode( (string) $row['state'], true );
		$run->state          = is_array( $state ) ? $state : array();
		$run->archive_file   = (string) $row['archive_file'];
		$run->archive_size   = (int) $row['archive_size'];
		$run->warnings       = (int) $row['warnings'];
		$run->errors         = (int) $row['errors'];
		$run->log_token      = (string) $row['log_token'];
		$run->continue_token = (string) $row['continue_token'];

		return $run;
	}
}
