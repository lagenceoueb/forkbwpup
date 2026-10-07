<?php
/**
 * Routes REST de la restauration.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Engine\Run_Repository;
use Oueb\WpBackup\Engine\Runner;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Restore\Extractor;
use Oueb\WpBackup\Restore\Maintenance;
use Oueb\WpBackup\Restore\Restore_Plan;
use Oueb\WpBackup\Restore\Upload_Repository;
use Oueb\WpBackup\Security\Archive_Cipher;
use Oueb\WpBackup\Plugin;
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
 * Lance une restauration et reçoit les archives envoyées depuis le navigateur.
 *
 * La progression se suit ensuite avec les routes des exécutions.
 *
 * @since 0.1.0
 */
final class Restore_Controller extends WP_REST_Controller {

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
	 * Archives envoyées.
	 *
	 * @since 0.1.0
	 * @var Upload_Repository
	 */
	private Upload_Repository $uploads;

	/**
	 * Crée le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Repository     $runs      Exécutions.
	 * @param Job_Repository     $jobs      Tâches.
	 * @param Runner             $runner    Moteur.
	 * @param Storage_Repository $storages  Stockages.
	 * @param Workspace          $workspace Dossiers de travail.
	 * @param Upload_Repository  $uploads   Archives envoyées.
	 */
	public function __construct( Run_Repository $runs, Job_Repository $jobs, Runner $runner, Storage_Repository $storages, Workspace $workspace, Upload_Repository $uploads ) {
		$this->runs      = $runs;
		$this->jobs      = $jobs;
		$this->runner    = $runner;
		$this->storages  = $storages;
		$this->workspace = $workspace;
		$this->uploads   = $uploads;
		$this->namespace = Settings_Controller::REST_NAMESPACE;
		$this->rest_base = 'restore';
	}

