<?php
/**
 * Routes REST des exécutions.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Admin\Download;
use Oueb\WpBackup\Engine\Continuation;
use Oueb\WpBackup\Engine\Logger;
use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Engine\Run_Repository;
use Oueb\WpBackup\Engine\Runner;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Storage\Storage_Repository;
use Oueb\WpBackup\Storage\Workspace;
use Throwable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Expose les exécutions sur oueb-wp-backup/v1/runs.
 *
 * - GET /runs : historique, le plus récent d'abord ;
 * - POST /runs : lance la tâche principale, ou celle de job_id ;
 * - GET /runs/{id} : état et progression ;
 * - GET /runs/{id}/log : journal ;
 * - POST /runs/{id}/abort : demande l'arrêt ;
 * - POST /runs/{id}/continue : passage suivant, réservé au moteur (jeton à usage unique).
 *
 * @since 0.1.0
 */
final class Runs_Controller extends WP_REST_Controller {

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
	 * Moteur.
	 *
	 * @since 0.1.0
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Relance.
	 *
	 * @since 0.1.0
	 * @var Continuation
	 */
	private Continuation $continuation;

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

	/**
	 * Dossiers de travail.
	 *
	 * @since 0.1.0
	 * @var Workspace
	 */
	private Workspace $workspace;

	/**
	 * Prépare le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Repository     $runs         Exécutions.
	 * @param Job_Repository     $jobs         Tâches.
	 * @param Runner             $runner       Moteur.
	 * @param Continuation       $continuation Relance.
	 * @param Workspace          $workspace    Dossiers de travail.
	 * @param Storage_Repository $storages Stockages.
	 */
	public function __construct( Run_Repository $runs, Job_Repository $jobs, Runner $runner, Continuation $continuation, Workspace $workspace, Storage_Repository $storages ) {
		$this->runs         = $runs;
		$this->jobs         = $jobs;
		$this->runner       = $runner;
		$this->continuation = $continuation;
		$this->workspace    = $workspace;
		$this->storages     = $storages;
		$this->namespace    = Settings_Controller::REST_NAMESPACE;
		$this->rest_base    = 'runs';
	}

	/**
	 * Déclare les routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		$manage = array( Settings_Controller::class, 'check_permission' );
		$id     = '/' . $this->rest_base . '/(?P<id>\d+)';

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $manage,
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $manage,
					'args'                => array(
						'job_id' => array(
							'type'    => 'string',
							'default' => Job::MAIN,
							'pattern' => '^[a-z0-9-]+$',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$id,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$this->namespace,
			$id . '/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_log' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$this->namespace,
			$id . '/archive',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_archive' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$this->namespace,
			$id . '/abort',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'abort' ),
				'permission_callback' => $manage,
			)
		);

		// Appelée par le site lui-même, sans session : le jeton tient lieu d'autorisation.
		register_rest_route(
			$this->namespace,
			$id . '/continue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'continue_run' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Renvoie l'historique des exécutions.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Exécutions, avec le total dans l'en-tête X-WP-Total.
	 */
	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$page     = (int) $request['page'];
		$runs     = $this->runs->latest( $per_page, ( $page - 1 ) * $per_page );

		$response = rest_ensure_response( array_map( array( $this, 'prepare_run' ), $runs ) );
		$response->header( 'X-WP-Total', (string) $this->runs->count() );

