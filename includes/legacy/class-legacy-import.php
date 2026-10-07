<?php
/**
 * Import des tâches et réglages de BackWPup.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Legacy;

use Oueb\WpBackup\Engine\Steps\File_List;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Schedule\Cron_Expression;
use Oueb\WpBackup\Schedule\Scheduler;
use Oueb\WpBackup\Settings\Settings;
use Oueb\WpBackup\Storage\Storage_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Lit les options backwpup_* et crée les tâches et stockages équivalents.
 *
 * Les données de BackWPup ne sont jamais modifiées ni supprimées. Les tâches
 * importées sont des tâches supplémentaires : la sauvegarde principale ne
 * change pas. Un stockage partagé par plusieurs tâches n'est créé qu'une fois.
 *
 * Le rapport dit, tâche par tâche, ce qui a été repris et ce qui ne l'a pas
 * été : FTP, services retirés, secrets illisibles.
 *
 * @since 0.1.0
 */
final class Legacy_Import {

	/**
	 * État de l'import : statut (vide, done ou dismissed), date et rapport.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'oueb_wp_backup_import';

	/**
	 * Tâches de BackWPup.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const JOBS_OPTION = 'backwpup_jobs';

	/**
	 * Fichier principal de BackWPup, pour savoir s'il est actif.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PLUGIN = 'backwpup/backwpup.php';

	/**
	 * Destinations de BackWPup reprises.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const SUPPORTED = array( 'FOLDER', 'S3', 'SFTP', 'KDRIVE' );

	/**
	 * Tâches.
	 *
	 * @since 0.1.0
	 * @var Job_Repository
	 */
	private Job_Repository $jobs;

	/**
	 * Stockages.
	 *
	 * @since 0.1.0
	 * @var Storage_Repository
	 */
	private Storage_Repository $storages;

	/**
	 * Planificateur, null pour ne pas synchroniser.
	 *
	 * @since 0.1.0
	 * @var Scheduler|null
	 */
	private ?Scheduler $scheduler;

	/**
	 * Stockages créés pendant l'import, par empreinte de leurs réglages.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	private array $created = array();

	/**
	 * Crée l'import.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Repository     $jobs      Tâches.
	 * @param Storage_Repository $storages  Stockages.
	 * @param Scheduler|null     $scheduler Planificateur.
	 */
	public function __construct( Job_Repository $jobs, Storage_Repository $storages, ?Scheduler $scheduler = null ) {
		$this->jobs      = $jobs;
		$this->storages  = $storages;
		$this->scheduler = $scheduler;
	}

	/**
	 * Branche le nettoyage des événements WP-Cron laissés par l'ancien code du fork.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'clear_orphan_events' ) );
	}

	/**
	 * Retire les événements WP-Cron de BackWPup quand BackWPup n'est pas actif.
	 *
	 * Ce sont ceux de l'ancien code du fork : plus personne ne les traite.
	 * Ceux d'un BackWPup actif restent intacts.
	 *
	 * @since 0.1.0
	 */
	public static function clear_orphan_events(): void {
		if ( self::backwpup_active() ) {
			return;
		}
		foreach ( array( 'backwpup_cron', 'backwpup_check_cleanup' ) as $hook ) {
			if ( false !== wp_next_scheduled( $hook ) || false !== wp_get_scheduled_event( $hook ) ) {
				wp_unschedule_hook( $hook );
			}
		}
	}

	/**
	 * Indique si l'extension BackWPup est active.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai si elle est active sur ce site ou sur le réseau.
	 */
	public static function backwpup_active(): bool {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( self::PLUGIN, $active, true ) ) {
			return true;
		}

