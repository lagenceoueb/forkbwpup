<?php
/**
 * Tests de la capacité de gestion.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Oueb\WpBackup\Security\Capabilities;

/**
 * Vérifie la correspondance de la capacité de l'extension.
 */
final class Test_Capabilities extends Test_Case {

	/**
	 * Sur un site simple, la capacité se ramène à manage_options.
	 */
	public function test_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertSame( array( 'manage_options' ), Capabilities::map_meta_cap( array( 'do_not_allow' ), Capabilities::MANAGE ) );
	}

	/**
	 * En multisite, elle se ramène à manage_network_options.
	 */
	public function test_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$this->assertSame( array( 'manage_network_options' ), Capabilities::map_meta_cap( array(), Capabilities::MANAGE ) );
	}

	/**
	 * Le filtre oueb_wp_backup_capability change la capacité requise.
	 */
	public function test_filter(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Filters\expectApplied( 'oueb_wp_backup_capability' )->once()->andReturn( 'edit_posts' );

		$this->assertSame( array( 'edit_posts' ), Capabilities::map_meta_cap( array(), Capabilities::MANAGE ) );
	}

	/**
	 * Les autres capacités ne sont pas touchées.
	 */
	public function test_other_capabilities(): void {
		$this->assertSame( array( 'edit_posts' ), Capabilities::map_meta_cap( array( 'edit_posts' ), 'edit_post' ) );
	}
}
