<?php
/**
 * Tests des réglages.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Security\Secret_Box;
use Oueb\WpBackup\Settings\Settings;

/**
 * Vérifie la lecture, la validation et l'enregistrement des réglages.
 */
final class Test_Settings extends Test_Case {

	/**
	 * Sans réglage enregistré, les valeurs par défaut s'appliquent.
	 */
	public function test_defaults(): void {
		$values = Settings::all();

		$this->assertSame( 30, $values['max_execution_time'] );
		$this->assertSame( 3, $values['step_retries'] );
		$this->assertSame( 30, $values['max_logs'] );
		$this->assertTrue( $values['show_agency_card'] );
		$this->assertSame( '', $values['cronjob_org_key'] );
	}

	/**
	 * La clé de déclenchement est générée une fois, puis gardée.
	 */
	public function test_trigger_key_is_generated_once(): void {
		$first = Settings::get( 'trigger_key' );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32,}$/', $first );
		$this->assertSame( $first, Settings::get( 'trigger_key' ) );
		$this->assertSame( $first, $this->site_options[ Settings::OPTION ]['trigger_key'] );
	}

	/**
	 * Une modification valide est enregistrée, sans toucher aux autres réglages.
	 */
	public function test_update_valid_values(): void {
		$key    = Settings::get( 'trigger_key' );
		$result = Settings::update(
			array(
				'max_execution_time' => '120',
				'show_agency_card'   => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 120, $result['max_execution_time'] );
		$this->assertFalse( $result['show_agency_card'] );
		$this->assertSame( 3, $result['step_retries'] );
		$this->assertSame( $key, $result['trigger_key'] );
	}

	/**
	 * Des valeurs invalides sont refusées, et rien n'est enregistré.
	 *
	 * @dataProvider invalid_changes
	 *
	 * @param array<string, mixed> $changes Modifications refusées.
	 */
	public function test_rejects_invalid_values( array $changes ): void {
		$before = Settings::all();
		$result = Settings::update( $changes );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( $before, Settings::all() );
	}

	/**
	 * Modifications invalides.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Cas de test.
	 */
	public static function invalid_changes(): array {
		return array(
			'durée trop courte'  => array( array( 'max_execution_time' => 5 ) ),
			'durée trop longue'  => array( array( 'max_execution_time' => 301 ) ),
			'nombre décimal'     => array( array( 'max_logs' => '12.5' ) ),
			'texte pour booléen' => array( array( 'show_agency_card' => 'yes' ) ),
			'clé trop courte'    => array( array( 'trigger_key' => 'abc' ) ),
			'clé avec symbole'   => array( array( 'trigger_key' => str_repeat( 'a', 40 ) . '!' ) ),
			'réglage inconnu'    => array( array( 'unknown' => 1 ) ),
		);
	}

	/**
	 * Un secret est chiffré en base et n'apparaît pas dans les valeurs publiques.
	 */
	public function test_secret_is_encrypted_and_hidden(): void {
		$public = Settings::update( array( 'cronjob_org_key' => 'cle-api' ) );

		$this->assertArrayNotHasKey( 'cronjob_org_key', $public );
		$this->assertTrue( $public['cronjob_org_key_set'] );

		$stored = $this->site_options[ Settings::OPTION ]['cronjob_org_key'];
		$this->assertStringStartsWith( Secret_Box::PREFIX, $stored );
		$this->assertSame( 'cle-api', Settings::get( 'cronjob_org_key' ) );
	}

	/**
	 * Une chaîne vide garde le secret, null l'efface.
	 */
	public function test_secret_keep_and_clear(): void {
		Settings::update( array( 'cronjob_org_key' => 'cle-api' ) );

		Settings::update( array( 'cronjob_org_key' => '' ) );
		$this->assertSame( 'cle-api', Settings::get( 'cronjob_org_key' ) );

		$public = Settings::update( array( 'cronjob_org_key' => null ) );
		$this->assertFalse( $public['cronjob_org_key_set'] );
		$this->assertSame( '', Settings::get( 'cronjob_org_key' ) );
	}
}
