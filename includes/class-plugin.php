<?php
/**
 * Point d'entrée du noyau d'Oueb WP Backup.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup;

use Oueb\WpBackup\Admin\Admin_Page;
use Oueb\WpBackup\Admin\Download;
use Oueb\WpBackup\Cli\Command;
use Oueb\WpBackup\Database\Table_Maintenance;
use Oueb\WpBackup\Engine\Continuation;
use Oueb\WpBackup\Engine\Run_Repository;
use Oueb\WpBackup\Engine\Runner;
use Oueb\WpBackup\Engine\Schema;
use Oueb\WpBackup\Engine\Steps\Step_Factory;
use Oueb\WpBackup\Engine\Watchdog;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Legacy\Legacy_Import;
use Oueb\WpBackup\Restore\Restore_Plan;
use Oueb\WpBackup\Restore\Upload_Repository;
use Oueb\WpBackup\Rest\Database_Controller;
use Oueb\WpBackup\Rest\Encryption_Controller;
use Oueb\WpBackup\Rest\Import_Controller;
use Oueb\WpBackup\Rest\Jobs_Controller;
use Oueb\WpBackup\Rest\Restore_Controller;
use Oueb\WpBackup\Rest\Runs_Controller;
use Oueb\WpBackup\Rest\Settings_Controller;
use Oueb\WpBackup\Rest\Storages_Controller;
use Oueb\WpBackup\Rest\Trigger_Controller;
use Oueb\WpBackup\Schedule\Main_Site;
use Oueb\WpBackup\Schedule\Scheduler;
use Oueb\WpBackup\Security\Capabilities;
use Oueb\WpBackup\Security\Key_Ring;
use Oueb\WpBackup\Storage\Storage_Repository;
use Oueb\WpBackup\Storage\Workspace;
use Oueb\WpBackup\Update\Github_Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Branche les services de l'extension sur WordPress et les fournit.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Version de l'extension.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const VERSION = '0.1.0';

	/**
	 * Chemin du fichier principal de l'extension.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private static string $file = '';

	/**
	 * Services déjà construits.
	 *
	 * @since 0.1.0
	 * @var array<string, object>
	 */
	private static array $services = array();

	/**
	 * Démarre l'extension.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Chemin du fichier principal de l'extension.
	 */
	public static function boot( string $file ): void {
		if ( '' !== self::$file ) {
			return;
		}
		self::$file = $file;

		Capabilities::register();
		add_action( 'init', array( Schema::class, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		add_action( Watchdog::HOOK, array( self::class, 'run_watchdog' ) );
		add_action( Scheduler::HOOK, array( self::class, 'run_scheduled' ) );
		add_action( 'init', array( self::class, 'ensure_schedules' ), 20 );
		add_action( 'init', array( self::class, 'load_textdomain' ), 1 );
		Download::register();
		Legacy_Import::register();

		if ( is_admin() ) {
			Admin_Page::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Command::register();
		}
		// Absent de la version wordpress.org, qui gère ses propres mises à jour.
		if ( class_exists( Github_Updater::class ) ) {
			Github_Updater::register();
		}
	}

	/**
	 * Charge les traductions de l'extension.
	 *
	 * @since 0.1.0
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'oueb-wp-backup', false, dirname( plugin_basename( self::$file ) ) . '/languages' );
	}

	/**
	 * Désactive l'extension : plus aucun événement WP-Cron de l'extension.
	 *
	 * Les réglages, les clés et les sauvegardes restent en place pour une
	 * réactivation. La désinstallation, elle, efface les réglages.
	 *
	 * @since 0.1.0
	 */
	public static function deactivate(): void {
		Main_Site::run(
			static function (): void {
				wp_unschedule_hook( Scheduler::HOOK );
				wp_unschedule_hook( Watchdog::HOOK );
			}
		);
	}

	/**
	 * Déclare les routes REST de l'extension.
	 *
	 * @since 0.1.0
	 */
	public static function register_rest_routes(): void {
		( new Settings_Controller() )->register_routes();
		( new Jobs_Controller( self::jobs(), self::storages(), self::scheduler() ) )->register_routes();
		( new Trigger_Controller( self::jobs(), self::runner() ) )->register_routes();
		( new Encryption_Controller( self::keys() ) )->register_routes();
		( new Runs_Controller( self::runs(), self::jobs(), self::runner(), self::continuation(), self::workspace(), self::storages() ) )->register_routes();
		( new Storages_Controller( self::storages(), self::jobs() ) )->register_routes();
		( new Import_Controller( new Legacy_Import( self::jobs(), self::storages(), self::scheduler() ) ) )->register_routes();
		( new Restore_Controller( self::runs(), self::jobs(), self::runner(), self::storages(), self::workspace(), new Upload_Repository( self::workspace() ) ) )->register_routes();
		( new Database_Controller( new Table_Maintenance(), self::runs() ) )->register_routes();
	}

	/**
	 * Lance la surveillance des exécutions, appelée par WP-Cron.
	 *
	 * @since 0.1.0
	 */
	public static function run_watchdog(): void {
		Watchdog::check( self::runs(), self::runner() );
	}

	/**
	 * Lance une tâche planifiée, appelée par WP-Cron.
	 *
	 * @since 0.1.0
	 *
	 * @param string $job_id Tâche.
	 */
	public static function run_scheduled( $job_id ): void {
		self::scheduler()->run( (string) $job_id );
	}

	/**
	 * Reprogramme les tâches WP-Cron perdues, pendant WP-Cron et en administration.
	 *
	 * @since 0.1.0
	 */
	public static function ensure_schedules(): void {
		if ( wp_doing_cron() || is_admin() ) {
			self::scheduler()->ensure();
		}
	}

	/**
	 * Renvoie les clés de chiffrement.
	 *
	 * @since 0.1.0
	 *
	 * @return Key_Ring Clés.
	 */
	public static function keys(): Key_Ring {
		return self::service( Key_Ring::class, static fn(): Key_Ring => new Key_Ring() );
	}

	/**
	 * Renvoie le planificateur.
	 *
	 * @since 0.1.0
	 *
	 * @return Scheduler Planificateur.
	 */
	public static function scheduler(): Scheduler {
		return self::service( Scheduler::class, static fn(): Scheduler => new Scheduler( self::jobs(), self::runner() ) );
	}

	/**
	 * Renvoie le dépôt des tâches.
	 *
	 * @since 0.1.0
	 *
	 * @return Job_Repository Tâches.
	 */
	public static function jobs(): Job_Repository {
		return self::service( Job_Repository::class, static fn(): Job_Repository => new Job_Repository() );
	}

	/**
	 * Renvoie le dépôt des exécutions.
	 *
	 * @since 0.1.0
	 *
	 * @return Run_Repository Exécutions.
	 */
	public static function runs(): Run_Repository {
		return self::service( Run_Repository::class, static fn(): Run_Repository => new Run_Repository() );
	}

	/**
	 * Renvoie les dossiers de travail.
	 *
	 * @since 0.1.0
	 *
	 * @return Workspace Dossiers de travail.
	 */
	public static function workspace(): Workspace {
		return self::service( Workspace::class, static fn(): Workspace => new Workspace() );
	}

	/**
	 * Renvoie le dépôt des stockages.
	 *
	 * @since 0.1.0
	 *
	 * @return Storage_Repository Stockages.
	 */
	public static function storages(): Storage_Repository {
		return self::service( Storage_Repository::class, static fn(): Storage_Repository => new Storage_Repository( self::workspace() ) );
	}

	/**
	 * Renvoie la relance des exécutions.
	 *
	 * @since 0.1.0
	 *
	 * @return Continuation Relance.
	 */
	public static function continuation(): Continuation {
		return self::service( Continuation::class, static fn(): Continuation => new Continuation( self::runs() ) );
	}

	/**
	 * Renvoie le moteur.
	 *
	 * @since 0.1.0
	 *
	 * @return Runner Moteur.
	 */
	public static function runner(): Runner {
		return self::service(
			Runner::class,
			static fn(): Runner => new Runner(
				self::runs(),
				self::jobs(),
				self::workspace(),
				new Step_Factory( self::storages(), self::keys() ),
				self::continuation(),
				new Restore_Plan( self::storages(), self::keys(), self::runs() )
			)
		);
	}

	/**
	 * Construit un service une seule fois.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name    Nom du service.
	 * @param callable $factory Fonction qui le construit.
	 * @return mixed Service.
	 */
	private static function service( string $name, callable $factory ) {
		if ( ! isset( self::$services[ $name ] ) ) {
			self::$services[ $name ] = $factory();
		}

		return self::$services[ $name ];
	}

	/**
	 * Renvoie le nom de l'extension pour WordPress, tel qu'il figure dans active_plugins.
	 *
	 * @since 0.1.0
	 *
	 * @return string Nom, par exemple « oueb-wp-backup/backwpup.php ».
	 */
	public static function basename(): string {
		return plugin_basename( self::$file );
	}

	/**
	 * Renvoie le dossier de l'extension, sans barre oblique finale.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	public static function dir(): string {
		return dirname( self::$file );
	}

	/**
	 * Renvoie l'adresse du dossier de l'extension, sans barre oblique finale.
	 *
	 * @since 0.1.0
	 *
	 * @return string Adresse absolue.
	 */
	public static function url(): string {
		return untrailingslashit( plugins_url( '', self::$file ) );
	}
}
