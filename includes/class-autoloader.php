<?php
/**
 * Autoloader des classes d'Oueb WP Backup.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup;

defined( 'ABSPATH' ) || exit;

/**
 * Charge les classes de l'espace de noms Oueb\WpBackup.
 *
 * Les fichiers suivent la convention des WordPress Coding Standards :
 * Oueb\WpBackup\Storage\S3_Storage se trouve dans
 * includes/storage/class-s3-storage.php. Une interface, un trait ou une
 * énumération prend le préfixe interface-, trait- ou enum-.
 *
 * @since 0.1.0
 */
final class Autoloader {

	/**
	 * Espace de noms racine.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PREFIX = 'Oueb\\WpBackup\\';

	/**
	 * Préfixes de fichier essayés, dans l'ordre.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const FILE_PREFIXES = array( 'class', 'interface', 'trait', 'enum' );

	/**
	 * Dossier racine des classes.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Enregistre l'autoloader.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base_dir Dossier racine des classes.
	 */
	public static function register( string $base_dir ): void {
		self::$base_dir = rtrim( $base_dir, '/\\' );
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Charge le fichier d'une classe de l'extension.
	 *
	 * @since 0.1.0
	 *
	 * @param string $class_name Nom complet de la classe.
	 */
	public static function load( string $class_name ): void {
		$file = self::find( $class_name );
		if ( null !== $file ) {
			require_once $file;
		}
	}

	/**
	 * Renvoie le chemin du fichier d'une classe, s'il existe.
	 *
	 * @since 0.1.0
	 *
	 * @param string $class_name Nom complet de la classe.
	 * @return string|null Chemin du fichier, null hors de l'espace de noms ou si rien ne correspond.
	 */
	public static function find( string $class_name ): ?string {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return null;
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::PREFIX ) ) );
		$name  = self::to_file_name( (string) array_pop( $parts ) );
		$dir   = self::$base_dir;
		foreach ( $parts as $part ) {
			$dir .= '/' . self::to_file_name( $part );
		}

		foreach ( self::FILE_PREFIXES as $prefix ) {
			$file = $dir . '/' . $prefix . '-' . $name . '.php';
			if ( is_readable( $file ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Convertit un nom de classe ou d'espace de noms en nom de fichier.
	 *
	 * Settings_Controller devient settings-controller, Rest devient rest.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom de classe ou segment d'espace de noms.
	 * @return string Nom de fichier, en minuscules avec des tirets.
	 */
	private static function to_file_name( string $name ): string {
		return strtolower( str_replace( '_', '-', $name ) );
	}
}
