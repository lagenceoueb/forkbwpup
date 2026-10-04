<?php
/**
 * Client S3 minimal, signé en AWS Signature Version 4.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Parle à un service compatible S3 par l'API HTTP de WordPress.
 *
 * Il couvre ce qu'une sauvegarde demande : vérifier un bucket, lister,
 * envoyer en une fois ou en plusieurs parties, lire une plage d'octets et
 * supprimer. Il remplace le SDK d'AWS, qui pesait plusieurs mégaoctets.
 *
 * @since 0.1.0
 */
final class S3_Client {

	/**
	 * Algorithme de signature.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const ALGORITHM = 'AWS4-HMAC-SHA256';

	/**
	 * Empreinte SHA-256 d'un corps vide.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Schéma de l'adresse : https, ou http pour un service de test.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $scheme;

	/**
	 * Hôte du service, avec son port éventuel.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $host;

	/**
	 * Région de signature.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $region;

	/**
	 * Clé d'accès.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $access_key;

	/**
	 * Clé secrète.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Vrai pour mettre le bucket dans le chemin plutôt que dans l'hôte.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $path_style;

	/**
	 * Horloge, remplaçable pour les tests de signature.
	 *
	 * @since 0.1.0
	 * @var callable
	 */
	private $clock;

	/**
	 * Crée le client.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $endpoint   Adresse du service, par exemple https://s3.fr-par.scw.cloud.
	 * @param string        $region     Région de signature.
	 * @param string        $access_key Clé d'accès.
	 * @param string        $secret_key Clé secrète.
	 * @param bool          $path_style Vrai pour l'adressage par chemin.
	 * @param callable|null $clock      Renvoie l'horodatage courant ; time() par défaut.
	 *
	 * @throws Remote_Exception Si l'adresse est invalide.
	 */
	public function __construct( string $endpoint, string $region, string $access_key, string $secret_key, bool $path_style = false, ?callable $clock = null ) {
		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'https', 'http' ), true ) ) {
			throw new Remote_Exception( esc_html__( 'The S3 address is not valid.', 'oueb-wp-backup' ) );
		}

		$this->scheme     = $parts['scheme'];
		$this->host       = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$this->region     = '' === $region ? 'us-east-1' : $region;
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->path_style = $path_style;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Vérifie que le bucket existe et que les clés y donnent accès.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 *
	 * @throws Remote_Exception Si le bucket est inaccessible.
	 */
	public function check_bucket( string $bucket ): void {
		$this->request(
			'GET',
			$bucket,
			'',
			array(
				'list-type' => '2',
				'max-keys'  => '1',
			)
		);
	}

	/**
	 * Liste les objets dont la clé commence par un préfixe.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 * @param string $prefix Préfixe.
	 * @return array<int, array{key: string, size: int, time: int}> Objets.
	 *
	 * @throws Remote_Exception Si la liste échoue.
	 */
	public function list_objects( string $bucket, string $prefix ): array {
		$objects = array();
		$token   = '';

		do {
			$query = array( 'list-type' => '2' );
			if ( '' !== $prefix ) {
				$query['prefix'] = $prefix;
			}
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}

			$document = Http::xml( $this->request( 'GET', $bucket, '', $query )['body'] );
			if ( null === $document ) {
				throw new Remote_Exception( esc_html__( 'The S3 service returned an unreadable list.', 'oueb-wp-backup' ) );
			}

			foreach ( $document->getElementsByTagNameNS( '*', 'Contents' ) as $item ) {
				$objects[] = array(
					'key'  => Http::text( $item, 'Key' ),
					'size' => (int) Http::text( $item, 'Size' ),
					'time' => (int) strtotime( Http::text( $item, 'LastModified' ) ),
				);
			}

			$token = 'true' === Http::text( $document, 'IsTruncated' ) ? Http::text( $document, 'NextContinuationToken' ) : '';
		} while ( '' !== $token );

		return $objects;
	}

	/**
	 * Renvoie la taille d'un objet.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Clé.
	 * @return int Taille, -1 si l'objet n'existe pas.
	 *
	 * @throws Remote_Exception Si le service répond par une autre erreur.
	 */
	public function object_size( string $bucket, string $key ): int {
		try {
			$response = $this->request( 'HEAD', $bucket, $key );
		} catch ( Remote_Exception $error ) {
			if ( 404 === $error->status() ) {
				return -1;
			}
			throw $error;
		}

		return (int) ( $response['headers']['content-length'] ?? -1 );
	}

	/**
	 * Envoie un objet en une requête.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Clé.
	 * @param string $body   Contenu.
	 *
	 * @throws Remote_Exception Si l'envoi échoue.
	 */
	public function put_object( string $bucket, string $key, string $body ): void {
		$this->request( 'PUT', $bucket, $key, array(), array( 'content-type' => 'application/octet-stream' ), $body );
	}

	/**
	 * Commence un envoi en plusieurs parties.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Clé.
	 * @return string Identifiant de l'envoi.
	 *
	 * @throws Remote_Exception Si le service refuse.
	 */
	public function create_multipart( string $bucket, string $key ): string {
		$response = $this->request( 'POST', $bucket, $key, array( 'uploads' => '' ), array( 'content-type' => 'application/octet-stream' ) );
		$document = Http::xml( $response['body'] );
		$id       = null === $document ? '' : Http::text( $document, 'UploadId' );
		if ( '' === $id ) {
			throw new Remote_Exception( esc_html__( 'The S3 service did not start the upload.', 'oueb-wp-backup' ) );
		}

		return $id;
	}

	/**
	 * Envoie une partie.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket    Bucket.
	 * @param string $key       Clé.
	 * @param string $upload_id Identifiant de l'envoi.
	 * @param int    $number    Numéro de la partie, à partir de 1.
	 * @param string $body      Contenu de la partie.
	 * @return string ETag de la partie.
	 *
	 * @throws Remote_Exception Si l'envoi échoue.
	 */
	public function upload_part( string $bucket, string $key, string $upload_id, int $number, string $body ): string {
		$response = $this->request(
			'PUT',
			$bucket,
			$key,
			array(
				'partNumber' => (string) $number,
				'uploadId'   => $upload_id,
			),
			array(),
			$body
		);
		$etag     = trim( $response['headers']['etag'] ?? '' );
		if ( '' === $etag ) {
			throw new Remote_Exception( esc_html__( 'The S3 service did not confirm a part of the upload.', 'oueb-wp-backup' ) );
		}

		return $etag;
	}

	/**
	 * Termine un envoi en plusieurs parties.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $bucket    Bucket.
	 * @param string             $key       Clé.
	 * @param string             $upload_id Identifiant de l'envoi.
	 * @param array<int, string> $etags     ETag de chaque partie, par numéro.
	 *
	 * @throws Remote_Exception Si le service refuse l'assemblage.
	 */
	public function complete_multipart( string $bucket, string $key, string $upload_id, array $etags ): void {
		ksort( $etags );
		$xml = '<CompleteMultipartUpload>';
		foreach ( $etags as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars( $etag, ENT_XML1 ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$response = $this->request( 'POST', $bucket, $key, array( 'uploadId' => $upload_id ), array( 'content-type' => 'application/xml' ), $xml );

		// Le service peut répondre 200 avec une erreur dans le corps.
		$document = Http::xml( $response['body'] );
		if ( null !== $document && $document->getElementsByTagNameNS( '*', 'Error' )->length > 0 ) {
			throw new Remote_Exception( esc_html( Http::text( $document, 'Message' ) ), 200, esc_html( Http::text( $document, 'Code' ) ) );
		}
	}

	/**
	 * Abandonne un envoi en plusieurs parties et libère ses parties.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket    Bucket.
	 * @param string $key       Clé.
	 * @param string $upload_id Identifiant de l'envoi.
	 *
	 * @throws Remote_Exception Si le service refuse.
	 */
	public function abort_multipart( string $bucket, string $key, string $upload_id ): void {
		$this->request( 'DELETE', $bucket, $key, array( 'uploadId' => $upload_id ) );
	}

	/**
	 * Lit une plage d'octets d'un objet.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Clé.
	 * @param int    $start  Premier octet.
	 * @param int    $end    Dernier octet, inclus.
	 * @return string Octets lus.
	 *
	 * @throws Remote_Exception Si la lecture échoue.
	 */
	public function get_range( string $bucket, string $key, int $start, int $end ): string {
		return $this->request( 'GET', $bucket, $key, array(), array( 'range' => 'bytes=' . $start . '-' . $end ) )['body'];
	}

	/**
	 * Supprime un objet.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Clé.
	 *
	 * @throws Remote_Exception Si la suppression échoue.
	 */
	public function delete_object( string $bucket, string $key ): void {
		$this->request( 'DELETE', $bucket, $key );
	}

	/**
	 * Envoie une requête signée.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $method  Méthode HTTP.
	 * @param string                $bucket  Bucket.
	 * @param string                $key     Clé, vide pour une requête sur le bucket.
	 * @param array<string, string> $query   Paramètres de requête.
	 * @param array<string, string> $headers En-têtes, noms en minuscules.
	 * @param string                $body    Corps.
	 * @return array{status: int, headers: array<string, string>, body: string} Réponse.
	 *
	 * @throws Remote_Exception Si le service répond par une erreur.
	 */
	private function request( string $method, string $bucket, string $key = '', array $query = array(), array $headers = array(), string $body = '' ): array {
		// Sans type explicite, WordPress enverrait « application/x-www-form-urlencoded »
		// et certains services liraient le corps comme un formulaire.
		if ( '' !== $body && ! isset( $headers['content-type'] ) ) {
			$headers['content-type'] = 'application/octet-stream';
		}

		$target       = $this->target( $bucket, $key );
		$query_string = self::canonical_query( $query );
		$url          = $this->scheme . '://' . $target['host'] . $target['path'] . ( '' === $query_string ? '' : '?' . $query_string );
		$headers      = $this->sign( $method, $target['host'], $target['path'], $query_string, $headers, $body );

		// L'hôte est déduit de l'adresse par la bibliothèque HTTP.
		unset( $headers['host'] );

		$response = Http::request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 300,
			),
			's3'
		);

		if ( $response['status'] < 200 || $response['status'] > 299 ) {
			$document = Http::xml( $response['body'] );
			$code     = null === $document ? '' : Http::text( $document, 'Code' );
			$message  = null === $document ? '' : Http::text( $document, 'Message' );
			throw new Remote_Exception(
				esc_html(
					'' !== $message ? $message : ( '' !== $code ? $code : sprintf(
					/* translators: %d: HTTP status code. */
						__( 'The S3 service answered with the HTTP error %d.', 'oueb-wp-backup' ),
						$response['status']
					) )
				),
				(int) $response['status'],
				esc_html( $code )
			);
		}

		return $response;
	}

	/**
	 * Calcule l'hôte et le chemin encodé d'une requête.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Clé.
	 * @return array{host: string, path: string} Hôte et chemin.
	 */
	private function target( string $bucket, string $key ): array {
		$key  = ltrim( $key, '/' );
		$path = '' === $key ? '' : '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );

		// Un nom avec un point casserait le certificat du sous-domaine.
		if ( $this->path_style || false !== strpos( $bucket, '.' ) ) {
			return array(
				'host' => $this->host,
				'path' => '/' . rawurlencode( $bucket ) . $path,
			);
		}

		return array(
			'host' => $bucket . '.' . $this->host,
			'path' => '' === $path ? '/' : $path,
		);
	}

	/**
	 * Construit la chaîne de requête canonique : noms triés, valeurs encodées.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $query Paramètres.
	 * @return string Chaîne canonique.
	 */
	public static function canonical_query( array $query ): string {
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[ rawurlencode( (string) $name ) ] = rawurlencode( (string) $value );
		}
		ksort( $pairs, SORT_STRING );

		$parts = array();
		foreach ( $pairs as $name => $value ) {
			$parts[] = $name . '=' . $value;
		}

		return implode( '&', $parts );
	}

	/**
	 * Ajoute la signature Version 4 aux en-têtes.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $method       Méthode HTTP.
	 * @param string                $host         Hôte.
	 * @param string                $path         Chemin encodé.
	 * @param string                $query_string Chaîne de requête canonique.
	 * @param array<string, string> $headers      En-têtes, noms en minuscules.
	 * @param string                $body         Corps.
	 * @return array<string, string> En-têtes signés, avec « host ».
	 */
	public function sign( string $method, string $host, string $path, string $query_string, array $headers, string $body ): array {
		$amz_date = gmdate( 'Ymd\THis\Z', (int) call_user_func( $this->clock ) );
		$day      = substr( $amz_date, 0, 8 );
		$scope    = $day . '/' . $this->region . '/s3/aws4_request';

		$headers                         = array_change_key_case( $headers, CASE_LOWER );
		$headers['host']                 = $host;
		$headers['x-amz-date']           = $amz_date;
		$headers['x-amz-content-sha256'] = '' === $body ? self::EMPTY_HASH : hash( 'sha256', $body );
		ksort( $headers, SORT_STRING );

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) ) . "\n";
		}
		$signed_headers = implode( ';', array_keys( $headers ) );

		$canonical_request = implode( "\n", array( $method, $path, $query_string, $canonical_headers, $signed_headers, $headers['x-amz-content-sha256'] ) );
		$string_to_sign    = self::ALGORITHM . "\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$signing_key = hash_hmac( 'sha256', $day, 'AWS4' . $this->secret_key, true );
		$signing_key = hash_hmac( 'sha256', $this->region, $signing_key, true );
		$signing_key = hash_hmac( 'sha256', 's3', $signing_key, true );
		$signing_key = hash_hmac( 'sha256', 'aws4_request', $signing_key, true );

		$headers['authorization'] = self::ALGORITHM
			. ' Credential=' . $this->access_key . '/' . $scope
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return $headers;
	}
}
