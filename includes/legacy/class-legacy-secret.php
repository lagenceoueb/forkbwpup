<?php
/**
 * Lecture des secrets enregistrés par BackWPup.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Déchiffre les mots de passe et clés que BackWPup range dans ses tâches.
 *
 * BackWPup préfixe une valeur chiffrée par « $BackWPup$ », puis par le nom de
 * la méthode : « OSSL$ » pour AES-CTR avec OpenSSL, « ENC1$ » pour un
 * décalage d'octets de repli. « $0 » signale la clé personnelle de la
 * constante BACKWPUP_ENC_KEY ; sans elle, la clé vient des accès à la base
 * (DB_NAME, DB_USER, DB_PASSWORD). Une valeur sans préfixe est en clair.
 *
 * @since 0.1.0
 */
final class Legacy_Secret {

	/**
	 * Préfixe des valeurs chiffrées.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PREFIX = '$BackWPup$';

	/**
	 * Méthodes OpenSSL essayées après celle que BackWPup aurait choisie.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const CIPHERS = array( 'aes-256-ctr', 'aes-128-ctr', 'aes-192-ctr', 'aes-128-cbc', 'aes-256-cbc' );

	/**
	 * Déchiffre une valeur.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $value      Valeur enregistrée par BackWPup.
	 * @param string|null $custom_key Clé personnelle, null pour lire BACKWPUP_ENC_KEY.
	 * @return string|null Valeur en clair, null si elle ne se déchiffre pas.
	 */
	public static function decrypt( string $value, ?string $custom_key = null ): ?string {
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value;
		}

		$rest   = substr( $value, strlen( self::PREFIX ) );
		$method = substr( $rest, 0, 5 );
		$rest   = substr( $rest, 5 );
		if ( 'OSSL$' !== $method && 'ENC1$' !== $method ) {
			return null;
		}

		if ( 0 === strpos( $rest, '$0' ) ) {
			$rest = substr( $rest, 2 );
			if ( null === $custom_key ) {
				$custom_key = defined( 'BACKWPUP_ENC_KEY' ) ? (string) constant( 'BACKWPUP_ENC_KEY' ) : null;
			}
			if ( null === $custom_key ) {
				return null;
			}
			$key = md5( $custom_key );
		} else {
			$key = md5( self::database_key() );
		}

		$data = base64_decode( $rest, true );
		if ( false === $data ) {
			return null;
		}

		$plain = 'OSSL$' === $method ? self::openssl( $data, $key ) : self::shift( $data, $key );
		if ( null === $plain ) {
			return null;
		}

		// BackWPup retire les barres obliques ajoutées et les octets nuls de bourrage.
		return trim( stripslashes( $plain ), "\0" );
	}

	/**
	 * Renvoie la clé par défaut de BackWPup : les accès à la base, mis bout à bout.
	 *
	 * @since 0.1.0
	 *
	 * @return string Clé.
	 */
	private static function database_key(): string {
		$parts = array();
		foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ) as $constant ) {
			$parts[] = defined( $constant ) ? (string) constant( $constant ) : '';
		}

		return implode( '', $parts );
	}

	/**
	 * Déchiffre une valeur OpenSSL : vecteur d'initialisation, puis données.
	 *
	 * BackWPup cherche « AES-256-CTR » en majuscules dans la liste d'OpenSSL.
	 * Avec OpenSSL 3, la liste est en minuscules : il prend alors la première
	 * méthode de la liste, souvent aes-128-cbc. La méthode dépend donc du
	 * serveur. Chacune est essayée, et seul un texte lisible est retenu.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data Données décodées.
	 * @param string $key  Clé : empreinte MD5 en hexadécimal, comme chez BackWPup.
	 * @return string|null Valeur, null si aucune méthode ne convient.
	 */
	private static function openssl( string $data, string $key ): ?string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$available = (array) openssl_get_cipher_methods();
		$lower     = array_map( 'strtolower', $available );
		$ciphers   = array();
		foreach ( array( 'AES-256-CTR', 'AES-128-CTR', 'AES-192-CTR' ) as $preferred ) {
			if ( in_array( $preferred, $available, true ) ) {
				$ciphers[] = $preferred;
				break;
			}
		}
		if ( array() === $ciphers && isset( $available[0] ) ) {
			$ciphers[] = (string) $available[0];
		}
		$ciphers = array_unique( array_merge( $ciphers, array_intersect( self::CIPHERS, $lower ) ) );

		foreach ( $ciphers as $cipher ) {
			$size = (int) openssl_cipher_iv_length( $cipher );
			if ( strlen( $data ) <= $size ) {
				continue;
			}
			$plain = openssl_decrypt( (string) substr( $data, $size ), $cipher, $key, OPENSSL_RAW_DATA, (string) substr( $data, 0, $size ) );
			if ( false !== $plain && self::readable( $plain ) ) {
				return $plain;
			}
		}

		return null;
	}

	/**
	 * Indique si une valeur déchiffrée ressemble à un secret : du texte UTF-8 sans caractère de contrôle.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Valeur.
	 * @return bool Vrai si elle est lisible.
	 */
	private static function readable( string $value ): bool {
		$value = trim( $value, "\0" );

		return '' !== $value && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value );
	}

	/**
	 * Annule le décalage d'octets de la méthode de repli.
	 *
	 * Chaque octet a reçu le code d'un caractère de la clé. BackWPup prend le
	 * caractère à la position (i modulo 32) - 1, c'est-à-dire le dernier pour
	 * le premier octet : ce décalage est repris tel quel.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data Données décodées.
	 * @param string $key  Clé : empreinte MD5 en hexadécimal.
	 * @return string Valeur.
	 */
	private static function shift( string $data, string $key ): string {
		$plain  = '';
		$length = strlen( $data );
		$size   = strlen( $key );
		for ( $i = 0; $i < $length; $i++ ) {
			$position = ( $i % $size ) - 1;
			$key_char = $key[ $position < 0 ? $size - 1 : $position ];
			$plain   .= chr( ( ord( $data[ $i ] ) - ord( $key_char ) + 256 ) % 256 );
		}

		return $plain;
	}
}
