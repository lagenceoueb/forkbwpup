<?php
/**
 * Client WebDAV, utilisé pour Infomaniak kDrive.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Parle à un serveur WebDAV avec une identification Basic.
 *
 * WebDAV n'a pas d'envoi par morceaux : un fichier part en une requête PUT,
 * lue en flux par cURL depuis le disque. Le fichier est envoyé sous un nom
 * provisoire, puis renommé : une sauvegarde incomplète ne porte jamais le
 * nom d'une sauvegarde.
 *
 * @since 0.1.0
 */
final class Webdav_Client {

	/**
	 * Débit minimal, en octets par seconde, en dessous duquel un envoi est figé.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const LOW_SPEED_LIMIT = 1024;

	/**
	 * Durée, en secondes, sous le débit minimal avant d'abandonner.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const LOW_SPEED_TIME = 120;

	/**
	 * Adresse de base, sans barre finale.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $base_url;

	/**
	 * Identifiant.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $user;

	/**
	 * Mot de passe.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $password;

	/**
	 * Crée le client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base_url Adresse de base.
	 * @param string $user     Identifiant.
	 * @param string $password Mot de passe.
	 */
	public function __construct( string $base_url, string $user, string $password ) {
		$this->base_url = untrailingslashit( $base_url );
		$this->user     = $user;
		$this->password = $password;
	}

	/**
	 * Crée un dossier et ses parents.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Dossier, relatif à l'adresse de base.
	 *
	 * @throws Remote_Exception Si un dossier ne peut pas être créé.
	 */
	public function ensure_dir( string $dir ): void {
		$path = '';
		foreach ( array_filter( explode( '/', $dir ), 'strlen' ) as $segment ) {
			$path    .= '/' . $segment;
			$response = $this->request( 'MKCOL', $path . '/' );

			// 405 : le dossier existe déjà.
			if ( ! in_array( $response['status'], array( 201, 405 ), true ) ) {
				$this->fail( $response['status'] );
			}
		}
	}

	/**
	 * Vérifie que le dossier est lisible.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Dossier.
	 *
	 * @throws Remote_Exception Si le serveur refuse.
	 */
	public function check( string $dir ): void {
		$response = $this->propfind( $dir, '0' );
		if ( 207 !== $response['status'] ) {
			$this->fail( $response['status'] );
		}
	}

