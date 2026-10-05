<?php
/**
 * Tests des expressions cron.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Oueb\WpBackup\Schedule\Cron_Expression;

/**
 * Vérifie la lecture des expressions et le calcul des dates.
 */
final class Test_Cron_Expression extends Test_Case {

	/**
	 * Calcule la prochaine date sous forme lisible.
	 *
	 * @param string $cron     Expression.
	 * @param string $after    Date de départ, dans le fuseau.
	 * @param string $timezone Fuseau.
	 * @return string Date suivante, dans le fuseau.
	 */
	private function next( string $cron, string $after, string $timezone = 'Europe/Paris' ): string {
		$zone = new DateTimeZone( $timezone );
		$next = ( new Cron_Expression( $cron ) )->next( ( new DateTimeImmutable( $after, $zone ) )->getTimestamp(), $zone );

		return null === $next ? 'jamais' : ( new DateTimeImmutable( '@' . $next ) )->setTimezone( $zone )->format( 'Y-m-d H:i D' );
	}

	/**
	 * Prochaines dates attendues.
	 *
	 * @dataProvider schedules
	 *
	 * @param string $cron     Expression.
	 * @param string $after    Départ.
	 * @param string $expected Date attendue.
	 */
	public function test_next( string $cron, string $after, string $expected ): void {
		$this->assertSame( $expected, $this->next( $cron, $after ) );
	}

	/**
	 * Expressions, départs et dates attendues, à Paris.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function schedules(): array {
		return array(
			'chaque jour, plus tard'        => array( '0 3 * * *', '2026-10-04 01:00', '2026-10-04 03:00 Sun' ),
			'chaque jour, déjà passé'       => array( '0 3 * * *', '2026-10-04 03:00', '2026-10-05 03:00 Mon' ),
			'deux fois par jour'            => array( '30 2,14 * * *', '2026-10-04 03:00', '2026-10-04 14:30 Sun' ),
			'lundi'                         => array( '15 23 * * 1', '2026-10-04 12:00', '2026-10-05 23:15 Mon' ),
			'dimanche écrit 7'              => array( '0 4 * * 7', '2026-10-05 12:00', '2026-10-11 04:00 Sun' ),
			'jours ouvrés'                  => array( '0 3 * * 1-5', '2026-10-09 04:00', '2026-10-12 03:00 Mon' ),
			'le 31, mois suivant'           => array( '0 3 31 * *', '2026-11-01 00:00', '2026-12-31 03:00 Thu' ),
			'jour du mois OU de la semaine' => array( '0 3 13 * 5', '2026-11-01 00:00', '2026-11-06 03:00 Fri' ),
			'toutes les 6 heures'           => array( '0 */6 * * *', '2026-10-04 07:00', '2026-10-04 12:00 Sun' ),
			'pas dans une plage'            => array( '0 3 1-20/10 * *', '2026-10-02 00:00', '2026-10-11 03:00 Sun' ),
			'31 février'                    => array( '0 3 31 2 *', '2026-01-01 00:00', 'jamais' ),
			'heure sautée au printemps'     => array( '30 2 * * *', '2027-03-28 00:00', '2027-03-29 02:30 Mon' ),
		);
	}

	/**
	 * Les expressions invalides ou trop fréquentes sont refusées.
	 *
	 * @dataProvider invalid
	 *
	 * @param string $cron Expression.
	 */
	public function test_invalid( string $cron ): void {
		$this->expectException( InvalidArgumentException::class );
		new Cron_Expression( $cron );
	}

	/**
	 * Expressions refusées.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalid(): array {
		return array(
			'quatre champs'         => array( '0 3 * *' ),
			'minute hors bornes'    => array( '60 3 * * *' ),
			'plage inversée'        => array( '0 5-3 * * *' ),
			'pas nul'               => array( '0 */0 * * *' ),
			'lettres'               => array( '0 3 * * mon' ),
			'chaque minute'         => array( '* * * * *' ),
			'toutes les 10 minutes' => array( '*/10 * * * *' ),
			'jour du mois zéro'     => array( '0 3 0 * *' ),
		);
	}

	/**
	 * Quatre départs par heure restent permis.
	 */
	public function test_four_per_hour(): void {
		$this->assertSame( '', Cron_Expression::error( '*/15 * * * *' ) );
		$this->assertNotSame( '', Cron_Expression::error( '*/12 * * * *' ) );
	}

	/**
	 * La conversion pour cron-job.org garde -1 pour les champs libres.
	 */
	public function test_cronjob_org(): void {
		$this->assertSame(
			array(
				'minutes' => array( 30 ),
				'hours'   => array( 2, 14 ),
				'mdays'   => array( -1 ),
				'months'  => array( -1 ),
				'wdays'   => array( 0, 1 ),
			),
			( new Cron_Expression( '30 2,14 * * 7,1' ) )->to_cronjob_org()
		);
	}
}
