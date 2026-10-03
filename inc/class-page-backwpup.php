<?php
/**
 * Render plugin dashboard.
 */
class BackWPup_Page_BackWPup
{
    /**
     * Called on load action.
     */
    public static function load()
    {
        global $wpdb;

        if (isset($_GET['action']) && $_GET['action'] == 'dbdumpdl') {
            //check permissions
            check_admin_referer('backwpupdbdumpdl');

            if (!current_user_can('backwpup_jobs_edit')) {
                exit();
            }

            //doing dump
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Content-Type: application/octet-stream; charset=' . get_bloginfo('charset'));
            header('Content-Disposition: attachment; filename=' . DB_NAME . '.sql;');

            try {
                $sql_dump = new BackWPup_MySQLDump();

                foreach ($sql_dump->tables_to_dump as $key => $table) {
                    if ($wpdb->prefix != substr((string) $table, 0, strlen((string) $wpdb->prefix))) {
                        unset($sql_dump->tables_to_dump[$key]);
                    }
                }
                $sql_dump->execute();
                unset($sql_dump);
            } catch (Exception $e) {
                exit($e->getMessage());
            }

            exit();
        }
    }

    /**
     * Enqueue script.
     */
    public static function admin_print_scripts()
    {
        wp_enqueue_script('backwpupgeneral');
    }

    /**
     * Print the markup.
     */
    public static function page()
    {
        ?>
        <div class="wrap" id="backwpup-page">
            <h1><?php echo sprintf(__('%s &rsaquo; Dashboard', 'backwpup'), BackWPup::get_plugin_data('name')); ?></h1>
			<?php

            BackWPup_Admin::display_messages();

        ?>
				<div class="backwpup-welcome backwpup-max-width">
					<h3><?php _ex('Planning backups', 'Dashboard heading', 'backwpup'); ?></h3>
					<p><?php _e('Use the short links in the <strong>First steps</strong> box to plan and schedule backup jobs.', 'backwpup'); echo ' '; _e('Use your backup archives to save your entire WordPress installation including <code>/wp-content/</code>. Push them to an external storage service if you don’t want to save the backups on the same server.', 'backwpup'); ?></p>
					<h3><?php _ex('Restoring backups', 'Dashboard heading', 'backwpup'); ?></h3>
					<p><?php printf(__('With a single backup archive you are able to restore an installation from the <a href="%s">Restore</a> page.', 'backwpup'), network_admin_url('admin.php') . '?page=backwpuprestore'); ?></p>
					<h3><?php _ex('Ready to set up a backup job?', 'Dashboard heading', 'backwpup'); ?></h3>
					<p><?php printf(__('<a href="%s">Add a new backup job</a> and plan what you want to save.', 'backwpup'), network_admin_url('admin.php') . '?page=backwpupeditjob'); ?>
					<br /><?php _e('<strong>Please note: You are solely responsible for the security of your data; the authors of this plugin are not.</strong>', 'backwpup'); ?></p>
				</div>
			<?php

        if (current_user_can('backwpup_jobs_edit') && current_user_can('backwpup_logs') && current_user_can('backwpup_jobs_start')) {
            ?>
				<div  id="backwpup-first-steps" class="metabox-holder postbox backwpup-floated-postbox">
					<h3 class="hndle"><span><?php _e('First Steps', 'backwpup'); ?></span></h3>
					<div class="inside">
						<ul>
                           		<li type="1"><a href="<?php echo network_admin_url('admin.php') . '?page=backwpupsettings#backwpup-tab-information'; ?>"><?php _e('Check the installation', 'backwpup'); ?></a></li>
                            	<li type="1"><a href="<?php echo network_admin_url('admin.php') . '?page=backwpupeditjob'; ?>"><?php _e('Create a Job', 'backwpup'); ?></a></li>
							<li type="1"><a href="<?php echo network_admin_url('admin.php') . '?page=backwpupjobs'; ?>"><?php _e('Run the created job', 'backwpup'); ?></a></li>
							<li type="1"><a href="<?php echo network_admin_url('admin.php') . '?page=backwpuplogs'; ?>"><?php _e('Check the job log', 'backwpup'); ?></a></li>
						</ul>
					</div>
				</div>
			<?php
        }

        if (current_user_can('backwpup_jobs_start')) {?>
				<div id="backwpup-one-click-backup" class="metabox-holder postbox backwpup-floated-postbox">
					<h3 class="hndle"><span><?php esc_html_e('One click backup', 'backwpup'); ?></span></h3>
					<div class="inside">
						<a href="<?php echo wp_nonce_url(network_admin_url('admin.php?page=backwpup&action=dbdumpdl'), 'backwpupdbdumpdl'); ?>" class="button button-primary button-primary-bwp" title="<?php esc_attr_e('Generate a database backup of WordPress tables and download it right away!', 'backwpup'); ?>"><?php esc_html_e('Download database backup', 'backwpup'); ?></a><br />
					</div>
				</div>
			<?php } ?>

			<div id="backwpup-stats" class="metabox-holder postbox backwpup-cleared-postbox backwpup-max-width">
				<div class="backwpup-table-wrap">
				<?php
                    self::mb_next_jobs();
        self::mb_last_logs(); ?>
				</div>
			</div>

        </div>
	<?php
    }

