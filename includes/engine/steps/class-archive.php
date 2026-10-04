<?php
/**
 * Étape de création de l'archive.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Archive\Archive_Writer;
use Oueb\WpBackup\Archive\Tar_Gz_Writer;
use Oueb\WpBackup\Archive\Zip_Writer;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Rassemble le manifeste, l'export de la base et les fichiers dans une archive.
 *
 * Les fichiers de la sauvegarde elle-même vont dans le dossier
 * « oueb-wp-backup-data/ » de l'archive. Les autres gardent leur chemin depuis
 * la racine de WordPress. L'archive est validée par lots de 64 Mo ou de 500
 * fichiers au plus : la position dans la liste n'avance qu'après un lot validé.
 *
 * @since 0.1.0
 */
final class Archive implements Step {

	/**
	 * Dossier des fichiers propres à la sauvegarde, dans l'archive.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const DATA_DIR = 'oueb-wp-backup-data';

	/**
	 * Octets ajoutés avant de valider un lot.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const BATCH_BYTES = 67108864;

	/**
	 * Fichiers ajoutés avant de valider un lot.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const BATCH_FILES = 500;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'archive';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Archive creation', 'oueb-wp-backup' );
	}

	/**
	 * Renvoie le chemin de l'archive en cours, dans le dossier temporaire.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @return string Chemin.
	 */
	public static function path( Run_Context $context ): string {
		return $context->tmp() . '/archive.' . $context->job->archive_format;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws \RuntimeException Si la liste des fichiers ne peut pas être lue.
	 */
	public function run( Run_Context $context ): bool {
		$tmp    = $context->tmp();
		$writer = 'zip' === $context->job->archive_format ? new Zip_Writer() : new Tar_Gz_Writer();
		$writer->open( self::path( $context ), (array) $context->get( 'checkpoint', array() ) );

		if ( ! $context->get( 'data_done' ) ) {
			$writer->add_file( $tmp . '/' . Manifest::FILE, self::DATA_DIR . '/' . Manifest::FILE );
			if ( $context->job->include_database ) {
				$writer->add_file( $tmp . '/' . Database_Dump::FILE, self::DATA_DIR . '/' . Database_Dump::FILE );
			}
			$context->set( 'checkpoint', $writer->commit() );
			$context->set( 'data_done', true );
			$context->set( 'offset', 0 );
			$context->set( 'files', 0 );
			$context->set( 'bytes', 0 );
		}

		$list = $tmp . '/' . File_List::FILE;
		if ( ! is_file( $list ) ) {
			$context->set( 'size', $writer->finish() );
			return true;
		}

		$total  = max( 1, (int) ( $context->run->state['steps']['files']['bytes'] ?? 1 ) );
		$reader = fopen( $list, 'rb' );
		if ( false === $reader ) {
			throw new \RuntimeException( esc_html__( 'Cannot read the list of files.', 'oueb-wp-backup' ) );
		}

		try {
			fseek( $reader, (int) $context->get( 'offset', 0 ) );
			$batch_bytes = 0;
			$batch_files = 0;
			$pending     = array(
				'files' => 0,
				'bytes' => 0,
			);

			while ( true ) {
				$line = fgets( $reader );
				if ( false === $line ) {
					break;
				}

				$parts = explode( "\t", rtrim( $line, "\n" ) );
				if ( 3 !== count( $parts ) ) {
					continue;
				}
				list( $name, $path ) = $parts;

				if ( is_readable( $path ) && ! is_dir( $path ) ) {
					$writer->add_file( $path, $name );
					$size         = (int) filesize( $path );
					$batch_bytes += $size;
					++$batch_files;
					++$pending['files'];
					$pending['bytes'] += $size;
				} else {
					$context->logger->warning(
						/* translators: %s: file path. */
						sprintf( __( 'File skipped, it disappeared or cannot be read anymore: %s', 'oueb-wp-backup' ), $path )
					);
				}

				if ( $batch_bytes >= self::BATCH_BYTES || $batch_files >= self::BATCH_FILES || $context->should_pause() ) {
					$this->commit_batch( $context, $writer, (int) ftell( $reader ), $pending, $total );
					$batch_bytes = 0;
					$batch_files = 0;
					$pending     = array(
						'files' => 0,
						'bytes' => 0,
					);

					if ( $context->should_pause() ) {
						return false;
					}
				}
			}

			$this->commit_batch( $context, $writer, (int) ftell( $reader ), $pending, $total );
		} finally {
			fclose( $reader );
		}

		$context->set( 'size', $writer->finish() );
		$context->logger->info(
			sprintf(
				/* translators: %s: archive size. */
				__( 'Archive created: %s.', 'oueb-wp-backup' ),
				size_format( (int) $context->get( 'size' ), 1 )
			)
		);

		return true;
	}

	/**
	 * Valide un lot et note la position atteinte dans la liste.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context        $context Contexte.
	 * @param Archive_Writer     $writer  Archive.
	 * @param int                $offset  Position dans la liste après le lot.
	 * @param array<string, int> $pending Fichiers et octets du lot.
	 * @param int                $total   Octets à archiver en tout.
	 */
	private function commit_batch( Run_Context $context, Archive_Writer $writer, int $offset, array $pending, int $total ): void {
		$context->set( 'checkpoint', $writer->commit() );
		$context->set( 'offset', $offset );
		$context->set( 'files', (int) $context->get( 'files', 0 ) + $pending['files'] );
		$context->set( 'bytes', (int) $context->get( 'bytes', 0 ) + $pending['bytes'] );
		$context->progress( (int) $context->get( 'bytes' ) / $total );
		$context->checkpoint();
	}
}
