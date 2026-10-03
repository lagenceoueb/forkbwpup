<?php
/**
 * Page d'administration React.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Admin;

use Oueb\WpBackup\Plugin;
use Oueb\WpBackup\Rest\Settings_Controller;
use Oueb\WpBackup\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Déclare la page « Sauvegardes » et y charge l'application React.
 *
 * Toute l'interface est rendue côté navigateur à partir de dist/, construit
 * par @wordpress/scripts. La page PHP ne fournit que le point de montage.
 *
 * @since 0.1.0
 */
final class Admin_Page {

	/**
	 * Identifiant de la page.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SLUG = 'oueb-wp-backup';

	/**
	 * Identifiant du script et de la feuille de style.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const HANDLE = 'oueb-wp-backup-admin';

	/**
	 * Suffixe de la page renvoyé par add_menu_page().
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private static string $hook_suffix = '';

	/**
	 * Branche la page dans le menu du site, ou du réseau en multisite.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Ajoute l'entrée de menu.
	 *
	 * @since 0.1.0
	 */
	public static function add_menu(): void {
		self::$hook_suffix = (string) add_menu_page(
			__( 'Backups', 'oueb-wp-backup' ),
			__( 'Backups', 'oueb-wp-backup' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-backup',
			80
		);
	}

	/**
	 * Charge l'application sur la page de l'extension seulement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Page affichée.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		$asset_file = Plugin::dir() . '/dist/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			Plugin::url() . '/dist/index.js',
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::HANDLE, 'oueb-wp-backup', Plugin::dir() . '/languages' );

		wp_add_inline_script(
			self::HANDLE,
			'window.ouebWpBackup = ' . wp_json_encode(
				array(
					'version'   => Plugin::VERSION,
					'restPath'  => '/' . Settings_Controller::REST_NAMESPACE,
					'agencyUrl' => 'https://lagenceoueb.tech',
				)
			) . ';',
			'before'
		);

		wp_enqueue_style( self::HANDLE, Plugin::url() . '/dist/style-index.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * Affiche le point de montage de l'application.
	 *
	 * @since 0.1.0
	 */
	public static function render(): void {
		echo '<div class="wrap"><div id="oueb-wp-backup-root"></div>';
		echo '<noscript><p>' . esc_html__( 'The backup interface needs JavaScript.', 'oueb-wp-backup' ) . '</p></noscript></div>';
	}
}
