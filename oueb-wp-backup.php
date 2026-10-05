<?php
/**
 * Plugin Name:       Oueb WP Backup
 * Plugin URI:        https://github.com/lagenceoueb/oueb-wp-backup
 * Description:       Sauvegarde et restauration de WordPress vers des hébergeurs européens. Fork de BackWPup 4.1.7.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            L'agence Oueb
 * Author URI:        https://lagenceoueb.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oueb-wp-backup
 * Domain Path:       /languages
 * Network:           true
 *
 * @package Oueb_WP_Backup
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/bootstrap.php';

register_deactivation_hook( __FILE__, array( Oueb\WpBackup\Plugin::class, 'deactivate' ) );