		return $response;
	}

	/**
	 * Lance une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Exécution créée (202), ou erreur.
	 */
	public function create_item( $request ) {
		$job = $this->jobs->get( (string) $request['job_id'] );
		if ( null === $job ) {
			return new WP_Error( 'oueb_wp_backup_job_not_found', __( 'This backup job does not exist.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
		}

		$run = $this->runner->start( $job, 'manual' );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$response = rest_ensure_response( $this->prepare_run( $run ) );
		$response->set_status( 202 );

		return $response;
	}

	/**
	 * Renvoie une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Exécution, ou erreur 404.
	 */
	public function get_item( $request ) {
		$run = $this->runs->find( (int) $request['id'] );

		return null === $run ? self::not_found() : rest_ensure_response( $this->prepare_run( $run ) );
	}

	/**
	 * Renvoie le journal d'une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Entrées du journal, ou erreur 404.
	 */
	public function get_log( $request ) {
		$run = $this->runs->find( (int) $request['id'] );
		if ( null === $run ) {
			return self::not_found();
		}

		return rest_ensure_response( Logger::read( Logger::path( $run, $this->workspace->logs() ) ) );
	}

	/**
	 * Demande l'arrêt d'une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Exécution, ou erreur.
	 */
	public function abort( $request ) {
		$run = $this->runner->abort( (int) $request['id'] );

		return is_wp_error( $run ) ? $run : rest_ensure_response( $this->prepare_run( $run ) );
	}

	/**
	 * Donne un passage à une exécution, si le jeton est le bon.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Accusé de réception, ou erreur 403.
	 */
	public function continue_run( $request ) {
		$run = $this->runs->find( (int) $request['id'] );
		if ( null === $run || ! $this->continuation->consume( $run, (string) $request['token'] ) ) {
			return new WP_Error( 'oueb_wp_backup_invalid_token', __( 'Invalid or expired token.', 'oueb-wp-backup' ), array( 'status' => 403 ) );
		}

		$this->runner->process( $run->id );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Prépare une exécution pour l'API.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 * @return array<string, mixed> Exécution, avec l'adresse de téléchargement si l'archive est sur le serveur.
	 */
	public function prepare_run( Run $run ): array {
		$data = $run->to_public_array();

		$data['storage_names'] = array();
		foreach ( $data['stored'] as $storage_id ) {
			$record = $this->storages->get( (string) $storage_id );
			if ( null !== $record ) {
				$data['storage_names'][] = $record['name'];
			}
		}

		$file      = '' === $run->archive_file ? '' : $this->workspace->root() . '/archives/' . basename( $run->archive_file );
		$available = '' !== $file && ( is_file( $file ) || array() !== array_diff( $data['stored'], array( Storage_Repository::LOCAL ) ) );

		$data['download_url'] = $available && ! $run->is_active() ? Download::url( $run ) : null;

		return $data;
	}

	/**
	 * Supprime l'archive d'une exécution de tous ses stockages.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Exécution, ou erreur.
	 */
	public function delete_archive( $request ) {
		$run = $this->runs->find( (int) $request['id'] );
		if ( null === $run ) {
			return self::not_found();
		}
		if ( $run->is_active() || '' === $run->archive_file ) {
			return new WP_Error( 'oueb_wp_backup_no_archive', __( 'This backup has no archive to delete.', 'oueb-wp-backup' ), array( 'status' => 409 ) );
		}

		$name   = basename( $run->archive_file );
		$failed = array();
		$ids    = array_unique( array_merge( array( Storage_Repository::LOCAL ), (array) ( $run->state['stored'] ?? array() ) ) );
		foreach ( $ids as $storage_id ) {
			$storage = $this->storages->instance( (string) $storage_id );
			if ( null === $storage ) {
				continue;
			}
			try {
				$storage->delete( $name );
			} catch ( Throwable $error ) {
				$record   = $this->storages->get( (string) $storage_id );
				$failed[] = ( null === $record ? $storage_id : $record['name'] ) . ' (' . wp_specialchars_decode( $error->getMessage(), ENT_QUOTES ) . ')';
			}
		}

		if ( array() !== $failed ) {
			return new WP_Error(
				'oueb_wp_backup_delete_failed',
				sprintf(
					/* translators: %s: list of storages with their error. */
					__( 'The archive could not be deleted from: %s.', 'oueb-wp-backup' ),
					implode( ', ', $failed )
				),
				array( 'status' => 502 )
			);
		}

		$this->runs->forget_archives( $run->job_id, array( $name ) );

		return rest_ensure_response( $this->prepare_run( $this->runs->find( $run->id ) ) );
	}

	/**
	 * Construit l'erreur d'une exécution introuvable.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error Erreur 404.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'oueb_wp_backup_run_not_found', __( 'This backup does not exist.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
	}
}