    /**
     * Displaying next jobs.
     */
    private static function mb_next_jobs()
    {
        if (!current_user_can('backwpup_jobs')) {
            return;
        } ?>
		<table class="wp-list-table widefat" cellspacing="0">
			<caption><?php _e('Next scheduled jobs', 'backwpup'); ?></caption>
			<thead>
			<tr>
				<th style="width: 30%"><?php esc_html_e('Time', 'backwpup'); ?></th>
				<th style="width: 70%"><?php esc_html_e('Job', 'backwpup'); ?></th>
			</tr>
			</thead>
			<?php
            //get next jobs
            $mainsactive = BackWPup_Option::get_job_ids('activetype', 'wpcron');
        sort($mainsactive);
        $alternate = true;
        // add working job if it not in active jobs
        $job_object = BackWPup_Job::get_working_data();
        if (!empty($job_object) && !empty($job_object->job['jobid']) && !in_array($job_object->job['jobid'], $mainsactive, true)) {
            $mainsactive[] = $job_object->job['jobid'];
        }

        foreach ($mainsactive as $jobid) {
            $name = BackWPup_Option::get($jobid, 'name');
            if (!empty($job_object) && $job_object->job['jobid'] == $jobid) {
                $runtime = current_time('timestamp') - $job_object->job['lastrun'];
                if (!$alternate) {
                    echo '<tr>';
                    $alternate = true;
                } else {
                    echo '<tr class="alternate">';
                    $alternate = false;
                }
                echo '<td>' . sprintf('<span style="color:#e66f00;">' . esc_html__('working since %d seconds', 'backwpup') . '</span>', $runtime) . '</td>';
                echo '<td><span style="font-weight:bold;">' . esc_html($job_object->job['name']) . '</span><br />';
                echo '<a style="color:red;" href="' . wp_nonce_url(network_admin_url('admin.php?page=backwpupjobs&action=abort'), 'abort-job') . '">' . esc_html__('Abort', 'backwpup') . '</a>';
                echo '</td></tr>';
            } else {
                if (!$alternate) {
                    echo '<tr>';
                    $alternate = true;
                } else {
                    echo '<tr class="alternate">';
                    $alternate = false;
                }
                if ($nextrun = wp_next_scheduled('backwpup_cron', ['arg' => $jobid]) + (get_option('gmt_offset') * 3600)) {
                    echo '<td>' . sprintf(__('%1$s at %2$s', 'backwpup'), date_i18n(get_option('date_format'), $nextrun, true), date_i18n(get_option('time_format'), $nextrun, true)) . '</td>';
                } else {
                    echo '<td><em>' . esc_html__('Not scheduled!', 'backwpup') . '</em></td>';
                }

                echo '<td><a href="' . wp_nonce_url(network_admin_url('admin.php') . '?page=backwpupeditjob&jobid=' . $jobid, 'edit-job') . '" title="' . esc_attr(__('Edit Job', 'backwpup')) . '">' . esc_html($name) . '</a></td></tr>';
            }
        }
        if (empty($mainsactive) and !empty($job_object)) {
            echo '<tr><td colspan="2"><i>' . esc_html__('none', 'backwpup') . '</i></td></tr>';
        } ?>
		</table>
		<?php
    }

