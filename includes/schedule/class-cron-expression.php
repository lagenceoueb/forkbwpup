<?php
/**
 * Expression cron à cinq champs.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Lit une expression « minute heure jour mois jour-de-semaine » et calcule ses dates.
 *
 * Chaque champ accepte « * », une valeur, une plage « 1-5 », un pas « *\/6 »
 * ou « 1-20/5 », et des listes séparées par des virgules. Le dimanche
 * s'écrit 0 ou 7. Comme dans cron, si le jour du mois et le jour de la
 * semaine sont tous deux restreints, l'un ou l'autre suffit.
 *
 * Une sauvegarde ne part pas plus de quatre fois par heure.
 *
 * @since 0.1.0
 */
final class Cron_Expression {

	/**
	 * Bornes de chaque champ.
	 *
	 * @since 0.1.0
	 * @var array<string, int[]>
	 */
	const FIELDS = array(
		'minutes' => array( 0, 59 ),
		'hours'   => array( 0, 23 ),
		'mdays'   => array( 1, 31 ),
		'months'  => array( 1, 12 ),
		'wdays'   => array( 0, 6 ),
	);

	/**
	 * Valeurs permises, par champ.
	 *
	 * @since 0.1.0
	 * @var array<string, int[]>
	 */
	private array $sets = array();

	/**
	 * Champs restreints, c'est-à-dire autres que « * ».
	 *
	 * @since 0.1.0
	 * @var array<string, bool>
	 */
	private array $restricted = array();

	/**
	 * Lit une expression.
	 *
	 * @since 0.1.0
	 *
	 * @param string $expression Expression, par exemple « 0 3 * * * ».
	 *
	 * @throws InvalidArgumentException Si l'expression est invalide ou trop fréquente.
	 */
	public function __construct( string $expression ) {
		$parts = preg_split( '/\s+/', trim( $expression ) );
		if ( ! is_array( $parts ) || 5 !== count( $parts ) ) {
			throw new InvalidArgumentException( esc_html__( 'The schedule needs five fields: minute, hour, day of the month, month, day of the week.', 'oueb-wp-backup' ) );
		}

		$index = 0;
		foreach ( self::FIELDS as $name => $bounds ) {
			$this->restricted[ $name ] = '*' !== $parts[ $index ];
			$this->sets[ $name ]       = self::parse_field( $parts[ $index ], $bounds[0], $bounds[1], 'wdays' === $name );
			++$index;
		}

		if ( count( $this->sets['minutes'] ) > 4 ) {
			throw new InvalidArgumentException( esc_html__( 'A backup cannot start more than four times an hour.', 'oueb-wp-backup' ) );
		}
	}

	/**
	 * Indique si une expression est valide.
	 *
	 * @since 0.1.0
	 *
	 * @param string $expression Expression.
	 * @return string Message d'erreur, vide si elle est valide.
	 */
	public static function error( string $expression ): string {
		try {
			new self( $expression );
		} catch ( InvalidArgumentException $error ) {
			return $error->getMessage();
		}

		return '';
	}

	/**
	 * Calcule la prochaine date après un instant.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $after    Horodatage de départ, exclu.
	 * @param DateTimeZone $timezone Fuseau du site.
	 * @return int|null Horodatage, ou null si aucune date dans les cinq ans (31 février par exemple).
	 */
	public function next( int $after, DateTimeZone $timezone ): ?int {
		$start = ( new DateTimeImmutable( '@' . ( $after - ( $after % 60 ) + 60 ) ) )->setTimezone( $timezone );
		$day   = $start->setTime( 0, 0 );

		for ( $i = 0; $i < 366 * 5; $i++ ) {
			$date = $day->modify( '+' . $i . ' days' );
			if ( $this->matches_day( $date ) ) {
				foreach ( $this->sets['hours'] as $hour ) {
					foreach ( $this->sets['minutes'] as $minute ) {
						$candidate = $date->setTime( $hour, $minute );
						// Heure qui n'existe pas au passage à l'heure d'été : PHP la décale, on l'écarte.
						if ( (int) $candidate->format( 'G' ) !== $hour ) {
							continue;
						}
						if ( $candidate >= $start ) {
							return $candidate->getTimestamp();
						}
					}
				}
			}
		}

		return null;
	}

	/**
	 * Convertit l'expression au format de planification de cron-job.org.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int[]> Champs, -1 pour « toutes les valeurs ».
	 */
	public function to_cronjob_org(): array {
		$schedule = array();
		foreach ( array_keys( self::FIELDS ) as $name ) {
			$schedule[ $name ] = $this->restricted[ $name ] ? $this->sets[ $name ] : array( -1 );
		}

		return $schedule;
	}

	/**
	 * Indique si un jour correspond aux champs jour, mois et jour de la semaine.
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $date Jour.
	 * @return bool Vrai si la sauvegarde peut partir ce jour-là.
	 */
	private function matches_day( DateTimeImmutable $date ): bool {
		if ( ! in_array( (int) $date->format( 'n' ), $this->sets['months'], true ) ) {
			return false;
		}

		$mday = in_array( (int) $date->format( 'j' ), $this->sets['mdays'], true );
		$wday = in_array( (int) $date->format( 'w' ), $this->sets['wdays'], true );

		if ( $this->restricted['mdays'] && $this->restricted['wdays'] ) {
			return $mday || $wday;
		}

		return $mday && $wday;
	}

	/**
	 * Lit un champ.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field   Champ.
	 * @param int    $min     Valeur minimale.
	 * @param int    $max     Valeur maximale.
	 * @param bool   $weekday Vrai pour le jour de la semaine, où 7 vaut 0.
	 * @return int[] Valeurs, triées.
	 *
	 * @throws InvalidArgumentException Si le champ est invalide.
	 */
	private static function parse_field( string $field, int $min, int $max, bool $weekday ): array {
		$values = array();
		$top    = $weekday ? 7 : $max;

		foreach ( explode( ',', $field ) as $item ) {
			if ( ! preg_match( '#^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?$#', $item, $match ) ) {
				/* translators: %s: part of the schedule. */
				throw new InvalidArgumentException( esc_html( sprintf( __( 'This part of the schedule is not valid: %s', 'oueb-wp-backup' ), $item ) ) );
			}

			$step = isset( $match[4] ) && '' !== $match[4] ? (int) $match[4] : 1;
			if ( '*' === $match[1] ) {
				$from = $min;
				$to   = $max;
			} else {
				$from = (int) $match[2];
				$to   = isset( $match[3] ) && '' !== $match[3] ? (int) $match[3] : ( isset( $match[4] ) && '' !== $match[4] ? $top : $from );
			}

			if ( $step < 1 || $from < $min || $to > $top || $from > $to ) {
				/* translators: %s: part of the schedule. */
				throw new InvalidArgumentException( esc_html( sprintf( __( 'This part of the schedule is out of range: %s', 'oueb-wp-backup' ), $item ) ) );
			}

			for ( $value = $from; $value <= $to; $value += $step ) {
				$values[] = $weekday && 7 === $value ? 0 : $value;
			}
		}

		$values = array_values( array_unique( $values ) );
		sort( $values );

		return $values;
	}
}
