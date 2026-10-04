<?php
/**
 * Étape de chiffrement de l'archive.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Engine\Step_Failure;
use Oueb\WpBackup\Security\Archive_Cipher;
use Oueb\WpBackup\Security\Key_Ring;
use Oueb\WpBackup\Storage\Resumable_File;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Chiffre l'archive avant son envoi, bloc par bloc.
 *
 * Après chaque bloc, la taille du fichier chiffré et l'état du flux sont
 * notés ensemble : une reprise tronque le fichier à cette taille et repart
 * du bloc suivant. L'archive en clair est supprimée une fois chiffrée.
 *
 * @since 0.1.0
 */
final class Encrypt implements Step {

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
		return 'encrypt';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Encrypting the archive', 'oueb-wp-backup' );
	}

	/**
	 * Renvoie le chemin de l'archive chiffrée.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @return string Chemin.
	 */
	public static function path( Run_Context $context ): string {
		return Archive::path( $context ) . '.enc';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws Step_Failure     Sans clé de chiffrement.
	 * @throws RuntimeException Si l'archive en clair manque.
	 */
	public function run( Run_Context $context ): bool {
		$source = Archive::path( $context );
		$target = self::path( $context );

		$fresh = null === $context->get( 'state' );
		if ( $fresh ) {
			$key = $this->keys->active();
			if ( null === $key ) {
				throw new Step_Failure( esc_html__( 'Encryption is on, but there is no encryption key. Create one in the settings.', 'oueb-wp-backup' ) );
			}

			$start  = Archive_Cipher::start( $key['key'] );
			$writer = Resumable_File::open( $target, 0 );
			Resumable_File::write( $writer, $start['header'] );
			$context->set( 'size', Resumable_File::commit( $writer ) );
			fclose( $writer );

			clearstatcache( true, $source );
			$context->set( 'total', (int) filesize( $source ) );
			$context->set( 'offset', 0 );
			$context->set( 'key_id', $key['id'] );
			$context->set( 'state', base64_encode( $start['state'] ) );
			$context->logger->info(
				/* translators: %s: key identifier. */
				sprintf( __( 'Encrypting the archive with the key %s.', 'oueb-wp-backup' ), $key['id'] )
			);
		}

		$total  = (int) $context->get( 'total' );
		$offset = (int) $context->get( 'offset' );
		$state  = (string) base64_decode( (string) $context->get( 'state' ), true );

		// Coupure après le dernier bloc : le fichier chiffré est complet.
		if ( ! $fresh && $offset >= $total ) {
			wp_delete_file( $source );
			return true;
		}

		$reader = fopen( $source, 'rb' );
		if ( false === $reader ) {
			throw new RuntimeException( esc_html__( 'The archive to encrypt is missing.', 'oueb-wp-backup' ) );
		}
		$writer = Resumable_File::open( $target, (int) $context->get( 'size' ) );

		try {
			fseek( $reader, $offset );
			do {
				$data    = Resumable_File::read( $reader, Archive_Cipher::CHUNK );
				$offset += strlen( $data );
				$last    = $offset >= $total;
				Resumable_File::write( $writer, Archive_Cipher::seal( $state, $data, $last ) );

				// Taille du fichier, position et état du flux sont notés ensemble, après l'écriture.
				$context->set( 'size', Resumable_File::commit( $writer ) );
				$context->set( 'offset', $offset );
				$context->set( 'state', base64_encode( $state ) );
				$context->progress( $total > 0 ? $offset / $total : 1.0 );
				$context->checkpoint();

				if ( $last ) {
					break;
				}
				if ( $context->should_pause() ) {
					return false;
				}
			} while ( true );
		} finally {
			fclose( $reader );
			fclose( $writer );
		}

		wp_delete_file( $source );
		$context->logger->info(
			sprintf(
				/* translators: %s: archive size. */
				__( 'Archive encrypted: %s.', 'oueb-wp-backup' ),
				size_format( (int) $context->get( 'size' ), 1 )
			)
		);

		return true;
	}
}
