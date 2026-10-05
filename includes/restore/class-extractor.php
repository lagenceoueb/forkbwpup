<?php
/**
 * Extraction reprenable d'une archive.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore;

use Oueb\WpBackup\Archive\Archive_Reader;
use Oueb\WpBackup\Archive\Tar_Reader;
use Oueb\WpBackup\Archive\Zip_Reader;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Storage\Resumable_File;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Extrait les fichiers d'une archive en plusieurs passages.
 *
 * L'état de l'étape garde le point de reprise de l'entrée en cours, les
 * octets déjà écrits et si l'entrée est finie. Chaque fichier s'écrit à côté
 * de sa cible, puis la remplace d'un coup : une extension en service ne voit
 * jamais de fichier PHP à moitié écrit.
 *
 * @since 0.1.0
 */
final class Extractor {

	/**
	 * Suffixe des fichiers en cours d'écriture.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PART = '.oueb-part';

	/**
	 * Octets lus à la fois.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 1048576;

	/**
	 * Octets écrits entre deux points de sauvegarde, dans un gros fichier.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const SAVE_EVERY = 16777216;

	/**
	 * Formats d'archive reconnus, par extension de fichier.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const EXTENSIONS = array( '.zip', '.tar.gz', '.tgz', '.tar' );

	/**
	 * Crée le lecteur qui convient au nom d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom ou chemin de l'archive, sans .enc.
	 * @return Archive_Reader Lecteur.
	 *
	 * @throws RuntimeException Si le format n'est pas reconnu.
	 */
	public static function reader( string $name ): Archive_Reader {
		$name = strtolower( $name );
		if ( '.zip' === substr( $name, -4 ) ) {
			return new Zip_Reader();
		}
		if ( '.tar.gz' === substr( $name, -7 ) || '.tgz' === substr( $name, -4 ) ) {
			return new Tar_Reader( true );
		}
		if ( '.tar' === substr( $name, -4 ) ) {
			return new Tar_Reader( false );
		}

		throw new RuntimeException( esc_html__( 'This archive format is not supported. Use a zip, tar.gz or tar archive.', 'oueb-wp-backup' ) );
	}

