<?php
/**
 * Étape de recensement des fichiers.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Storage\Resumable_File;

defined( 'ABSPATH' ) || exit;

/**
 * Recense les fichiers à sauvegarder dans files.txt.
 *
 * Une ligne par fichier : nom dans l'archive, chemin absolu et taille,
 * séparés par des tabulations. Le parcours garde sa pile de dossiers dans
 * l'état de l'étape et reprend où il s'est arrêté.
 *
 * Les liens symboliques ne sont pas suivis : ils peuvent former des boucles
 * ou sortir du site.
 *
 * @since 0.1.0
 */
final class File_List implements Step {

	/**
	 * Nom du fichier produit, dans le dossier temporaire.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const FILE = 'files.txt';

	/**
	 * Noms de dossiers jamais sauvegardés, où qu'ils soient.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const SKIPPED_DIR_NAMES = array( '.git', '.svn', '.hg', 'node_modules' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'files';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'List of files', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 */
	public function run( Run_Context $context ): bool {
		if ( null === $context->get( 'roots' ) ) {
			$roots = self::roots( $context->job );
			$context->set( 'roots', $roots );
			$context->set( 'excluded', self::excluded_paths( $context->job, $context->workspace->root() ) );
			$context->set( 'stack', array_map( static fn( array $root ): array => array( $root['path'], $root['base'] ), array_reverse( $roots ) ) );
			$context->set( 'size', 0 );
			$context->set( 'files', 0 );
			$context->set( 'bytes', 0 );
			$context->set( 'dirs', 0 );
			$context->set( 'skipped', 0 );
		}

		$excluded = array_flip( (array) $context->get( 'excluded' ) );
		$patterns = $context->job->exclude;
		$stack    = (array) $context->get( 'stack' );
		$handle   = Resumable_File::open( $context->tmp() . '/' . self::FILE, (int) $context->get( 'size' ) );

		try {
			while ( array() !== $stack ) {
				list( $dir, $base ) = array_pop( $stack );
				$entries            = is_readable( $dir ) ? scandir( $dir ) : false;

				if ( false === $entries ) {
					$context->logger->warning(
						/* translators: %s: folder path. */
						sprintf( __( 'Folder skipped, it cannot be read: %s', 'oueb-wp-backup' ), $dir )
					);
				} else {
					$subdirs = array();
					foreach ( $entries as $entry ) {
						if ( '.' === $entry || '..' === $entry ) {
							continue;
						}

						$path = $dir . '/' . $entry;
						$name = self::relative( $path, $base );

						if ( isset( $excluded[ $path ] ) ) {
							continue;
						}

						if ( is_link( $path ) ) {
							$context->set( 'skipped', (int) $context->get( 'skipped' ) + 1 );
							continue;
						}

						if ( is_dir( $path ) ) {
							if ( ! in_array( $entry, self::SKIPPED_DIR_NAMES, true ) && ! self::matches( $name, $patterns ) ) {
								$subdirs[] = array( $path, $base );
							}
							continue;
						}

						if ( self::matches( $name, $patterns ) ) {
							continue;
						}

						if ( false !== strpbrk( $name, "\t\n\r" ) ) {
							$context->logger->warning(
								/* translators: %s: file path. */
								sprintf( __( 'File skipped, its name contains a tab or a line break: %s', 'oueb-wp-backup' ), $path )
							);
							continue;
						}

						if ( ! is_readable( $path ) ) {
							$context->logger->warning(
								/* translators: %s: file path. */
								sprintf( __( 'File skipped, it cannot be read: %s', 'oueb-wp-backup' ), $path )
							);
							continue;
						}

						$size = (int) filesize( $path );
						Resumable_File::write( $handle, $name . "\t" . $path . "\t" . $size . "\n" );
						$context->set( 'files', (int) $context->get( 'files' ) + 1 );
						$context->set( 'bytes', (int) $context->get( 'bytes' ) + $size );
					}

					// Les sous-dossiers sortent de la pile dans l'ordre alphabétique.
					foreach ( array_reverse( $subdirs ) as $subdir ) {
						$stack[] = $subdir;
					}
				}

				$context->set( 'dirs', (int) $context->get( 'dirs' ) + 1 );
				$context->set( 'stack', $stack );
				$context->set( 'size', Resumable_File::commit( $handle ) );
				$context->progress( (int) $context->get( 'dirs' ) / ( (int) $context->get( 'dirs' ) + count( $stack ) ) );
				$context->checkpoint();

				if ( array() !== $stack && $context->should_pause() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle );
		}

		if ( (int) $context->get( 'skipped' ) > 0 ) {
			$context->logger->info(
				sprintf(
					/* translators: %d: number of symbolic links. */
					_n( '%d symbolic link was not followed.', '%d symbolic links were not followed.', (int) $context->get( 'skipped' ), 'oueb-wp-backup' ),
					(int) $context->get( 'skipped' )
				)
			);
		}

		$context->logger->info(
			sprintf(
				/* translators: 1: number of files, 2: total size. */
				__( 'Files to back up: %1$s, %2$s.', 'oueb-wp-backup' ),
				number_format_i18n( (int) $context->get( 'files' ) ),
				size_format( (int) $context->get( 'bytes' ), 1 )
			)
		);

		return true;
	}

	/**
	 * Calcule les dossiers de départ selon le contenu de la tâche.
	 *
	 * Les extensions, les thèmes et les médias sont des dossiers à part : le
	 * reste de wp-content les exclut, pour qu'aucun fichier ne soit compté deux fois.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job Tâche.
	 * @return array<int, array{path: string, base: string}> Dossiers, avec la base des noms dans l'archive.
	 */
	public static function roots( Job $job ): array {
		$candidates = array();
		if ( $job->include_core ) {
			$candidates[] = ABSPATH;
		}
		if ( $job->include_other_content ) {
			$candidates[] = WP_CONTENT_DIR;
		}
		if ( $job->include_plugins ) {
			$candidates[] = WP_PLUGIN_DIR;
		}
		if ( $job->include_themes ) {
			$candidates[] = get_theme_root();
		}
		if ( $job->include_uploads ) {
			$candidates[] = self::uploads_dir();
		}

		$roots = array();
		$seen  = array();
		foreach ( $candidates as $path ) {
			$path = untrailingslashit( wp_normalize_path( (string) $path ) );
			if ( '' === $path || isset( $seen[ $path ] ) || ! is_dir( $path ) ) {
				continue;
			}
			$seen[ $path ] = true;
			$roots[]       = array(
				'path' => $path,
				'base' => self::base_for( $path ),
			);
		}

		return $roots;
	}

	/**
	 * Renvoie les emplacements du site, par nature de contenu.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Chemins absolus, sans barre oblique finale : core, content, plugins, themes, uploads.
	 */
	public static function locations(): array {
		return array(
			'core'    => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'content' => untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
			'plugins' => untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ),
			'themes'  => untrailingslashit( wp_normalize_path( get_theme_root() ) ),
			'uploads' => self::uploads_dir(),
		);
	}

