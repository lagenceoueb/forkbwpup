<?php
/**
 * Requêtes HTTP vers les stockages, avec nouvel essai sur erreur passagère.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Envoie une requête par l'API HTTP de WordPress et normalise la réponse.
 *
 * Une erreur de transport ou un statut 429, 500, 502, 503 ou 504 donne lieu
 * à deux nouveaux essais, après 1 puis 2 secondes. Un seul 503 sur la
 * partie 900 d'un envoi ne doit pas faire échouer toute la sauvegarde.
 *
 * @since 0.1.0
 */
final class Http {

	/**
	 * Nombre maximal d'essais.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Statuts qui justifient un nouvel essai.
	 *
	 * @since 0.1.0
	 * @var int[]
	 */
	const TRANSIENT = array( 429, 500, 502, 503, 504 );

	/**
	 * Envoie une requête.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url     Adresse.
	 * @param array<string, mixed> $args    Arguments de wp_remote_request().
	 * @param string               $service Nom du service, pour les filtres.
	 * @return array{status: int, headers: array<string, string>, body: string} Réponse.
	 *
	 * @throws Remote_Exception Si le service reste injoignable.
	 */
	public static function request( string $url, array $args, string $service ): array {
		$args += array(
			'timeout'     => 60,
			'redirection' => 0,
		);

		/**
		 * Filtre les arguments d'une requête vers un stockage.
		 *
		 * @since 0.1.0
		 *
		 * @param array  $args    Arguments de wp_remote_request().
		 * @param string $url     Adresse.
		 * @param string $service Service : s3 ou webdav.
		 */
		$args = (array) apply_filters( 'oueb_wp_backup_remote_request_args', $args, $url, $service );

		$attempt = 1;
		while ( true ) {
			$response = wp_remote_request( $url, $args );
			if ( $attempt >= self::MAX_ATTEMPTS || ! self::is_transient( $response ) ) {
				break;
			}

			/**
			 * Filtre le délai avant un nouvel essai, en secondes.
			 *
			 * @since 0.1.0
			 *
			 * @param int $delay   Délai : 1 avant le deuxième essai, 2 avant le troisième.
			 * @param int $attempt Numéro de l'essai qui vient d'échouer.
			 */
			$delay = (int) apply_filters( 'oueb_wp_backup_remote_retry_delay', 2 ** ( $attempt - 1 ), $attempt );
			if ( $delay > 0 ) {
				sleep( $delay );
			}
			++$attempt;
		}

		if ( is_wp_error( $response ) ) {
			throw new Remote_Exception( esc_html( $response->get_error_message() ) );
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Indique si une réponse est une erreur passagère.
	 *
	 * @since 0.1.0
	 *
	 * @param array|\WP_Error $response Réponse de wp_remote_request().
	 * @return bool Vrai pour une erreur de transport ou un statut passager.
	 */
	private static function is_transient( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		return in_array( (int) wp_remote_retrieve_response_code( $response ), self::TRANSIENT, true );
	}

	/**
	 * Lit un document XML sans accès réseau ni entité externe.
	 *
	 * @since 0.1.0
	 *
	 * @param string $body Corps de la réponse.
	 * @return \DOMDocument|null Document, ou null si le corps n'est pas du XML.
	 */
	public static function xml( string $body ): ?\DOMDocument {
		if ( '' === trim( $body ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $body, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $document : null;
	}

	/**
	 * Renvoie le texte du premier descendant qui porte ce nom local.
	 *
	 * @since 0.1.0
	 *
	 * @param \DOMDocument|\DOMElement $node Nœud de départ.
	 * @param string                   $name Nom local de l'élément, sans espace de noms.
	 * @return string Texte, vide si l'élément n'existe pas.
	 */
	public static function text( $node, string $name ): string {
		$element = $node->getElementsByTagNameNS( '*', $name )->item( 0 );

		return null === $element ? '' : trim( (string) simplexml_import_dom( $element ) );
	}
}
