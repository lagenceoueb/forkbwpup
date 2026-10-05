<?php
/**
 * Routes REST de l'import BackWPup.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Legacy\Legacy_Import;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Décrit, lance ou écarte l'import des tâches et réglages de BackWPup.
 *
 * @since 0.1.0
 */
final class Import_Controller extends WP_REST_Controller {

	/**
	 * Import.
	 *
	 * @since 0.1.0
	 * @var Legacy_Import
	 */
	private Legacy_Import $import;

	/**
	 * Crée le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Legacy_Import $import Import.
	 */
	public function __construct( Legacy_Import $import ) {
		$this->import    = $import;
		$this->namespace = Settings_Controller::REST_NAMESPACE;
		$this->rest_base = 'import';
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
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Décrit ce qui peut être importé.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Résumé.
	 */
	public function get_item( $request ) {
		unset( $request );

		return rest_ensure_response( $this->import->summary() );
	}

	/**
	 * Lance l'import.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Résumé, avec le rapport.
	 */
	public function create_item( $request ) {
		unset( $request );
		$this->import->run();

		return rest_ensure_response( $this->import->summary() );
	}

	/**
	 * Écarte l'import : la notice ne s'affiche plus.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Résumé.
	 */
	public function dismiss( $request ) {
		unset( $request );
		$this->import->dismiss();

		return rest_ensure_response( $this->import->summary() );
	}
}
