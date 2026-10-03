<?php
/**
 * Route REST des réglages.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Security\Capabilities;
use Oueb\WpBackup\Settings\Settings;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Expose les réglages généraux sur oueb-wp-backup/v1/settings.
 *
 * GET renvoie les réglages sans secret, POST modifie ceux qui sont envoyés.
 * Les deux exigent la capacité oueb_wp_backup_manage, et le nonce REST que
 * l'interface ajoute à chaque requête.
 *
 * @since 0.1.0
 */
final class Settings_Controller extends WP_REST_Controller {

	/**
	 * Espace de noms des routes de l'extension.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const REST_NAMESPACE = 'oueb-wp-backup/v1';

	/**
	 * Prépare le contrôleur.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
		$this->rest_base = 'settings';
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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Vérifie le droit de lire les réglages.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return true|WP_Error Vrai, ou erreur 401 ou 403.
	 */
	public function get_item_permissions_check( $request ) {
		return self::check_permission();
	}

	/**
	 * Vérifie le droit de modifier les réglages.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return true|WP_Error Vrai, ou erreur 401 ou 403.
	 */
	public function update_item_permissions_check( $request ) {
		return self::check_permission();
	}

	/**
	 * Renvoie les réglages.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Réglages sans secret.
	 */
	public function get_item( $request ) {
		return rest_ensure_response( Settings::public_values() );
	}

	/**
	 * Modifie les réglages envoyés.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête, corps JSON.
	 * @return WP_REST_Response|WP_Error Réglages après enregistrement, ou erreur de validation.
	 */
	public function update_item( $request ) {
		$changes = $request->get_json_params();
		if ( ! is_array( $changes ) ) {
			return new WP_Error(
				'oueb_wp_backup_invalid_body',
				__( 'The request body must be a JSON object.', 'oueb-wp-backup' ),
				array( 'status' => 400 )
			);
		}

		$result = Settings::update( $changes );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Décrit la ressource pour l'API.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Schéma JSON des réglages publics.
	 */
	public function get_item_schema() {
		$properties = array();
		foreach ( Settings::fields() as $name => $field ) {
			if ( ! empty( $field['secret'] ) ) {
				$properties[ $name . '_set' ] = array(
					'type'        => 'boolean',
					'description' => $field['description'],
					'readonly'    => true,
				);
				continue;
			}

			unset( $field['default'] );
			$properties[ $name ] = $field;
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'oueb-wp-backup-settings',
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * Vérifie que l'utilisateur peut gérer les sauvegardes.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error Vrai, ou erreur 401 ou 403.
	 */
	public static function check_permission() {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage backups.', 'oueb-wp-backup' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
