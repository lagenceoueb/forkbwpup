<?php
/**
 * Client WebDAV léger.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Envoie, liste, lit et supprime des fichiers sur un serveur WebDAV.
 *
 * Les petites requêtes passent par l'API HTTP de WordPress. L'envoi d'une
 * archive passe par cURL, qui lit le fichier en flux depuis le disque :
 * l'API HTTP de WordPress chargerait l'archive entière en mémoire.
 *
 * @since 0.1.0
 */
class Oueb_Webdav_Client {

	/**
	 * Adresse de base, sans barre oblique finale.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $base_url;

	/**
	 * Identifiant de connexion.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $user;

	/**
	 * Mot de passe, en clair.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $password;

	/**
	 * Construit le client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base_url Adresse du serveur, en HTTPS hors tests.
	 * @param string $user     Identifiant de connexion.
	 * @param string $password Mot de passe, en clair.
	 */
	public function __construct( $base_url, $user, $password ) {
		$this->base_url = untrailingslashit( (string) $base_url );
		$this->user     = (string) $user;
		$this->password = (string) $password;
	}

	/**
	 * Crée un dossier et ses parents s'ils n'existent pas.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Chemin du dossier, segments séparés par « / ».
	 *
	 * @throws Oueb_Webdav_Exception Si un dossier ne peut pas être créé.
	 */
	public function ensure_dir( $dir ) {
		$path = '';

		foreach ( array_filter( explode( '/', (string) $dir ), 'strlen' ) as $segment ) {
			$path    .= '/' . $segment;
			$response = $this->request( 'MKCOL', $path . '/' );

			// 405 : le dossier existe déjà.
			if ( ! in_array( $response['status'], array( 201, 405 ), true ) ) {
				$this->fail( $response );
			}
		}
	}

