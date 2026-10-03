<?php
/**
 * Chiffrement des secrets enregistrés en base.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Chiffre et déchiffre les mots de passe et clés d'API avant leur passage en base.
 *
 * Utilise crypto_secretbox de Sodium (XSalsa20-Poly1305), avec une clé dérivée
 * des clés secrètes du site. Une copie de la base seule ne suffit donc pas à
 * lire les secrets : il faut aussi wp-config.php.
 *
 * @since 0.1.0
 */
final class Secret_Box {

	/**
	 * Préfixe des valeurs chiffrées, avec la version du format.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PREFIX = 'oueb1:';

	/**
	 * Chiffre une valeur.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plain Valeur en clair.
	 * @return string Valeur chiffrée, vide si la valeur l'est.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		return self::PREFIX . sodium_bin2base64( $nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Déchiffre une valeur.
	 *
	 * @since 0.1.0
	 *
	 * @param string $box Valeur chiffrée par encrypt().
	 * @return string Valeur en clair, vide si le format est inconnu ou la clé différente.
	 */
	public static function decrypt( string $box ): string {
		if ( 0 !== strpos( $box, self::PREFIX ) ) {
			return '';
		}

		try {
			$raw = sodium_base642bin( substr( $box, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return '';
		}

		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Dérive la clé de chiffrement des clés secrètes du site.
	 *
	 * @since 0.1.0
	 *
	 * @return string Clé binaire de SODIUM_CRYPTO_SECRETBOX_KEYBYTES octets.
	 */
	private static function key(): string {
		return sodium_crypto_generichash(
			'oueb-wp-backup|' . wp_salt( 'auth' ) . wp_salt( 'secure_auth' ),
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}
}
