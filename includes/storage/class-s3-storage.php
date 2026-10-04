<?php
/**
 * Stockage compatible S3.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use Oueb\WpBackup\Remote\Remote_Exception;
use Oueb\WpBackup\Remote\S3_Client;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Range les archives dans un bucket S3, chez un fournisseur retenu ou à une adresse personnalisée.
 *
 * Au-delà d'une partie, l'archive part en plusieurs parties. L'identifiant
 * de l'envoi et l'ETag de chaque partie sont notés dans l'état : après une
 * coupure, l'envoi reprend à la partie suivante.
 *
 * @since 0.1.0
 */
final class S3_Storage implements Storage {

	/**
	 * Taille minimale d'une partie. S3 exige au moins 5 Mo.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MIN_PART = 8388608;

	/**
	 * Nombre maximal de parties. Scaleway en accepte 1 000.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_PARTS = 1000;

	/**
	 * Client.
	 *
	 * @since 0.1.0
	 * @var S3_Client
	 */
	private S3_Client $client;

	/**
	 * Bucket.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $bucket;

	/**
	 * Dossier dans le bucket, sans barre finale.
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
	 * @param S3_Client|null       $client   Client, remplaçable pour les tests.
	 *
	 * @throws RuntimeException Si la région est inconnue.
	 */
	public function __construct( array $settings, ?S3_Client $client = null ) {
		$this->bucket = (string) $settings['bucket'];
		$this->folder = trim( (string) $settings['folder'], '/' );

		if ( null === $client ) {
			$target = self::target( $settings );
			$client = new S3_Client( $target['endpoint'], $target['region'], (string) $settings['access_key'], (string) $settings['secret_key'], $target['path_style'] );
		}
		$this->client = $client;
	}

	/**
	 * Libellé du type.
	 *
	 * @since 0.1.0
	 *
	 * @return string Libellé.
	 */
	public static function label(): string {
		return __( 'S3 object storage', 'oueb-wp-backup' );
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
			'provider'   => array(
				'type'     => 'enum',
				'required' => true,
				'options'  => array_merge( array_keys( Providers::all() ), array( 'custom' ) ),
			),
			'region'     => array(
				'type' => 'string',
				'max'  => 64,
			),
			'endpoint'   => array(
				'type' => 'string',
				'max'  => 255,
			),
			'path_style' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'bucket'     => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/',
			),
			'folder'     => array(
				'type'    => 'string',
				'max'     => 200,
				'default' => '',
			),
			'access_key' => array(
				'type'     => 'string',
				'required' => true,
				'max'      => 200,
			),
			'secret_key' => array(
				'type'     => 'string',
				'required' => true,
				'secret'   => true,
				'max'      => 200,
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
		if ( false !== strpos( (string) $settings['folder'], '..' ) ) {
			return array( 'folder' => __( 'The folder cannot contain “..”.', 'oueb-wp-backup' ) );
		}

		if ( 'custom' === $settings['provider'] ) {
			$scheme = wp_parse_url( (string) $settings['endpoint'], PHP_URL_SCHEME );
			if ( 'https' !== $scheme ) {
				return array( 'endpoint' => __( 'Enter the HTTPS address of the S3 service.', 'oueb-wp-backup' ) );
			}
			return array();
		}

		if ( null === Providers::region( (string) $settings['provider'], (string) $settings['region'] ) ) {
			return array( 'region' => __( 'Choose a region of this provider.', 'oueb-wp-backup' ) );
		}

		return array();
	}

	/**
	 * Décrit l'emplacement en une ligne.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return string Description, par exemple « Scaleway, Paris : bucket/dossier ».
	 */
	public static function describe( array $settings ): string {
		$where     = trim( $settings['bucket'] . '/' . trim( (string) $settings['folder'], '/' ), '/' );
		$providers = Providers::all();
		$region    = Providers::region( (string) $settings['provider'], (string) $settings['region'] );
		if ( null === $region ) {
			return (string) wp_parse_url( (string) $settings['endpoint'], PHP_URL_HOST ) . ' : ' . $where;
		}

		return $providers[ $settings['provider'] ]['name'] . ', ' . $region['name'] . ' : ' . $where;
	}

	/**
	 * Calcule l'adresse, la région de signature et l'adressage.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return array{endpoint: string, region: string, path_style: bool} Cible.
	 *
	 * @throws RuntimeException Si la région est inconnue.
	 */
	public static function target( array $settings ): array {
		if ( 'custom' === $settings['provider'] ) {
			return array(
				'endpoint'   => (string) $settings['endpoint'],
				'region'     => (string) $settings['region'],
				'path_style' => (bool) $settings['path_style'],
			);
		}

		$region = Providers::region( (string) $settings['provider'], (string) $settings['region'] );
		if ( null === $region ) {
			throw new RuntimeException( esc_html__( 'This S3 region is no longer offered. Choose another one in the storage settings.', 'oueb-wp-backup' ) );
		}

		return array(
			'endpoint'   => $region['endpoint'],
			'region'     => (string) $settings['region'],
			'path_style' => $region['path_style'],
		);
	}

