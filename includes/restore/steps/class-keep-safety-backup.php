<?php
/**
 * Étape qui range la sauvegarde préalable.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore\Steps;

use Oueb\WpBackup\Engine\Logger;
use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Run_Repository;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Engine\Steps\Archive;
use Oueb\WpBackup\Engine\Steps\Finish;
use Oueb\WpBackup\Restore\Restore_State;
use Oueb\WpBackup\Storage\Storage_Repository;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Range l'archive de l'état actuel dans le dossier local, et l'inscrit dans
 * l'historique comme une sauvegarde ordinaire.
 *
 * Elle reste ainsi téléchargeable et restaurable si la restauration déçoit.
 * Seules les deux dernières sauvegardes préalables sont gardées.
 *
 * @since 0.1.0
 */
final class Keep_Safety_Backup implements Step {

	/**
	 * Identifiant des sauvegardes préalables dans l'historique.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const JOB_ID = 'pre-restore';

	/**
	 * Sauvegardes préalables gardées.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const KEEP = 2;

	/**
	 * Exécutions.
	 *
	 * @since 0.1.0
	 * @var Run_Repository
	 */
	private Run_Repository $runs;

	/**
	 * Crée l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Repository $runs Exécutions.
	 */
	public function __construct( Run_Repository $runs ) {
		$this->runs = $runs;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_safety';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Saving the backup of the current site', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws RuntimeException Si l'archive ne peut pas être rangée.
	 */
	public function run( Run_Context $context ): bool {
		$archives = $context->workspace->archives();
		$name     = (string) $context->get( 'name', '' );
		if ( '' === $name ) {
			$name = Finish::archive_name( self::JOB_ID, time(), $context->job->archive_format );
			$context->set( 'name', $name );
			$context->checkpoint( true );
		}

		$source = Archive::path( $context );
		$target = $archives . '/' . $name;
		if ( is_file( $source ) && ! rename( $source, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Déplacement dans le même dossier de travail.
			throw new RuntimeException( esc_html__( 'Cannot move the backup of the current site to the archives folder.', 'oueb-wp-backup' ) );
		}
		if ( ! is_file( $target ) ) {
			throw new RuntimeException( esc_html__( 'The backup of the current site is missing.', 'oueb-wp-backup' ) );
		}

		if ( 0 === (int) $context->get( 'run_id', 0 ) ) {
			$run               = $this->runs->create(
				self::JOB_ID,
				'restore',
				array(
					'contents' => array(
						'database'      => $context->job->include_database,
						'uploads'       => $context->job->include_uploads,
						'themes'        => $context->job->include_themes,
						'plugins'       => $context->job->include_plugins,
						'other_content' => $context->job->include_other_content,
						'core'          => $context->job->include_core,
						'encrypted'     => false,
					),
					'stored'   => array( Storage_Repository::LOCAL ),
				)
			);
			$run->status       = Run::SUCCESS;
			$run->started_at   = $context->run->started_at;
			$run->finished_at  = time();
			$run->progress     = 100;
			$run->archive_file = $name;
			$run->archive_size = (int) filesize( $target );
			$this->runs->save( $run );
			( new Logger( $run, $context->workspace->logs() ) )->info(
				/* translators: %d: restore number. */
				sprintf( __( 'Backup of the site made before the restore #%d.', 'oueb-wp-backup' ), $context->run->id )
			);

			$context->set( 'run_id', $run->id );
			Restore_State::set( $context, 'safety_run', $run->id );
			$context->checkpoint( true );
		}

		$old = Finish::outdated( array_map( 'basename', (array) glob( $archives . '/*' ) ), Finish::prefix( self::JOB_ID ), self::KEEP );
		foreach ( $old as $file ) {
			wp_delete_file( $archives . '/' . $file );
		}
		if ( array() !== $old ) {
			$this->runs->forget_archives( self::JOB_ID, $old );
		}

		$context->logger->info(
			sprintf(
				/* translators: 1: archive name, 2: size. */
				__( 'Backup of the current site saved: %1$s, %2$s. It appears in the list of backups.', 'oueb-wp-backup' ),
				$name,
				size_format( (int) filesize( $target ), 1 )
			)
		);

		return true;
	}
}
