<?php
/**
 * Connexion SFTP pour les sauvegardes.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * Ouvre une session SFTP et vérifie l'identité du serveur.
 *
 * L'empreinte de la clé du serveur est mémorisée à la première connexion
 * réussie, puis comparée à chaque connexion suivante. Un serveur dont la
 * clé change est refusé avant tout envoi de données.
 *
 * @since 0.1.0
 */
class Oueb_Sftp_Client {

	/**
	 * Session SFTP ouverte.
	 *
	 * @since 0.1.0
	 * @var SFTP
	 */
	private $sftp;

	/**
	 * Empreinte SHA-256 de la clé du serveur, au format d'OpenSSH.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $fingerprint;

	/**
	 * Ouvre la session.
	 *
	 * @since 0.1.0
	 *
	 * @param array $settings {
	 *     Réglages de connexion.
	 *
	 *     @type string $host        Nom ou adresse du serveur.
	 *     @type int    $port        Port SSH.
	 *     @type string $user        Identifiant.
	 *     @type string $auth        « password » ou « key ».
	 *     @type string $password    Mot de passe, en clair.
	 *     @type string $private_key Clé privée au format PEM ou OpenSSH, en clair.
	 *     @type string $passphrase  Phrase de passe de la clé, en clair.
	 *     @type string $fingerprint Empreinte attendue, vide à la première connexion.
	 *     @type int    $timeout     Délai d'attente, en secondes.
	 * }
	 *
	 * @throws Oueb_Sftp_Exception Si le serveur ne répond pas, change de clé ou refuse l'identification.
	 */
	public function __construct( $settings ) {
		$settings = wp_parse_args(
			$settings,
			array(
				'host'        => '',
				'port'        => 22,
				'user'        => '',
				'auth'        => 'password',
				'password'    => '',
				'private_key' => '',
				'passphrase'  => '',
				'fingerprint' => '',
				'timeout'     => 30,
			)
		);

		if ( '' === $settings['host'] || '' === $settings['user'] ) {
			throw new Oueb_Sftp_Exception( esc_html__( 'Enter the SFTP server and the username.', 'oueb-wp-backup' ) );
		}

		try {
			$this->sftp = new SFTP( $settings['host'], (int) $settings['port'], (int) $settings['timeout'] );
			$host_key   = $this->sftp->getServerPublicHostKey();
		} catch ( Exception $e ) {
			throw new Oueb_Sftp_Exception( esc_html( $e->getMessage() ) );
		}

		if ( false === $host_key ) {
			throw new Oueb_Sftp_Exception( esc_html__( 'The SFTP server does not answer.', 'oueb-wp-backup' ) );
		}

		$this->fingerprint = self::fingerprint( $host_key );

		if ( '' !== $settings['fingerprint'] && ! hash_equals( $settings['fingerprint'], $this->fingerprint ) ) {
			throw new Oueb_Sftp_Exception(
				esc_html(
					sprintf(
						/* translators: 1: expected fingerprint, 2: received fingerprint. */
						__( 'The SFTP server key has changed. Expected %1$s, received %2$s. If the server was reinstalled, clear the saved fingerprint in the job settings.', 'oueb-wp-backup' ),
						$settings['fingerprint'],
						$this->fingerprint
					)
				)
			);
		}

		try {
			$credential = $settings['password'];
			if ( 'key' === $settings['auth'] ) {
				$credential = PublicKeyLoader::load( $settings['private_key'], '' === $settings['passphrase'] ? false : $settings['passphrase'] );
			}
			$logged_in = $this->sftp->login( $settings['user'], $credential );
		} catch ( Exception $e ) {
			throw new Oueb_Sftp_Exception( esc_html( $e->getMessage() ) );
		}

		if ( ! $logged_in ) {
			throw new Oueb_Sftp_Exception( esc_html__( 'The SFTP server refused the username, password or key.', 'oueb-wp-backup' ) );
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
	public static function fingerprint( $public_key ) {
		$parts = explode( ' ', trim( (string) $public_key ) );
		$blob  = sodium_base642bin( isset( $parts[1] ) ? $parts[1] : $parts[0], SODIUM_BASE64_VARIANT_ORIGINAL );

		return 'SHA256:' . sodium_bin2base64( hash( 'sha256', $blob, true ), SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING );
	}

	/**
	 * Renvoie l'empreinte de la clé du serveur.
	 *
	 * @since 0.1.0
	 *
	 * @return string Empreinte au format d'OpenSSH.
	 */
	public function get_fingerprint() {
		return $this->fingerprint;
	}

	/**
	 * Crée un dossier et ses parents s'il n'existe pas.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Chemin du dossier.
	 *
	 * @throws Oueb_Sftp_Exception Si le dossier ne peut pas être créé.
	 */
	public function ensure_dir( $dir ) {
		if ( '' === $dir || $this->guard( fn() => $this->sftp->is_dir( $dir ) ) ) {
			return;
		}

		if ( ! $this->guard( fn() => $this->sftp->mkdir( $dir, -1, true ) ) ) {
			/* translators: %s: folder path on the server. */
			throw new Oueb_Sftp_Exception( esc_html( sprintf( __( 'Cannot create the folder %s on the SFTP server.', 'oueb-wp-backup' ), $dir ) ) );
		}
	}

	/**
	 * Envoie un fichier local, en reprenant un envoi interrompu si demandé.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $remote_file Chemin distant.
	 * @param string   $local_file  Chemin local.
	 * @param bool     $resume      Vrai pour compléter un fichier distant partiel.
	 * @param callable $progress    Fonction appelée avec le nombre d'octets envoyés.
	 *
	 * @throws Oueb_Sftp_Exception Si l'envoi échoue.
	 */
	public function upload( $remote_file, $local_file, $resume = false, $progress = null ) {
		$mode = SFTP::SOURCE_LOCAL_FILE | ( $resume ? SFTP::RESUME : 0 );

		if ( ! $this->guard( fn() => $this->sftp->put( $remote_file, $local_file, $mode, -1, -1, $progress ) ) ) {
			/* translators: %s: file path on the server. */
			throw new Oueb_Sftp_Exception( esc_html( sprintf( __( 'The upload of %s to the SFTP server failed.', 'oueb-wp-backup' ), $remote_file ) ) );
		}
	}

	/**
	 * Lit une plage d'octets d'un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $remote_file Chemin distant.
	 * @param int    $offset      Premier octet.
	 * @param int    $length      Nombre d'octets.
	 * @return string Octets lus.
	 *
	 * @throws Oueb_Sftp_Exception Si la lecture échoue.
	 */
	public function read( $remote_file, $offset, $length ) {
		$data = $this->guard( fn() => $this->sftp->get( $remote_file, false, (int) $offset, (int) $length ) );

		if ( false === $data ) {
			/* translators: %s: file path on the server. */
			throw new Oueb_Sftp_Exception( esc_html( sprintf( __( 'Cannot read %s on the SFTP server.', 'oueb-wp-backup' ), $remote_file ) ) );
		}

		return $data;
	}

	/**
	 * Renvoie la taille d'un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $remote_file Chemin distant.
	 * @return int Taille en octets, 0 si le fichier n'existe pas.
	 */
	public function size( $remote_file ) {
		$stat = $this->guard( fn() => $this->sftp->stat( $remote_file ) );

		return is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
	}

	/**
	 * Liste les fichiers d'un dossier, sans les sous-dossiers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Chemin du dossier.
	 * @return array[] Fichiers avec les clés « name », « size » et « mtime ».
	 */
	public function list_files( $dir ) {
		$entries = $this->guard( fn() => $this->sftp->rawlist( '' === $dir ? '.' : $dir ) );
		$files   = array();

		if ( ! is_array( $entries ) ) {
			return $files;
		}

		foreach ( $entries as $name => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['type'] ) || NET_SFTP_TYPE_REGULAR !== $entry['type'] ) {
				continue;
			}

			$files[] = array(
				'name'  => (string) $name,
				'size'  => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
				'mtime' => isset( $entry['mtime'] ) ? (int) $entry['mtime'] : 0,
			);
		}

		return $files;
	}

