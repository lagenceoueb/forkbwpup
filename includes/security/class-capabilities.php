<?php
/**
 * Capacité de gestion des sauvegardes.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Définit qui peut gérer les sauvegardes.
 *
 * Une seule capacité, oueb_wp_backup_manage, protège toute l'extension :
 * restaurer une sauvegarde remplace le site entier, il n'y a pas de demi-droit
 * qui vaille. Elle se ramène à manage_options, ou à manage_network_options en
 * multisite, sans écrire de rôle en base. Le filtre oueb_wp_backup_capability
 * permet de la confier à une autre capacité.
 *
 * @since 0.1.0
 */
final class Capabilities {

	/**
	 * Capacité vérifiée par l'extension.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const MANAGE = 'oueb_wp_backup_manage';

	/**
	 * Branche la correspondance des capacités.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 2 );
	}

	/**
	 * Ramène la capacité de l'extension à une capacité de WordPress.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $caps Capacités primitives requises.
	 * @param string   $cap  Capacité demandée.
	 * @return string[] Capacités primitives requises.
	 */
	public static function map_meta_cap( $caps, $cap ) {
		if ( self::MANAGE !== $cap ) {
			return $caps;
		}

		$required = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filtre la capacité de WordPress qui donne accès aux sauvegardes.
		 *
		 * @since 0.1.0
		 *
		 * @param string $required Capacité requise : manage_options, ou manage_network_options en multisite.
		 */
		return array( (string) apply_filters( 'oueb_wp_backup_capability', $required ) );
	}
}
