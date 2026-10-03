<?php
/**
 * Tests du chiffrement des secrets.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Security\Secret_Box;

/**
 * Vérifie le chiffrement et le déchiffrement des secrets.
 */
final class Test_Secret_Box extends Test_Case {

	/**
	 * Un secret chiffré se déchiffre à l'identique.
	 */
	public function test_round_trip(): void {
		$box = Secret_Box::encrypt( 'mot de passe é ☃' );

		$this->assertStringStartsWith( Secret_Box::PREFIX, $box );
		$this->assertStringNotContainsString( 'mot de passe', $box );
		$this->assertSame( 'mot de passe é ☃', Secret_Box::decrypt( $box ) );
	}

	/**
	 * Deux chiffrements du même secret diffèrent, grâce au nonce aléatoire.
	 */
	public function test_uses_a_new_nonce_each_time(): void {
		$this->assertNotSame( Secret_Box::encrypt( 'secret' ), Secret_Box::encrypt( 'secret' ) );
	}

	/**
	 * Une valeur vide reste vide.
	 */
	public function test_empty_value(): void {
		$this->assertSame( '', Secret_Box::encrypt( '' ) );
		$this->assertSame( '', Secret_Box::decrypt( '' ) );
	}

	/**
	 * Une valeur modifiée, d'un autre format ou d'un autre site ne se déchiffre pas.
	 */
	public function test_rejects_tampered_or_foreign_values(): void {
		$box = Secret_Box::encrypt( 'secret' );

		$tampered = substr( $box, 0, -2 ) . ( 'A' === substr( $box, -2, 1 ) ? 'B' : 'A' ) . substr( $box, -1 );
		$this->assertSame( '', Secret_Box::decrypt( $tampered ) );
		$this->assertSame( '', Secret_Box::decrypt( 'secret en clair' ) );
		$this->assertSame( '', Secret_Box::decrypt( Secret_Box::PREFIX . '!!!' ) );
		$this->assertSame( '', Secret_Box::decrypt( Secret_Box::PREFIX . 'AAAA' ) );

		Functions\when( 'wp_salt' )->justReturn( 'autre site' );
		$this->assertSame( '', Secret_Box::decrypt( $box ) );
	}
}
