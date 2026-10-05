<?php
/**
 * Tests du planificateur.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Schedule\Scheduler;

/**
 * Vérifie le nom de fuseau envoyé à cron-job.org.
 */
final class Test_Scheduler extends Test_Case {

	/**
	 * Fuseaux attendus.
	 *
	 * @dataProvider timezones
	 *
	 * @param string $wp_timezone Fuseau donné par WordPress.
	 * @param float  $offset      Décalage en heures.
	 * @param string $expected    Fuseau attendu.
	 */
	public function test_iana_timezone( string $wp_timezone, float $offset, string $expected ): void {
		Functions\when( 'wp_timezone_string' )->justReturn( $wp_timezone );
		Functions\when( 'get_option' )->justReturn( $offset );

		$this->assertSame( $expected, Scheduler::iana_timezone() );
	}

	/**
	 * Fuseaux de WordPress et noms attendus.
	 *
	 * @return array<string, array{0: string, 1: float, 2: string}>
	 */
	public static function timezones(): array {
		return array(
			'ville'            => array( 'Europe/Paris', 2.0, 'Europe/Paris' ),
			'UTC'              => array( 'UTC', 0.0, 'UTC' ),
			'décalage positif' => array( '+02:00', 2.0, 'Etc/GMT-2' ),
			'décalage négatif' => array( '-05:00', -5.0, 'Etc/GMT+5' ),
			'demi-heure'       => array( '+05:30', 5.5, 'UTC' ),
		);
	}
}
