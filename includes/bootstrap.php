<?php
/**
 * Charge le noyau d'Oueb WP Backup.
 *
 * Appelé par le fichier principal de l'extension, qui passe son propre chemin.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-autoloader.php';

Oueb\WpBackup\Autoloader::register( __DIR__ );
Oueb\WpBackup\Plugin::boot( dirname( __DIR__ ) . '/oueb-wp-backup.php' );
