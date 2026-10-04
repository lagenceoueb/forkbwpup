<?php
/**
 * Stockage sur un serveur SFTP.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use Oueb\WpBackup\Remote\Sftp_Client;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Range les archives sur un serveur SFTP administré par le client.
 *
 * L'archive est écrite par morceaux sous un nom provisoire, puis renommée.
 * Après une coupure, l'envoi reprend au dernier morceau confirmé.
 *
 * @since 0.1.0
 */
final class Sftp_Storage implements Storage {

	/**
	 * Taille des morceaux envoyés.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 4194304;

	/**
	 * Réglages, secrets déchiffrés.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Mémorise l'empreinte du serveur à la première connexion.
	 *
	 * @since 0.1.0
	 * @var callable|null
	 */
	private $remember;

	/**
	 * Session, ouverte à la première utilisation.
	 *
	 * @since 0.1.0
	 * @var Sftp_Client|null
	 */
	private ?Sftp_Client $client = null;

	/**
	 * Crée le stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages, secrets déchiffrés.
	 * @param callable|null        $remember Reçoit l'empreinte du serveur quand aucune n'est connue.
	 */
	public function __construct( array $settings, ?callable $remember = null ) {
		$this->settings = $settings;
		$this->remember = $remember;
	}

	/**
	 * Libellé du type.
	 *
	 * @since 0.1.0
	 *
	 * @return string Libellé.
	 */
	public static function label(): string {
		return __( 'SFTP server', 'oueb-wp-backup' );
	}

	/**
	 * Champs des réglages.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Champs.
	 */
	public static function fields(): array {
		return array(
			'host'        => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '/^[A-Za-z0-9.\-:\[\]]{1,253}$/',
			),
			'port'        => array(
				'type'    => 'int',
				'min'     => 1,
				'max'     => 65535,
				'default' => 22,
			),
			'user'        => array(
				'type'     => 'string',
				'required' => true,
				'max'      => 100,
			),
			'auth'        => array(
				'type'    => 'enum',
				'options' => array( 'password', 'key' ),
				'default' => 'password',
			),
			'password'    => array(
				'type'   => 'string',
				'secret' => true,
				'max'    => 500,
			),
			'private_key' => array(
				'type'   => 'text',
				'secret' => true,
				'max'    => 20000,
			),
			'passphrase'  => array(
				'type'   => 'string',
				'secret' => true,
				'max'    => 500,
			),
			'folder'      => array(
				'type'    => 'string',
				'max'     => 500,
				'default' => '',
			),
			'fingerprint' => array(
				'type'    => 'string',
				'pattern' => '/^(SHA256:[A-Za-z0-9+\/]{43})?$/',
				'default' => '',
			),
		);
	}

	/**
	 * Vérifie des réglages complets.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages, secrets compris.
	 * @return array<string, string> Erreurs, par champ.
	 */
	public static function validate( array $settings ): array {
		if ( 'key' === $settings['auth'] && '' === (string) $settings['private_key'] ) {
			return array( 'private_key' => __( 'Paste the private key.', 'oueb-wp-backup' ) );
		}
		if ( 'password' === $settings['auth'] && '' === (string) $settings['password'] ) {
			return array( 'password' => __( 'Enter the password.', 'oueb-wp-backup' ) );
		}
		if ( false !== strpos( (string) $settings['folder'], '..' ) ) {
			return array( 'folder' => __( 'The folder cannot contain “..”.', 'oueb-wp-backup' ) );
		}

		return array();
	}

	/**
	 * Décrit l'emplacement en une ligne.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return string Description, par exemple « sauvegarde@exemple.fr:/sauvegardes ».
	 */
	public static function describe( array $settings ): string {
		return $settings['user'] . '@' . $settings['host'] . ':' . ( '' === $settings['folder'] ? '~' : $settings['folder'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string   $file     Archive locale.
	 * @param string   $name     Nom.
	 * @param Transfer $transfer État.
	 *
	 * @throws RuntimeException Si l'envoi échoue.
	 */
	public function upload( string $file, string $name, Transfer $transfer ): bool {
		$client = $this->client();
		$client->ensure_dir( $this->folder() );

		$target = $this->path( $name );
		$part   = $target . '.part';
		$total  = (int) filesize( $file );
		$sent   = (int) $transfer->get( 'sent', 0 );

		// Une coupure juste après le renommage laisse un état incomplet : l'archive est pourtant là.
		if ( $sent > 0 && $client->size( $target ) === $total ) {
			return true;
		}

		if ( $sent > 0 ) {
			// Le serveur peut avoir moins que ce qui a été noté, jamais plus.
			$sent = max( 0, min( $sent, $client->size( $part ) ) );
		}

		$reader = fopen( $file, 'rb' );
		if ( false === $reader ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $file ) ) );
		}

		try {
			if ( 0 === $sent ) {
				$client->truncate( $part );
			}
			fseek( $reader, $sent );
			while ( $sent < $total ) {
				$data = Resumable_File::read( $reader, self::CHUNK );
				if ( '' === $data ) {
					break;
				}
				$client->write( $part, $data, $sent );
				$sent += strlen( $data );
				$transfer->checkpoint( $sent, $total );
				$transfer->keep_alive();

				if ( $sent < $total && $transfer->should_pause() ) {
					return false;
				}
			}
		} finally {
			fclose( $reader );
		}

		if ( $client->size( $part ) !== $total ) {
			$transfer->reset();
			throw new RuntimeException( esc_html__( 'The size of the file on the SFTP server does not match the archive. The upload starts again.', 'oueb-wp-backup' ) );
		}
		$client->rename( $part, $target );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function files(): array {
		return $this->client()->list_files( $this->folder() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   Nom.
	 * @param int    $offset Position.
	 * @param int    $length Longueur.
	 */
	public function read( string $name, int $offset, int $length ): string {
		return $this->client()->read( $this->path( $name ), $offset, $length );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 */
	public function delete( string $name ): void {
		$this->client()->delete( $this->path( $name ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function test(): string {
		$client = $this->client();
		$client->ensure_dir( $this->folder() );
		$probe = $this->path( 'oueb-wp-backup-test.txt' );
		$client->write( $probe, 'test', 0 );
		$client->delete( $probe );

		return sprintf(
			/* translators: %s: server key fingerprint. */
			__( 'The folder is writable. Server key: %s.', 'oueb-wp-backup' ),
			$client->server_fingerprint()
		);
	}

	/**
	 * Ouvre la session et retient l'empreinte du serveur la première fois.
	 *
	 * @since 0.1.0
	 *
	 * @return Sftp_Client Session.
	 */
	private function client(): Sftp_Client {
		if ( null === $this->client ) {
			Sftp_Loader::load();
			$this->client = new Sftp_Client( $this->settings );
			if ( '' === (string) $this->settings['fingerprint'] && null !== $this->remember ) {
				call_user_func( $this->remember, $this->client->server_fingerprint() );
			}
		}

		return $this->client;
	}

	/**
	 * Renvoie le dossier distant, sans barre finale.
	 *
	 * @since 0.1.0
	 *
	 * @return string Dossier, vide pour le dossier personnel.
	 */
	private function folder(): string {
		$folder = (string) $this->settings['folder'];

		return '/' === $folder ? '/' : rtrim( $folder, '/' );
	}

	/**
	 * Renvoie le chemin distant d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 * @return string Chemin.
	 */
	private function path( string $name ): string {
		$folder = $this->folder();

		return ( '' === $folder ? '' : rtrim( $folder, '/' ) . '/' ) . basename( $name );
	}
}