		return is_multisite() && isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ self::PLUGIN ] );
	}

	/**
	 * Décrit ce qui peut être importé.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> available, status, backwpup_active, jobs (nom, destinations reprises ou non), report.
	 */
	public function summary(): array {
		$state = (array) get_site_option( self::OPTION, array() );
		$jobs  = array();
		foreach ( self::legacy_jobs() as $legacy ) {
			$destinations = array_map( 'strtoupper', array_map( 'strval', (array) ( $legacy['destinations'] ?? array() ) ) );
			$jobs[]       = array(
				'name'        => (string) ( $legacy['name'] ?? '' ),
				'supported'   => array_values( array_intersect( $destinations, self::SUPPORTED ) ),
				'unsupported' => array_values( array_diff( $destinations, self::SUPPORTED ) ),
			);
		}

		return array(
			'available'       => array() !== $jobs,
			'status'          => (string) ( $state['status'] ?? '' ),
			'imported_at'     => isset( $state['imported_at'] ) ? gmdate( 'c', (int) $state['imported_at'] ) : null,
			'backwpup_active' => self::backwpup_active(),
			'jobs'            => $jobs,
			'report'          => (array) ( $state['report'] ?? array() ),
		);
	}

	/**
	 * Note que l'administrateur ne veut pas importer.
	 *
	 * @since 0.1.0
	 */
	public function dismiss(): void {
		$state           = (array) get_site_option( self::OPTION, array() );
		$state['status'] = 'dismissed';
		update_site_option( self::OPTION, $state );
	}

	/**
	 * Importe les tâches et les réglages.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>> Rapport : une entrée par tâche, avec la tâche créée, ses stockages et ses remarques.
	 */
	public function run(): array {
		$report = array();
		foreach ( self::legacy_jobs() as $legacy ) {
			$report[] = $this->import_job( $legacy );
		}
		$report[] = $this->import_settings();

		update_site_option(
			self::OPTION,
			array(
				'status'      => 'done',
				'imported_at' => time(),
				'report'      => $report,
			)
		);

		return $report;
	}

	/**
	 * Renvoie les tâches de BackWPup.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>> Tâches, dans l'ordre de leur numéro.
	 */
	public static function legacy_jobs(): array {
		$jobs = get_site_option( self::JOBS_OPTION, array() );
		if ( ! is_array( $jobs ) ) {
			return array();
		}
		ksort( $jobs );

		return array_values( array_filter( $jobs, 'is_array' ) );
	}

	/**
	 * Importe une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $legacy Tâche de BackWPup.
	 * @return array<string, mixed> Rapport de la tâche.
	 */
	private function import_job( array $legacy ): array {
		$name  = trim( (string) ( $legacy['name'] ?? '' ) );
		$name  = '' === $name ? __( 'Imported backup', 'oueb-wp-backup' ) : $name;
		$entry = array(
			'name'     => $name,
			'job_id'   => '',
			'storages' => array(),
			'notes'    => array(),
		);

		$types    = array_map( 'strtoupper', array_map( 'strval', (array) ( $legacy['type'] ?? array() ) ) );
		$database = in_array( 'DBDUMP', $types, true );
		$files    = in_array( 'FILE', $types, true );
		if ( ! $database && ! $files ) {
			$entry['notes'][] = __( 'Not imported: this job neither backs up the database nor the files (database check or XML export only).', 'oueb-wp-backup' );
			return $entry;
		}

		$keep = 0;
		foreach ( array_map( 'strtoupper', array_map( 'strval', (array) ( $legacy['destinations'] ?? array() ) ) ) as $destination ) {
			$result = $this->import_destination( $destination, $legacy );
			if ( isset( $result['note'] ) ) {
				$entry['notes'][] = $result['note'];
			}
			if ( isset( $result['id'] ) ) {
				$entry['storages'][] = $result['id'];
				$keep                = max( $keep, (int) $result['keep'] );
			}
		}
		$stranded = array() === $entry['storages'];
		if ( $stranded ) {
			$entry['storages'][] = Storage_Repository::LOCAL;
			$entry['notes'][]    = __( 'No storage of this job could be imported. It starts by hand only, and keeps its backups on this server, until you choose a storage and a schedule for it.', 'oueb-wp-backup' );
		}

		$data = array(
			'name'                  => $name,
			'include_database'      => $database,
			'include_core'          => $files && ! empty( $legacy['backuproot'] ),
			'include_other_content' => $files && ! empty( $legacy['backupcontent'] ),
			'include_plugins'       => $files && ! empty( $legacy['backupplugins'] ),
			'include_themes'        => $files && ! empty( $legacy['backupthemes'] ),
			'include_uploads'       => $files && ! empty( $legacy['backupuploads'] ),
			'exclude'               => $files ? self::exclusions( $legacy ) : array(),
			'archive_format'        => self::format( (string) ( $legacy['archiveformat'] ?? '' ), $entry['notes'] ),
			'keep'                  => max( 1, min( 365, 0 === $keep ? 365 : $keep ) ),
			'storages'              => array_values( array_unique( $entry['storages'] ) ),
		);
		if ( ! $database && ! $data['include_core'] && ! $data['include_other_content'] && ! $data['include_plugins'] && ! $data['include_themes'] && ! $data['include_uploads'] ) {
			// Une tâche de fichiers sans dossier coché sauvegardait wp-content chez BackWPup.
			$data['include_other_content'] = true;
		}
		$data += self::trigger( $legacy, $entry['notes'] );
		if ( $stranded ) {
			$data['trigger'] = 'manual';
		}

		$job = $this->jobs->create( $data );
		if ( is_wp_error( $job ) ) {
			$entry['notes'][] = sprintf(
				/* translators: %s: error message. */
				__( 'Not imported: %s', 'oueb-wp-backup' ),
				$job->get_error_message()
			);
			return $entry;
		}

		$entry['job_id'] = $job->id;
		if ( null !== $this->scheduler ) {
			$error = $this->scheduler->sync( $job );
			if ( '' !== $error ) {
				$entry['notes'][] = $error;
			}
		}

		return $entry;
	}

	/**
	 * Importe une destination de BackWPup comme stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $destination Identifiant de la destination chez BackWPup.
	 * @param array<string, mixed> $legacy      Tâche de BackWPup.
	 * @return array<string, mixed> Identifiant et nombre de copies gardées, ou remarque.
	 */
	private function import_destination( string $destination, array $legacy ): array {
		switch ( $destination ) {
			case 'FOLDER':
				$path   = self::absolute_path( (string) ( $legacy['backupdir'] ?? '' ) );
				$values = array(
					/* translators: %s: folder name. */
					'name'     => sprintf( __( 'Folder %s', 'oueb-wp-backup' ), self::short_path( $path ) ),
					'settings' => array( 'path' => $path ),
				);
				return $this->create( 'folder', $values, (int) ( $legacy['maxbackups'] ?? 0 ), $destination );

			case 'S3':
				return $this->import_s3( $legacy );

			case 'SFTP':
				$auth   = 'key' === ( $legacy['sftpauth'] ?? 'password' ) ? 'key' : 'password';
				$secret = $this->secrets(
					array(
						'password'    => $legacy['sftppass'] ?? '',
						'private_key' => $legacy['sftpkey'] ?? '',
						'passphrase'  => $legacy['sftpkeypass'] ?? '',
					)
				);
				if ( null === $secret ) {
					return array( 'note' => self::unreadable( 'SFTP' ) );
				}
				$values = array(
					/* translators: %s: server name. */
					'name'     => sprintf( __( 'SFTP %s', 'oueb-wp-backup' ), (string) ( $legacy['sftphost'] ?? '' ) ),
					'settings' => array(
						'host'        => (string) ( $legacy['sftphost'] ?? '' ),
						'port'        => (int) ( $legacy['sftpport'] ?? 22 ),
						'user'        => (string) ( $legacy['sftpuser'] ?? '' ),
						'auth'        => $auth,
						'password'    => 'password' === $auth ? $secret['password'] : '',
						'private_key' => 'key' === $auth ? $secret['private_key'] : '',
						'passphrase'  => 'key' === $auth ? $secret['passphrase'] : '',
						'folder'      => trim( (string) ( $legacy['sftpdir'] ?? '' ), '/' ),
						'fingerprint' => (string) ( $legacy['sftpfingerprint'] ?? '' ),
					),
				);
				return $this->create( 'sftp', $values, (int) ( $legacy['sftpmaxbackups'] ?? 0 ), $destination );

			case 'KDRIVE':
				$secret = $this->secrets( array( 'password' => $legacy['kdrivepassword'] ?? '' ) );
				if ( null === $secret ) {
					return array( 'note' => self::unreadable( 'kDrive' ) );
				}
				$values = array(
					/* translators: %s: kDrive identifier. */
					'name'     => sprintf( __( 'kDrive %s', 'oueb-wp-backup' ), (string) ( $legacy['kdriveid'] ?? '' ) ),
					'settings' => array(
						'drive_id' => (string) ( $legacy['kdriveid'] ?? '' ),
						'email'    => (string) ( $legacy['kdriveemail'] ?? '' ),
						'password' => $secret['password'],
						'folder'   => trim( (string) ( $legacy['kdrivedir'] ?? '' ), '/' ),
					),
				);
				return $this->create( 'kdrive', $values, (int) ( $legacy['kdrivemaxbackups'] ?? 0 ), $destination );

			case 'FTP':
				return array( 'note' => __( 'FTP not imported: it sends the password in clear text. Create an SFTP storage on the same server instead.', 'oueb-wp-backup' ) );

			default:
				return array(
					'note' => sprintf(
						/* translators: %s: name of a removed service, such as DROPBOX. */
						__( '%s not imported: this service is no longer offered. Choose a European storage.', 'oueb-wp-backup' ),
						$destination
					),
				);
		}
	}

	/**
	 * Importe une destination S3.
	 *
	 * BackWPup range le fournisseur et la région dans s3region
	 * (« scaleway-fr-par »), ou un endpoint personnalisé dans s3base_url.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $legacy Tâche de BackWPup.
	 * @return array<string, mixed> Identifiant et nombre de copies gardées, ou remarque.
	 */
	private function import_s3( array $legacy ): array {
		$secret = $this->secrets( array( 'secret_key' => $legacy['s3secretkey'] ?? '' ) );
		if ( null === $secret ) {
			return array( 'note' => self::unreadable( 'S3' ) );
		}

		$endpoint = trim( (string) ( $legacy['s3base_url'] ?? '' ) );
		if ( '' !== $endpoint ) {
			$provider = 'custom';
			$region   = (string) ( $legacy['s3base_region'] ?? '' );
		} else {
			$id         = (string) ( $legacy['s3region'] ?? '' );
			$legacy_ids = array(
				'scaleway-par' => 'scaleway-fr-par',
				'scaleway-ams' => 'scaleway-nl-ams',
			);
			$id         = $legacy_ids[ $id ] ?? $id;
			$position   = strpos( $id, '-' );
			$provider   = false === $position ? $id : substr( $id, 0, $position );
			$region     = false === $position ? '' : substr( $id, $position + 1 );
		}

		$values = array(
			/* translators: %s: bucket name. */
			'name'     => sprintf( __( 'S3 %s', 'oueb-wp-backup' ), (string) ( $legacy['s3bucket'] ?? '' ) ),
			'settings' => array(
				'provider'   => $provider,
				'region'     => $region,
				'endpoint'   => $endpoint,
				'path_style' => ! empty( $legacy['s3base_pathstylebucket'] ),
				'bucket'     => (string) ( $legacy['s3bucket'] ?? '' ),
				'folder'     => trim( (string) ( $legacy['s3dir'] ?? '' ), '/' ),
				'access_key' => (string) ( $legacy['s3accesskey'] ?? '' ),
				'secret_key' => $secret['secret_key'],
			),
		);
		return $this->create( 's3', $values, (int) ( $legacy['s3maxbackups'] ?? 0 ), 'S3' );
	}

	/**
	 * Crée un stockage, ou reprend celui déjà créé avec les mêmes réglages.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $type        Type de stockage.
	 * @param array<string, mixed> $values      Nom et réglages.
	 * @param int                  $keep        Copies gardées par BackWPup, 0 pour illimité.
	 * @param string               $destination Destination de BackWPup, pour le message d'erreur.
	 * @return array<string, mixed> Identifiant et copies gardées, ou remarque.
	 */
	private function create( string $type, array $values, int $keep, string $destination ): array {
		$fingerprint = md5( $type . '|' . (string) wp_json_encode( $values['settings'] ) );
		if ( isset( $this->created[ $fingerprint ] ) ) {
			return array(
				'id'   => $this->created[ $fingerprint ],
				'keep' => $keep,
			);
		}

		$record = $this->storages->create( $type, $values );
		if ( is_wp_error( $record ) ) {
			return array(
				'note' => sprintf(
					/* translators: 1: destination name, such as S3, 2: error message. */
					__( '%1$s not imported: %2$s', 'oueb-wp-backup' ),
					$destination,
					$record->get_error_message()
				),
			);
		}

		$this->created[ $fingerprint ] = (string) $record['id'];

		return array(
			'id'   => (string) $record['id'],
			'keep' => $keep,
		);
	}

	/**
	 * Déchiffre les secrets d'une destination.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $values Secrets chiffrés par BackWPup.
	 * @return array<string, string>|null Secrets en clair, null si l'un d'eux ne se déchiffre pas.
	 */
	private function secrets( array $values ): ?array {
		$plain = array();
		foreach ( $values as $name => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				$plain[ $name ] = '';
				continue;
			}
			$decrypted = Legacy_Secret::decrypt( $value );
			if ( null === $decrypted ) {
				return null;
			}
			$plain[ $name ] = $decrypted;
		}

		return $plain;
	}

	/**
	 * Importe les réglages généraux, sans écraser ceux déjà faits ici.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Rapport des réglages.
	 */
	private function import_settings(): array {
		$current = Settings::all();
		$changes = array();

		$time = (int) get_site_option( 'backwpup_cfg_jobmaxexecutiontime', 0 );
		if ( $time > 0 ) {
			$changes['max_execution_time'] = max( 10, min( 300, $time ) );
		}
		$logs = (int) get_site_option( 'backwpup_cfg_maxlogs', 0 );
		if ( $logs > 0 ) {
			$changes['max_logs'] = max( 1, min( 1000, $logs ) );
		}
		$retries = (int) get_site_option( 'backwpup_cfg_jobstepretry', 0 );
		if ( $retries > 0 ) {
			$changes['step_retries'] = max( 1, min( 10, $retries ) );
		}

		// Le lien de déclenchement et cron-job.org gardent leur clé, s'il n'y en a pas déjà une.
		$key = preg_replace( '/[^a-zA-Z0-9]/', '', (string) get_site_option( 'backwpup_cfg_jobrunauthkey', '' ) );
		if ( '' === (string) $current['trigger_key'] && strlen( (string) $key ) >= 32 ) {
			$changes['trigger_key'] = $key;
		}
		$cronjob = (string) get_site_option( 'oueb_cronjob_org_key', '' );
		if ( '' === (string) $current['cronjob_org_key'] && '' !== $cronjob ) {
			$changes['cronjob_org_key'] = $cronjob;
		}

		$notes = array();
		if ( array() !== $changes ) {
			$result = Settings::update( $changes );
			if ( is_wp_error( $result ) ) {
				$notes[] = $result->get_error_message();
			}
		}

		return array(
			'name'     => __( 'General settings', 'oueb-wp-backup' ),
			'job_id'   => '',
			'storages' => array(),
			'settings' => array_keys( $changes ),
			'notes'    => $notes,
		);
	}

	/**
	 * Convertit le déclenchement d'une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $legacy Tâche de BackWPup.
	 * @param string[]             $notes  Remarques, complétées au besoin.
	 * @return array<string, string> Déclencheur et expression cron.
	 */
	private static function trigger( array $legacy, array &$notes ): array {
		$types   = array(
			'wpcron'     => 'wpcron',
			'link'       => 'link',
			'cronjoborg' => 'cronjoborg',
		);
		$active  = (string) ( $legacy['activetype'] ?? '' );
		$trigger = $types[ $active ] ?? 'manual';
		if ( 'easycron' === $active ) {
			$notes[] = __( 'EasyCron is no longer offered: this job now starts by hand. Choose WP-Cron, the trigger link or cron-job.org.', 'oueb-wp-backup' );
		}

		$schedule = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $legacy['cron'] ?? '' ) ) );
		if ( '' === $schedule || '' !== Cron_Expression::error( $schedule ) ) {
			if ( '' !== $schedule ) {
				$notes[] = sprintf(
					/* translators: %s: cron expression. */
					__( 'The schedule %s cannot be kept: check the schedule of this job.', 'oueb-wp-backup' ),
					$schedule
				);
			}
			$schedule = '0 3 * * *';
		}

		return array(
			'trigger'  => $trigger,
			'schedule' => $schedule,
		);
	}

	/**
	 * Convertit le format d'archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $format Format chez BackWPup : .zip, .tar, .tar.gz ou .tar.bz2.
	 * @param string[] $notes  Remarques, complétées au besoin.
	 * @return string Format : zip ou tar.gz.
	 */
	private static function format( string $format, array &$notes ): string {
		if ( '.zip' === $format ) {
			return 'zip';
		}
		if ( '.tar.gz' !== $format && '' !== $format ) {
			$notes[] = sprintf(
				/* translators: %s: archive format, such as .tar.bz2. */
				__( 'The %s format is not offered: the archives are now in tar.gz.', 'oueb-wp-backup' ),
				$format
			);
		}

		return 'tar.gz';
	}

	/**
	 * Convertit les exclusions de BackWPup en motifs relatifs à la racine du site.
	 *
	 * BackWPup exclut un fichier dont le nom contient un des textes de
	 * fileexclude, et les dossiers nommés dans chaque partie du site.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $legacy Tâche de BackWPup.
	 * @return string[] Motifs.
	 */
	public static function exclusions( array $legacy ): array {
		$patterns = array();
		foreach ( explode( ',', (string) ( $legacy['fileexclude'] ?? '' ) ) as $text ) {
			$text = trim( $text );
			if ( '' !== $text ) {
				$patterns[] = false === strpbrk( $text, '*?' ) ? '*' . $text . '*' : $text;
			}
		}

		$prefixes = File_List::archive_prefixes();
		$parts    = array(
			'backuprootexcludedirs'    => $prefixes['core'] ?? '',
			'backupcontentexcludedirs' => $prefixes['content'] ?? 'wp-content',
			'backuppluginsexcludedirs' => $prefixes['plugins'] ?? 'wp-content/plugins',
			'backupthemesexcludedirs'  => $prefixes['themes'] ?? 'wp-content/themes',
			'backupuploadsexcludedirs' => $prefixes['uploads'] ?? 'wp-content/uploads',
		);
		foreach ( $parts as $key => $prefix ) {
			foreach ( (array) ( $legacy[ $key ] ?? array() ) as $dir ) {
				$dir = trim( (string) $dir, '/ ' );
				if ( '' !== $dir && false === strpos( $dir, '..' ) ) {
					$patterns[] = ltrim( $prefix . '/' . $dir, '/' );
				}
			}
		}

		return array_values( array_unique( $patterns ) );
	}

	/**
	 * Rend absolu un dossier de BackWPup : un chemin relatif part de wp-content.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Dossier enregistré par BackWPup.
	 * @return string Chemin absolu, sans barre oblique finale.
	 */
	public static function absolute_path( string $path ): string {
		$path    = str_replace( '\\', '/', trim( $path ) );
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		if ( '' === $path || '/' === $path ) {
			return $content;
		}
		if ( '/' !== $path[0] && ! preg_match( '#^[a-zA-Z]+:/#', $path ) ) {
			$path = $content . '/' . $path;
		}

		return untrailingslashit( $path );
	}

	/**
	 * Raccourcit un chemin pour le nom d'un stockage : relatif au site, 60 caractères au plus.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Chemin absolu.
	 * @return string Chemin court.
	 */
	private static function short_path( string $path ): string {
		$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( $path, $root . '/' ) ) {
			$path = substr( $path, strlen( $root ) + 1 );
		}

		return mb_strlen( $path ) > 60 ? '…' . mb_substr( $path, -59 ) : $path;
	}

	/**
	 * Écrit la remarque d'un secret illisible.
	 *
	 * @since 0.1.0
	 *
	 * @param string $destination Destination.
	 * @return string Remarque.
	 */
	private static function unreadable( string $destination ): string {
		return sprintf(
			/* translators: %s: destination name, such as SFTP. */
			__( '%s not imported: its password cannot be read. It was encrypted with the database access or the BACKWPUP_ENC_KEY constant of another configuration. Create this storage again.', 'oueb-wp-backup' ),
			$destination
		);
	}
}
