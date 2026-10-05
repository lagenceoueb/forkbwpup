<?php
/**
 * Étape de déchiffrement de l'archive à restaurer.
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
use Oueb\WpBackup\Security\Archive_Cipher;
use Oueb\WpBackup\Security\Key_Ring;
use Oueb\WpBackup\Storage\Resumable_File;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Déchiffre une archive .enc à côté d'elle, bloc par bloc, avec reprise.
 *
 * Comme pour le chiffrement, la position lue, la taille écrite et l'état du
 * flux sont notés ensemble après chaque bloc.
 *
 * @since 0.1.0
 */
final class Decrypt implements Step {

	/**
	 * Clés de chiffrement.
	 *
	 * @since 0.1.0
	 * @var Key_Ring
	 */
	private Key_Ring $keys;

	/**
	 * Crée l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param Key_Ring $keys Clés de chiffrement.
	 */
	public function __construct( Key_Ring $keys ) {
		$this->keys = $keys;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_decrypt';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Decrypting the archive', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws Step_Failure Si la clé manque ou si l'archive est abîmée.
	 * @throws RuntimeException Si un fichier ne se lit pas.
	 */
	public function run( Run_Context $context ): bool {
		$source = (string) Restore_State::get( $context, 'file', '' );
		$target = Restore_State::dir( $context ) . '/' . basename( (string) Restore_State::get( $context, 'name' ) );

		// Coupure juste après la fin : l'archive en clair est complète.
		if ( $context->get( 'done' ) ) {
			return true;
		}

		$reader = fopen( $source, 'rb' );
		if ( false === $reader ) {
			throw new RuntimeException( esc_html__( 'The archive to decrypt is missing.', 'oueb-wp-backup' ) );
		}

		try {
			if ( null === $context->get( 'state' ) ) {
				try {
					$state = Archive_Cipher::open( Resumable_File::read( $reader, Archive_Cipher::HEADER_BYTES ), array( $this->keys, 'find' ) );
				} catch ( RuntimeException $error ) {
					throw new Step_Failure( esc_html( $error->getMessage() ) );
				}
				$context->set( 'state', base64_encode( $state ) );
				$context->set( 'offset', Archive_Cipher::HEADER_BYTES );
				$context->set( 'size', 0 );
				clearstatcache( true, $source );
				$context->set( 'total', (int) filesize( $source ) );
			}

			$state  = (string) base64_decode( (string) $context->get( 'state' ), true );
			$offset = (int) $context->get( 'offset' );
			$total  = max( 1, (int) $context->get( 'total' ) );
			$writer = Resumable_File::open( $target, (int) $context->get( 'size' ) );

			try {
				fseek( $reader, $offset );
				while ( true ) {
					$sealed = Resumable_File::read( $reader, Archive_Cipher::sealed_chunk() );
					try {
						list( $plain, $last ) = Archive_Cipher::unseal( $state, $sealed );
					} catch ( RuntimeException $error ) {
						throw new Step_Failure( esc_html( $error->getMessage() ) );
					}
					Resumable_File::write( $writer, $plain );
					$offset += strlen( $sealed );

					$context->set( 'size', Resumable_File::commit( $writer ) );
					$context->set( 'offset', $offset );
					$context->set( 'state', base64_encode( $state ) );
					$context->progress( $offset / $total );
					$context->checkpoint();

					if ( $last ) {
						if ( '' !== Resumable_File::read( $reader, 1 ) ) {
							throw new Step_Failure( esc_html__( 'The encrypted backup has extra data after its end.', 'oueb-wp-backup' ) );
						}
						break;
					}
					if ( $context->should_pause() ) {
						return false;
					}
				}
			} finally {
				fclose( $writer );
			}
		} finally {
			fclose( $reader );
		}

		$downloaded = (bool) Restore_State::get( $context, 'downloaded' );
		Restore_State::set( $context, 'file', $target );
		Restore_State::set( $context, 'downloaded', true );
		$context->set( 'done', true );
		$context->checkpoint( true );

		// Une copie téléchargée pour l'occasion ne sert plus.
		if ( $downloaded ) {
			wp_delete_file( $source );
		}
		$context->logger->info(
			/* translators: %s: archive size. */
			sprintf( __( 'Archive decrypted: %s.', 'oueb-wp-backup' ), size_format( (int) $context->get( 'size' ), 1 ) )
		);

		return true;
	}
}
