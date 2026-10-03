<?php
/**
 * Table des exécutions.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Crée et met à jour la table des exécutions.
 *
 * Une seule table pour tout le réseau en multisite, sous le préfixe de base.
 *
 * @since 0.1.0
 */
final class Schema {

	/**
	 * Version du schéma. À augmenter à chaque changement de la table.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * Option qui garde la version installée.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'oueb_wp_backup_db_version';

	/**
	 * Renvoie le nom complet de la table.
	 *
	 * @since 0.1.0
	 *
	 * @return string Nom de la table.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'oueb_wp_backup_runs';
	}

	/**
	 * Crée ou met à jour la table si la version installée est ancienne.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_site_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		self::install();
		update_site_option( self::OPTION, self::VERSION );
	}

	/**
	 * Crée ou met à jour la table avec dbDelta().
	 *
	 * @since 0.1.0
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id varchar(64) NOT NULL,
  status varchar(20) NOT NULL,
  trigger_type varchar(20) NOT NULL,
  started_at bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  finished_at bigint(20) unsigned NOT NULL DEFAULT 0,
  step varchar(40) NOT NULL DEFAULT '',
  progress tinyint(3) unsigned NOT NULL DEFAULT 0,
  state longtext NOT NULL,
  lock_owner varchar(64) NOT NULL DEFAULT '',
  lock_until bigint(20) unsigned NOT NULL DEFAULT 0,
  archive_file varchar(255) NOT NULL DEFAULT '',
  archive_size bigint(20) unsigned NOT NULL DEFAULT 0,
  warnings int(10) unsigned NOT NULL DEFAULT 0,
  errors int(10) unsigned NOT NULL DEFAULT 0,
  log_token varchar(32) NOT NULL DEFAULT '',
  continue_token varchar(64) NOT NULL DEFAULT '',
  abort_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY job_started (job_id,started_at)
) {$charset};"
		);
	}
}