	/**
	 * Envoie un fichier en flux.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $path     Chemin distant.
	 * @param string        $file     Fichier local.
	 * @param callable|null $progress Appelée avec le nombre d'octets envoyés.
	 *
	 * @throws Remote_Exception Si l'envoi échoue.
	 */
	public function upload( string $path, string $file, ?callable $progress = null ): void {
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			/* translators: %s: file path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $file ) ) );
		}

		$curl = curl_init( $this->url( $path ) );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_UPLOAD           => true,
				CURLOPT_INFILE           => $handle,
				CURLOPT_INFILESIZE       => (int) filesize( $file ),
				CURLOPT_USERPWD          => $this->user . ':' . $this->password,
				CURLOPT_HTTPAUTH         => CURLAUTH_BASIC,
				CURLOPT_RETURNTRANSFER   => true,
				CURLOPT_CONNECTTIMEOUT   => 30,
				// Pas de durée maximale pour une grosse archive, mais un envoi
				// figé sous 1 Ko/s pendant deux minutes s'arrête.
				CURLOPT_TIMEOUT          => 0,
				CURLOPT_LOW_SPEED_LIMIT  => self::LOW_SPEED_LIMIT,
				CURLOPT_LOW_SPEED_TIME   => self::LOW_SPEED_TIME,
				CURLOPT_HTTPHEADER       => array( 'Content-Type: application/octet-stream' ),
				CURLOPT_NOPROGRESS       => null === $progress,
				CURLOPT_XFERINFOFUNCTION => static function ( $curl_handle, $download_total, $downloaded, $upload_total, $uploaded ) use ( $progress ): int {
					if ( null !== $progress ) {
						call_user_func( $progress, (int) $uploaded );
					}
					return 0;
				},
			)
		);

		curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $curl );
		curl_close( $curl );
		fclose( $handle );

		if ( '' !== $error ) {
			throw new Remote_Exception( esc_html( $error ) );
		}
		if ( ! in_array( $status, array( 200, 201, 204 ), true ) ) {
			$this->fail( $status );
		}
	}

	/**
	 * Renomme un fichier, en remplaçant la cible.
	 *
	 * @since 0.1.0
	 *
	 * @param string $from Chemin actuel.
	 * @param string $to   Nouveau chemin.
	 *
	 * @throws Remote_Exception Si le serveur refuse.
	 */
	public function move( string $from, string $to ): void {
		$response = $this->request(
			'MOVE',
			$from,
			'',
			array(
				'Destination' => $this->url( $to ),
				'Overwrite'   => 'T',
			)
		);
		if ( ! in_array( $response['status'], array( 201, 204 ), true ) ) {
			$this->fail( $response['status'] );
		}
	}

	/**
	 * Lit une plage d'octets.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path  Chemin distant.
	 * @param int    $start Premier octet.
	 * @param int    $end   Dernier octet, inclus.
	 * @return string Octets lus.
	 *
	 * @throws Remote_Exception Si la lecture échoue.
	 */
	public function read( string $path, int $start, int $end ): string {
		$response = $this->request( 'GET', $path, '', array( 'Range' => 'bytes=' . $start . '-' . $end ) );
		if ( 206 === $response['status'] ) {
			return $response['body'];
		}
		if ( 200 === $response['status'] ) {
			// Serveur sans plages : il renvoie tout le fichier.
			return (string) substr( $response['body'], $start, $end - $start + 1 );
		}
		$this->fail( $response['status'] );
	}

	/**
	 * Liste les fichiers d'un dossier, sans les sous-dossiers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Dossier.
	 * @return array<int, array{name: string, size: int, time: int}> Fichiers.
	 *
	 * @throws Remote_Exception Si la liste échoue.
	 */
	public function list_files( string $dir ): array {
		$response = $this->propfind( $dir, '1' );
		if ( 404 === $response['status'] ) {
			return array();
		}
		if ( 207 !== $response['status'] ) {
			$this->fail( $response['status'] );
		}

		$document = Http::xml( $response['body'] );
		if ( null === $document ) {
			throw new Remote_Exception( esc_html__( 'The WebDAV server returned an unreadable list.', 'oueb-wp-backup' ) );
		}

		$files = array();
		foreach ( $document->getElementsByTagNameNS( 'DAV:', 'response' ) as $item ) {
			if ( $item->getElementsByTagNameNS( 'DAV:', 'collection' )->length > 0 ) {
				continue;
			}
			$href    = Http::text( $item, 'href' );
			$files[] = array(
				'name' => rawurldecode( basename( untrailingslashit( $href ) ) ),
				'size' => (int) Http::text( $item, 'getcontentlength' ),
				'time' => (int) strtotime( Http::text( $item, 'getlastmodified' ) ),
			);
		}

		return $files;
	}

	/**
	 * Supprime un fichier. Un fichier déjà absent n'est pas une erreur.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Chemin distant.
	 *
	 * @throws Remote_Exception Si le serveur refuse.
	 */
	public function delete( string $path ): void {
		$status = $this->request( 'DELETE', $path )['status'];
		if ( ! in_array( $status, array( 200, 204, 404 ), true ) ) {
			$this->fail( $status );
		}
	}

	/**
	 * Interroge les propriétés d'un dossier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir   Dossier.
	 * @param string $depth Profondeur : 0 ou 1.
	 * @return array{status: int, headers: array<string, string>, body: string} Réponse.
	 */
	private function propfind( string $dir, string $depth ): array {
		return $this->request(
			'PROPFIND',
			trailingslashit( '/' . ltrim( $dir, '/' ) ),
			'<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>',
			array(
				'Depth'        => $depth,
				'Content-Type' => 'application/xml; charset=utf-8',
			)
		);
	}

	/**
	 * Construit l'adresse d'un chemin, segments encodés.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Chemin.
	 * @return string Adresse.
	 */
	private function url( string $path ): string {
		$trailing = '/' === substr( $path, -1 );
		$segments = array_filter( explode( '/', $path ), 'strlen' );

		return $this->base_url . '/' . implode( '/', array_map( 'rawurlencode', $segments ) ) . ( $trailing && $segments ? '/' : '' );
	}

	/**
	 * Envoie une requête identifiée.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $method  Méthode.
	 * @param string                $path    Chemin.
	 * @param string                $body    Corps.
	 * @param array<string, string> $headers En-têtes.
	 * @return array{status: int, headers: array<string, string>, body: string} Réponse.
	 */
	private function request( string $method, string $path, string $body = '', array $headers = array() ): array {
		$headers['Authorization'] = 'Basic ' . base64_encode( $this->user . ':' . $this->password );

		return Http::request(
			$this->url( $path ),
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 120,
			),
			'webdav'
		);
	}

	/**
	 * Lève l'erreur qui correspond à un statut HTTP.
	 *
	 * @since 0.1.0
	 *
	 * @param int $status Statut.
	 * @return never
	 *
	 * @throws Remote_Exception Toujours.
	 */
	private function fail( int $status ) {
		$messages = array(
			401 => __( 'The server refused the email address or the password. With kDrive, use an application password.', 'oueb-wp-backup' ),
			403 => __( 'The server refused access to this folder.', 'oueb-wp-backup' ),
			404 => __( 'The folder does not exist on the server.', 'oueb-wp-backup' ),
			507 => __( 'The storage space is full.', 'oueb-wp-backup' ),
		);

		throw new Remote_Exception(
			esc_html(
				$messages[ $status ] ?? sprintf(
				/* translators: %d: HTTP status code. */
					__( 'The WebDAV server answered with the HTTP error %d.', 'oueb-wp-backup' ),
					$status
				)
			),
			(int) $status
		);
	}
}
