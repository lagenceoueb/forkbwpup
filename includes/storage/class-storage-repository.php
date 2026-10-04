<?php
/**
 * Stockages enregistrés.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use Oueb\WpBackup\Security\Secret_Box;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre les stockages dans une option de site, secrets chiffrés.
 *
 * Le stockage « local », le dossier des archives de l'extension, existe
 * toujours et ne se modifie pas.
 *
 * @since 0.1.0
 */
class Storage_Repository {

	/**
	 * Option des stockages.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'oueb_wp_backup_storages';

	/**
	 * Identifiant du stockage local.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const LOCAL = 'local';

	/**
	 * Types de stockage, par identifiant.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	const TYPES = array(
		's3'     => S3_Storage::class,
		'sftp'   => Sftp_Storage::class,
		'kdrive' => Kdrive_Storage::class,
		'folder' => Folder_Storage::class,
	);

	/**
	 * Dossiers de travail.
	 *
	 * @since 0.1.0
	 * @var Workspace
	 */
	private Workspace $workspace;

	/**
	 * Crée le dépôt.
	 *
	 * @since 0.1.0
	 *
	 * @param Workspace $workspace Dossiers de travail.
	 */
	public function __construct( Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/**
	 * Renvoie tous les stockages, le local en premier.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Enregistrements, par identifiant.
	 */
	public function all(): array {
		$records = array(
			self::LOCAL => array(
				'id'       => self::LOCAL,
				'type'     => 'folder',
				'name'     => __( 'This server', 'oueb-wp-backup' ),
				'settings' => array( 'path' => $this->workspace->root() . '/archives' ),
				'secrets'  => array(),
			),
		);

		$stored = get_site_option( self::OPTION, array() );
		foreach ( is_array( $stored ) ? $stored : array() as $record ) {
			if ( is_array( $record ) && isset( $record['id'], $record['type'], self::TYPES[ $record['type'] ] ) && self::LOCAL !== $record['id'] ) {
				$records[ (string) $record['id'] ] = $record + array(
					'name'     => '',
					'settings' => array(),
					'secrets'  => array(),
				);
			}
		}

		return $records;
	}

	/**
	 * Renvoie un stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return array<string, mixed>|null Enregistrement, ou null.
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Crée un stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $type   Type.
	 * @param array<string, mixed> $values Nom et réglages.
	 * @return array<string, mixed>|WP_Error Enregistrement, ou erreur 400.
	 */
	public function create( string $type, array $values ) {
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return self::invalid( 'type', __( 'This type of storage does not exist.', 'oueb-wp-backup' ) );
		}

		$record = array(
			'id'       => 'st-' . strtolower( wp_generate_password( 8, false, false ) ),
			'type'     => $type,
			'name'     => '',
			'settings' => array(),
			'secrets'  => array(),
		);

		$record = $this->apply( $record, $values );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$this->write( array_merge( $this->custom(), array( $record['id'] => $record ) ) );

		return $record;
	}

	/**
	 * Modifie un stockage. Un secret vide ou absent garde sa valeur.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $id     Identifiant.
	 * @param array<string, mixed> $values Champs modifiés.
	 * @return array<string, mixed>|WP_Error Enregistrement, ou erreur.
	 */
	public function update( string $id, array $values ) {
		$custom = $this->custom();
		if ( ! isset( $custom[ $id ] ) ) {
			return self::not_editable( $id );
		}

		$record = $this->apply( $custom[ $id ], $values );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$custom[ $id ] = $record;
		$this->write( $custom );

		return $record;
	}

	/**
	 * Supprime un stockage. Les archives qu'il contient restent en place.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return true|WP_Error Vrai, ou erreur.
	 */
	public function delete( string $id ) {
		$custom = $this->custom();
		if ( ! isset( $custom[ $id ] ) ) {
			return self::not_editable( $id );
		}

		unset( $custom[ $id ] );
		$this->write( $custom );

		return true;
	}

