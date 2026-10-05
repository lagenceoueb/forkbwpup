<?php
/**
 * Format de chiffrement des archives.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Security;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Chiffre et déchiffre une archive en flux, avec XChaCha20-Poly1305 (libsodium secretstream).
 *
 * Format, version 1 :
 *
 * - « OUEBENC1 » sur 8 octets ;
 * - identifiant de la clé, 8 octets : le début de son empreinte SHA-256 ;
 * - en-tête du flux secretstream, 24 octets ;
 * - blocs de 1 Mio chiffrés, 17 octets de plus chacun ; le dernier porte
 *   l'étiquette de fin, même s'il est vide.
 *
 * Chaque bloc est authentifié : une archive modifiée, tronquée ou réordonnée
 * est refusée. L'état du flux tient en une courte chaîne, que l'étape de
 * chiffrement enregistre pour reprendre après une coupure.
 *
 * @since 0.1.0
 */
final class Archive_Cipher {

	/**
	 * Signature du format.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const MAGIC = 'OUEBENC1';

	/**
	 * Taille d'un bloc en clair.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 1048576;

	/**
	 * Taille de l'en-tête du fichier.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const HEADER_BYTES = 40;

	/**
	 * Taille d'un bloc chiffré complet.
	 *
	 * @since 0.1.0
	 *
	 * @return int Octets.
	 */
	public static function sealed_chunk(): int {
		return self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
	}

	/**
	 * Commence un chiffrement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Clé brute de 32 octets.
	 * @return array{header: string, state: string} En-tête du fichier et état du flux.
	 */
	public static function start( string $key ): array {
		list( $state, $stream_header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );

		return array(
			'header' => self::MAGIC . hex2bin( Key_Ring::id( $key ) ) . $stream_header,
			'state'  => $state,
		);
	}

	/**
	 * Chiffre un bloc.
	 *
	 * @since 0.1.0
	 *
	 * @param string $state État du flux, mis à jour.
	 * @param string $data  Bloc en clair, CHUNK octets sauf le dernier.
	 * @param bool   $last  Vrai pour le dernier bloc.
	 * @return string Bloc chiffré.
	 */
	public static function seal( string &$state, string $data, bool $last ): string {
		return sodium_crypto_secretstream_xchacha20poly1305_push(
			$state,
			$data,
			'',
			$last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
		);
	}

	/**
	 * Lit l'identifiant de clé d'un en-tête.
	 *
	 * @since 0.1.0
	 *
	 * @param string $header Premiers octets du fichier.
	 * @return string|null Identifiant, ou null si ce n'est pas une archive chiffrée.
	 */
	public static function key_id( string $header ): ?string {
		if ( strlen( $header ) < self::HEADER_BYTES || self::MAGIC !== substr( $header, 0, 8 ) ) {
			return null;
		}

		return bin2hex( substr( $header, 8, 8 ) );
	}

	/**
	 * Déchiffre une archive en flux.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $read  Reçoit un nombre d'octets, renvoie au plus autant d'octets ; une chaîne vide en fin de fichier.
	 * @param callable $write Reçoit chaque bloc en clair.
	 * @param callable $key   Reçoit l'identifiant de clé, renvoie la clé brute ou null.
	 *
	 * @throws RuntimeException Si la clé manque, ou si l'archive est abîmée ou modifiée.
	 */
	public static function decrypt( callable $read, callable $write, callable $key ): void {
		$state = self::open( self::read_exactly( $read, self::HEADER_BYTES ), $key );
		while ( true ) {
			list( $plain, $last ) = self::unseal( $state, self::read_exactly( $read, self::sealed_chunk() ) );
			call_user_func( $write, $plain );

			if ( $last ) {
				if ( '' !== self::read_exactly( $read, 1 ) ) {
					throw new RuntimeException( esc_html__( 'The encrypted backup has extra data after its end.', 'oueb-wp-backup' ) );
				}
				return;
			}
		}
	}

	/**
	 * Ouvre le flux de déchiffrement d'une archive à partir de son en-tête.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $header En-tête de HEADER_BYTES octets.
	 * @param callable $key    Reçoit l'identifiant de clé, renvoie la clé brute ou null.
	 * @return string État du flux, à passer à unseal().
	 *
	 * @throws RuntimeException Si ce n'est pas une archive chiffrée ou si la clé manque.
	 */
	public static function open( string $header, callable $key ): string {
		$id = self::key_id( $header );
		if ( null === $id ) {
			throw new RuntimeException( esc_html__( 'This file is not an encrypted backup.', 'oueb-wp-backup' ) );
		}

		$secret = call_user_func( $key, $id );
		if ( ! is_string( $secret ) || '' === $secret ) {
			/* translators: %s: key identifier. */
			throw new RuntimeException( esc_html( sprintf( __( 'The key %s is missing. Add it in the encryption settings.', 'oueb-wp-backup' ), $id ) ) );
		}

		return sodium_crypto_secretstream_xchacha20poly1305_init_pull( substr( $header, 16, 24 ), $secret );
	}

	/**
	 * Déchiffre un bloc.
	 *
	 * @since 0.1.0
	 *
	 * @param string $state  État du flux, mis à jour.
	 * @param string $sealed Bloc chiffré, de sealed_chunk() octets au plus.
	 * @return array{0: string, 1: bool} Bloc en clair, et vrai pour le dernier bloc.
	 *
	 * @throws RuntimeException Si le bloc manque, est modifié ou si la clé est fausse.
	 */
	public static function unseal( string &$state, string $sealed ): array {
		if ( '' === $sealed ) {
			throw new RuntimeException( esc_html__( 'The encrypted backup is truncated.', 'oueb-wp-backup' ) );
		}

		$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $sealed );
		if ( false === $result ) {
			throw new RuntimeException( esc_html__( 'The encrypted backup is damaged, or the key is wrong.', 'oueb-wp-backup' ) );
		}

		return array( (string) $result[0], SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $result[1] );
	}

	/**
	 * Lit exactement un nombre d'octets, ou moins seulement en fin de fichier.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $read   Lecture.
	 * @param int      $length Nombre d'octets.
	 * @return string Octets lus.
	 */
	private static function read_exactly( callable $read, int $length ): string {
		$data = '';
		$left = $length;
		while ( $left > 0 ) {
			$chunk = (string) call_user_func( $read, $left );
			if ( '' === $chunk ) {
				break;
			}
			$data .= $chunk;
			$left -= strlen( $chunk );
		}

		return $data;
	}
}