	/**
	 * Déclare les routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		$manage = array( Settings_Controller::class, 'check_permission' );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => $manage,
				'args'                => array(
					'source'   => array(
						'type'     => 'object',
						'required' => true,
					),
					'database' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'files'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'safety'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/uploads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_upload' ),
				'permission_callback' => $manage,
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
					'size' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/uploads/(?P<id>[a-z0-9]{24})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_upload' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'append_upload' ),
					'permission_callback' => $manage,
					'args'                => array(
						'offset' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 0,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_upload' ),
					'permission_callback' => $manage,
				),
			)
		);
	}

	/**
	 * Lance une restauration.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Exécution créée (202), ou erreur.
	 */
	public function create_item( $request ) {
		$database = (bool) $request['database'];
		$files    = (bool) $request['files'];
		if ( ! $database && ! $files ) {
			return new WP_Error( 'oueb_wp_backup_restore_nothing', __( 'Choose the database, the files, or both.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
		}

		$source = $this->resolve( (array) $request['source'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$key = $this->check_key( $source );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$token   = Maintenance::new_token();
		$options = array(
			'source'     => $source,
			'database'   => $database,
			'files'      => $files,
			'safety'     => (bool) $request['safety'],
			'user_id'    => get_current_user_id(),
			'token_hash' => $token['hash'],
		);

		$main = $this->jobs->get( Job::MAIN );
		$run  = $this->runner->start_restore( $options, Restore_Plan::safety_job( null === $main ? Job::main() : $main, $options ) );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		// Ce cookie laisse l'administrateur suivre la restauration pendant la maintenance.
		if ( ! headers_sent() ) {
			setcookie(
				Maintenance::COOKIE,
				$token['token'],
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => SITECOOKIEPATH ? SITECOOKIEPATH : '/',
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$response = rest_ensure_response( $run->to_public_array() );
		$response->set_status( 202 );

		return $response;
	}

	/**
	 * Commence l'envoi d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Envoi, ou erreur.
	 */
	public function create_upload( $request ) {
		$upload = $this->uploads->create( (string) $request['name'], (int) $request['size'] );

		return is_wp_error( $upload ) ? $upload : rest_ensure_response( self::public_upload( $upload ) );
	}

	/**
	 * Renvoie l'état d'un envoi, pour reprendre après une coupure.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Envoi, ou erreur 404.
	 */
	public function get_upload( $request ) {
		$upload = $this->uploads->find( (string) $request['id'] );

		return null === $upload ? self::upload_not_found() : rest_ensure_response( self::public_upload( $upload ) );
	}

	/**
	 * Ajoute un morceau à un envoi : le corps de la requête, brut.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Envoi mis à jour, ou erreur.
	 */
	public function append_upload( $request ) {
		$upload = $this->uploads->append( (string) $request['id'], (int) $request['offset'], (string) $request->get_body() );

		return is_wp_error( $upload ) ? $upload : rest_ensure_response( self::public_upload( $upload ) );
	}

	/**
	 * Abandonne un envoi.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Confirmation.
	 */
	public function delete_upload( $request ) {
		$this->uploads->delete( (string) $request['id'] );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Transforme la source demandée en archive à récupérer.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $source Source : run, storage ou upload.
	 * @return array<string, mixed>|WP_Error Source pour l'étape de récupération, ou erreur.
	 */
	private function resolve( array $source ) {
		$type = (string) ( $source['type'] ?? '' );

		if ( 'run' === $type ) {
			$run = $this->runs->find( (int) ( $source['run_id'] ?? 0 ) );
			if ( null === $run || Run::KIND_BACKUP !== $run->kind || '' === $run->archive_file || $run->is_active() ) {
				return self::archive_not_found();
			}

			$name  = basename( $run->archive_file );
			$local = $this->workspace->root() . '/archives/' . $name;
			if ( is_file( $local ) ) {
				return array(
					'type' => 'file',
					'path' => $local,
					'name' => $name,
					'size' => (int) filesize( $local ),
				);
			}

			$remote = array_values( array_diff( array_map( 'strval', (array) ( $run->state['stored'] ?? array() ) ), array( Storage_Repository::LOCAL ) ) );
			if ( array() === $remote ) {
				return self::archive_not_found();
			}

			return array(
				'type'     => 'storages',
				'storages' => $remote,
				'name'     => $name,
				'size'     => $run->archive_size,
			);
		}

		if ( 'storage' === $type ) {
			$id   = (string) ( $source['storage_id'] ?? '' );
			$name = basename( (string) ( $source['name'] ?? '' ) );
			if ( Storage_Repository::LOCAL === $id && Extractor::supports( $name ) && is_file( $this->workspace->root() . '/archives/' . $name ) ) {
				$path = $this->workspace->root() . '/archives/' . $name;
				return array(
					'type' => 'file',
					'path' => $path,
					'name' => $name,
					'size' => (int) filesize( $path ),
				);
			}

			$storage = $this->storages->instance( $id );
			if ( null === $storage || ! Extractor::supports( $name ) ) {
				return self::archive_not_found();
			}

			try {
				$found = wp_list_filter( $storage->files(), array( 'name' => $name ) );
			} catch ( Throwable $error ) {
				return new WP_Error( 'oueb_wp_backup_storage_error', wp_specialchars_decode( $error->getMessage(), ENT_QUOTES ), array( 'status' => 502 ) );
			}
			if ( array() === $found ) {
				return self::archive_not_found();
			}

			return array(
				'type'     => 'storages',
				'storages' => array( $id ),
				'name'     => $name,
				'size'     => (int) ( reset( $found )['size'] ?? 0 ),
			);
		}

		if ( 'upload' === $type ) {
			$upload = $this->uploads->find( (string) ( $source['upload_id'] ?? '' ) );
			if ( null === $upload ) {
				return self::upload_not_found();
			}
			if ( $upload['received'] !== $upload['size'] ) {
				return new WP_Error( 'oueb_wp_backup_upload_incomplete', __( 'The archive is not fully uploaded yet.', 'oueb-wp-backup' ), array( 'status' => 409 ) );
			}

			return array(
				'type'   => 'file',
				'path'   => $upload['path'],
				'name'   => $upload['name'],
				'size'   => $upload['size'],
				'upload' => true,
			);
		}

		return new WP_Error( 'oueb_wp_backup_restore_source', __( 'Choose a backup to restore.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
	}

	/**
	 * Vérifie, pour une archive chiffrée, que le site a sa clé.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $source Source résolue.
	 * @return true|WP_Error Vrai si la clé est là, ou si l'en-tête ne se lit pas encore.
	 */
	private function check_key( array $source ) {
		if ( '.enc' !== strtolower( substr( (string) $source['name'], -4 ) ) ) {
			return true;
		}

		$header = '';
		try {
			if ( 'file' === $source['type'] ) {
				$header = (string) file_get_contents( (string) $source['path'], false, null, 0, Archive_Cipher::HEADER_BYTES );
			} else {
				$storage = $this->storages->instance( (string) $source['storages'][0] );
				$header  = null === $storage ? '' : $storage->read( (string) $source['name'], 0, Archive_Cipher::HEADER_BYTES );
			}
		} catch ( Throwable $error ) {
			// L'étape de récupération dira mieux ce qui ne va pas.
			return true;
		}

		$id = Archive_Cipher::key_id( $header );
		if ( null === $id ) {
			return new WP_Error( 'oueb_wp_backup_not_encrypted', __( 'This file ends with .enc but is not an encrypted backup.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
		}
		if ( null === Plugin::keys()->find( $id ) ) {
			return new WP_Error(
				'oueb_wp_backup_key_missing',
				/* translators: %s: key identifier. */
				sprintf( __( 'The key %s that encrypted this archive is missing. Import it in Settings, Encryption, then start again.', 'oueb-wp-backup' ), $id ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Retire le chemin sur le serveur d'un envoi.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $upload Envoi.
	 * @return array<string, mixed> Envoi pour l'API.
	 */
	private static function public_upload( array $upload ): array {
		unset( $upload['path'] );

		return $upload;
	}

	/**
	 * Erreur : archive introuvable.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error Erreur 404.
	 */
	private static function archive_not_found(): WP_Error {
		return new WP_Error( 'oueb_wp_backup_archive_not_found', __( 'This archive is no longer available.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
	}

	/**
	 * Erreur : envoi introuvable.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error Erreur 404.
	 */
	private static function upload_not_found(): WP_Error {
		return new WP_Error( 'oueb_wp_backup_upload_not_found', __( 'This upload no longer exists. Start it again.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
	}
}