	/**
	 * Envoie un fichier local en flux.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $remote_path Chemin distant.
	 * @param string   $local_file  Chemin local.
	 * @param callable $progress    Fonction appelée avec le nombre d'octets envoyés.
	 *
	 * @throws Oueb_Webdav_Exception Si l'envoi échoue.
	 */
	public function upload( $remote_path, $local_file, $progress = null ) {
		$handle = fopen( $local_file, 'rb' );
		if ( false === $handle ) {
			throw new Oueb_Webdav_Exception( esc_html__( 'Can not open source file for transfer.', 'oueb-wp-backup' ) );
		}

		$curl = curl_init( $this->url( $remote_path ) );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_UPLOAD           => true,
				CURLOPT_INFILE           => $handle,
				CURLOPT_INFILESIZE       => filesize( $local_file ),
				CURLOPT_USERPWD          => $this->user . ':' . $this->password,
				CURLOPT_HTTPAUTH         => CURLAUTH_BASIC,
				CURLOPT_RETURNTRANSFER   => true,
				CURLOPT_TIMEOUT          => 0,
				CURLOPT_CONNECTTIMEOUT   => 30,
				CURLOPT_HTTPHEADER       => array( 'Content-Type: application/octet-stream' ),
				CURLOPT_NOPROGRESS       => null === $progress,
				CURLOPT_XFERINFOFUNCTION => static function ( $curl_handle, $download_total, $downloaded, $upload_total, $uploaded ) use ( $progress ) {
					if ( null !== $progress && $uploaded > 0 ) {
						call_user_func( $progress, $uploaded );
					}
					return 0;
				},
			)
		);

		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $curl );
		curl_close( $curl );
		fclose( $handle );

		if ( '' !== $error ) {
			throw new Oueb_Webdav_Exception( esc_html( $error ) );
		}
		if ( ! in_array( $status, array( 200, 201, 204 ), true ) ) {
			$this->fail(
				array(
					'status' => $status,
					'body'   => (string) $body,
				)
			);
		}
	}

	/**
	 * Lit une plage d'octets d'un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $remote_path Chemin distant.
	 * @param int    $start       Premier octet, inclus.
	 * @param int    $end         Dernier octet, inclus.
	 * @return string Octets lus.
	 *
	 * @throws Oueb_Webdav_Exception Si la lecture échoue.
	 */
	public function read( $remote_path, $start, $end ) {
		$response = $this->request( 'GET', $remote_path, '', array( 'Range' => 'bytes=' . (int) $start . '-' . (int) $end ) );

		if ( ! in_array( $response['status'], array( 200, 206 ), true ) ) {
			$this->fail( $response );
		}

		// Un serveur sans prise en charge des plages renvoie tout le fichier.
		if ( 200 === $response['status'] ) {
			return (string) substr( $response['body'], (int) $start, (int) $end - (int) $start + 1 );
		}

		return $response['body'];
	}

	/**
	 * Liste les fichiers d'un dossier, sans les sous-dossiers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Chemin du dossier.
	 * @return array[] Fichiers avec les clés « name », « size » et « mtime ».
	 *
	 * @throws Oueb_Webdav_Exception Si le dossier ne peut pas être lu.
	 */
	public function list_files( $dir ) {
		$body     = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>';
		$response = $this->request(
			'PROPFIND',
			trailingslashit( '/' . ltrim( (string) $dir, '/' ) ),
			$body,
			array(
				'Depth'        => '1',
				'Content-Type' => 'application/xml; charset=utf-8',
			)
		);

		if ( 404 === $response['status'] ) {
			return array();
		}
		if ( 207 !== $response['status'] ) {
			$this->fail( $response );
		}

		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$loaded   = $document->loadXML( $response['body'], LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$files = array();
		if ( ! $loaded ) {
			return $files;
		}

		foreach ( $document->getElementsByTagNameNS( 'DAV:', 'response' ) as $item ) {
			if ( $item->getElementsByTagNameNS( 'DAV:', 'collection' )->length > 0 ) {
				continue;
			}

			$href    = $this->node_text( $item, 'href' );
			$files[] = array(
				'name'  => rawurldecode( basename( untrailingslashit( $href ) ) ),
				'size'  => (int) $this->node_text( $item, 'getcontentlength' ),
				'mtime' => (int) strtotime( $this->node_text( $item, 'getlastmodified' ) ),
			);
		}

		return $files;
	}

	/**
	 * Renvoie la taille d'un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $remote_path Chemin distant.
	 * @return int Taille en octets, 0 si le fichier n'existe pas.
	 */
	public function size( $remote_path ) {
		$response = $this->request( 'HEAD', $remote_path );

		return 200 === $response['status'] && isset( $response['headers']['content-length'] ) ? (int) $response['headers']['content-length'] : 0;
	}

	/**
	 * Supprime un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $remote_path Chemin distant.
	 * @return bool Vrai si le fichier a été supprimé ou n'existait pas.
	 */
	public function delete( $remote_path ) {
		return in_array( $this->request( 'DELETE', $remote_path )['status'], array( 200, 204, 404 ), true );
	}

	/**
	 * Encode un chemin distant segment par segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Chemin distant.
	 * @return string Adresse complète.
	 */
	private function url( $path ) {
		$trailing = '/' === substr( (string) $path, -1 );
		$segments = array_filter( explode( '/', (string) $path ), 'strlen' );

		return $this->base_url . '/' . implode( '/', array_map( 'rawurlencode', $segments ) ) . ( $trailing && $segments ? '/' : '' );
	}

	/**
	 * Envoie une requête par l'API HTTP de WordPress.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method  Méthode HTTP ou WebDAV.
	 * @param string $path    Chemin distant.
	 * @param string $body    Corps de la requête.
	 * @param array  $headers En-têtes supplémentaires.
	 * @return array Réponse avec les clés « status », « headers » et « body ».
	 *
	 * @throws Oueb_Webdav_Exception Sur une erreur de transport.
	 */
	private function request( $method, $path, $body = '', $headers = array() ) {
		$headers['Authorization'] = 'Basic ' . sodium_bin2base64( $this->user . ':' . $this->password, SODIUM_BASE64_VARIANT_ORIGINAL );

		$response = wp_remote_request(
			$this->url( $path ),
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => 60,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Oueb_Webdav_Exception( esc_html( $response->get_error_message() ) );
		}

		$result = array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => array(),
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$result['headers'][ strtolower( $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return $result;
	}

	/**
	 * Renvoie le texte du premier élément DAV portant ce nom.
	 *
	 * @since 0.1.0
	 *
	 * @param DOMElement $node Nœud de départ.
	 * @param string     $name Nom local de l'élément.
	 * @return string Texte, vide si absent.
	 */
	private function node_text( $node, $name ) {
		$element = $node->getElementsByTagNameNS( 'DAV:', $name )->item( 0 );

		return null === $element ? '' : trim( (string) simplexml_import_dom( $element ) );
	}

	/**
	 * Lève l'exception correspondant à une réponse en erreur.
	 *
	 * @since 0.1.0
	 *
	 * @param array $response Réponse avec la clé « status ».
	 *
	 * @throws Oueb_Webdav_Exception Toujours.
	 */
	private function fail( $response ) {
		$messages = array(
			401 => __( 'The server refused the username or password. With kDrive, use an application password.', 'oueb-wp-backup' ),
			403 => __( 'The server refused access to this folder.', 'oueb-wp-backup' ),
			404 => __( 'The folder does not exist on the server.', 'oueb-wp-backup' ),
			507 => __( 'The storage space is full.', 'oueb-wp-backup' ),
		);
		/* translators: %d: HTTP status code. */
		$message = isset( $messages[ $response['status'] ] ) ? $messages[ $response['status'] ] : sprintf( __( 'The WebDAV server answered with error %d.', 'oueb-wp-backup' ), $response['status'] );

		throw new Oueb_Webdav_Exception( esc_html( $message ), (int) $response['status'] );
	}
}
