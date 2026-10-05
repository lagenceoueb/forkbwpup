<?php
/**
 * Étape de restauration des fichiers.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Engine\Steps\Archive;
use Oueb\WpBackup\Engine\Steps\File_List;
use Oueb\WpBackup\Plugin;
use Oueb\WpBackup\Restore\Extractor;
use Oueb\WpBackup\Restore\Maintenance;
use Oueb\WpBackup\Restore\Restore_State;

defined( 'ABSPATH' ) || exit;

/**
 * Remet les fichiers de l'archive à leur place.
 *
 * Chaque nom de l'archive commence par le préfixe d'un emplacement noté
 * dans le manifeste (wp-content/uploads…) : le fichier va au même emplacement
 * sur ce site, même s'il est rangé ailleurs. Les fichiers absents de l'archive
 * ne sont pas supprimés.
 *
 * Quelques fichiers ne sont jamais remplacés : wp-config.php, qui porte les
 * accès à la base de ce serveur, l'extension elle-même, qui tourne pendant
 * la restauration, son dossier de travail, la base SQLite en service et le
 * fichier de maintenance.
 *
 * @since 0.1.0
 */
final class Restore_Files implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_files';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Restoring the files', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 */
	public function run( Run_Context $context ): bool {
		if ( ! Restore_State::get( $context, 'files' ) ) {
			return true;
		}

		$map       = self::map( (array) Restore_State::get( $context, 'roots', array() ), File_List::locations() );
		$protected = self::protected_paths( $context->workspace->root() );
		$total     = max( 1, (int) Restore_State::get( $context, 'files_bytes', 1 ) );
		$skipped   = (array) $context->get( 'skipped', array() );

		$done = Extractor::extract(
			$context,
			(string) Restore_State::get( $context, 'file' ),
			(string) Restore_State::get( $context, 'name' ),
			static function ( array $entry ) use ( $context, $map, $protected, $total, &$skipped ): ?string {
				$context->progress( (int) $context->get( 'bytes', 0 ) / $total );
				Maintenance::keep();

				$name = Extractor::safe_name( $entry['name'] );
				if ( null === $name ) {
					$context->logger->warning(
						/* translators: %s: file name in the archive. */
						sprintf( __( 'File skipped, its name is not safe: %s', 'oueb-wp-backup' ), $entry['name'] )
					);
					return null;
				}
				if ( 0 === strpos( $name, Archive::DATA_DIR . '/' ) ) {
					return null;
				}

				$path = self::target( $name, $map );
				foreach ( $protected as $kept ) {
					if ( $path === $kept || 0 === strpos( $path, $kept . '/' ) ) {
						$skipped[ $kept ] = true;
						$context->set( 'skipped', $skipped );
						return null;
					}
				}

				return $path;
			}
		);
		if ( ! $done ) {
			return false;
		}

		foreach ( array_keys( $skipped ) as $kept ) {
			$context->logger->info(
				/* translators: %s: file or folder path. */
				sprintf( __( 'Kept as it is on this site: %s', 'oueb-wp-backup' ), $kept )
			);
		}
		$context->logger->info(
			sprintf(
				/* translators: 1: number of files, 2: total size. */
				__( 'Files restored: %1$s, %2$s.', 'oueb-wp-backup' ),
				number_format_i18n( (int) $context->get( 'entries', 0 ) ),
				size_format( (int) $context->get( 'bytes', 0 ), 1 )
			)
		);

		return true;
	}

	/**
	 * Associe chaque préfixe de l'archive à un dossier de ce site.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $prefixes  Préfixes de l'archive, par nature de contenu.
	 * @param array<string, string> $locations Dossiers de ce site, par nature de contenu.
	 * @return array<string, string> Dossiers, par préfixe ; les plus longs préfixes en premier.
	 */
	public static function map( array $prefixes, array $locations ): array {
		$map = array();
		foreach ( $prefixes as $kind => $prefix ) {
			if ( isset( $locations[ $kind ] ) ) {
				$map[ trim( (string) $prefix, '/' ) ] = $locations[ $kind ];
			}
		}
		if ( ! isset( $map[''] ) && isset( $locations['core'] ) ) {
			$map[''] = $locations['core'];
		}
		uksort( $map, static fn( $a, $b ): int => strlen( (string) $b ) <=> strlen( (string) $a ) );

		return $map;
	}

	/**
	 * Calcule le chemin d'un fichier de l'archive sur ce site.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $name Nom propre dans l'archive.
	 * @param array<string, string> $map  Dossiers, par préfixe, les plus longs en premier.
	 * @return string Chemin absolu.
	 */
	public static function target( string $name, array $map ): string {
		foreach ( $map as $prefix => $dir ) {
			$prefix = (string) $prefix;
			if ( '' === $prefix ) {
				return $dir . '/' . $name;
			}
			if ( $name === $prefix || 0 === strpos( $name, $prefix . '/' ) ) {
				return $dir . substr( $name, strlen( $prefix ) );
			}
		}

		return untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/' . $name;
	}

	/**
	 * Renvoie les fichiers et dossiers jamais remplacés.
	 *
	 * @since 0.1.0
	 *
	 * @param string $workspace Dossier de travail de l'extension.
	 * @return string[] Chemins absolus.
	 */
	public static function protected_paths( string $workspace ): array {
		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$paths   = array(
			$abspath . '/wp-config.php',
			dirname( $abspath ) . '/wp-config.php',
			$abspath . '/.maintenance',
			untrailingslashit( wp_normalize_path( Plugin::dir() ) ),
			untrailingslashit( wp_normalize_path( $workspace ) ),
		);

		/**
		 * Filtre les fichiers et dossiers que la restauration ne remplace jamais.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $paths Chemins absolus.
		 */
		return array_values( (array) apply_filters( 'oueb_wp_backup_restore_protected_paths', array_merge( $paths, File_List::live_database_files() ) ) );
	}
}
