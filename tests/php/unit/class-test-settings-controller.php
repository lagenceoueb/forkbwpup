<?php
/**
 * Tests de la route REST des réglages.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Rest\Settings_Controller;

/**
 * Vérifie les droits, la lecture et la modification par l'API.
 */
final class Test_Settings_Controller extends Test_Case {

	/**
	 * Contrôleur testé.
	 *
	 * @var Settings_Controller
	 */
	private Settings_Controller $controller;

	/**
	 * Prépare le contrôleur et les fonctions REST.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );
		$this->controller = new Settings_Controller();
	}

	/**
	 * Sans la capacité, l'accès est refusé en lecture comme en écriture.
	 */
	public function test_permission_denied(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$read  = $this->controller->get_item_permissions_check( new \WP_REST_Request() );
		$write = $this->controller->update_item_permissions_check( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $read );
		$this->assertSame( 403, $read->get_error_data()['status'] );
		$this->assertInstanceOf( \WP_Error::class, $write );
	}

	/**
	 * Avec la capacité, l'accès est permis.
	 */
	public function test_permission_granted(): void {
		Functions\expect( 'current_user_can' )->twice()->with( 'oueb_wp_backup_manage' )->andReturn( true );

		$this->assertTrue( $this->controller->get_item_permissions_check( new \WP_REST_Request() ) );
		$this->assertTrue( $this->controller->update_item_permissions_check( new \WP_REST_Request() ) );
	}

	/**
	 * La lecture ne renvoie aucun secret.
	 */
	public function test_get_item_hides_secrets(): void {
		$response = $this->controller->get_item( new \WP_REST_Request() );

		$this->assertArrayNotHasKey( 'cronjob_org_key', $response );
		$this->assertArrayHasKey( 'cronjob_org_key_set', $response );
	}

	/**
	 * Un corps qui n'est pas un objet JSON est refusé.
	 */
	public function test_update_rejects_non_object_body(): void {
		$result = $this->controller->update_item( new \WP_REST_Request( 'texte' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Une modification valide renvoie les réglages à jour.
	 */
	public function test_update_valid_body(): void {
		$result = $this->controller->update_item( new \WP_REST_Request( array( 'max_logs' => 12 ) ) );

		$this->assertSame( 12, $result['max_logs'] );
	}

	/**
	 * Le schéma décrit les réglages publics, sans secret ni valeur par défaut.
	 */
	public function test_schema(): void {
		$schema = $this->controller->get_item_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'max_execution_time', $schema['properties'] );
		$this->assertArrayNotHasKey( 'default', $schema['properties']['max_execution_time'] );
		$this->assertArrayNotHasKey( 'cronjob_org_key', $schema['properties'] );
		$this->assertTrue( $schema['properties']['cronjob_org_key_set']['readonly'] );
	}
}
