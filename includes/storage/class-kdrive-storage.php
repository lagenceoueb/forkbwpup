<?php
/**
 * Stockage sur Infomaniak kDrive.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use Oueb\WpBackup\Remote\Webdav_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Range les archives sur un kDrive, par WebDAV, avec un mot de passe d'application.
 *
 * WebDAV n'envoie pas par morceaux : l'archive part en une requête. Une
 * coupure fait recommencer l'envoi au passage suivant. Pendant l'envoi, le
 * verrou de l'exécution est prolongé.
 *
 * @since 0.1.0
 */
final class Kdrive_Storage implements Storage {

	/**
	 * Client.
	 *
	 * @since 0.1.0
	 * @var Webdav_Client
	 */
	private Webdav_Client $client;

	/**
	 * Dossier dans le kDrive, sans barre au début ni à la fin.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $folder;

	/**
	 * Crée le stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages, secrets déchiffrés.
	 * @param Webdav_Client|null   $client   Client, remplaçable pour les tests.
	 */
	public function __construct( array $settings, ?Webdav_Client $client = null ) {
		$this->folder = trim( (string) $settings['folder'], '/' );
		$this->client = $client ?? new Webdav_Client( self::url( (string) $settings['drive_id'] ), (string) $settings['email'], (string) $settings['password'] );
	}

	/**
	 * Calcule l'adresse WebDAV d'un kDrive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $drive_id Identifiant du kDrive.
	 * @return string Adresse.
	 */
	public static function url( string $drive_id ): string {
		/**
		 * Filtre l'adresse WebDAV d'un kDrive.
		 *
		 * @since 0.1.0
		 *
		 * @param string $url      Adresse.
		 * @param string $drive_id Identifiant du kDrive.
		 */
		return (string) apply_filters( 'oueb_wp_backup_kdrive_url', 'https://' . $drive_id . '.connect.kdrive.infomaniak.com', $drive_id );
	}

	/**
	 * Libellé du type.
	 *
	 * @since 0.1.0
	 *
	 * @return string Libellé.
	 */
	public static function label(): string {
		return __( 'Infomaniak kDrive', 'oueb-wp-backup' );
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
			'drive_id' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '/^[0-9]{1,12}$/',
			),
			'email'    => array(
				'type'     => 'string',
				'required' => true,
				'max'      => 254,
			),
			'password' => array(
				'type'     => 'string',
				'required' => true,
				'secret'   => true,
				'max'      => 500,
			),
			'folder'   => array(
				'type'    => 'string',
				'max'     => 500,
				'default' => 'Sauvegardes WordPress',
			),
		);
	}

	/**
	 * Vérifie des réglages complets.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return array<string, string> Erreurs, par champ.
	 */
	public static function validate( array $settings ): array {
		if ( ! is_email( (string) $settings['email'] ) ) {
			return array( 'email' => __( 'Enter the email address of your Infomaniak account.', 'oueb-wp-backup' ) );
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
	 * @return string Description, par exemple « kDrive 123456 : Sauvegardes WordPress ».
	 */
	public static function describe( array $settings ): string {
		return 'kDrive ' . $settings['drive_id'] . ' : ' . trim( (string) $settings['folder'], '/' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string   $file     Archive locale.
	 * @param string   $name     Nom.
	 * @param Transfer $transfer État.
	 */
	public function upload( string $file, string $name, Transfer $transfer ): bool {
		$total = (int) filesize( $file );
		$this->client->ensure_dir( $this->folder );

		$part = $this->path( $name ) . '.part';
		$this->client->upload(
			$part,
			$file,
			static function ( int $sent ) use ( $transfer, $total ): void {
				$transfer->checkpoint( $sent, $total );
				$transfer->keep_alive();
			}
		);
		$this->client->move( $part, $this->path( $name ) );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function files(): array {
		return $this->client->list_files( $this->folder );
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
		return $this->client->read( $this->path( $name ), $offset, $offset + $length - 1 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 */
	public function delete( string $name ): void {
		$this->client->delete( $this->path( $name ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function test(): string {
		$this->client->ensure_dir( $this->folder );
		$this->client->check( $this->folder );

		return __( 'The kDrive folder is reachable.', 'oueb-wp-backup' );
	}

	/**
	 * Renvoie le chemin d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 * @return string Chemin.
	 */
	private function path( string $name ): string {
		return ( '' === $this->folder ? '' : $this->folder . '/' ) . basename( $name );
	}
}
