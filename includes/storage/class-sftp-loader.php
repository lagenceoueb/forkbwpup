<?php
/**
 * Chargement de phpseclib.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use Oueb\WpBackup\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Charge phpseclib à la demande, seule bibliothèque tierce de l'extension.
 *
 * @since 0.1.0
 */
final class Sftp_Loader {

	/**
	 * Charge l'autoloader de Composer si phpseclib n'est pas encore disponible.
	 *
	 * @since 0.1.0
	 */
	public static function load(): void {
		if ( ! class_exists( '\phpseclib3\Net\SFTP' ) && is_file( Plugin::dir() . '/vendor/autoload.php' ) ) {
			require_once Plugin::dir() . '/vendor/autoload.php';
		}
	}
}
