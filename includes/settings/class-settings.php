<?php
/**
 * Réglages généraux de l'extension.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Settings;

use Oueb\WpBackup\Security\Secret_Box;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lit, valide et enregistre les réglages généraux.
 *
 * Un seul schéma décrit chaque réglage : type, bornes, valeur par défaut. Il
 * sert à la validation en PHP et au schéma de l'API REST. Les secrets sont
 * chiffrés en base et ne sortent jamais par l'API : elle indique seulement
 * s'ils sont renseignés.
 *
 * En multisite, les réglages valent pour tout le réseau.
 *
 * @since 0.1.0
 */
final class Settings {

	/**
	 * Nom de l'option en base.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'oueb_wp_backup_settings';

	/**
	 * Longueur minimale de la clé de déclenchement.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TRIGGER_KEY_MIN_LENGTH = 32;

	/**
	 * Décrit chaque réglage.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Réglages, avec type, bornes et valeur par défaut.
	 */
	public static function fields(): array {
		return array(
			'max_execution_time' => array(
				'type'        => 'integer',
				'default'     => 30,
				'minimum'     => 10,
				'maximum'     => 300,
				'description' => __( 'Maximum duration of a backup step, in seconds, before the job restarts.', 'oueb-wp-backup' ),
			),
			'step_retries'       => array(
				'type'        => 'integer',
				'default'     => 3,
				'minimum'     => 1,
				'maximum'     => 10,
				'description' => __( 'Number of attempts for a step that fails.', 'oueb-wp-backup' ),
			),
			'max_logs'           => array(
				'type'        => 'integer',
				'default'     => 30,
				'minimum'     => 1,
				'maximum'     => 1000,
				'description' => __( 'Number of job logs to keep.', 'oueb-wp-backup' ),
			),
			'show_agency_card'   => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Show the agency card on the dashboard.', 'oueb-wp-backup' ),
			),
			'trigger_key'        => array(
				'type'        => 'string',
				'default'     => '',
				'pattern'     => '^[A-Za-z0-9]{' . self::TRIGGER_KEY_MIN_LENGTH . ',}$',
				'description' => __( 'Key of the trigger link, at least 32 letters and digits.', 'oueb-wp-backup' ),
			),
			'cronjob_org_key'    => array(
				'type'        => 'string',
				'default'     => '',
				'secret'      => true,
				'description' => __( 'cron-job.org API key.', 'oueb-wp-backup' ),
			),
		);
	}

	/**
	 * Renvoie les réglages, secrets compris et déchiffrés.
	 *
	 * À n'utiliser que côté serveur : public_values() est la version
	 * destinée à l'interface.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Réglages complets.
	 */
	public static function all(): array {
		$stored = get_site_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$values = array();

		foreach ( self::fields() as $name => $field ) {
			$value = array_key_exists( $name, $stored ) ? $stored[ $name ] : $field['default'];
			if ( ! empty( $field['secret'] ) ) {
				$value = Secret_Box::decrypt( (string) $value );
			}
			$values[ $name ] = $value;
		}

		// La clé de déclenchement est créée à la première lecture, puis gardée.
		if ( '' === $values['trigger_key'] ) {
			$values['trigger_key'] = self::generate_trigger_key();
			$stored['trigger_key'] = $values['trigger_key'];
			update_site_option( self::OPTION, $stored );
		}

		return $values;
	}

	/**
	 * Renvoie la valeur d'un réglage.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom du réglage.
	 * @return mixed Valeur, ou null pour un réglage inconnu.
	 */
	public static function get( string $name ) {
		$values = self::all();

		return array_key_exists( $name, $values ) ? $values[ $name ] : null;
	}

	/**
	 * Renvoie les réglages destinés à l'interface.
	 *
	 * Chaque secret est remplacé par un booléen « <nom>_set ».
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Réglages sans secret.
	 */
	public static function public_values(): array {
		$values = self::all();

		foreach ( self::fields() as $name => $field ) {
			if ( ! empty( $field['secret'] ) ) {
				$values[ $name . '_set' ] = '' !== $values[ $name ];
				unset( $values[ $name ] );
			}
		}

		return $values;
	}

	/**
	 * Valide et enregistre des réglages.
	 *
	 * Seuls les réglages présents dans $changes sont modifiés. Pour un secret,
	 * une chaîne vide garde la valeur enregistrée et null l'efface.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $changes Réglages à modifier.
	 * @return array<string, mixed>|WP_Error Réglages publics après enregistrement, ou erreur de validation.
	 */
	public static function update( array $changes ) {
		$fields = self::fields();
		self::all();
		$stored = get_site_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( $changes as $name => $value ) {
			if ( ! isset( $fields[ $name ] ) ) {
				return new WP_Error(
					'oueb_wp_backup_unknown_setting',
					/* translators: %s: setting name. */
					sprintf( __( 'Unknown setting: %s.', 'oueb-wp-backup' ), $name ),
					array( 'status' => 400 )
				);
			}

			$field = $fields[ $name ];

			if ( ! empty( $field['secret'] ) ) {
				if ( null === $value ) {
					$stored[ $name ] = '';
				} elseif ( is_string( $value ) && '' !== $value ) {
					$stored[ $name ] = Secret_Box::encrypt( $value );
				}
				continue;
			}

			$valid = self::validate( $name, $field, $value );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$stored[ $name ] = $valid;
		}

		update_site_option( self::OPTION, $stored );

		return self::public_values();
	}

	/**
	 * Génère une clé de déclenchement.
	 *
	 * @since 0.1.0
	 *
	 * @return string Clé de 40 lettres et chiffres.
	 */
	public static function generate_trigger_key(): string {
		return wp_generate_password( 40, false, false );
	}

	/**
	 * Valide la valeur d'un réglage.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name  Nom du réglage.
	 * @param array<string, mixed> $field Description du réglage.
	 * @param mixed                $value Valeur reçue.
	 * @return mixed|WP_Error Valeur normalisée, ou erreur.
	 */
	private static function validate( string $name, array $field, $value ) {
		switch ( $field['type'] ) {
			case 'integer':
				if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
					return self::invalid( $name );
				}
				$value = (int) $value;
				if ( $value < $field['minimum'] || $value > $field['maximum'] ) {
					return new WP_Error(
						'oueb_wp_backup_invalid_setting',
						sprintf(
							/* translators: 1: setting name, 2: minimum value, 3: maximum value. */
							__( 'The setting %1$s must be between %2$d and %3$d.', 'oueb-wp-backup' ),
							$name,
							$field['minimum'],
							$field['maximum']
						),
						array( 'status' => 400 )
					);
				}
				return $value;

			case 'boolean':
				if ( ! is_bool( $value ) ) {
					return self::invalid( $name );
				}
				return $value;

			default:
				if ( ! is_string( $value ) ) {
					return self::invalid( $name );
				}
				if ( isset( $field['pattern'] ) && ! preg_match( '/' . $field['pattern'] . '/', $value ) ) {
					return self::invalid( $name );
				}
				return $value;
		}
	}

	/**
	 * Construit l'erreur d'une valeur invalide.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom du réglage.
	 * @return WP_Error Erreur 400.
	 */
	private static function invalid( string $name ): WP_Error {
		return new WP_Error(
			'oueb_wp_backup_invalid_setting',
			/* translators: %s: setting name. */
			sprintf( __( 'The value of the setting %s is not valid.', 'oueb-wp-backup' ), $name ),
			array( 'status' => 400 )
		);
	}
}
