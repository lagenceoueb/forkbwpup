<?php
/**
 * Routes REST des tâches.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Schedule\Scheduler;
use Oueb\WpBackup\Storage\Storage_Repository;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Expose les tâches sur oueb-wp-backup/v1/jobs.
 *
 * @since 0.1.0
 */
final class Jobs_Controller extends WP_REST_Controller {

	/**
	 * Tâches.
	 *
	 * @since 0.1.0
	 * @var Job_Repository
	 */
	private Job_Repository $jobs;

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

	/**
	 * Planificateur.
	 *
	 * @since 0.1.0
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Prépare le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Repository     $jobs     Tâches.
	 * @param Storage_Repository $storages  Stockages.
	 * @param Scheduler          $scheduler Planificateur.
	 */
	public function __construct( Job_Repository $jobs, Storage_Repository $storages, Scheduler $scheduler ) {
		$this->jobs      = $jobs;
		$this->storages  = $storages;
		$this->scheduler = $scheduler;
		$this->namespace = Settings_Controller::REST_NAMESPACE;
		$this->rest_base = 'jobs';
	}

	/**
	 * Déclare les routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( Settings_Controller::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( Settings_Controller::class, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( Settings_Controller::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( Settings_Controller::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( Settings_Controller::class, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Renvoie toutes les tâches.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Tâches.
	 */
	public function get_items( $request ) {
		return rest_ensure_response( array_values( array_map( array( $this, 'prepare_job' ), $this->jobs->all() ) ) );
	}

	/**
	 * Renvoie une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Tâche, ou erreur 404.
	 */
	public function get_item( $request ) {
		$job = $this->jobs->get( (string) $request['id'] );

		return null === $job ? self::not_found() : rest_ensure_response( $this->prepare_job( $job ) );
	}

	/**
	 * Modifie une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête, corps JSON.
	 * @return WP_REST_Response|WP_Error Tâche modifiée, ou erreur.
	 */
	public function update_item( $request ) {
		$job = $this->jobs->get( (string) $request['id'] );
		if ( null === $job ) {
			return self::not_found();
		}

		$changes = $request->get_json_params();
		if ( ! is_array( $changes ) ) {
			return new WP_Error( 'oueb_wp_backup_invalid_body', __( 'The request body must be a JSON object.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
		}

		$missing = $this->missing_storage( $changes );
		if ( null !== $missing ) {
			return $missing;
		}

		$result = $job->apply( $changes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->jobs->save( $job );

		$sync_error         = $this->scheduler->sync( $job );
		$data               = $this->prepare_job( $job );
		$data['sync_error'] = $sync_error;

		return rest_ensure_response( $data );
	}

	/**
	 * Crée une tâche supplémentaire.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête, corps JSON : nom et réglages.
	 * @return WP_REST_Response|WP_Error Tâche créée (201), ou erreur.
	 */
	public function create_item( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'oueb_wp_backup_invalid_body', __( 'The request body must be a JSON object.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
		}

		$missing = $this->missing_storage( $data );
		if ( null !== $missing ) {
			return $missing;
		}

		$job = $this->jobs->create( $data );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$sync_error             = $this->scheduler->sync( $job );
		$response               = $this->prepare_job( $job );
		$response['sync_error'] = $sync_error;

		$response = rest_ensure_response( $response );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Supprime une tâche supplémentaire, avec sa planification.
	 *
	 * Les archives déjà faites restent dans leurs stockages et dans la liste.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Confirmation, ou erreur.
	 */
	public function delete_item( $request ) {
		$job = $this->jobs->get( (string) $request['id'] );
		if ( null === $job ) {
			return self::not_found();
		}
		if ( Job::MAIN === $job->id ) {
			return $this->jobs->delete( $job->id );
		}

		// Une tâche en déclenchement manuel n'a plus d'événement WP-Cron ni de tâche chez cron-job.org.
		$job->trigger = 'manual';
		$sync_error   = $this->scheduler->sync( $job );

		$result = $this->jobs->delete( $job->id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'deleted'    => true,
				'sync_error' => $sync_error,
			)
		);
	}

	/**
	 * Vérifie que les stockages demandés existent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data Réglages de la tâche.
	 * @return WP_Error|null Erreur 400, ou null.
	 */
	private function missing_storage( array $data ): ?WP_Error {
		foreach ( (array) ( $data['storages'] ?? array() ) as $id ) {
			if ( ! is_string( $id ) || null === $this->storages->get( $id ) ) {
				return new WP_Error(
					'oueb_wp_backup_storage_not_found',
					__( 'This storage does not exist.', 'oueb-wp-backup' ),
					array(
						'status' => 400,
						'field'  => 'storages',
					)
				);
			}
		}

		return null;
	}

	/**
	 * Prépare une tâche pour l'API : prochaine exécution et lien de déclenchement en plus.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 * @return array<string, mixed> Données.
	 */
	public function prepare_job( Job $job ): array {
		$data                = $job->to_array();
		$data['next_run']    = $this->next_run( $job );
		$data['trigger_url'] = Scheduler::trigger_url( $job );

		return $data;
	}

	/**
	 * Renvoie la prochaine exécution, au format ISO 8601.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 * @return string|null Date, ou null.
	 */
	private function next_run( Job $job ): ?string {
		$next = $this->scheduler->next_run( $job );

		return null === $next ? null : gmdate( 'c', $next );
	}

	/**
	 * Construit l'erreur d'une tâche introuvable.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error Erreur 404.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'oueb_wp_backup_job_not_found', __( 'This backup job does not exist.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
	}
}
