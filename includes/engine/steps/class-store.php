<?php
/**
 * Étape d'envoi de l'archive vers les stockages.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Engine\Step_Failure;
use Oueb\WpBackup\Storage\Storage_Repository;
use Oueb\WpBackup\Storage\Transfer;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Envoie l'archive vers chaque stockage de la tâche, l'un après l'autre.
 *
 * Le stockage local passe en dernier : l'archive y est déplacée plutôt que
 * copiée. Un stockage qui échoue est retenté au passage suivant, puis
 * abandonné après le nombre d'essais des réglages ; les autres stockages
 * reçoivent quand même l'archive. Si aucun ne la reçoit, l'exécution échoue.
 *
 * @since 0.1.0
 */
final class Store implements Step {

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

	/**
	 * Nombre d'essais par stockage.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $retries;

	/**
	 * Crée l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param Storage_Repository $storages Stockages.
	 * @param int                $retries  Nombre d'essais par stockage.
	 */
	public function __construct( Storage_Repository $storages, int $retries ) {
		$this->storages = $storages;
		$this->retries  = max( 1, $retries );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'store';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Sending the archive', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws Step_Failure Si aucun stockage n'a reçu l'archive.
	 */
	public function run( Run_Context $context ): bool {
		$file = Archive::path( $context );

		if ( null === $context->get( 'targets' ) ) {
			if ( ! is_file( $file ) ) {
				throw new Step_Failure( esc_html__( 'The archive is missing.', 'oueb-wp-backup' ) );
			}
			clearstatcache( true, $file );
			$context->set( 'name', Finish::archive_name( $context->job->id, $context->run->started_at, $context->job->archive_format ) );
			$context->set( 'size', (int) filesize( $file ) );
			$context->set( 'targets', $this->targets( $context ) );
			$context->set( 'index', 0 );
			$context->set( 'attempts', 0 );
			$context->set( 'stored', array() );
		}

		$name    = (string) $context->get( 'name' );
		$targets = (array) $context->get( 'targets' );
		$count   = count( $targets );

		for ( $index = (int) $context->get( 'index' ); $index < $count; $index++ ) {
			$id      = (string) $targets[ $index ];
			$storage = $this->storages->instance( $id );
			$record  = $this->storages->get( $id );

			if ( null === $storage || null === $record ) {
				$context->logger->error(
					/* translators: %s: storage identifier. */
					sprintf( __( 'The storage %s no longer exists. The archive was not sent there.', 'oueb-wp-backup' ), $id )
				);
				$this->next( $context, $index );
				continue;
			}

			$transfer = new Transfer( $context, $id, Storage_Repository::LOCAL === $id && $index === $count - 1 );
			$transfer->place( $index, $count );

			try {
				$done = $storage->upload( $file, $name, $transfer );
			} catch ( Throwable $error ) {
				$attempts = (int) $context->get( 'attempts' ) + 1;
				$context->set( 'attempts', $attempts );
				if ( $attempts < $this->retries ) {
					$context->logger->warning(
						sprintf(
							/* translators: 1: storage name, 2: attempt number, 3: maximum attempts, 4: error message. */
							__( 'Sending to %1$s failed (attempt %2$d of %3$d): %4$s', 'oueb-wp-backup' ),
							$record['name'],
							$attempts,
							$this->retries,
							$error->getMessage()
						)
					);
					return false;
				}

				$context->logger->error(
					sprintf(
						/* translators: 1: storage name, 2: error message. */
						__( 'The archive was not sent to %1$s: %2$s', 'oueb-wp-backup' ),
						$record['name'],
						$error->getMessage()
					)
				);
				$this->next( $context, $index );
				continue;
			}

			if ( ! $done ) {
				return false;
			}

			$stored   = (array) $context->get( 'stored' );
			$stored[] = $id;
			$context->set( 'stored', $stored );
			$context->logger->info(
				/* translators: %s: storage name. */
				sprintf( __( 'Archive sent to %s.', 'oueb-wp-backup' ), $record['name'] )
			);
			$this->next( $context, $index );
			$context->checkpoint();

			if ( $index + 1 < $count && $context->should_pause() ) {
				return false;
			}
		}

		if ( array() === (array) $context->get( 'stored' ) ) {
			throw new Step_Failure( esc_html__( 'No storage received the archive.', 'oueb-wp-backup' ) );
		}

		return true;
	}

	/**
	 * Calcule les stockages à servir, le local en dernier.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @return string[] Identifiants.
	 */
	private function targets( Run_Context $context ): array {
		$targets = array_values( array_diff( $context->job->storages, array( Storage_Repository::LOCAL ) ) );
		if ( in_array( Storage_Repository::LOCAL, $context->job->storages, true ) ) {
			$targets[] = Storage_Repository::LOCAL;
		}

		return $targets;
	}

	/**
	 * Passe au stockage suivant.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @param int         $index   Rang du stockage terminé.
	 */
	private function next( Run_Context $context, int $index ): void {
		$context->set( 'index', $index + 1 );
		$context->set( 'attempts', 0 );
	}
}
