<?php
/**
 * Point d'entrée du noyau d'Oueb WP Backup.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup;

use Oueb\WpBackup\Admin\Admin_Page;
use Oueb\WpBackup\Rest\Settings_Controller;
use Oueb\WpBackup\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Branche les services de l'extension sur WordPress.
 *
 * Pendant la refonte, l'interface React ne s'affiche que si la constante
 * OUEB_WP_BACKUP_NEXT vaut true dans wp-config.php. L'ancienne interface
 * reste la seule active par défaut, jusqu'à la bascule du lot 6.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Version de l'extension.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const VERSION = '0.0.1';

	/**
	 * Chemin du fichier principal de l'extension.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private static string $file = '';

	/**
	 * Démarre l'extension.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Chemin du fichier principal de l'extension.
	 */
	public static function boot( string $file ): void {
		if ( '' !== self::$file ) {
			return;
		}
		self::$file = $file;

		Capabilities::register();
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		if ( self::is_next_enabled() && is_admin() ) {
			Admin_Page::register();
		}
	}

	/**
	 * Déclare les routes REST de l'extension.
	 *
	 * @since 0.1.0
	 */
	public static function register_rest_routes(): void {
		( new Settings_Controller() )->register_routes();
	}

	/**
	 * Indique si la nouvelle interface est activée.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai si OUEB_WP_BACKUP_NEXT vaut true.
	 */
	public static function is_next_enabled(): bool {
		return defined( 'OUEB_WP_BACKUP_NEXT' ) && true === OUEB_WP_BACKUP_NEXT;
	}

	/**
	 * Renvoie le dossier de l'extension, sans barre oblique finale.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	public static function dir(): string {
		return dirname( self::$file );
	}

	/**
	 * Renvoie l'adresse du dossier de l'extension, sans barre oblique finale.
	 *
	 * @since 0.1.0
	 *
	 * @return string Adresse absolue.
	 */
	public static function url(): string {
		return untrailingslashit( plugins_url( '', self::$file ) );
	}
}
