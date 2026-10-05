<?php
/**
 * Clés de chiffrement des archives.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Garde les clés de chiffrement des archives, la plus récente servant aux nouvelles archives.
 *
 * Les anciennes clés restent pour déchiffrer les anciennes archives. Chaque
 * clé est rangée chiffrée par Secret_Box. Son identifiant, écrit en tête de
 * chaque archive, est le début de son empreinte SHA-256 : il permet de
 * retrouver la bonne clé sans la révéler.
 *
 * Sans la clé, une archive chiffrée est illisible : l'administrateur doit la
 * garder hors du site.
 *
 * @since 0.1.0
 */
class Key_Ring {

	/**
	 * Option des clés.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'oueb_wp_backup_keys';

	/**
	 * Renvoie la clé des nouvelles archives.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, key: string}|null Identifiant et clé brute, ou null sans clé.
	 */
	public function active(): ?array {
		$records = $this->records();
		$last    = end( $records );
		if ( false === $last ) {
			return null;
		}

		$key = Secret_Box::decrypt( (string) $last['key'] );

		return SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES === strlen( $key ) ? array(
			'id'  => (string) $last['id'],
			'key' => $key,
		) : null;
	}

	/**
	 * Retrouve une clé par son identifiant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant, 16 caractères hexadécimaux.
	 * @return string|null Clé brute, ou null si elle est inconnue.
	 */
	public function find( string $id ): ?string {
		foreach ( $this->records() as $record ) {
			if ( hash_equals( (string) $record['id'], $id ) ) {
				$key = Secret_Box::decrypt( (string) $record['key'] );
				return '' === $key ? null : $key;
			}
		}

		return null;
	}

	/**
	 * Crée une clé, qui devient celle des nouvelles archives.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, key: string} Identifiant et clé brute.
	 */
	public function generate(): array {
		return $this->add( sodium_crypto_secretstream_xchacha20poly1305_keygen() );
	}

	/**
	 * Ajoute une clé existante, par exemple celle d'un autre site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Clé brute de 32 octets.
	 * @return array{id: string, key: string} Identifiant et clé brute.
	 */
	public function add( string $key ): array {
		$id      = self::id( $key );
		$records = array_filter( $this->records(), static fn( array $record ): bool => $record['id'] !== $id );

		$records[] = array(
			'id'      => $id,
			'key'     => Secret_Box::encrypt( $key ),
			'created' => time(),
		);
		update_site_option( self::OPTION, array_values( $records ) );

		return array(
			'id'  => $id,
			'key' => $key,
		);
	}

	/**
	 * Liste les clés, sans leur valeur.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{id: string, created: int, active: bool}> Clés, la plus récente en dernier.
	 */
	public function summary(): array {
		$records = $this->records();
		$count   = count( $records );
		$list    = array();
		foreach ( array_values( $records ) as $index => $record ) {
			$list[] = array(
				'id'      => (string) $record['id'],
				'created' => (int) $record['created'],
				'active'  => $index === $count - 1,
			);
		}

		return $list;
	}

	/**
	 * Calcule l'identifiant d'une clé.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Clé brute.
	 * @return string 16 caractères hexadécimaux.
	 */
	public static function id( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 16 );
	}

	/**
	 * Écrit une clé sous la forme à conserver hors du site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Clé brute.
	 * @return string Clé encodée en base64.
	 */
	public static function encode( string $key ): string {
		return base64_encode( $key );
	}

	/**
	 * Lit une clé conservée hors du site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $encoded Clé encodée en base64.
	 * @return string|null Clé brute, ou null si la valeur n'est pas une clé.
	 */
	public static function decode( string $encoded ): ?string {
		$key = base64_decode( trim( $encoded ), true );

		return false !== $key && SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES === strlen( $key ) ? $key : null;
	}

	/**
	 * Lit les enregistrements valides.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>> Enregistrements, le plus récent en dernier.
	 */
	private function records(): array {
		$stored = get_site_option( self::OPTION, array() );

		return array_values(
			array_filter(
				is_array( $stored ) ? $stored : array(),
				static fn( $record ): bool => is_array( $record ) && isset( $record['id'], $record['key'] )
			)
		);
	}
}
