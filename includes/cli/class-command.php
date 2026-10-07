<?php
/**
 * Commandes WP-CLI.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Cli;

use Oueb\WpBackup\Database\Table_Maintenance;
use Oueb\WpBackup\Engine\Logger;
use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Legacy\Legacy_Import;
use Oueb\WpBackup\Plugin;
use Oueb\WpBackup\Restore\Upload_Repository;
use Oueb\WpBackup\Security\Capabilities;
use WP_CLI;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Backs up, restores and maintains the site.
 *
 * The commands go through the same REST routes as the admin screens: same
 * checks, same messages. Without --user, they act as the first administrator
 * who can manage backups. A backup or a restore started here moves on in this
 * process, without waiting for WP-Cron.
 *
 * ## EXAMPLES
 *
 *     # Back up the site with the main backup.
 *     $ wp oueb-backup backup
 *
 *     # Restore the database of backup 42, without question.
 *     $ wp oueb-backup restore 42 --database --yes
 *
 * @since 0.1.0
 */
final class Command {

	/**
	 * Déclare la commande auprès de WP-CLI.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		WP_CLI::add_command( 'oueb-backup', self::class );
	}

	/**
	 * Backs up the site now, and waits until the backup ends.
	 *
	 * ## OPTIONS
	 *
	 * [<job>]
	 * : Job to run. Default: the main backup. See `wp oueb-backup jobs`.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp oueb-backup backup
	 *     $ wp oueb-backup backup job-1a2b3c
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function backup( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		self::act_as_admin();
		$job = Plugin::jobs()->get( $args[0] ?? 'main' );
		if ( null === $job ) {
			WP_CLI::error( __( 'This backup job does not exist.', 'oueb-wp-backup' ) );
			return;
		}

		self::drive_here();
		$run = Plugin::runner()->start( $job, 'cli' );
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( self::message( $run ) );
			return;
		}
		$this->follow( $run->id );
	}

	/**
	 * Lists the backups and restores.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kind>]
	 * : Only backups or only restores.
	 * ---
	 * options:
	 *   - backup
	 *   - restore
	 * ---
	 *
	 * [--number=<number>]
	 * : How many, newest first.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp oueb-backup list --kind=backup --number=5
	 *
	 * @subcommand list
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );
		$runs = $this->request(
			'GET',
			'/runs',
			array(
				'per_page' => max( 1, min( 100, (int) ( $assoc_args['number'] ?? 20 ) ) ),
				'kind'     => (string) ( $assoc_args['kind'] ?? '' ),
			)
		);

		$items = array_map(
			static fn( array $run ): array => array(
				'id'       => $run['id'],
				'kind'     => $run['kind'],
				'job'      => (string) ( $run['job_name'] ?? $run['job_id'] ),
				'status'   => $run['status'],
				'started'  => get_date_from_gmt( (string) $run['started_at'], 'Y-m-d H:i' ),
				'archive'  => basename( (string) ( $run['restore']['archive'] ?? $run['archive_file'] ) ),
				'size'     => $run['archive_size'] ? size_format( (int) $run['archive_size'] ) : '',
				'warnings' => $run['warnings'],
				'errors'   => $run['errors'],
			),
			$runs
		);

		self::output( $assoc_args['format'] ?? 'table', $items, array( 'id', 'kind', 'job', 'status', 'started', 'archive', 'size', 'warnings', 'errors' ) );
	}

	/**
	 * Lists the backup jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function jobs( array $args, array $assoc_args ): void {
		unset( $args );
		self::act_as_admin();
		$items = array();
		foreach ( Plugin::jobs()->all() as $job ) {
			$items[] = array(
				'id'       => $job->id,
				'name'     => $job->name,
				'trigger'  => $job->trigger,
				'schedule' => 'manual' === $job->trigger ? '' : $job->schedule,
				'storages' => implode( ', ', $job->storages ),
				'keep'     => $job->keep,
			);
		}

		self::output( $assoc_args['format'] ?? 'table', $items, array( 'id', 'name', 'trigger', 'schedule', 'storages', 'keep' ) );
	}

	/**
	 * Tells whether a backup or a restore is running.
	 *
	 * ## OPTIONS
	 *
	 * [--follow]
	 * : Make the running backup or restore move on here, and wait until it ends.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		self::act_as_admin();
		$run = Plugin::runs()->active();
		if ( null === $run ) {
			WP_CLI::log( __( 'No backup or restore is running.', 'oueb-wp-backup' ) );
			return;
		}

		WP_CLI::log(
			sprintf(
				/* translators: 1: run ID, 2: kind, backup or restore, 3: step, 4: progress in percent. */
				__( 'Run %1$d (%2$s) in progress: step %3$s, %4$d %%.', 'oueb-wp-backup' ),
				$run->id,
				$run->kind,
				'' === $run->step ? '-' : $run->step,
				$run->progress
			)
		);

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'follow', false ) ) {
			$this->follow( $run->id );
		}
	}

	/**
	 * Prints the log of a backup or a restore.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID of the backup or restore. See `wp oueb-backup list`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function log( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		foreach ( $this->request( 'GET', '/runs/' . (int) $args[0] . '/log' ) as $entry ) {
			self::print_entry( $entry );
		}
	}

	/**
	 * Stops a running backup or restore.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID of the backup or restore.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function abort( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$run = $this->request( 'POST', '/runs/' . (int) $args[0] . '/abort' );

		WP_CLI::success(
			Run::ABORTED === $run['status']
				? __( 'Stopped.', 'oueb-wp-backup' )
				: __( 'Stop requested: the run stops at the end of its current step.', 'oueb-wp-backup' )
		);
	}

	/**
	 * Restores a backup.
	 *
	 * The site is put in maintenance mode during the restore. By default,
	 * the current site is backed up first, and both the database and the
	 * files are restored.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : ID of the backup to restore. See `wp oueb-backup list --kind=backup`.
	 *
	 * [--file=<path>]
	 * : Restore an archive from this server instead: zip, tar.gz or encrypted .enc.
	 *
	 * [--storage=<storage>]
	 * : Restore an archive from this storage, with --archive.
	 *
	 * [--archive=<name>]
	 * : Name of the archive in the storage.
	 *
	 * [--database]
	 * : Restore the database only, unless --files is also given.
	 *
	 * [--files]
	 * : Restore the files only, unless --database is also given.
	 *
	 * [--[no-]safety]
	 * : Back up the current site first. Default: yes. Use --no-safety to skip it.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp oueb-backup restore 42
	 *     $ wp oueb-backup restore --file=/home/site/backup.zip --database --yes
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function restore( array $args, array $assoc_args ): void {
		$database = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'database', false );
		$files    = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'files', false );
		if ( ! $database && ! $files ) {
			$database = true;
			$files    = true;
		}
		$safety = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'safety', true );

		if ( isset( $args[0] ) ) {
			$source = array(
				'type'   => 'run',
				'run_id' => (int) $args[0],
			);
		} elseif ( isset( $assoc_args['storage'], $assoc_args['archive'] ) ) {
			$source = array(
				'type'       => 'storage',
				'storage_id' => (string) $assoc_args['storage'],
				'name'       => (string) $assoc_args['archive'],
			);
		} elseif ( isset( $assoc_args['file'] ) ) {
			$source = null;
		} else {
			WP_CLI::error( __( 'Give the ID of a backup, a --file, or a --storage with an --archive.', 'oueb-wp-backup' ) );
			return;
		}

		$parts = array();
		if ( $database ) {
			$parts[] = __( 'the database', 'oueb-wp-backup' );
		}
		if ( $files ) {
			$parts[] = __( 'the files', 'oueb-wp-backup' );
		}
		WP_CLI::confirm(
			sprintf(
				/* translators: %s: "the database and the files", or one of them. */
				__( 'Replace %s of this site? The changes made since the backup will be lost.', 'oueb-wp-backup' ),
				implode( __( ' and ', 'oueb-wp-backup' ), $parts )
			)
			. ( $safety ? '' : ' ' . __( 'The current site will not be backed up first.', 'oueb-wp-backup' ) ),
			$assoc_args
		);

		self::drive_here();
		$upload = '';
		if ( null === $source ) {
			$upload = $this->upload( (string) $assoc_args['file'] );
			$source = array(
				'type'      => 'upload',
				'upload_id' => $upload,
			);
		}

		$response = $this->send(
			'POST',
			'/restore',
			array(
				'source'   => $source,
				'database' => $database,
				'files'    => $files,
				'safety'   => $safety,
			)
		);
		$run      = is_wp_error( $response ) ? null : $this->drive( (int) $response['id'] );

		// Après un échec, la copie de l'archive ne sert plus : une nouvelle commande en refait une.
		if ( '' !== $upload && ( null === $run || in_array( $run->status, array( Run::FAILED, Run::ABORTED ), true ) ) ) {
			( new Upload_Repository( Plugin::workspace() ) )->delete( $upload );
		}
		if ( null === $run ) {
			WP_CLI::error( self::message( $response ) );
			return;
		}
		self::finish( $run );
	}

	/**
	 * Imports the jobs and settings of BackWPup.
	 *
	 * BackWPup data is neither changed nor deleted.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Import again without asking, even if the jobs were imported before.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function import( array $args, array $assoc_args ): void {
		unset( $args );
		$summary = $this->request( 'GET', '/import' );
		if ( empty( $summary['available'] ) ) {
			WP_CLI::error( __( 'No BackWPup job found on this site.', 'oueb-wp-backup' ) );
		}
		if ( 'done' === $summary['status'] ) {
			WP_CLI::confirm( __( 'The jobs were already imported. Import them a second time?', 'oueb-wp-backup' ), $assoc_args );
		}

		$summary = $this->request( 'POST', '/import' );
		$count   = 0;
		foreach ( (array) $summary['report'] as $entry ) {
			if ( '' !== (string) ( $entry['job_id'] ?? '' ) ) {
				++$count;
				WP_CLI::log( sprintf( '%s → %s', $entry['name'], $entry['job_id'] ) );
			} elseif ( '' !== (string) ( $entry['name'] ?? '' ) ) {
				WP_CLI::log( (string) $entry['name'] );
			}
			foreach ( (array) ( $entry['notes'] ?? array() ) as $note ) {
				WP_CLI::log( '  - ' . $note );
			}
		}

		WP_CLI::success(
			sprintf(
				/* translators: %d: number of imported jobs. */
				_n( '%d job imported from BackWPup.', '%d jobs imported from BackWPup.', $count, 'oueb-wp-backup' ),
				$count
			)
		);
		if ( ! empty( $summary['backwpup_active'] ) ) {
			WP_CLI::warning( __( 'BackWPup is still active: both extensions would make the same backups. Deactivate it with `wp plugin deactivate backwpup`.', 'oueb-wp-backup' ) );
		}
	}

	/**
	 * Checks, repairs or optimizes the tables of the site, or lists them.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : What to do.
	 * ---
	 * options:
	 *   - tables
	 *   - check
	 *   - repair
	 *   - optimize
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp oueb-backup db check
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function db( array $args, array $assoc_args ): void {
		$operation = $args[0];
		$format    = $assoc_args['format'] ?? 'table';

		if ( 'tables' === $operation ) {
			$items = array_map(
				static fn( array $table ): array => array(
					'name' => $table['name'],
					'rows' => $table['rows'],
					'size' => size_format( $table['size'] ),
				),
				$this->request( 'GET', '/database/tables' )
			);
			WP_CLI\Utils\format_items( $format, $items, array( 'name', 'rows', 'size' ) );
			return;
		}

		$results = $this->request( 'POST', '/database/' . $operation );
		WP_CLI\Utils\format_items( $format, $results, array( 'table', 'status', 'message' ) );

		$failed = count( wp_list_filter( $results, array( 'status' => 'error' ) ) );
		if ( $failed > 0 ) {
			WP_CLI::error(
				sprintf(
					/* translators: %d: number of tables. */
					_n( '%d table has an error.', '%d tables have an error.', $failed, 'oueb-wp-backup' ),
					$failed
				)
			);
		}
		WP_CLI::success( __( 'Done.', 'oueb-wp-backup' ) );
	}

	/**
	 * Fait avancer une exécution dans ce processus, en affichant son journal.
	 *
	 * Si un autre processus tient l'exécution, la commande attend et affiche
	 * le journal à mesure.
	 *
	 * @since 0.1.0
	 *
	 * @param int $run_id Exécution.
	 */
	private function follow( int $run_id ): void {
		self::finish( $this->drive( $run_id ) );
	}

	/**
	 * Mène une exécution jusqu'à sa fin dans ce processus, en affichant son journal.
	 *
	 * @since 0.1.0
	 *
	 * @param int $run_id Exécution.
	 * @return Run Exécution terminée.
	 */
	private function drive( int $run_id ): Run {
		self::drive_here();

		$runs    = Plugin::runs();
		$printed = 0;
		do {
			$worked = Plugin::runner()->process( $run_id );
			$run    = $runs->find( $run_id );
			if ( null === $run ) {
				// WP_CLI::error() arrête la commande.
				WP_CLI::error( __( 'This backup does not exist.', 'oueb-wp-backup' ) );
			}

			$entries = Logger::read( Logger::path( $run, Plugin::workspace()->logs() ) );
			foreach ( array_slice( $entries, $printed ) as $entry ) {
				self::print_entry( $entry );
			}
			$printed = count( $entries );

			if ( ! $worked && $run->is_active() ) {
				sleep( 2 );
			}
		} while ( $run->is_active() );

		return $run;
	}

	/**
	 * Affiche une liste ; le format ids n'en garde que les identifiants.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $format Format.
	 * @param array<int, array>  $items  Lignes.
	 * @param array<int, string> $fields Colonnes.
	 */
	private static function output( string $format, array $items, array $fields ): void {
		if ( 'ids' === $format ) {
			$items = wp_list_pluck( $items, 'id' );
		}
		WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Ce processus mène l'exécution : pas de relance par une requête HTTP.
	 *
	 * @since 0.1.0
	 */
	private static function drive_here(): void {
		add_filter( 'oueb_wp_backup_continuation_request', '__return_false' );
	}

	/**
	 * Annonce la fin d'une exécution, et sort en erreur si elle a échoué.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution terminée.
	 */
	private static function finish( Run $run ): void {
		$restore = Run::KIND_RESTORE === $run->kind;
		switch ( $run->status ) {
			case Run::SUCCESS:
				WP_CLI::success(
					$restore
						? __( 'Restore finished.', 'oueb-wp-backup' )
						: sprintf(
							/* translators: 1: archive name, 2: size, like "12 MB". */
							__( 'Backup finished: %1$s, %2$s.', 'oueb-wp-backup' ),
							basename( $run->archive_file ),
							size_format( $run->archive_size )
						)
				);
				return;
			case Run::WARNING:
				WP_CLI::warning(
					$restore
						? __( 'Restore finished with warnings. Read them above.', 'oueb-wp-backup' )
						: __( 'Backup finished with warnings. Read them above.', 'oueb-wp-backup' )
				);
				return;
			case Run::ABORTED:
				WP_CLI::error( $restore ? __( 'Restore stopped.', 'oueb-wp-backup' ) : __( 'Backup stopped.', 'oueb-wp-backup' ) );
				return;
			default:
				WP_CLI::error( $restore ? __( 'Restore failed. Read the errors above.', 'oueb-wp-backup' ) : __( 'Backup failed. Read the errors above.', 'oueb-wp-backup' ) );
		}
	}

	/**
	 * Affiche une ligne de journal.
	 *
	 * @since 0.1.0
	 *
	 * @param array{time: string, level: string, message: string} $entry Ligne.
	 */
	private static function print_entry( array $entry ): void {
		$time = get_date_from_gmt( $entry['time'], 'H:i:s' );
		switch ( $entry['level'] ) {
			case 'error':
				WP_CLI::log( WP_CLI::colorize( '%R' . $time . ' ' . $entry['message'] . '%n' ) );
				break;
			case 'warning':
				WP_CLI::log( WP_CLI::colorize( '%Y' . $time . ' ' . $entry['message'] . '%n' ) );
				break;
			default:
				WP_CLI::log( $time . ' ' . $entry['message'] );
		}
	}

	/**
	 * Copie une archive du serveur dans les envois, comme depuis l'administration.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Chemin de l'archive.
	 * @return string Identifiant de l'envoi.
	 */
	private function upload( string $path ): string {
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: file path. */
					__( 'Cannot read %s.', 'oueb-wp-backup' ),
					$path
				)
			);
		}

		$uploads = new Upload_Repository( Plugin::workspace() );
		$upload  = $uploads->create( basename( $path ), (int) filesize( $path ) );
		if ( is_wp_error( $upload ) ) {
			WP_CLI::error( $upload );
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Lecture par morceaux d'une grosse archive.
		$offset = 0;
		while ( ! feof( $handle ) ) {
			$chunk = (string) fread( $handle, 8 * MB_IN_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Idem.
			if ( '' === $chunk ) {
				break;
			}
			$result = $uploads->append( $upload['id'], $offset, $chunk );
			if ( is_wp_error( $result ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Idem.
				$uploads->delete( $upload['id'] );
				WP_CLI::error( $result );
			}
			$offset += strlen( $chunk );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Idem.

		return $upload['id'];
	}

	/**
	 * Appelle une route REST de l'extension, au nom d'un administrateur.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method Méthode HTTP.
	 * @param string               $route  Route, après l'espace de noms.
	 * @param array<string, mixed> $params Paramètres.
	 * @return array<mixed> Réponse.
	 */
	private function request( string $method, string $route, array $params = array() ): array {
		$data = $this->send( $method, $route, $params );
		if ( is_wp_error( $data ) ) {
			WP_CLI::error( self::message( $data ) );
		}

		return $data;
	}

	/**
	 * Appelle une route REST de l'extension, et renvoie son erreur au lieu de s'arrêter.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method Méthode HTTP.
	 * @param string               $route  Route, après l'espace de noms.
	 * @param array<string, mixed> $params Paramètres.
	 * @return array<mixed>|WP_Error Réponse, ou erreur.
	 */
	private function send( string $method, string $route, array $params = array() ) {
		self::act_as_admin();

		$request = new WP_REST_Request( $method, '/oueb-wp-backup/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		return $response->is_error() ? $response->as_error() : (array) $response->get_data();
	}

	/**
	 * Agit au nom du premier administrateur, si --user n'a choisi personne.
	 *
	 * @since 0.1.0
	 */
	private static function act_as_admin(): void {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		if ( 0 !== get_current_user_id() ) {
			WP_CLI::error( __( 'This user cannot manage backups. Choose an administrator with --user.', 'oueb-wp-backup' ) );
		}

		$candidates = is_multisite()
			? array_filter( array_map( static fn( string $login ) => get_user_by( 'login', $login ), get_super_admins() ) )
			: get_users(
				array(
					'role'    => 'administrator',
					'orderby' => 'ID',
					'number'  => 10,
				)
			);
		foreach ( $candidates as $user ) {
			wp_set_current_user( $user->ID );
			if ( current_user_can( Capabilities::MANAGE ) ) {
				return;
			}
		}

		WP_CLI::error( __( 'No administrator can manage backups on this site. Choose one with --user.', 'oueb-wp-backup' ) );
	}

	/**
	 * Texte d'une erreur, sans balises.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error Erreur.
	 * @return string Message.
	 */
	private static function message( WP_Error $error ): string {
		return wp_strip_all_tags( html_entity_decode( $error->get_error_message(), ENT_QUOTES ) );
	}
}
