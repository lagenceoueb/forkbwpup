<?php
/**
 * Étape de récupération de l'archive à restaurer.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Engine\Step_Failure;
use Oueb\WpBackup\Restore\Restore_State;
use Oueb\WpBackup\Storage\Resumable_File;
use Oueb\WpBackup\Storage\Storage_Repository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Rend l'archive disponible sur le serveur.
 *
 * Une archive du dossier local ou envoyée depuis le navigateur sert telle
 * quelle. Une archive distante est téléchargée par morceaux de 8 Mo, avec
 * reprise, depuis le premier stockage qui la fournit.
 *
 * @since 0.1.0
 */
final class Fetch implements Step {

	/**
	 * Octets lus à la fois sur un stockage distant.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 8388608;

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

	/**
	 * Crée l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param Storage_Repository $storages Stockages.
	 */
	public function __construct( Storage_Repository $storages ) {
		$this->storages = $storages;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_fetch';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Getting the archive', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws Step_Failure Si l'archive n'est plus disponible.
	 */
	public function run( Run_Context $context ): bool {
		$source = (array) Restore_State::get( $context, 'source', array() );
		$name   = basename( (string) ( $source['name'] ?? '' ) );

		// Nom de l'archive en clair : il donne son format aux étapes suivantes.
		Restore_State::set( $context, 'name', (string) preg_replace( '/\.enc$/i', '', $name ) );

		if ( 'file' === ( $source['type'] ?? '' ) ) {
			$path = (string) ( $source['path'] ?? '' );
			if ( ! is_file( $path ) ) {
				throw new Step_Failure( esc_html__( 'The archive to restore no longer exists on the server.', 'oueb-wp-backup' ) );
			}
			Restore_State::set( $context, 'file', $path );
			$context->logger->info(
				/* translators: %s: archive name. */
				sprintf( __( 'Archive to restore: %s, found on the server.', 'oueb-wp-backup' ), $name )
			);
			return true;
		}

		return $this->download( $context, $source, $name );
	}

	/**
	 * Télécharge l'archive depuis un stockage distant.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context          $context Contexte.
	 * @param array<string, mixed> $source  Source : stockages possibles, nom et taille.
	 * @param string               $name    Nom de l'archive.
	 * @return bool Vrai quand le téléchargement est fini.
	 *
	 * @throws \RuntimeException Si le téléchargement s'arrête avant la fin, ou Step_Failure si aucun stockage ne fournit l'archive.
	 */
	private function download( Run_Context $context, array $source, string $name ): bool {
		$target = Restore_State::dir( $context ) . '/' . $name;

		$storage_id = (string) $context->get( 'storage', '' );
		$storage    = '' === $storage_id ? null : $this->storages->instance( $storage_id );
		if ( null === $storage ) {
			list( $storage_id, $storage ) = $this->find( $context, (array) ( $source['storages'] ?? array() ), $name );
			$context->set( 'storage', $storage_id );
			$context->set( 'offset', 0 );
		}

		$total  = (int) ( $source['size'] ?? 0 );
		$offset = (int) $context->get( 'offset', 0 );
		$handle = Resumable_File::open( $target, $offset );

		try {
			while ( $total <= 0 || $offset < $total ) {
				$chunk = $storage->read( $name, $offset, self::CHUNK );
				Resumable_File::write( $handle, $chunk );
				$offset = Resumable_File::commit( $handle );
				$context->set( 'offset', $offset );
				$context->progress( $total > 0 ? $offset / $total : 0.5 );
				$context->checkpoint();

				if ( strlen( $chunk ) < self::CHUNK ) {
					break;
				}
				if ( $context->should_pause() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle );
		}

		if ( $total > 0 && $offset !== $total ) {
			throw new \RuntimeException(
				/* translators: 1: bytes received, 2: bytes expected. */
				esc_html( sprintf( __( 'The download stopped early: %1$s bytes received out of %2$s.', 'oueb-wp-backup' ), $offset, $total ) )
			);
		}

		Restore_State::set( $context, 'file', $target );
		Restore_State::set( $context, 'downloaded', true );
		$context->logger->info(
			/* translators: 1: archive name, 2: size. */
			sprintf( __( 'Archive %1$s downloaded: %2$s.', 'oueb-wp-backup' ), $name, size_format( $offset, 1 ) )
		);

		return true;
	}

	/**
	 * Trouve le premier stockage qui fournit l'archive.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @param string[]    $ids     Stockages à essayer, dans l'ordre.
	 * @param string      $name    Nom de l'archive.
	 * @return array{0: string, 1: \Oueb\WpBackup\Storage\Storage} Identifiant et stockage.
	 *
	 * @throws Step_Failure Si aucun ne la fournit.
	 */
	private function find( Run_Context $context, array $ids, string $name ): array {
		foreach ( $ids as $id ) {
			$id      = (string) $id;
			$storage = Storage_Repository::LOCAL === $id ? null : $this->storages->instance( $id );
			if ( null === $storage ) {
				continue;
			}
			try {
				$storage->read( $name, 0, 1 );
			} catch ( Throwable $error ) {
				$record = $this->storages->get( $id );
				$context->logger->warning(
					/* translators: 1: storage name, 2: error message. */
					sprintf( __( 'The archive cannot be read from %1$s: %2$s', 'oueb-wp-backup' ), null === $record ? $id : $record['name'], $error->getMessage() )
				);
				continue;
			}

			return array( $id, $storage );
		}

		throw new Step_Failure( esc_html__( 'The archive to restore is no longer available in any storage.', 'oueb-wp-backup' ) );
	}
}