	/**
	 * Supprime un fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $remote_file Chemin distant.
	 * @return bool Vrai si le fichier a été supprimé.
	 */
	public function delete( $remote_file ) {
		return (bool) $this->guard( fn() => $this->sftp->delete( $remote_file, false ) );
	}

	/**
	 * Exécute un appel à phpseclib en convertissant ses exceptions.
	 *
	 * La bibliothèque phpseclib lève ses propres exceptions, par exemple quand le serveur coupe
	 * la connexion pendant un envoi. Les appelants n'attrapent que
	 * Oueb_Sftp_Exception : sans conversion, la tâche s'arrêterait net, sans
	 * passer aux destinations suivantes ni libérer son verrou.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $call Appel à exécuter.
	 * @return mixed Valeur renvoyée par l'appel.
	 *
	 * @throws Oueb_Sftp_Exception Si l'appel lève une exception.
	 */
	private function guard( $call ) {
		try {
			return $call();
		} catch ( Oueb_Sftp_Exception $e ) {
			throw $e;
		} catch ( Exception $e ) {
			/* translators: %s: technical error message. */
			throw new Oueb_Sftp_Exception( esc_html( sprintf( __( 'The SFTP connection failed: %s', 'oueb-wp-backup' ), $e->getMessage() ) ) );
		}
	}
}
