<?php
/**
 * Désinstallation d'Oueb WP Backup.
 *
 * Efface les réglages, les clés de chiffrement, l'historique et les journaux.
 * Les archives du dossier local restent sur le serveur : ce sont des
 * sauvegardes, et leur suppression doit rester un choix de l'administrateur.
 * Les données de BackWPup ne sont jamais touchées.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Efface les données de l'extension.
 *
 * @since 0.1.0
 */
function oueb_wp_backup_uninstall(): void {
	global $wpdb;

	wp_unschedule_hook( 'oueb_wp_backup_scheduled' );
	wp_unschedule_hook( 'oueb_wp_backup_watchdog' );

	// Les tâches créées chez cron-job.org appelleraient le site pour rien.
	require_once __DIR__ . '/includes/bootstrap.php';
	$key = (string) Oueb\WpBackup\Settings\Settings::get( 'cronjob_org_key' );
	if ( '' !== $key ) {
		foreach ( ( new Oueb\WpBackup\Job\Job_Repository() )->all() as $job ) {
			if ( $job->cronjob_org_id > 0 ) {
				try {
					( new Oueb\WpBackup\Remote\Cronjob_Org_Client( $key ) )->delete_job( $job->cronjob_org_id );
				} catch ( Throwable $error ) {
					// Le service est injoignable : la tâche distante reste, sans effet.
					unset( $error );
				}
			}
		}
	}

	// Dossier de travail : journaux, fichiers temporaires et envois, pas les archives.
	$suffix = (string) get_site_option( 'oueb_wp_backup_workspace', '' );
	if ( preg_match( '/^[a-z0-9]{16}$/', $suffix ) ) {
		$uploads = wp_upload_dir( null, false );
		$root    = $uploads['basedir'] . '/oueb-wp-backup-' . $suffix;
		foreach ( array( 'logs', 'tmp', 'uploads' ) as $dir ) {
			oueb_wp_backup_remove_tree( $root . '/' . $dir );
		}
	}

	$like = $wpdb->esc_like( 'oueb_wp_backup_' ) . '%';
	if ( is_multisite() ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->sitemeta, $like ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . 'oueb_wp_backup_runs' ) );

	wp_cache_flush();
}

/**
 * Supprime un dossier et son contenu, sans suivre les liens symboliques.
 *
 * @since 0.1.0
 *
 * @param string $dir Dossier.
 */
function oueb_wp_backup_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Désinstallation, sans WP_Filesystem.
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Désinstallation, sans WP_Filesystem.
}

oueb_wp_backup_uninstall();
