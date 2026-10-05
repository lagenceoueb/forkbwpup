<?php
/**
 * Routes REST de la maintenance de la base.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Rest;

use Oueb\WpBackup\Database\Table_Maintenance;
use Oueb\WpBackup\Engine\Run_Repository;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Liste les tables du site et lance leur vérification, réparation ou optimisation.
 *
 * @since 0.1.0
 */
final class Database_Controller extends WP_REST_Controller {

	/**
	 * Maintenance des tables.
	 *
	 * @since 0.1.0
	 * @var Table_Maintenance
	 */
	private Table_Maintenance $maintenance;

	/**
	 * Exécutions, pour ne rien lancer pendant une sauvegarde ou une restauration.
	 *
	 * @since 0.1.0
	 * @var Run_Repository
	 */
	private Run_Repository $runs;

	/**
	 * Crée le contrôleur.
	 *
	 * @since 0.1.0
	 *
	 * @param Table_Maintenance $maintenance Maintenance des tables.
	 * @param Run_Repository    $runs        Exécutions.
	 */
	public function __construct( Table_Maintenance $maintenance, Run_Repository $runs ) {
		$this->maintenance = $maintenance;
		$this->runs        = $runs;
		$this->namespace   = Settings_Controller::REST_NAMESPACE;
		$this->rest_base   = 'database';
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
			'/' . $this->rest_base . '/tables',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<operation>check|repair|optimize)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Liste les tables du site.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response Tables.
	 */
	public function get_items( $request ) {
		unset( $request );

		return rest_ensure_response( $this->maintenance->tables() );
	}

	/**
	 * Lance une opération sur toutes les tables.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error Résultat par table, ou refus pendant une exécution.
	 */
	public function create_item( $request ) {
		if ( null !== $this->runs->active() ) {
			return new WP_Error(
				'oueb_wp_backup_run_active',
				__( 'A backup or a restore is in progress. Wait for it to end, then try again.', 'oueb-wp-backup' ),
				array( 'status' => 409 )
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Optimiser une grosse base prend du temps.
		}

		return rest_ensure_response( $this->maintenance->run( (string) $request['operation'] ) );
	}
}
