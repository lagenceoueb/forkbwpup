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
use Oueb\WpBackup\Engine\Continuation;
use Oueb\WpBackup\Engine\Run_Repository;
use Oueb\WpBackup\Engine\Runner;
use Oueb\WpBackup\Engine\Schema;
use Oueb\WpBackup\Engine\Steps\Step_Factory;
use Oueb\WpBackup\Engine\Watchdog;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Rest\Jobs_Controller;
use Oueb\WpBackup\Rest\Runs_Controller;
use Oueb\WpBackup\Rest\Settings_Controller;
use Oueb\WpBackup\Rest\Storages_Controller;
use Oueb\WpBackup\Security\Capabilities;
use Oueb\WpBackup\Storage\Storage_Repository;
use Oueb\WpBackup\Storage\Workspace;

defined( 'ABSPATH' ) || exit;

/**
 * Branche les services de l'extension sur WordPress et les fournit.
 *
 * Pendant la refonte, l'interface React ne s'affiche que si la constante
 * OUEB_WP_BACKUP_NEXT vaut true dans wp-config.php. L'ancienne interface
 * reste la seule active par défaut, jusqu'à la bascule du lot 6.
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
	const VERSION = '0.0.1';

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
		Download::register();

		if ( self::is_next_enabled() && is_admin() ) {
			Admin_Page::register();
		}
	}

	/**
	 * Déclare les routes REST de l'extension.
	 *
	 * @since 0.1.0
	 */
	public static function register_rest_routes(): void {
		( new Settings_Controller() )->register_routes();
		( new Jobs_Controller( self::jobs(), self::storages() ) )->register_routes();
		( new Runs_Controller( self::runs(), self::jobs(), self::runner(), self::continuation(), self::workspace(), self::storages() ) )->register_routes();
		( new Storages_Controller( self::storages(), self::jobs() ) )->register_routes();
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
			static fn(): Runner => new Runner( self::runs(), self::jobs(), self::workspace(), new Step_Factory( self::storages() ), self::continuation() )
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
	 * Indique si la nouvelle interface est activée.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai si OUEB_WP_BACKUP_NEXT vaut true.
	 */
	public static function is_next_enabled(): bool {
		return defined( 'OUEB_WP_BACKUP_NEXT' ) && true === OUEB_WP_BACKUP_NEXT;
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
