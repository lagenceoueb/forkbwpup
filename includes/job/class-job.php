<?php
/**
 * Tâche de sauvegarde.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Job;

use Oueb\WpBackup\Schedule\Cron_Expression;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Décrit ce qu'une tâche sauvegarde et sous quelle forme.
 *
 * La tâche principale porte l'identifiant « main » : c'est celle que gèrent
 * le tableau de bord et l'assistant. Les autres tâches relèvent du mode avancé.
 *
 * @since 0.1.0
 */
final class Job {

	/**
	 * Identifiant de la tâche principale.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const MAIN = 'main';

	/**
	 * Formats d'archive acceptés.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const FORMATS = array( 'zip', 'tar.gz' );

	/**
	 * Déclencheurs possibles.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const TRIGGERS = array( 'manual', 'wpcron', 'link', 'cronjoborg' );

	/**
	 * Identifiant, en lettres minuscules, chiffres et tirets.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $id;

	/**
	 * Nom affiché.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $name;

	/**
	 * Sauvegarder la base de données.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	public bool $include_database = true;

	/**
	 * Sauvegarder les médias (dossier des téléversements).
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	public bool $include_uploads = true;

	/**
	 * Sauvegarder les thèmes.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	public bool $include_themes = true;

	/**
	 * Sauvegarder les extensions.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	public bool $include_plugins = true;

	/**
	 * Sauvegarder le reste du dossier wp-content (langues, mu-plugins…).
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	public bool $include_other_content = true;

	/**
	 * Sauvegarder les fichiers de WordPress et de la racine du site (wp-config.php, .htaccess).
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	public bool $include_core = false;

	/**
	 * Motifs d'exclusion, relatifs à la racine de WordPress (« wp-content/uploads/videos/* »).
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public array $exclude = array();

	/**
	 * Format de l'archive.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $archive_format = 'zip';

	/**
	 * Nombre d'archives gardées dans chaque stockage.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $keep = 14;

	/**
	 * Stockages qui reçoivent l'archive, par identifiant.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public array $storages = array( 'local' );

	/**
	 * Déclencheur : manual, wpcron, link ou cronjoborg.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $trigger = 'manual';

	/**
	 * Planification, en expression cron à cinq champs, dans le fuseau du site.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public string $schedule = '0 3 * * *';

	/**
	 * Vrai pour chiffrer l'archive avant l'envoi.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	public bool $encrypt = false;

	/**
	 * Identifiant de la tâche distante chez cron-job.org, 0 s'il n'y en a pas.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public int $cronjob_org_id = 0;

	/**
	 * Construit une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id   Identifiant.
	 * @param string $name Nom affiché.
	 */
	public function __construct( string $id, string $name ) {
		$this->id   = $id;
		$this->name = $name;
	}

	/**
	 * Construit la tâche principale avec ses valeurs par défaut.
	 *
	 * @since 0.1.0
	 *
	 * @return self Tâche principale.
	 */
	public static function main(): self {
		return new self( self::MAIN, __( 'Main backup', 'oueb-wp-backup' ) );
	}

	/**
	 * Indique si la tâche sauvegarde au moins un dossier.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai si des fichiers sont inclus.
	 */
	public function includes_files(): bool {
		return $this->include_uploads || $this->include_themes || $this->include_plugins
			|| $this->include_other_content || $this->include_core;
	}

	/**
	 * Convertit la tâche en tableau, pour l'option et pour l'API.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Tâche.
	 */
	public function to_array(): array {
		return array(
			'id'                    => $this->id,
			'name'                  => $this->name,
			'is_main'               => self::MAIN === $this->id,
			'include_database'      => $this->include_database,
			'include_uploads'       => $this->include_uploads,
			'include_themes'        => $this->include_themes,
			'include_plugins'       => $this->include_plugins,
			'include_other_content' => $this->include_other_content,
			'include_core'          => $this->include_core,
			'exclude'               => $this->exclude,
			'archive_format'        => $this->archive_format,
			'keep'                  => $this->keep,
			'storages'              => $this->storages,
			'trigger'               => $this->trigger,
			'schedule'              => $this->schedule,
			'encrypt'               => $this->encrypt,
			'cronjob_org_id'        => $this->cronjob_org_id,
		);
	}

