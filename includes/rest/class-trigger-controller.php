<?php
/**
 * Route REST du lien de déclenchement.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Engine\Runner;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Settings\Settings;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lance une tâche à l'appel du lien de déclenchement, sans session.
 *
 * La clé du lien tient lieu d'autorisation. Seules les tâches réglées pour
 * le lien ou pour cron-job.org répondent.
 *
 * @since 0.1.0
 */
final class Trigger_Controller extends WP_REST_Controller {

	/**
	 * Tâches.
	 *
	 * @since 0.1.0
	 * @var Job_Repository
	 */
	private Job_Repository $jobs;

	/**
	 * Moteur.
	 *
	 * @since 0.1.0
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Prépare le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Repository $jobs   Tâches.
	 * @param Runner         $runner Moteur.
	 */
	public function __construct( Job_Repository $jobs, Runner $runner ) {
		$this->jobs      = $jobs;
		$this->runner    = $runner;
		$this->namespace = Settings_Controller::REST_NAMESPACE;
		$this->rest_base = 'trigger';
	}

	/**
	 * Déclare la route.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		// Appelée par un service extérieur, sans session : la clé tient lieu d'autorisation.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9-]+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'trigger' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Lance la tâche si la clé est la bonne.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Exécution lancée, ou erreur 403 ou 409.
	 */
	public function trigger( $request ) {
		$key = (string) $request['key'];
		$job = $this->jobs->get( (string) $request['id'] );

		if ( ! hash_equals( (string) Settings::get( 'trigger_key' ), $key ) || null === $job || ! in_array( $job->trigger, array( 'link', 'cronjoborg' ), true ) ) {
			return new WP_Error( 'oueb_wp_backup_trigger_refused', __( 'Invalid key, or this backup is not started by a link.', 'oueb-wp-backup' ), array( 'status' => 403 ) );
		}

		$run = $this->runner->start( $job, 'cronjoborg' === $job->trigger ? 'cronjoborg' : 'link' );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$response = rest_ensure_response( array( 'run_id' => $run->id ) );
		$response->set_status( 202 );

		return $response;
	}
}
