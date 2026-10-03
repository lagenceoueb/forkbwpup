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
	 * Prépare le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Repository $jobs Tâches.
	 */
	public function __construct( Job_Repository $jobs ) {
		$this->jobs      = $jobs;
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
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( Settings_Controller::class, 'check_permission' ),
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
		return rest_ensure_response( array_values( array_map( static fn( Job $job ): array => $job->to_array(), $this->jobs->all() ) ) );
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

		return null === $job ? self::not_found() : rest_ensure_response( $job->to_array() );
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

		$result = $job->apply( $changes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->jobs->save( $job );

		return rest_ensure_response( $job->to_array() );
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
