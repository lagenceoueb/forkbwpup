<?php
/**
 * Classe de base des tests unitaires.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnit_Test_Case;

/**
 * Prépare Brain Monkey et des options de site en mémoire.
 */
abstract class Test_Case extends PHPUnit_Test_Case {

	/**
	 * Options de site simulées.
	 *
	 * @var array<string, mixed>
	 */
	protected array $site_options = array();

	/**
	 * Démarre Brain Monkey et simule les fonctions courantes de WordPress.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->site_options = array();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_site_option' )->alias(
			fn( $name, $fallback = false ) => array_key_exists( $name, $this->site_options ) ? $this->site_options[ $name ] : $fallback
		);
		Functions\when( 'update_site_option' )->alias(
			function ( $name, $value ) {
				$this->site_options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_salt' )->alias( fn( $scheme ) => 'salt-' . $scheme );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_generate_password' )->alias(
			fn( $length ) => substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, $length )
		);
	}

	/**
	 * Arrête Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
