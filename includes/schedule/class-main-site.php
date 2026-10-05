<?php
/**
 * WP-Cron du site principal.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Exécute du code sur le site principal du réseau.
 *
 * En multisite, chaque site a sa propre liste WP-Cron. Les événements de
 * l'extension vivent tous dans celle du site principal : un seul passage par
 * date, quel que soit le site visité.
 *
 * @since 0.1.0
 */
final class Main_Site {

	/**
	 * Exécute une fonction sur le site principal, puis revient au site courant.
	 *
	 * @since 0.1.0
	 *
	 * @template T
	 * @param callable(): T $callback Fonction.
	 * @return T Valeur de la fonction.
	 */
	public static function run( callable $callback ) {
		$switched = is_multisite() && ! is_main_site();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Indique si le site courant porte les événements de l'extension.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai hors multisite, ou sur le site principal.
	 */
	public static function is_current(): bool {
		return ! is_multisite() || is_main_site();
	}
}
