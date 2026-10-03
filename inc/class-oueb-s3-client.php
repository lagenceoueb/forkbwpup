<?php
/**
 * Client S3 léger, sans dépendance externe.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Client pour les services de stockage compatibles S3.
 *
 * Il couvre les opérations dont l'extension a besoin : buckets, envoi simple
 * et multipart, liste, lecture par plage, suppression. Les requêtes passent
 * par l'API HTTP de WordPress et sont signées avec AWS Signature Version 4.
 * La signature porte sur l'empreinte SHA-256 du contenu : le service rejette
 * tout envoi altéré en route.
 *
 * @since 0.1.0
 */
class Oueb_S3_Client {

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
	const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Schéma de l'endpoint, « https » ou « http ».
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $scheme;

	/**
	 * Hôte de l'endpoint, port compris s'il est précisé.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $host;

	/**
	 * Région utilisée pour la signature.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $region;

	/**
	 * Clé d'accès.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $access_key;

	/**
	 * Clé secrète, en clair.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $secret_key;

	/**
	 * Adressage par chemin (endpoint/bucket/clé) au lieu du sous-domaine.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $path_style;

	/**
	 * Délai d'attente des requêtes, en secondes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private $timeout;

	/**
	 * Construit le client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $endpoint   URL du service, par exemple « https://s3.fr-par.scw.cloud ».
	 * @param string $region     Région du service, par exemple « fr-par ».
	 * @param string $access_key Clé d'accès.
	 * @param string $secret_key Clé secrète, en clair.
	 * @param bool   $path_style Vrai pour l'adressage par chemin.
	 * @param int    $timeout    Délai d'attente des requêtes, en secondes.
	 *
	 * @throws Oueb_S3_Exception Si l'endpoint n'est pas une URL HTTP ou HTTPS.
	 */
	public function __construct( $endpoint, $region, $access_key, $secret_key, $path_style = false, $timeout = 300 ) {
		$parts = wp_parse_url( trim( (string) $endpoint ) );

		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			throw new Oueb_S3_Exception( 'Invalid S3 endpoint.' );
		}