	/**
	 * Indique si un nom de fichier désigne une archive lisible, chiffrée ou non.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom du fichier.
	 * @return bool Vrai pour zip, tar.gz, tgz et tar, avec ou sans .enc.
	 */
	public static function supports( string $name ): bool {
		$name = strtolower( $name );
		if ( '.enc' === substr( $name, -4 ) ) {
			$name = substr( $name, 0, -4 );
		}
		foreach ( self::EXTENSIONS as $extension ) {
			if ( substr( $name, -strlen( $extension ) ) === $extension ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Vérifie et normalise le nom d'une entrée.
	 *
	 * Refuse les chemins absolus, les remontées « .. » et les octets nuls :
	 * une archive piégée ne doit rien écrire hors de sa destination.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom dans l'archive.
	 * @return string|null Nom relatif propre, null s'il est dangereux ou vide.
	 */
	public static function safe_name( string $name ): ?string {
		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || false !== strpos( $name, "\0" ) || '/' === $name[0] || preg_match( '#^[a-z]:#i', $name ) ) {
			return null;
		}

		$parts = array();
		foreach ( explode( '/', $name ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return null;
			}
			$parts[] = $part;
		}

		return array() === $parts ? null : implode( '/', $parts );
	}

	/**
	 * Extrait les entrées de l'archive, jusqu'à la fin ou jusqu'à la limite de temps.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'étape.
	 * @param string      $archive Archive en clair.
	 * @param string      $name    Nom de l'archive, qui donne son format.
	 * @param callable    $target  Reçoit l'entrée, renvoie le chemin cible ou null pour la sauter.
	 * @param callable    $stop    Reçoit l'entrée, renvoie vrai pour arrêter là l'extraction.
	 * @return bool Vrai quand l'extraction est finie, faux s'il faut un autre passage.
	 *
	 * @throws RuntimeException Si l'archive est abîmée ou un fichier ne peut pas être écrit.
	 */
	public static function extract( Run_Context $context, string $archive, string $name, callable $target, ?callable $stop = null ): bool {
		$reader   = self::reader( $name );
		$resuming = null !== $context->get( 'position' );
		$reader->open( $archive, (array) $context->get( 'position', array() ) );

		try {
			while ( true ) {
				$entry = $reader->next();
				if ( null === $entry || ( null !== $stop && $stop( $entry ) ) ) {
					return true;
				}

				$written = 0;
				if ( $resuming ) {
					$resuming = false;
					// L'entrée notée au dernier passage était finie : on passe à la suivante.
					if ( $context->get( 'done' ) ) {
						continue;
					}
					$written = (int) $context->get( 'written', 0 );
				}

				$context->set( 'position', $reader->position() );
				$context->set( 'done', false );
				$context->set( 'written', $written );

				$path = 'file' === $entry['type'] ? $target( $entry ) : null;
				if ( null !== $path && ! self::write( $context, $reader, $entry, (string) $path, $written ) ) {
					return false;
				}

				$context->set( 'done', true );
				$context->set( 'written', 0 );
				$context->set( 'bytes', (int) $context->get( 'bytes', 0 ) + (int) $entry['size'] );
				if ( null !== $path ) {
					$context->set( 'entries', (int) $context->get( 'entries', 0 ) + 1 );
				}
				$context->checkpoint();
				Maintenance::keep();

				if ( $context->should_pause() ) {
					return false;
				}
			}
		} finally {
			$reader->close();
		}
	}

	/**
	 * Écrit une entrée dans sa cible.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context                                              $context Contexte.
	 * @param Archive_Reader                                           $reader  Lecteur, placé au début des données.
	 * @param array{name: string, type: string, size: int, mtime: int} $entry   Entrée.
	 * @param string                                                   $path    Cible.
	 * @param int                                                      $written Octets déjà écrits lors d'un passage précédent.
	 * @return bool Vrai si le fichier est complet, faux s'il faut un autre passage.
	 *
	 * @throws RuntimeException Si le fichier ne peut pas être écrit.
	 */
	private static function write( Run_Context $context, Archive_Reader $reader, array $entry, string $path, int $written ): bool {
		if ( is_dir( $path ) ) {
			$context->logger->warning(
				/* translators: %s: file path. */
				sprintf( __( 'File skipped, a folder has the same name: %s', 'oueb-wp-backup' ), $path )
			);
			return true;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: folder path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot create the folder %s. Check its permissions.', 'oueb-wp-backup' ), $dir ) ) );
		}

		$part   = $path . self::PART;
		$handle = Resumable_File::open( $part, $written );

		try {
			// Reprise au milieu d'un gros fichier : les octets déjà écrits sont relus et sautés.
			$skip = $written;
			while ( $skip > 0 ) {
				$data = $reader->read( (int) min( self::CHUNK, $skip ) );
				if ( '' === $data ) {
					break;
				}
				$skip -= strlen( $data );
			}

			$unsaved = 0;
			while ( true ) {
				$data = $reader->read( self::CHUNK );
				if ( '' === $data ) {
					break;
				}
				Resumable_File::write( $handle, $data );
				$written += strlen( $data );
				$unsaved += strlen( $data );

				if ( $unsaved >= self::SAVE_EVERY ) {
					$unsaved = 0;
					$context->set( 'written', Resumable_File::commit( $handle ) );
					$context->checkpoint();
					Maintenance::keep();
					if ( $written < $entry['size'] && $context->should_pause() ) {
						return false;
					}
				}
			}
			Resumable_File::commit( $handle );
		} finally {
			fclose( $handle );
		}

		if ( $written !== (int) $entry['size'] ) {
			wp_delete_file( $part );
			/* translators: %s: file name in the archive. */
			throw new RuntimeException( esc_html( sprintf( __( 'The archive is damaged: %s is incomplete.', 'oueb-wp-backup' ), $entry['name'] ) ) );
		}

		if ( ! rename( $part, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Remplacement d'un coup, sans passer par WP_Filesystem.
			wp_delete_file( $part );
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot write %s. Check its permissions.', 'oueb-wp-backup' ), $path ) ) );
		}
		if ( $entry['mtime'] > 0 ) {
			touch( $path, (int) $entry['mtime'] );
		}

		return true;
	}
}