	/**
	 * Renvoie le préfixe des noms dans l'archive pour chaque emplacement du site.
	 *
	 * La restauration s'en sert pour remettre chaque fichier à sa place, même
	 * si le site de destination range wp-content ailleurs.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Préfixes, par nature de contenu ; chaîne vide pour la racine.
	 */
	public static function archive_prefixes(): array {
		$prefixes = array();
		foreach ( self::locations() as $kind => $path ) {
			$prefixes[ $kind ] = self::relative( $path, self::base_for( $path ) );
		}

		return $prefixes;
	}

	/**
	 * Calcule les dossiers et fichiers exclus, en chemins absolus.
	 *
	 * @since 0.1.0
	 *
	 * @param Job    $job       Tâche.
	 * @param string $workspace Dossier de travail de l'extension.
	 * @return string[] Dossiers et fichiers exclus.
	 */
	public static function excluded_paths( Job $job, string $workspace ): array {
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$uploads = self::uploads_dir();

		$excluded = array(
			untrailingslashit( wp_normalize_path( $workspace ) ),
			$content . '/cache',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$content . '/ai1wm-backups',
			$content . '/updraft',
			$content . '/backups-dup-lite',
			$content . '/backups-dup-pro',
			$content . '/wpvividbackups',
			$uploads . '/wp-staging',
			$uploads . '/cache',
		);

		// Les dossiers traités à part ne sont pas parcourus deux fois.
		$excluded[] = untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$excluded[] = untrailingslashit( wp_normalize_path( get_theme_root() ) );
		$excluded[] = $uploads;
		$excluded[] = $content;

		// La base SQLite en service est déjà exportée en SQL. Ouvrir puis fermer
		// son fichier dans ce processus libérerait les verrous de SQLite, qui
		// sont propres au processus : des écritures concurrentes seraient perdues.
		foreach ( self::live_database_files() as $file ) {
			$excluded[] = $file;
		}

		// Dossiers d'archives de BackWPup dans les médias, suffixés d'un jeton.
		foreach ( (array) glob( $uploads . '/backwpup-*', GLOB_ONLYDIR ) as $dir ) {
			$excluded[] = untrailingslashit( wp_normalize_path( (string) $dir ) );
		}

		/**
		 * Filtre les dossiers et fichiers exclus de la sauvegarde.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $excluded Dossiers et fichiers exclus, en chemins absolus.
		 * @param Job      $job      Tâche.
		 */
		$excluded = (array) apply_filters( 'oueb_wp_backup_excluded_paths', $excluded, $job );

		return array_values( array_unique( array_map( 'strval', $excluded ) ) );
	}

