<?php
/**
 * Routes REST des clés de chiffrement.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Security\Key_Ring;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Liste, crée, importe et exporte les clés de chiffrement des archives.
 *
 * La valeur d'une clé ne sort qu'à la création et sur demande explicite
 * d'export, pour que l'administrateur la garde hors du site.
 *
 * @since 0.1.0
 */
final class Encryption_Controller extends WP_REST_Controller {

	/**
	 * Clés.
	 *
	 * @since 0.1.0
	 * @var Key_Ring
	 */
	private Key_Ring $keys;

	/**
	 * Prépare le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Key_Ring $keys Clés.
	 */
	public function __construct( Key_Ring $keys ) {
		$this->keys      = $keys;
		$this->namespace = Settings_Controller::REST_NAMESPACE;
		$this->rest_base = 'encryption/keys';
	}

	/**
	 * Déclare les routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		$permission = array( Settings_Controller::class, 'check_permission' );

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
					'args'                => array(
						'key' => array(
							'type'     => 'string',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[0-9a-f]{16})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Liste les clés, sans leur valeur.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Clés.
	 */
	public function get_items( $request ) {
		return rest_ensure_response( $this->keys->summary() );
	}

	/**
	 * Crée une clé, ou importe celle reçue.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête : key, en base64, pour importer.
	 * @return WP_REST_Response|WP_Error Clé, montrée une fois, ou erreur 400.
	 */
	public function create_item( $request ) {
		$encoded = (string) $request['key'];
		if ( '' === $encoded ) {
			$key = $this->keys->generate();
		} else {
			$raw = Key_Ring::decode( $encoded );
			if ( null === $raw ) {
				return new WP_Error( 'oueb_wp_backup_invalid_key', __( 'This is not an encryption key. Paste the key exactly as it was saved.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
			}
			$key = $this->keys->add( $raw );
		}

		$response = rest_ensure_response(
			array(
				'id'  => $key['id'],
				'key' => Key_Ring::encode( $key['key'] ),
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Exporte une clé, pour la garder hors du site.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Clé, ou erreur 404.
	 */
	public function get_item( $request ) {
		$key = $this->keys->find( (string) $request['id'] );
		if ( null === $key ) {
			return new WP_Error( 'oueb_wp_backup_key_not_found', __( 'This key does not exist.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'id'  => (string) $request['id'],
				'key' => Key_Ring::encode( $key ),
			)
		);
	}
}
