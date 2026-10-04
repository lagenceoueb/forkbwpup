<?php
/**
 * Connexion SFTP, avec vérification de l'identité du serveur.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Remote;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Ouvre une session SFTP avec phpseclib, seule dépendance de l'extension.
 *
 * L'empreinte de la clé du serveur est retenue à la première connexion
 * réussie, puis comparée aux suivantes. Un serveur dont la clé change est
 * refusé avant tout échange de données.
 *
 * Toutes les erreurs de phpseclib deviennent des Remote_Exception : sans
 * cette conversion, une connexion coupée arrêterait l'exécution sans passer
 * par les nouveaux essais du moteur.
 *
 * @since 0.1.0
 */
final class Sftp_Client {

	/**
	 * Session ouverte.
	 *
	 * @since 0.1.0
	 * @var SFTP
	 */
	private SFTP $sftp;

	/**
	 * Empreinte SHA-256 de la clé du serveur, au format d'OpenSSH.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $fingerprint;

	/**
	 * Ouvre la session.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings {
	 *     Réglages de connexion.
	 *
	 *     @type string $host        Serveur.
	 *     @type int    $port        Port.
	 *     @type string $user        Identifiant.
	 *     @type string $auth        « password » ou « key ».
	 *     @type string $password    Mot de passe.
	 *     @type string $private_key Clé privée, au format OpenSSH ou PEM.
	 *     @type string $passphrase  Phrase de passe de la clé.
	 *     @type string $fingerprint Empreinte attendue, vide à la première connexion.
	 * }
	 *
	 * @throws Remote_Exception Si le serveur ne répond pas, change de clé ou refuse l'identification.
	 */
	public function __construct( array $settings ) {
		$settings += array(
			'host'        => '',
			'port'        => 22,
			'user'        => '',
			'auth'        => 'password',
			'password'    => '',
			'private_key' => '',
			'passphrase'  => '',
			'fingerprint' => '',
		);

		$host_key = self::guard(
			function () use ( $settings ) {
				$this->sftp = new SFTP( (string) $settings['host'], (int) $settings['port'], 30 );
				return $this->sftp->getServerPublicHostKey();
			}
		);
		if ( ! is_string( $host_key ) || '' === $host_key ) {
			throw new Remote_Exception( esc_html__( 'The SFTP server does not answer.', 'oueb-wp-backup' ) );
		}

		$this->fingerprint = self::fingerprint( $host_key );
		if ( '' !== $settings['fingerprint'] && ! hash_equals( (string) $settings['fingerprint'], $this->fingerprint ) ) {
			throw new Remote_Exception(
				esc_html(
					sprintf(
					/* translators: 1: expected fingerprint, 2: received fingerprint. */
						__( 'The key of the SFTP server has changed: %1$s was expected, %2$s was received. If the server was reinstalled, forget the saved key in the storage settings.', 'oueb-wp-backup' ),
						$settings['fingerprint'],
						$this->fingerprint
					)
				)
			);
		}

		$logged_in = self::guard(
			function () use ( $settings ) {
				$credential = (string) $settings['password'];
				if ( 'key' === $settings['auth'] ) {
					$credential = PublicKeyLoader::load( (string) $settings['private_key'], '' === $settings['passphrase'] ? false : (string) $settings['passphrase'] );
				}
				return $this->sftp->login( (string) $settings['user'], $credential );
			}
		);
		if ( true !== $logged_in ) {
			throw new Remote_Exception( esc_html__( 'The SFTP server refused the username, the password or the key.', 'oueb-wp-backup' ) );
		}
	}

	/**
	 * Calcule l'empreinte d'une clé publique SSH, au format d'OpenSSH.
	 *
	 * @since 0.1.0
	 *
	 * @param string $public_key Clé publique, par exemple « ssh-ed25519 AAAA… ».
	 * @return string Empreinte, par exemple « SHA256:47DEQpj8… ».
	 */
	public static function fingerprint( string $public_key ): string {
		$parts = explode( ' ', trim( $public_key ) );
		$blob  = (string) base64_decode( $parts[1] ?? $parts[0], true );

		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' );
	}

	/**
	 * Renvoie l'empreinte de la clé du serveur.
	 *
	 * @since 0.1.0
	 *
	 * @return string Empreinte.
	 */
	public function server_fingerprint(): string {
		return $this->fingerprint;
	}