		$this->scheme     = $parts['scheme'];
		$this->host       = strtolower( $parts['host'] ) . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
		$this->region     = '' === (string) $region ? 'us-east-1' : (string) $region;
		$this->access_key = (string) $access_key;
		$this->secret_key = (string) $secret_key;
		$this->path_style = (bool) $path_style;
		$this->timeout    = (int) $timeout;
	}

	/**
	 * Liste les buckets du compte.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Noms des buckets.
	 */
	public function list_buckets() {
		$document = $this->parse_xml( $this->request( 'GET', '', '' )['body'] );
		$names    = array();

		foreach ( $document->getElementsByTagName( 'Bucket' ) as $bucket ) {
			$names[] = $this->child_text( $bucket, 'Name' );
		}

		return $names;
	}

	/**
	 * Crée un bucket dans la région du client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 */
	public function create_bucket( $bucket ) {
		$body = '';

		if ( 'us-east-1' !== $this->region ) {
			$body = '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>'
				. esc_xml( $this->region )
				. '</LocationConstraint></CreateBucketConfiguration>';
		}

		$this->request( 'PUT', $bucket, '', array(), array( 'content-type' => 'application/xml' ), $body );
	}

	/**
	 * Indique si le bucket existe et reste accessible avec ces clés.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @return bool Vrai si le bucket répond.
	 *
	 * @throws Oueb_S3_Exception Sur une erreur autre qu'un bucket absent.
	 */
	public function bucket_exists( $bucket ) {
		try {
			$this->request( 'HEAD', $bucket, '' );
		} catch ( Oueb_S3_Exception $e ) {
			if ( 404 === $e->getCode() ) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	/**
	 * Liste les objets d'un bucket sous un préfixe, toutes pages confondues.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @param string $prefix Préfixe des clés.
	 * @return array[] Objets avec les clés « key », « size », « last_modified » et « storage_class ».
	 */
	public function list_objects( $bucket, $prefix = '' ) {
		$objects = array();
		$token   = '';

		do {
			$query = array(
				'list-type' => '2',
				'prefix'    => (string) $prefix,
			);
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}

			$document = $this->parse_xml( $this->request( 'GET', $bucket, '', $query )['body'] );

			foreach ( $document->getElementsByTagName( 'Contents' ) as $item ) {
				$objects[] = array(
					'key'           => $this->child_text( $item, 'Key' ),
					'size'          => (int) $this->child_text( $item, 'Size' ),
					'last_modified' => $this->child_text( $item, 'LastModified' ),
					'storage_class' => $this->child_text( $item, 'StorageClass' ),
				);
			}

			$token = 'true' === $this->child_text( $document, 'IsTruncated' ) ? $this->child_text( $document, 'NextContinuationToken' ) : '';
		} while ( '' !== $token );

		return $objects;
	}

	/**
	 * Lit les en-têtes d'un objet.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @param string $key    Clé de l'objet.
	 * @return array En-têtes de la réponse, noms en minuscules.
	 */
	public function head_object( $bucket, $key ) {
		return $this->request( 'HEAD', $bucket, $key )['headers'];
	}

	/**
	 * Envoie un objet en une seule requête.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket  Nom du bucket.
	 * @param string $key     Clé de l'objet.
	 * @param string $body    Contenu de l'objet.
	 * @param array  $headers En-têtes supplémentaires, par exemple « x-amz-storage-class ».
	 */
	public function put_object( $bucket, $key, $body, $headers = array() ) {
		$this->request( 'PUT', $bucket, $key, array(), $headers, $body );
	}

	/**
	 * Supprime un objet.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @param string $key    Clé de l'objet.
	 */
	public function delete_object( $bucket, $key ) {
		$this->request( 'DELETE', $bucket, $key );
	}

	/**
	 * Lit une plage d'octets d'un objet.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @param string $key    Clé de l'objet.
	 * @param int    $start  Premier octet, inclus.
	 * @param int    $end    Dernier octet, inclus.
	 * @return string Octets lus.
	 */
	public function get_object_range( $bucket, $key, $start, $end ) {
		$headers = array( 'range' => 'bytes=' . (int) $start . '-' . (int) $end );

		return $this->request( 'GET', $bucket, $key, array(), $headers )['body'];
	}

	/**
	 * Démarre un envoi multipart.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket  Nom du bucket.
	 * @param string $key     Clé de l'objet.
	 * @param array  $headers En-têtes supplémentaires, appliqués à l'objet final.
	 * @return string Identifiant de l'envoi.
	 *
	 * @throws Oueb_S3_Exception Si le service ne renvoie pas d'identifiant.
	 */
	public function create_multipart_upload( $bucket, $key, $headers = array() ) {
		$document  = $this->parse_xml( $this->request( 'POST', $bucket, $key, array( 'uploads' => '' ), $headers )['body'] );
		$upload_id = $this->child_text( $document, 'UploadId' );

		if ( '' === $upload_id ) {
			throw new Oueb_S3_Exception( 'The S3 service did not return an upload ID.' );
		}

		return $upload_id;
	}

	/**
	 * Envoie une partie d'un envoi multipart.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket      Nom du bucket.
	 * @param string $key         Clé de l'objet.
	 * @param string $upload_id   Identifiant de l'envoi.
	 * @param int    $part_number Numéro de la partie, de 1 à 10 000.
	 * @param string $body        Contenu de la partie.
	 * @return string ETag de la partie, guillemets compris.
	 *
	 * @throws Oueb_S3_Exception Si le service ne renvoie pas d'ETag.
	 */
	public function upload_part( $bucket, $key, $upload_id, $part_number, $body ) {
		$query   = array(
			'partNumber' => (string) (int) $part_number,
			'uploadId'   => (string) $upload_id,
		);
		$headers = $this->request( 'PUT', $bucket, $key, $query, array(), $body )['headers'];

		if ( empty( $headers['etag'] ) ) {
			throw new Oueb_S3_Exception( 'The S3 service did not return an ETag.' );
		}

		return (string) $headers['etag'];
	}

	/**
	 * Termine un envoi multipart.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket    Nom du bucket.
	 * @param string $key       Clé de l'objet.
	 * @param string $upload_id Identifiant de l'envoi.
	 * @param array  $parts     Parties envoyées, chacune avec « PartNumber » et « ETag ».
	 */
	public function complete_multipart_upload( $bucket, $key, $upload_id, $parts ) {
		usort( $parts, array( $this, 'compare_part_numbers' ) );

		$body = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			$body .= '<Part><PartNumber>' . (int) $part['PartNumber'] . '</PartNumber><ETag>' . esc_xml( $part['ETag'] ) . '</ETag></Part>';
		}
		$body .= '</CompleteMultipartUpload>';

		$response = $this->request( 'POST', $bucket, $key, array( 'uploadId' => (string) $upload_id ), array( 'content-type' => 'application/xml' ), $body );

		// S3 peut répondre 200 puis signaler l'échec dans le corps.
		if ( false !== strpos( $response['body'], '<Error>' ) ) {
			$this->throw_from_response( $response );
		}
	}

	/**
	 * Annule un envoi multipart et libère ses parties.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket    Nom du bucket.
	 * @param string $key       Clé de l'objet.
	 * @param string $upload_id Identifiant de l'envoi.
	 */
	public function abort_multipart_upload( $bucket, $key, $upload_id ) {
		$this->request( 'DELETE', $bucket, $key, array( 'uploadId' => (string) $upload_id ) );
	}

	/**
	 * Liste les envois multipart en cours sous un préfixe.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @param string $prefix Préfixe des clés.
	 * @return array[] Envois avec les clés « key » et « upload_id ».
	 */
	public function list_multipart_uploads( $bucket, $prefix = '' ) {
		$query    = array(
			'uploads' => '',
			'prefix'  => (string) $prefix,
		);
		$document = $this->parse_xml( $this->request( 'GET', $bucket, '', $query )['body'] );
		$uploads  = array();

		foreach ( $document->getElementsByTagName( 'Upload' ) as $upload ) {
			$uploads[] = array(
				'key'       => $this->child_text( $upload, 'Key' ),
				'upload_id' => $this->child_text( $upload, 'UploadId' ),
			);
		}

		return $uploads;
	}

	/**
	 * Construit l'URL d'un objet, sans signature.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @param string $key    Clé de l'objet.
	 * @return string URL de l'objet.
	 */
	public function object_url( $bucket, $key ) {
		$target = $this->target( $bucket, $key );

		return $this->scheme . '://' . $target['host'] . $target['path'];
	}

	/**
	 * Compare deux parties par numéro, pour usort().
	 *
	 * @since 0.1.0
	 *
	 * @param array $a Première partie.
	 * @param array $b Seconde partie.
	 * @return int Résultat de la comparaison.
	 */
	public function compare_part_numbers( $a, $b ) {
		return (int) $a['PartNumber'] <=> (int) $b['PartNumber'];
	}

	/**
	 * Envoie une requête signée et renvoie la réponse.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method  Méthode HTTP.
	 * @param string $bucket  Nom du bucket, vide pour une requête sur le compte.
	 * @param string $key     Clé de l'objet, vide pour une requête sur le bucket.
	 * @param array  $query   Paramètres de requête.
	 * @param array  $headers En-têtes supplémentaires.
	 * @param string $body    Corps de la requête.
	 * @return array Réponse avec les clés « status », « headers » et « body ».
	 *
	 * @throws Oueb_S3_Exception Sur une erreur de transport.
	 */
	private function request( $method, $bucket, $key, $query = array(), $headers = array(), $body = '' ) {
		$target       = $this->target( $bucket, $key );
		$query_string = $this->canonical_query( $query );
		$url          = $this->scheme . '://' . $target['host'] . $target['path'] . ( '' === $query_string ? '' : '?' . $query_string );
		$headers      = array_change_key_case( $headers, CASE_LOWER );

		// Sans type explicite, WordPress enverrait « application/x-www-form-urlencoded ».
		if ( '' !== $body && empty( $headers['content-type'] ) ) {
			$headers['content-type'] = 'application/octet-stream';
		}

		$headers = $this->sign( $method, $target['host'], $target['path'], $query_string, $headers, $body );

		/**
		 * Filtre les arguments des requêtes envoyées au service S3.
		 *
		 * @since 0.1.0
		 *
		 * @param array  $args   Arguments passés à wp_remote_request().
		 * @param string $method Méthode HTTP.
		 * @param string $url    URL de la requête.
		 */
		$args = apply_filters(
			'oueb_s3_request_args',
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $this->timeout,
				'redirection' => 0,
			),
			$method,
			$url
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Oueb_S3_Exception( esc_html( $response->get_error_message() ) );
		}

		$result = array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => array(),
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);

		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$result['headers'][ strtolower( $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		if ( $result['status'] < 200 || $result['status'] > 299 ) {
			$this->throw_from_response( $result );
		}

		return $result;
	}

	/**
	 * Calcule l'hôte et le chemin d'une requête.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket Nom du bucket.
	 * @param string $key    Clé de l'objet.
	 * @return array Tableau avec les clés « host » et « path », chemin déjà encodé.
	 */
	private function target( $bucket, $key ) {
		$bucket = (string) $bucket;
		$key    = ltrim( (string) $key, '/' );
		$path   = '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );

		if ( '' === $bucket ) {
			return array(
				'host' => $this->host,
				'path' => '/',
			);
		}

		// Un nom avec un point casserait le certificat du sous-domaine.
		if ( $this->path_style || false !== strpos( $bucket, '.' ) ) {
			return array(
				'host' => $this->host,
				'path' => '/' . rawurlencode( $bucket ) . ( '' === $key ? '' : $path ),
			);
		}

		return array(
			'host' => $bucket . '.' . $this->host,
			'path' => $path,
		);
	}

	/**
	 * Construit la chaîne de requête canonique : clés triées, valeurs encodées.
	 *
	 * @since 0.1.0
	 *
	 * @param array $query Paramètres de requête.
	 * @return string Chaîne de requête canonique.
	 */
	private function canonical_query( $query ) {
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
	 * Ajoute aux en-têtes la signature AWS Version 4.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method       Méthode HTTP.
	 * @param string $host         Hôte de la requête.
	 * @param string $path         Chemin encodé.
	 * @param string $query_string Chaîne de requête canonique.
	 * @param array  $headers      En-têtes, noms en minuscules.
	 * @param string $body         Corps de la requête.
	 * @return array En-têtes complétés.
	 */
	private function sign( $method, $host, $path, $query_string, $headers, $body ) {
		$amz_date = gmdate( 'Ymd\THis\Z' );
		$day      = substr( $amz_date, 0, 8 );
		$scope    = $day . '/' . $this->region . '/s3/aws4_request';

		$headers['host']                 = $host;
		$headers['x-amz-date']           = $amz_date;
		$headers['x-amz-content-sha256'] = '' === $body ? self::EMPTY_PAYLOAD_HASH : hash( 'sha256', $body );
		ksort( $headers, SORT_STRING );

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( preg_replace( '/\s+/', ' ', (string) $value ) ) . "\n";
		}
		$signed_headers = implode( ';', array_keys( $headers ) );

		$canonical_request = implode(
			"\n",
			array(
				$method,
				$path,
				$query_string,
				$canonical_headers,
				$signed_headers,
				$headers['x-amz-content-sha256'],
			)
		);

		$string_to_sign = self::ALGORITHM . "\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$signing_key = hash_hmac( 'sha256', $day, 'AWS4' . $this->secret_key, true );
		$signing_key = hash_hmac( 'sha256', $this->region, $signing_key, true );
		$signing_key = hash_hmac( 'sha256', 's3', $signing_key, true );
		$signing_key = hash_hmac( 'sha256', 'aws4_request', $signing_key, true );

		$headers['authorization'] = self::ALGORITHM
			. ' Credential=' . $this->access_key . '/' . $scope
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Requests calcule lui-même l'en-tête Host à partir de l'URL.
		unset( $headers['host'] );

		return $headers;
	}

	/**
	 * Lit une réponse XML du service.
	 *
	 * @since 0.1.0
	 *
	 * @param string $body Corps de la réponse.
	 * @return DOMDocument Document lu.
	 *
	 * @throws Oueb_S3_Exception Si le corps n'est pas du XML valide.
	 */
	private function parse_xml( $body ) {
		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$loaded   = '' !== (string) $body && $document->loadXML( (string) $body, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			throw new Oueb_S3_Exception( 'The S3 service returned an unreadable response.' );
		}

		return $document;
	}

	/**
	 * Renvoie le texte du premier élément descendant portant ce nom.
	 *
	 * @since 0.1.0
	 *
	 * @param DOMDocument|DOMElement $node Nœud de départ.
	 * @param string                 $name Nom de l'élément.
	 * @return string Texte de l'élément, vide s'il n'existe pas.
	 */
	private function child_text( $node, $name ) {
		$element = $node->getElementsByTagName( $name )->item( 0 );

		return null === $element ? '' : trim( (string) simplexml_import_dom( $element ) );
	}

	/**
	 * Lève l'exception correspondant à une réponse en erreur.
	 *
	 * @since 0.1.0
	 *
	 * @param array $response Réponse avec les clés « status » et « body ».
	 *
	 * @throws Oueb_S3_Exception Toujours.
	 */
	private function throw_from_response( $response ) {
		$code    = '';
		$message = sprintf( 'HTTP error %d.', $response['status'] );

		try {
			$document = $this->parse_xml( $response['body'] );
			$code     = $this->child_text( $document, 'Code' );
			$text     = $this->child_text( $document, 'Message' );
			if ( '' !== $code ) {
				$message = '' === $text ? $code : $text;
			}
		} catch ( Oueb_S3_Exception $e ) {
			// Corps vide ou non XML, par exemple pour HEAD : le statut HTTP suffit.
			$code = '';
		}

		throw new Oueb_S3_Exception( esc_html( $message ), esc_html( $code ), (int) $response['status'] );
	}
}