	/**
	 * Mémorise l'empreinte d'un serveur SFTP, si aucune n'est connue.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id          Identifiant.
	 * @param string $fingerprint Empreinte.
	 */
	public function remember_fingerprint( string $id, string $fingerprint ): void {
		$custom = $this->custom();
		if ( isset( $custom[ $id ] ) && '' === (string) ( $custom[ $id ]['settings']['fingerprint'] ?? '' ) ) {
			$custom[ $id ]['settings']['fingerprint'] = $fingerprint;
			$this->write( $custom );
		}
	}

	/**
	 * Construit le stockage d'un enregistrement, secrets déchiffrés.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return Storage|null Stockage, ou null s'il n'existe pas.
	 */
	public function instance( string $id ): ?Storage {
		$record = $this->get( $id );
		if ( null === $record ) {
			return null;
		}

		$settings = $this->settings( $record );
		switch ( $record['type'] ) {
			case 's3':
				return new S3_Storage( $settings );
			case 'sftp':
				return new Sftp_Storage(
					$settings,
					function ( string $fingerprint ) use ( $id ): void {
						$this->remember_fingerprint( $id, $fingerprint );
					}
				);
			case 'kdrive':
				return new Kdrive_Storage( $settings );
			default:
				return new Folder_Storage( $settings );
		}
	}

	/**
	 * Renvoie les réglages complets d'un enregistrement, secrets déchiffrés.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $record Enregistrement.
	 * @return array<string, mixed> Réglages.
	 */
	public function settings( array $record ): array {
		$class    = self::TYPES[ $record['type'] ];
		$settings = array();
		foreach ( $class::fields() as $name => $field ) {
			if ( ! empty( $field['secret'] ) ) {
				$box               = (string) ( $record['secrets'][ $name ] ?? '' );
				$settings[ $name ] = '' === $box ? '' : Secret_Box::decrypt( $box );
			} else {
				$settings[ $name ] = $record['settings'][ $name ] ?? ( $field['default'] ?? self::empty_value( $field ) );
			}
		}

		return $settings;
	}

	/**
	 * Prépare un enregistrement pour l'API : secrets remplacés par un indicateur.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $record Enregistrement.
	 * @return array<string, mixed> Données publiques.
	 */
	public function to_public( array $record ): array {
		$class    = self::TYPES[ $record['type'] ];
		$settings = array();
		$secrets  = array();
		foreach ( $class::fields() as $name => $field ) {
			if ( ! empty( $field['secret'] ) ) {
				$secrets[ $name ] = '' !== (string) ( $record['secrets'][ $name ] ?? '' );
			} else {
				$settings[ $name ] = $record['settings'][ $name ] ?? ( $field['default'] ?? self::empty_value( $field ) );
			}
		}

		return array(
			'id'          => $record['id'],
			'type'        => $record['type'],
			'type_label'  => $class::label(),
			'name'        => $record['name'],
			'builtin'     => self::LOCAL === $record['id'],
			'settings'    => $settings,
			'secrets_set' => $secrets,
			'description' => $class::describe( $settings ),
		);
	}