	/**
	 * Renvoie les fichiers de la base SQLite en service, s'il y en a une.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Base, journal et fichiers WAL, en chemins absolus.
	 */
	public static function live_database_files(): array {
		if ( defined( 'FQDB' ) ) {
			$database = (string) FQDB;
		} elseif ( defined( 'DB_DIR' ) && defined( 'DB_FILE' ) ) {
			$database = trailingslashit( (string) DB_DIR ) . DB_FILE;
		} else {
			return array();
		}

		$database = wp_normalize_path( $database );

		return array( $database, $database . '-wal', $database . '-shm', $database . '-journal' );
	}

	/**
	 * Indique si un nom correspond à un motif d'exclusion de la tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name     Nom relatif à la racine de WordPress.
	 * @param string[] $patterns Motifs, avec * et ?.
	 * @return bool Vrai si le nom est exclu.
	 */
	public static function matches( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( fnmatch( $pattern, $name ) || fnmatch( $pattern . '/*', $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calcule la base des noms d'un dossier de départ.
	 *
	 * Un dossier sous la racine de WordPress garde son chemin depuis cette
	 * racine (wp-content/uploads/…). Un dossier ailleurs garde son propre nom.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Dossier de départ.
	 * @return string Base, sans barre oblique finale.
	 */
	private static function base_for( string $path ): string {
		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );

		return 0 === strpos( $path . '/', $abspath . '/' ) ? $abspath : dirname( $path );
	}

	/**
	 * Calcule le nom d'un chemin dans l'archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Chemin absolu.
	 * @param string $base Base.
	 * @return string Nom relatif.
	 */
	private static function relative( string $path, string $base ): string {
		return ltrim( substr( $path, strlen( $base ) ), '/' );
	}

	/**
	 * Renvoie le dossier des médias du site principal.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	private static function uploads_dir(): string {
		$switched = is_multisite() && ! is_main_site();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		$uploads = wp_upload_dir( null, false );
		if ( $switched ) {
			restore_current_blog();
		}

		return untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	}
}
