<?php
/**
 * Amorce des tests unitaires : doublures des classes de WordPress et autoloader.
 *
 * Les fonctions de WordPress sont simulées test par test avec Brain Monkey.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );


require dirname( __DIR__, 2 ) . '/tools/vendor/autoload.php';
require __DIR__ . '/doubles/class-wp-error.php';
require __DIR__ . '/doubles/class-wp-rest.php';
require dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';
require __DIR__ . '/class-test-case.php';

Oueb\WpBackup\Autoloader::register( dirname( __DIR__, 2 ) . '/includes' );