	/**
	 * Calcule la taille des parties pour une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param int $size Taille de l'archive.
	 * @return int Taille d'une partie, multiple de 1 Mo.
	 */
	public static function part_size( int $size ): int {
		$mib = 1048576;

		return max( self::MIN_PART, (int) ceil( $size / self::MAX_PARTS / $mib ) * $mib );
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
	 * @throws RuntimeException Si l'archive est illisible.
	 * @throws Remote_Exception Si le service refuse une partie.
	 */
	public function upload( string $file, string $name, Transfer $transfer ): bool {
		$key   = $this->key( $name );
		$total = (int) filesize( $file );
		$part  = self::part_size( $total );

		if ( $total <= $part ) {
			$this->client->put_object( $this->bucket, $key, (string) file_get_contents( $file ) );
			$transfer->checkpoint( $total, $total );
			return true;
		}

		$upload_id = (string) $transfer->get( 'upload_id', '' );

		// Une coupure juste après l'assemblage laisse un état incomplet : l'objet est pourtant là.
		if ( '' !== $upload_id && $this->client->object_size( $this->bucket, $key ) === $total ) {
			$transfer->checkpoint( $total, $total );
			return true;
		}

		if ( '' === $upload_id || (int) $transfer->get( 'part_size', 0 ) !== $part ) {
			if ( '' !== $upload_id ) {
				$this->abandon( $key, $upload_id );
			}
			$transfer->reset();
			$upload_id = $this->client->create_multipart( $this->bucket, $key );
			$transfer->set( 'upload_id', $upload_id );
			$transfer->set( 'part_size', $part );
			$transfer->set( 'etags', array() );
			$transfer->save();
		}

		$etags  = (array) $transfer->get( 'etags', array() );
		$reader = fopen( $file, 'rb' );
		if ( false === $reader ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $file ) ) );
		}

		try {
			$count = (int) ceil( $total / $part );
			for ( $number = count( $etags ) + 1; $number <= $count; $number++ ) {
				fseek( $reader, ( $number - 1 ) * $part );
				$body = Resumable_File::read( $reader, $part );

				try {
					$etags[ $number ] = $this->client->upload_part( $this->bucket, $key, $upload_id, $number, $body );
				} catch ( Remote_Exception $error ) {
					// L'envoi a expiré chez le fournisseur : il repart de zéro.
					if ( 'NoSuchUpload' === $error->service_code() || 404 === $error->status() ) {
						$transfer->reset();
					}
					throw $error;
				}
				unset( $body );

				$transfer->set( 'etags', $etags );
				$transfer->checkpoint( min( $total, $number * $part ), $total );
				$transfer->keep_alive();

				if ( $number < $count && $transfer->should_pause() ) {
					return false;
				}
			}
		} finally {
			fclose( $reader );
		}

		$this->client->complete_multipart( $this->bucket, $key, $upload_id, $etags );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function files(): array {
		$prefix = '' === $this->folder ? '' : $this->folder . '/';
		$files  = array();
		foreach ( $this->client->list_objects( $this->bucket, $prefix ) as $object ) {
			$name = substr( $object['key'], strlen( $prefix ) );
			if ( '' !== $name && false === strpos( $name, '/' ) ) {
				$files[] = array(
					'name' => $name,
					'size' => $object['size'],
					'time' => $object['time'],
				);
			}
		}

		return $files;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   Nom.
	 * @param int    $offset Position.
	 * @param int    $length Longueur.
	 *
	 * @throws Remote_Exception Si la lecture échoue.
	 */
	public function read( string $name, int $offset, int $length ): string {
		try {
			return $this->client->get_range( $this->bucket, $this->key( $name ), $offset, $offset + $length - 1 );
		} catch ( Remote_Exception $error ) {
			// Au-delà de la fin du fichier, S3 répond 416.
			if ( 416 === $error->status() ) {
				return '';
			}
			throw $error;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 */
	public function delete( string $name ): void {
		$this->client->delete_object( $this->bucket, $this->key( $name ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function test(): string {
		$this->client->check_bucket( $this->bucket );
		$probe = $this->key( 'oueb-wp-backup-test.txt' );
		$this->client->put_object( $this->bucket, $probe, 'test' );
		$this->client->delete_object( $this->bucket, $probe );

		return __( 'The bucket is reachable and writable.', 'oueb-wp-backup' );
	}

	/**
	 * Abandonne un envoi en plusieurs parties, pour que le fournisseur libère ses parties.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key       Clé.
	 * @param string $upload_id Identifiant de l'envoi.
	 */
	private function abandon( string $key, string $upload_id ): void {
		try {
			$this->client->abort_multipart( $this->bucket, $key, $upload_id );
		} catch ( Remote_Exception $error ) {
			// Envoi déjà expiré : il n'y a rien à libérer.
			unset( $error );
		}
	}

	/**
	 * Renvoie la clé d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom.
	 * @return string Clé.
	 */
	private function key( string $name ): string {
		return ( '' === $this->folder ? '' : $this->folder . '/' ) . basename( $name );
	}
}