	/**
	 * Applique des valeurs à un enregistrement, après validation.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $record Enregistrement.
	 * @param array<string, mixed> $values Nom et réglages.
	 * @return array<string, mixed>|WP_Error Enregistrement modifié, ou erreur 400.
	 */
	private function apply( array $record, array $values ) {
		if ( array_key_exists( 'name', $values ) ) {
			$name = is_string( $values['name'] ) ? trim( sanitize_text_field( $values['name'] ) ) : '';
			if ( '' === $name || strlen( $name ) > 100 ) {
				return self::invalid( 'name', __( 'Give the storage a name of 100 characters at most.', 'oueb-wp-backup' ) );
			}
			$record['name'] = $name;
		} elseif ( '' === $record['name'] ) {
			return self::invalid( 'name', __( 'Give the storage a name of 100 characters at most.', 'oueb-wp-backup' ) );
		}

		$class    = self::TYPES[ $record['type'] ];
		$incoming = isset( $values['settings'] ) && is_array( $values['settings'] ) ? $values['settings'] : array();
		$full     = array();

		foreach ( $class::fields() as $name => $field ) {
			$secret  = ! empty( $field['secret'] );
			$present = array_key_exists( $name, $incoming ) && ! ( $secret && '' === $incoming[ $name ] );

			if ( $present ) {
				$value = self::clean( $incoming[ $name ], $field );
				if ( null === $value ) {
					return self::invalid(
						$name,
						/* translators: %s: field name. */
						sprintf( __( 'The value of %s is not valid.', 'oueb-wp-backup' ), $name )
					);
				}
				if ( $secret ) {
					$record['secrets'][ $name ] = '' === $value ? '' : Secret_Box::encrypt( $value );
				} else {
					$record['settings'][ $name ] = $value;
				}
			}

			$full[ $name ] = $secret
				? ( $present ? $value : ( '' === (string) ( $record['secrets'][ $name ] ?? '' ) ? '' : '•' ) )
				: ( $record['settings'][ $name ] ?? ( $field['default'] ?? self::empty_value( $field ) ) );

			if ( ! empty( $field['required'] ) && ( '' === $full[ $name ] || null === $full[ $name ] ) ) {
				return self::invalid(
					$name,
					/* translators: %s: field name. */
					sprintf( __( 'The field %s is required.', 'oueb-wp-backup' ), $name )
				);
			}
		}

		$errors = $class::validate( $full );
		if ( array() !== $errors ) {
			$field = (string) array_key_first( $errors );
			return self::invalid( $field, $errors[ $field ] );
		}

		return $record;
	}

	/**
	 * Nettoie une valeur selon son champ.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value Valeur reçue.
	 * @param array<string, mixed> $field Champ.
	 * @return mixed Valeur nettoyée, ou null si elle est invalide.
	 */
	public static function clean( $value, array $field ) {
		switch ( $field['type'] ) {
			case 'bool':
				return is_bool( $value ) ? $value : null;

			case 'int':
				if ( ! ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) ) {
					return null;
				}
				$value = (int) $value;
				return ( $value < ( $field['min'] ?? PHP_INT_MIN ) || $value > ( $field['max'] ?? PHP_INT_MAX ) ) ? null : $value;

			case 'enum':
				return in_array( $value, (array) $field['options'], true ) ? $value : null;

			default:
				if ( ! is_string( $value ) ) {
					return null;
				}
				// Un secret ou une clé privée gardent leurs espaces et leurs retours à la ligne.
				if ( empty( $field['secret'] ) ) {
					$value = trim( sanitize_text_field( $value ) );
				}
				if ( strlen( $value ) > ( $field['max'] ?? 255 ) ) {
					return null;
				}
				if ( '' !== $value && isset( $field['pattern'] ) && ! preg_match( $field['pattern'], $value ) ) {
					return null;
				}
				return $value;
		}
	}

	/**
	 * Renvoie la valeur vide d'un champ.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $field Champ.
	 * @return mixed Valeur vide.
	 */
	private static function empty_value( array $field ) {
		switch ( $field['type'] ) {
			case 'bool':
				return false;
			case 'int':
				return 0;
			default:
				return '';
		}
	}

	/**
	 * Renvoie les stockages enregistrés, sans le local.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Enregistrements.
	 */
	private function custom(): array {
		$records = $this->all();
		unset( $records[ self::LOCAL ] );

		return $records;
	}

	/**
	 * Enregistre les stockages.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $records Enregistrements.
	 */
	private function write( array $records ): void {
		update_site_option( self::OPTION, array_values( $records ) );
	}

	/**
	 * Construit une erreur de validation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field   Champ en cause.
	 * @param string $message Message.
	 * @return WP_Error Erreur 400.
	 */
	private static function invalid( string $field, string $message ): WP_Error {
		return new WP_Error(
			'oueb_wp_backup_invalid_storage',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * Construit l'erreur d'un stockage absent ou intégré.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return WP_Error Erreur 400 ou 404.
	 */
	private static function not_editable( string $id ): WP_Error {
		if ( self::LOCAL === $id ) {
			return new WP_Error( 'oueb_wp_backup_builtin_storage', __( 'The storage on this server cannot be changed or deleted.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
		}

		return new WP_Error( 'oueb_wp_backup_storage_not_found', __( 'This storage does not exist.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
	}
}
