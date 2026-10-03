<?php
/**
 * Class for upgrade / deactivation / uninstall.
 */
class BackWPup_Install
{
    /**
     * Creates DB und updates settings.
     */
    public static function activate()
    {
        $version_db = get_site_option('backwpup_version');

        //create new options
        if (is_multisite()) {
            add_site_option('backwpup_jobs', []);
        } else {
            add_option('backwpup_jobs', [], null, 'no');
        }

		// remove old schedule.
		wp_clear_scheduled_hook( 'backwpup_cron' );
		// make new schedule.
		$activejobs = BackWPup_Option::get_job_ids( 'activetype', 'wpcron' );
		if ( ! empty( $activejobs ) ) {
			foreach ( $activejobs as $id ) {
				$cron_next = BackWPup_Cron::cron_next( BackWPup_Option::get( $id, 'cron' ) );
				wp_schedule_single_event( $cron_next, 'backwpup_cron', [ 'arg' => $id ] );
			}
        }

        // Recreate or update the cron-job.org jobs removed on deactivation.
        oueb_cronjob_org_sync_all();

        //add Cleanup schedule
        if (!wp_next_scheduled('backwpup_check_cleanup')) {
            wp_schedule_event(time(), 'twicedaily', 'backwpup_check_cleanup');
        }

        //add capabilities to administrator role
        $role = get_role('administrator');
        if (is_object($role) && method_exists($role, 'add_cap')) {
            $role->add_cap('backwpup');
            $role->add_cap('backwpup_jobs');
            $role->add_cap('backwpup_jobs_edit');
            $role->add_cap('backwpup_jobs_start');
            $role->add_cap('backwpup_backups');
            $role->add_cap('backwpup_backups_download');
            $role->add_cap('backwpup_backups_delete');
            $role->add_cap('backwpup_logs');
            $role->add_cap('backwpup_logs_delete');
            $role->add_cap('backwpup_settings');
            $role->add_cap('backwpup_restore');
        }

        //add/overwrite roles
        add_role('backwpup_admin', __('BackWPup Admin', 'backwpup'), [
            'read' => true,                         // make it usable for single user
            'backwpup' => true, 					// BackWPup general accesses (like Dashboard)
            'backwpup_jobs' => true,				// accesses for job page
            'backwpup_jobs_edit' => true,			// user can edit/delete/copy/export jobs
            'backwpup_jobs_start' => true,		    // user can start jobs
            'backwpup_backups' => true,			    // accesses for backups page
            'backwpup_backups_download' => true,	// user can download backup files
            'backwpup_backups_delete' => true,	    // user can delete backup files
            'backwpup_logs' => true,				// accesses for logs page
            'backwpup_logs_delete' => true,		    // user can delete log files
            'backwpup_settings' => true,			// accesses for settings page
            'backwpup_restore' => true,				// accesses for restore page
        ]);

        add_role('backwpup_check', __('BackWPup jobs checker', 'backwpup'), [
            'read' => true,
            'backwpup' => true,
            'backwpup_jobs' => true,
            'backwpup_jobs_edit' => false,
            'backwpup_jobs_start' => false,
            'backwpup_backups' => true,
            'backwpup_backups_download' => false,
            'backwpup_backups_delete' => false,
            'backwpup_logs' => true,
            'backwpup_logs_delete' => false,
            'backwpup_settings' => false,
            'backwpup_restore' => false,
        ]);

        add_role('backwpup_helper', __('BackWPup jobs functions', 'backwpup'), [
            'read' => true,
            'backwpup' => true,
            'backwpup_jobs' => true,
            'backwpup_jobs_edit' => false,
            'backwpup_jobs_start' => true,
            'backwpup_backups' => true,
            'backwpup_backups_download' => true,
            'backwpup_backups_delete' => true,
            'backwpup_logs' => true,
            'backwpup_logs_delete' => true,
            'backwpup_settings' => false,
            'backwpup_restore' => false,
        ]);

        //add default options
        BackWPup_Option::default_site_options();

        //update version
        update_site_option('backwpup_version', BackWPup::get_plugin_data('Version'));

        //only redirect if not in WP CLI environment
        if (!$version_db && !(defined(\WP_CLI::class) && WP_CLI)) {
            wp_redirect(network_admin_url('admin.php') . '?page=backwpup');

            exit();
        }
    }

    /**
     * Cleanup on Plugin deactivation.
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook('backwpup_cron');
        $activejobs = BackWPup_Option::get_job_ids('activetype', 'wpcron');
        if (!empty($activejobs)) {
            foreach ($activejobs as $id) {
                wp_clear_scheduled_hook('backwpup_cron', ['arg' => $id]);
            }
        }
        wp_clear_scheduled_hook('backwpup_check_cleanup');

        // cron-job.org would otherwise keep calling the site.
        oueb_cronjob_org_remove_all();
    }
}