    /**
     * Displaying last logs.
     */
    private static function mb_last_logs()
    {
        if (!current_user_can('backwpup_logs')) {
            return;
        } ?>
		<table class="wp-list-table widefat" cellspacing="0">
			<caption><?php esc_html_e('Last logs', 'backwpup'); ?></caption>
			<thead>
			<tr><th style="width:30%"><?php esc_html_e('Time', 'backwpup'); ?></th><th style="width:55%"><?php esc_html_e('Job', 'backwpup'); ?></th><th style="width:20%"><?php esc_html_e('Result', 'backwpup'); ?></th></tr>
			</thead>
			<?php
            //get log files
            $logfiles = [];
        $log_folder = get_site_option('backwpup_cfg_logfolder');
        $log_folder = BackWPup_File::get_absolute_path($log_folder);
        if (is_readable($log_folder)) {
            try {
                $dir = new BackWPup_Directory($log_folder);

                foreach ($dir as $file) {
                    if ($file->isReadable() && $file->isFile() && strpos($file->getFilename(), 'backwpup_log_') !== false && strpos($file->getFilename(), '.html') !== false) {
                        $logfiles[$file->getMTime()] = clone $file;
                    }
                }
                krsort($logfiles, SORT_NUMERIC);
            } catch (UnexpectedValueException $e) {
                echo '<tr><td colspan="3"><span style="color:red;font-weight:bold;">' .
                        sprintf(__('Could not open log folder: %s', 'backwpup'), $log_folder) .
                        '</td></tr>';
            }
        }

        if (count($logfiles) > 0) {
            $count = 0;
            $alternate = true;

            foreach ($logfiles as $logfile) {
                $logdata = BackWPup_Job::read_logheader($logfile->getPathname());
                if (!$alternate) {
                    echo '<tr>';
                    $alternate = true;
                } else {
                    echo '<tr class="alternate">';
                    $alternate = false;
                }
                echo '<td>' . sprintf(__('%1$s at %2$s', 'backwpup'), date_i18n(get_option('date_format'), $logdata['logtime']), date_i18n(get_option('time_format'), $logdata['logtime'])) . '</td>';
                $log_name = str_replace(['.html', '.gz'], '', $logfile->getBasename());
                echo '<td><a class="thickbox" href="' . admin_url('admin-ajax.php?action=backwpup_view_log&log=' . $log_name . '&_ajax_nonce=' . wp_create_nonce('view-log_' . $log_name) . '&TB_iframe=true&width=640&height=440') . '" title="' . esc_attr($logfile->getBasename()) . '">' . esc_html($logdata['name']) . '</i></a></td>';
                echo '<td>';
                if ($logdata['errors']) {
                    printf('<span style="color:red;font-weight:bold;">' . _n('%d ERROR', '%d ERRORS', $logdata['errors'], 'backwpup') . '</span><br />', $logdata['errors']);
                }
                if ($logdata['warnings']) {
                    printf('<span style="color:#e66f00;font-weight:bold;">' . _n('%d WARNING', '%d WARNINGS', $logdata['warnings'], 'backwpup') . '</span><br />', $logdata['warnings']);
                }
                if (!$logdata['errors'] && !$logdata['warnings']) {
                    echo '<span style="color:green;font-weight:bold;">' . __('OK', 'backwpup') . '</span>';
                }
                echo '</td></tr>';
                ++$count;
                if ($count >= 5) {
                    break;
                }
            }
        } else {
            echo '<tr><td colspan="3">' . __('none', 'backwpup') . '</td></tr>';
        } ?>
		</table>
		<?php
    }
}
