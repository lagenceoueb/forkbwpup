<?php
/**
 * Enregistrement des tâches.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Job;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lit et enregistre les tâches dans l'option de réseau oueb_wp_backup_jobs.
 *
 * La tâche principale existe toujours : elle est créée à la première lecture
 * et ne peut pas être supprimée.
 *
 * @since 0.1.0
 */
final class Job_Repository {

	/**
	 * Nom de l'option en base.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'oueb_wp_backup_jobs';

	/**
	 * Renvoie toutes les tâches, la principale en premier.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Job> Tâches, par identifiant.
	 */
	public function all(): array {
		$stored = get_site_option( self::OPTION, array() );
		$jobs   = array( Job::MAIN => Job::main() );

		foreach ( is_array( $stored ) ? $stored : array() as $data ) {
			$job = is_array( $data ) ? Job::from_array( $data ) : null;
			if ( null !== $job ) {
				$jobs[ $job->id ] = $job;
			}
		}

		return $jobs;
	}

	/**
	 * Renvoie une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return Job|null Tâche, ou null si elle n'existe pas.
	 */
	public function get( string $id ): ?Job {
		$jobs = $this->all();

		return $jobs[ $id ] ?? null;
	}

	/**
	 * Enregistre une tâche, nouvelle ou modifiée.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 */
	public function save( Job $job ): void {
		$jobs             = $this->all();
		$jobs[ $job->id ] = $job;

		update_site_option(
			self::OPTION,
			array_values( array_map( static fn( Job $item ): array => $item->to_array(), $jobs ) )
		);
	}

	/**
	 * Crée une tâche supplémentaire.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data Champs de la tâche, dont « name ».
	 * @return Job|WP_Error Tâche créée, ou erreur de validation.
	 */
	public function create( array $data ) {
		$id  = 'job-' . strtolower( wp_generate_password( 8, false, false ) );
		$job = new Job( $id, $id );

		$result = $job->apply( $data + array( 'name' => '' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save( $job );

		return $job;
	}

	/**
	 * Supprime une tâche supplémentaire.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return true|WP_Error Vrai, ou erreur si la tâche est la principale ou n'existe pas.
	 */
	public function delete( string $id ) {
		if ( Job::MAIN === $id ) {
			return new WP_Error(
				'oueb_wp_backup_main_job',
				__( 'The main backup cannot be deleted.', 'oueb-wp-backup' ),
				array( 'status' => 400 )
			);
		}

		$jobs = $this->all();
		if ( ! isset( $jobs[ $id ] ) ) {
			return new WP_Error(
				'oueb_wp_backup_job_not_found',
				__( 'This backup job does not exist.', 'oueb-wp-backup' ),
				array( 'status' => 404 )
			);
		}

		unset( $jobs[ $id ] );
		update_site_option(
			self::OPTION,
			array_values( array_map( static fn( Job $item ): array => $item->to_array(), $jobs ) )
		);

		return true;
	}
}
