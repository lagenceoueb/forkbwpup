<?php
/**
 * Routes REST des stockages.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Engine\Steps\Finish;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Storage\Providers;
use Oueb\WpBackup\Storage\Storage_Repository;
use Throwable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Liste, crée, modifie, supprime et teste les stockages.
 *
 * Les secrets ne sortent jamais : l'API indique seulement s'ils sont
 * enregistrés. Un secret laissé vide garde sa valeur.
 *
 * @since 0.1.0
 */
final class Storages_Controller extends WP_REST_Controller {

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

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
	 * @param Storage_Repository $storages Stockages.
	 * @param Job_Repository     $jobs     Tâches.
	 */
	public function __construct( Storage_Repository $storages, Job_Repository $jobs ) {
		$this->storages  = $storages;
		$this->jobs      = $jobs;
		$this->namespace = Settings_Controller::REST_NAMESPACE;
		$this->rest_base = 'storages';
	}

	/**
	 * Déclare les routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		$permission = array( Settings_Controller::class, 'check_permission' );
		$item       = '/' . $this->rest_base . '/(?P<id>[a-z0-9-]+)';

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_types' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$this->namespace,
			$item,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$item . '/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$this->namespace,
			$item . '/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'files' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Liste les stockages.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Stockages.
	 */
	public function get_items( $request ) {
		return rest_ensure_response( array_values( array_map( array( $this->storages, 'to_public' ), $this->storages->all() ) ) );
	}

	/**
	 * Décrit les types de stockage et les fournisseurs S3.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Types et fournisseurs.
	 */
	public function get_types( $request ) {
		$types = array();
		foreach ( Storage_Repository::TYPES as $type => $class ) {
			$fields = array();
			foreach ( $class::fields() as $name => $field ) {
				$fields[ $name ] = array(
					'type'     => $field['type'],
					'required' => ! empty( $field['required'] ),
					'secret'   => ! empty( $field['secret'] ),
					'default'  => $field['default'] ?? null,
					'options'  => $field['options'] ?? null,
				);
			}
			$types[] = array(
				'id'     => $type,
				'label'  => $class::label(),
				'fields' => $fields,
			);
		}

		$providers = array();
		foreach ( Providers::all() as $id => $provider ) {
			$regions = array();
			foreach ( array_keys( (array) $provider['regions'] ) as $region ) {
				$data      = Providers::region( $id, (string) $region );
				$regions[] = array(
					'id'      => $region,
					'name'    => $data['name'],
					'country' => $data['country'],
				);
			}
			$criteria = array();
			foreach ( (array) $provider['criteria'] as $key => $criterion ) {
				$criteria[ $key ] = array(
					'text'   => (string) $criterion[0],
					'source' => (string) $criterion[1],
				);
			}
			$providers[] = array(
				'id'       => $id,
				'name'     => $provider['name'],
				'country'  => $provider['country'],
				'regions'  => $regions,
				'criteria' => $criteria,
				'notes'    => $provider['notes'],
			);
		}

		return rest_ensure_response(
			array(
				'types'      => $types,
				'providers'  => $providers,
				'checked_on' => Providers::CHECKED_ON,
			)
		);
	}

	/**
	 * Renvoie un stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Stockage, ou erreur 404.
	 */
	public function get_item( $request ) {
		$record = $this->storages->get( (string) $request['id'] );

		return null === $record ? self::not_found() : rest_ensure_response( $this->storages->to_public( $record ) );
	}

	/**
	 * Crée un stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête : type, name, settings.
	 * @return WP_REST_Response|WP_Error Stockage créé, ou erreur 400.
	 */
	public function create_item( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return self::invalid_body();
		}

		$record = $this->storages->create( (string) ( $body['type'] ?? '' ), $body );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$response = rest_ensure_response( $this->storages->to_public( $record ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Modifie un stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête : name, settings.
	 * @return WP_REST_Response|WP_Error Stockage, ou erreur.
	 */
	public function update_item( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return self::invalid_body();
		}

		$record = $this->storages->update( (string) $request['id'], $body );

		return is_wp_error( $record ) ? $record : rest_ensure_response( $this->storages->to_public( $record ) );
	}

	/**
	 * Supprime un stockage qu'aucune tâche n'utilise.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Accusé, ou erreur 409 si une tâche l'utilise.
	 */
	public function delete_item( $request ) {
		$id    = (string) $request['id'];
		$users = array_filter( $this->jobs->all(), static fn( Job $job ): bool => in_array( $id, $job->storages, true ) );
		if ( array() !== $users ) {
			return new WP_Error(
				'oueb_wp_backup_storage_in_use',
				sprintf(
					/* translators: %s: list of backup job names. */
					__( 'This storage is used by: %s. Remove it from these backups first.', 'oueb-wp-backup' ),
					implode( ', ', array_map( static fn( Job $job ): string => $job->name, $users ) )
				),
				array( 'status' => 409 )
			);
		}

		$result = $this->storages->delete( $id );

		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Teste la connexion et le droit d'écrire.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Compte rendu, ou erreur 502.
	 */
	public function test( $request ) {
		$storage = $this->storages->instance( (string) $request['id'] );
		if ( null === $storage ) {
			return self::not_found();
		}

		try {
			$message = $storage->test();
		} catch ( Throwable $error ) {
			return self::remote_error( $error );
		}

		return rest_ensure_response( array( 'message' => $message ) );
	}

	/**
	 * Liste les archives d'un stockage, toutes tâches et tous sites confondus.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Archives, les plus récentes d'abord, ou erreur.
	 */
	public function files( $request ) {
		$storage = $this->storages->instance( (string) $request['id'] );
		if ( null === $storage ) {
			return self::not_found();
		}

		try {
			$files = $storage->files();
		} catch ( Throwable $error ) {
			return self::remote_error( $error );
		}

		$own   = Finish::prefix( '' );
		$own   = substr( $own, 0, -1 );
		$items = array();
		foreach ( $files as $file ) {
			if ( preg_match( '/_\d{4}-\d{2}-\d{2}_\d{6}\.(zip|tar\.gz)$/', $file['name'] ) ) {
				$items[] = $file + array( 'this_site' => 0 === strpos( $file['name'], $own ) );
			}
		}
		usort( $items, static fn( array $a, array $b ): int => $b['time'] <=> $a['time'] );

		return rest_ensure_response( $items );
	}

	/**
	 * Construit l'erreur d'un stockage injoignable.
	 *
	 * @since 0.1.0
	 *
	 * @param Throwable $error Erreur.
	 * @return WP_Error Erreur 502, message en texte brut.
	 */
	private static function remote_error( Throwable $error ): WP_Error {
		return new WP_Error( 'oueb_wp_backup_storage_failed', wp_specialchars_decode( $error->getMessage(), ENT_QUOTES ), array( 'status' => 502 ) );
	}

	/**
	 * Construit l'erreur d'un corps invalide.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error Erreur 400.
	 */
	private static function invalid_body(): WP_Error {
		return new WP_Error( 'oueb_wp_backup_invalid_body', __( 'The request body must be a JSON object.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
	}

	/**
	 * Construit l'erreur d'un stockage absent.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error Erreur 404.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'oueb_wp_backup_storage_not_found', __( 'This storage does not exist.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
	}
}