	/**
	 * Crée un dossier et ses parents s'il n'existe pas.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Dossier.
	 *
	 * @throws Remote_Exception Si le dossier ne peut pas être créé.
	 */
	public function ensure_dir( string $dir ): void {
		if ( '' === $dir || self::guard( fn() => $this->sftp->is_dir( $dir ) ) ) {
			return;
		}
		if ( ! self::guard( fn() => $this->sftp->mkdir( $dir, -1, true ) ) ) {
			/* translators: %s: folder path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot create the folder %s on the SFTP server.', 'oueb-wp-backup' ), $dir ) ) );
		}
	}

	/**
	 * Crée un fichier vide, ou vide un fichier existant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Fichier distant.
	 *
	 * @throws Remote_Exception Si le fichier ne peut pas être créé.
	 */
	public function truncate( string $file ): void {
		if ( ! self::guard( fn() => $this->sftp->put( $file, '' ) ) ) {
			/* translators: %s: file path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot write %s on the SFTP server.', 'oueb-wp-backup' ), $file ) ) );
		}
	}

	/**
	 * Écrit des octets à une position d'un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file   Fichier distant.
	 * @param string $data   Octets.
	 * @param int    $offset Position.
	 *
	 * @throws Remote_Exception Si l'écriture échoue.
	 */
	public function write( string $file, string $data, int $offset ): void {
		if ( ! self::guard( fn() => $this->sftp->put( $file, $data, SFTP::SOURCE_STRING, $offset ) ) ) {
			/* translators: %s: file path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot write %s on the SFTP server.', 'oueb-wp-backup' ), $file ) ) );
		}
	}

	/**
	 * Lit une plage d'octets.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file   Fichier distant.
	 * @param int    $offset Position.
	 * @param int    $length Nombre d'octets.
	 * @return string Octets lus.
	 *
	 * @throws Remote_Exception Si la lecture échoue.
	 */
	public function read( string $file, int $offset, int $length ): string {
		$data = self::guard( fn() => $this->sftp->get( $file, false, $offset, $length ) );
		if ( ! is_string( $data ) ) {
			/* translators: %s: file path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot read %s on the SFTP server.', 'oueb-wp-backup' ), $file ) ) );
		}

		return $data;
	}

	/**
	 * Renvoie la taille d'un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Fichier distant.
	 * @return int Taille, -1 si le fichier n'existe pas.
	 */
	public function size( string $file ): int {
		$stat = self::guard( fn() => $this->sftp->stat( $file ) );

		return is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : -1;
	}

	/**
	 * Renomme un fichier distant, en remplaçant la cible.
	 *
	 * @since 0.1.0
	 *
	 * @param string $from Nom actuel.
	 * @param string $to   Nouveau nom.
	 *
	 * @throws Remote_Exception Si le renommage échoue.
	 */
	public function rename( string $from, string $to ): void {
		self::guard( fn() => $this->sftp->delete( $to, false ) );
		if ( ! self::guard( fn() => $this->sftp->rename( $from, $to ) ) ) {
			/* translators: %s: file path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot rename %s on the SFTP server.', 'oueb-wp-backup' ), $from ) ) );
		}
	}

	/**
	 * Liste les fichiers d'un dossier, sans les sous-dossiers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Dossier.
	 * @return array<int, array{name: string, size: int, time: int}> Fichiers.
	 *
	 * @throws Remote_Exception Si le dossier ne peut pas être lu.
	 */
	public function list_files( string $dir ): array {
		$entries = self::guard( fn() => $this->sftp->rawlist( '' === $dir ? '.' : $dir ) );
		if ( ! is_array( $entries ) ) {
			/* translators: %s: folder path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot read the folder %s on the SFTP server.', 'oueb-wp-backup' ), $dir ) ) );
		}

		$files = array();
		foreach ( $entries as $name => $entry ) {
			if ( is_array( $entry ) && NET_SFTP_TYPE_REGULAR === ( $entry['type'] ?? null ) ) {
				$files[] = array(
					'name' => (string) $name,
					'size' => (int) ( $entry['size'] ?? 0 ),
					'time' => (int) ( $entry['mtime'] ?? 0 ),
				);
			}
		}

		return $files;
	}

	/**
	 * Supprime un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Fichier distant.
	 *
	 * @throws Remote_Exception Si la suppression échoue.
	 */
	public function delete( string $file ): void {
		if ( ! self::guard( fn() => $this->sftp->delete( $file, false ) ) ) {
			/* translators: %s: file path. */
			throw new Remote_Exception( esc_html( sprintf( __( 'Cannot delete %s on the SFTP server.', 'oueb-wp-backup' ), $file ) ) );
		}
	}

	/**
	 * Exécute un appel à phpseclib en convertissant ses erreurs.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $call Appel.
	 * @return mixed Valeur de l'appel.
	 *
	 * @throws Remote_Exception Si l'appel échoue.
	 */
	private static function guard( callable $call ) {
		try {
			return $call();
		} catch ( Remote_Exception $error ) {
			throw $error;
		} catch ( Throwable $error ) {
			/* translators: %s: technical error message. */
			throw new Remote_Exception( esc_html( sprintf( __( 'The SFTP connection failed: %s', 'oueb-wp-backup' ), $error->getMessage() ) ) );
		}
	}
}