	/**
	 * Applique des modifications à la tâche, après validation.
	 *
	 * Seules les clés présentes sont modifiées. Rien n'est changé si une
	 * valeur est invalide.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $changes Modifications.
	 * @return true|WP_Error Vrai, ou erreur 400 qui nomme le champ.
	 */
	public function apply( array $changes ) {
		$copy = clone $this;

		foreach ( $changes as $key => $value ) {
			switch ( $key ) {
				case 'id':
				case 'is_main':
					break;

				case 'name':
					$name = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
					if ( '' === $name ) {
						return self::invalid( $key );
					}
					$copy->name = $name;
					break;

				case 'include_database':
				case 'include_uploads':
				case 'include_themes':
				case 'include_plugins':
				case 'include_other_content':
				case 'include_core':
					if ( ! is_bool( $value ) ) {
						return self::invalid( $key );
					}
					$copy->$key = $value;
					break;

				case 'exclude':
					if ( ! is_array( $value ) ) {
						return self::invalid( $key );
					}
					$patterns = array();
					foreach ( $value as $pattern ) {
						$pattern = is_string( $pattern ) ? trim( str_replace( '\\', '/', $pattern ), " \t/" ) : '';
						if ( '' !== $pattern && false === strpos( $pattern, '..' ) ) {
							$patterns[] = $pattern;
						}
					}
					$copy->exclude = array_values( array_unique( $patterns ) );
					break;

				case 'archive_format':
					if ( ! in_array( $value, self::FORMATS, true ) ) {
						return self::invalid( $key );
					}
					$copy->archive_format = $value;
					break;

				case 'trigger':
					if ( ! in_array( $value, self::TRIGGERS, true ) ) {
						return self::invalid( $key );
					}
					$copy->trigger = $value;
					break;

				case 'schedule':
					$schedule = is_string( $value ) ? trim( (string) preg_replace( '/\s+/', ' ', $value ) ) : '';
					$error    = Cron_Expression::error( $schedule );
					if ( '' !== $error ) {
						return new WP_Error(
							'oueb_wp_backup_invalid_job',
							$error,
							array(
								'status' => 400,
								'field'  => $key,
							)
						);
					}
					$copy->schedule = $schedule;
					break;

				case 'encrypt':
					if ( ! is_bool( $value ) ) {
						return self::invalid( $key );
					}
					$copy->encrypt = $value;
					break;

				case 'cronjob_org_id':
					// Tenu par la synchronisation avec cron-job.org, pas par l'interface.
					break;

				case 'storages':
					if ( ! is_array( $value ) ) {
						return self::invalid( $key );
					}
					$ids = array();
					foreach ( $value as $id ) {
						if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9-]{1,40}$/', $id ) ) {
							return self::invalid( $key );
						}
						$ids[] = $id;
					}
					$ids = array_values( array_unique( $ids ) );
					if ( array() === $ids ) {
						return new WP_Error(
							'oueb_wp_backup_no_storage',
							__( 'Choose at least one storage.', 'oueb-wp-backup' ),
							array(
								'status' => 400,
								'field'  => $key,
							)
						);
					}
					$copy->storages = $ids;
					break;

				case 'keep':
					if ( ! ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) || (int) $value < 1 || (int) $value > 365 ) {
						return self::invalid( $key );
					}
					$copy->keep = (int) $value;
					break;

				default:
					return self::invalid( $key );
			}
		}

		if ( ! $copy->include_database && ! $copy->includes_files() ) {
			return new WP_Error(
				'oueb_wp_backup_empty_job',
				__( 'Choose at least the database or one folder to back up.', 'oueb-wp-backup' ),
				array( 'status' => 400 )
			);
		}

		foreach ( get_object_vars( $copy ) as $property => $value ) {
			$this->$property = $value;
		}

		return true;
	}

	/**
	 * Reconstruit une tâche enregistrée.
	 *
	 * Les valeurs invalides ou absentes prennent la valeur par défaut, pour
	 * qu'une option abîmée ne bloque pas l'extension.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data Tâche enregistrée.
	 * @return self|null Tâche, ou null sans identifiant valide.
	 */
	public static function from_array( array $data ): ?self {
		$id = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
		if ( ! preg_match( '/^[a-z0-9-]{1,40}$/', $id ) ) {
			return null;
		}

		$job = self::MAIN === $id ? self::main() : new self( $id, $id );
		foreach ( $data as $key => $value ) {
			$job->apply( array( $key => $value ) );
		}
		$job->cronjob_org_id = max( 0, (int) ( $data['cronjob_org_id'] ?? 0 ) );

		return $job;
	}

	/**
	 * Construit l'erreur d'une valeur invalide.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Champ en cause.
	 * @return WP_Error Erreur 400.
	 */
	private static function invalid( string $field ): WP_Error {
		return new WP_Error(
			'oueb_wp_backup_invalid_job',
			/* translators: %s: field name. */
			sprintf( __( 'The value of %s is not valid.', 'oueb-wp-backup' ), $field ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}
}
