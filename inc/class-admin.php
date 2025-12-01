<?php

use Inpsyde\BackWPup\Infrastructure\Restore\Restore;
use Inpsyde\BackWPup\Notice;
use Inpsyde\BackWPup\Notice\DropboxNotice;
use Inpsyde\BackWPup\Notice\NoticeView;
use Inpsyde\BackWPup\Notice\PhpNotice;
use Inpsyde\BackWPup\Notice\WordPressNotice;
use Inpsyde\BackWPup\Pro\Settings;
use Inpsyde\BackWPup\Pro\Settings\AjaxEncryptionKeyHandler;
use Inpsyde\BackWPup\Notice\EvaluateNotice;
use Inpsyde\BackWPup\Notice\EasycronUpdateNotice;
use Inpsyde\BackWPup\Notice\RestoreFeatureInformationNotice;

/**
 * BackWPup_Admin.
 */
final class BackWPup_Admin
{
    public $page_hooks = [];

    /**
     * @var BackWPup_Page_Settings
     */
    private $settings;

    public function __construct(BackWPup_Page_Settings $settings)
    {
        $this->settings = $settings;

        BackWPup::load_text_domain();
    }

    /**
     * Enqueues main css file.
     */
    public function admin_css()
    {
        $pluginDir = untrailingslashit(BackWPup::get_plugin_data('plugindir'));
        $filePath = "{$pluginDir}/assets/css/main.min.css";

        wp_enqueue_style(
            'backwpup',
            BackWPup::get_plugin_data('URL') . '/assets/css/main.min.css',
            [],
            filemtime($filePath),
            'screen'
        );

        // Enqueue Agence Oueb Custom Admin CSS
        $customCssPath = "{$pluginDir}/assets/css/agence-oueb-admin.css";
        if (file_exists($customCssPath)) {
            wp_enqueue_style(
                'agence-oueb-admin',
                BackWPup::get_plugin_data('URL') . '/assets/css/agence-oueb-admin.css',
                ['backwpup'],
                filemtime($customCssPath),
                'screen'
            );
        }
    }

    /**
     * Load for all BackWPup pages.
     */
    public static function init_general()
    {
        add_thickbox();

        $suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';

        wp_register_script(
            'backwpupgeneral',
            BackWPup::get_plugin_data('URL') . "/assets/js/general{$suffix}.js",
            ['jquery'],
            ($suffix ? BackWPup::get_plugin_data('Version') : time()),
            false
        );

        // Register clipboard.js script
        wp_register_script(
            'backwpup_clipboard',
            BackWPup::get_plugin_data('URL') . '/assets/js/vendor/clipboard.min.js',
            ['jquery'],
            '1.7.1',
            true
        );

        // Add Help.
        BackWPup_Help::help();
    }

    /**
     * Add Message (across site loadings).
     *
     * @param string $message string Message test
     * @param bool   $error   bool ist it a error message
     */
    public static function message($message, $error = false)
    {
        if (empty($message)) {
            return;
        }

        $saved_message = self::get_messages();

        if ($error) {
            $saved_message['error'][] = $message;
        } else {
            $saved_message['updated'][] = $message;
        }

        update_site_option('backwpup_messages', $saved_message);
    }

    /**
     * Get all Message that not displayed.
     *
     * @return array
     */
    public static function get_messages()
    {
        return get_site_option('backwpup_messages', []);
    }

    /**
     * Display Messages.
     *
     * @param bool $echo
     *
     * @return string
     */
    public static function display_messages($echo = true)
    {
        /**
         * This hook can be used to display more messages in all BackWPup pages.
         */
        do_action('backwpup_admin_messages');

        $message_updated = '';
        $message_error = '';
        $saved_message = self::get_messages();
        $message_id = ' id="message"';

        if (empty($saved_message)) {
            return '';
        }

        if (!empty($saved_message['updated'])) {
            foreach ($saved_message['updated'] as $msg) {
                $message_updated .= '<p>' . $msg . '</p>';
            }
        }
        if (!empty($saved_message['error'])) {
            foreach ($saved_message['error'] as $msg) {
                $message_error .= '<p>' . $msg . '</p>';
            }
        }

        update_site_option('backwpup_messages', []);

        if (!empty($message_updated)) {
            $message_updated = '<div' . $message_id . ' class="updated">' . $message_updated . '</div>';
            $message_id = '';
        }
        if (!empty($message_error)) {
            $message_error = '<div' . $message_id . ' class="bwu-message-error">' . $message_error . '</div>';
        }

        if ($echo) {
            echo $message_updated . $message_error;
        }

        return $message_updated . $message_error;
    }

    /**
     * Admin init function.
     */
    public function admin_init()
    {
        if (!is_admin()) {
            return;
        }
        if (!defined('DOING_AJAX') || (defined('DOING_AJAX') && !DOING_AJAX)) {
            // Only init notices if this is not an AJAX request
            $this->init_notices();

            // Everything after this point applies to AJAX
            return;
        }

        $jobtypes = BackWPup::get_job_types();
        $destinations = BackWPup::get_registered_destinations();

        add_action('wp_ajax_backwpup_working', [\BackWPup_Page_Jobs::class, 'ajax_working']);
        add_action('wp_ajax_backwpup_cron_text', [\BackWPup_Page_Editjob::class, 'ajax_cron_text']);
        add_action('wp_ajax_backwpup_view_log', [\BackWPup_Page_Logs::class, 'ajax_view_log']);
        add_action('wp_ajax_download_backup_file', [\BackWPup_Destination_Downloader::class, 'download_by_ajax']);

        foreach ($jobtypes as $id => $jobtypeclass) {
            add_action('wp_ajax_backwpup_jobtype_' . strtolower($id), [$jobtypeclass, 'edit_ajax']);
        }

        foreach ($destinations as $id => $dest) {
            if (!empty($dest['class'])) {
                add_action(
                    'wp_ajax_backwpup_dest_' . strtolower($id),
                    [
                        BackWPup::get_destination($id),
                        'edit_ajax',
                    ],
                    10,
                    0
                );
            }
        }

        if (\BackWPup::is_pro()) {
            $this->admin_init_pro();
        }

        // Add Agence Oueb Dashboard Widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
    }

    /**
     * Add Dashboard Widget
     */
    public function add_dashboard_widget()
    {
        wp_add_dashboard_widget(
            'agence_oueb_dashboard_widget',
            'Agence Oueb - Support & News',
            [$this, 'dashboard_widget_content']
        );
    }

    /**
     * Dashboard Widget Content
     */
    public function dashboard_widget_content()
    {
        echo '<div class="agence-oueb-widget">';
        echo '<p><strong>Besoin d\'aide avec votre site WordPress ?</strong></p>';
        echo '<p>L\'Agence Oueb est là pour vous accompagner :</p>';
        echo '<ul>';
        echo '<li><a href="https://wpservices.fr" target="_blank">Maintenance & Dépannage</a></li>';
        echo '<li><a href="https://wpservices.fr" target="_blank">Hébergement spécialisé</a></li>';
        echo '<li><a href="https://wpservices.fr" target="_blank">Développement sur mesure</a></li>';
        echo '</ul>';
        echo '<p><a href="https://wpservices.fr" class="button button-primary" target="_blank">Visiter WPservices.fr</a></p>';
        echo '</div>';
    }

    private function admin_init_pro()
    {
        $ajax_encryption_key_handler = new AjaxEncryptionKeyHandler();

        add_action('wp_ajax_encrypt_key_handler', [$ajax_encryption_key_handler, 'handle']);
    }

	/**
	 * Init the plugin notices.
	 */
	private function init_notices() {
		// Show notice if PHP < 7.4.
		$phpNotice = new PhpNotice( //phpcs:ignore
			new NoticeView( PhpNotice::ID )
		);
        $phpNotice->init(PhpNotice::TYPE_ADMIN);

        // Show notice if WordPress < 5.0
        $wpNotice = new WordPressNotice(
            new NoticeView(WordPressNotice::ID)
        );
        $wpNotice->init(WordPressNotice::TYPE_ADMIN);

		// Show notice if Dropbox needs to be reauthenticated.
		$dropbox_notice = new DropboxNotice(
			new NoticeView( DropboxNotice::ID ),
			false // Not dismissible.
		);
		$dropbox_notice->init( DropboxNotice::TYPE_ADMIN );

		$evaluate_notice = new EvaluateNotice(
			new NoticeView( EvaluateNotice::ID )
		);
		$evaluate_notice->init( EvaluateNotice::TYPE_ADMIN );

		$easycron_update_notice = new EasycronUpdateNotice(
		new NoticeView( EasycronUpdateNotice::ID ),
		true
		);
		$easycron_update_notice->init( EasycronUpdateNotice::TYPE_ADMIN );

		$restore_feature_information_notice = new RestoreFeatureInformationNotice(
		new NoticeView( RestoreFeatureInformationNotice::ID ),
		true
		);
		$restore_feature_information_notice->init( RestoreFeatureInformationNotice::TYPE_ADMIN );
	}

    /**
     * Add Links in Plugins Menu to BackWPup.
     *
     * @param $links
     * @param $file
     *
     * @return array
     */
    public function plugin_links($links, $file)
    {
        if ($file == plugin_basename(BackWPup::get_plugin_data('MainFile'))) {
            $links[] = '<a href="' . esc_attr__('https://backwpup.com/docs/', 'backwpup') . '">' . __(
                'Documentation',
                'backwpup'
            ) . '</a>';
        }

        return $links;
    }

    /**
     * Add menu entries.
     */
    public function admin_menu()
    {
        add_menu_page(
            'Agence Oueb Backup',
            'Agence Oueb Backup',
            'backwpup',
            'backwpup',
            [
                \BackWPup_Page_BackWPup::class,
                'page',
            ],
            'div'
        );
        $this->page_hooks['backwpup'] = add_submenu_page(
            'backwpup',
            __('Agence Oueb Backup Dashboard', 'backwpup'),
            __('Dashboard', 'backwpup'),
            'backwpup',
            'backwpup',
            [
                \BackWPup_Page_BackWPup::class,
                'page',
            ]
        );
        add_action('load-' . $this->page_hooks['backwpup'], [\BackWPup_Admin::class, 'init_general']);
        add_action('load-' . $this->page_hooks['backwpup'], [\BackWPup_Page_BackWPup::class, 'load']);
        add_action(
            'admin_print_scripts-' . $this->page_hooks['backwpup'],
            [
                \BackWPup_Page_BackWPup::class,
                'admin_print_scripts',
            ]
        );

        //Add pages form plugins
        $this->page_hooks = apply_filters('backwpup_admin_pages', $this->page_hooks);
    }

    /**
     * @param $page_hooks
     *
     * @return mixed
     */
    public function admin_page_jobs($page_hooks)
    {
        $this->page_hooks['backwpupjobs'] = add_submenu_page(
            'backwpup',
            __('Jobs', 'backwpup'),
            __('Jobs', 'backwpup'),
            'backwpup_jobs',
            'backwpupjobs',
            [
                \BackWPup_Page_Jobs::class,
                'page',
            ]
        );
        add_action('load-' . $this->page_hooks['backwpupjobs'], [\BackWPup_Admin::class, 'init_general']);
        add_action('load-' . $this->page_hooks['backwpupjobs'], [\BackWPup_Page_Jobs::class, 'load']);
        add_action(
            'admin_print_styles-' . $this->page_hooks['backwpupjobs'],
            [
                \BackWPup_Page_Jobs::class,
                'admin_print_styles',
            ]
        );
        add_action(
            'admin_print_scripts-' . $this->page_hooks['backwpupjobs'],
            [
                \BackWPup_Page_Jobs::class,
                'admin_print_scripts',
            ]
        );

        return $page_hooks;
    }

    /**
     * @param $page_hooks
     *
     * @return mixed
     */
    public function admin_page_editjob($page_hooks)
    {
        $this->page_hooks['backwpupeditjob'] = add_submenu_page(
            'backwpup',
            __('Add new job', 'backwpup'),
            __('Add new job', 'backwpup'),
            'backwpup_jobs_edit',
            'backwpupeditjob',
            [
                \BackWPup_Page_Editjob::class,
                'page',
            ]
        );
        add_action('load-' . $this->page_hooks['backwpupeditjob'], [\BackWPup_Admin::class, 'init_general']);
        add_action('load-' . $this->page_hooks['backwpupeditjob'], [\BackWPup_Page_Editjob::class, 'auth']);
        add_action(
            'admin_print_styles-' . $this->page_hooks['backwpupeditjob'],
            [
                \BackWPup_Page_Editjob::class,
                'admin_print_styles',
            ]
        );
        add_action(
            'admin_print_scripts-' . $this->page_hooks['backwpupeditjob'],
            [
                \BackWPup_Page_Editjob::class,
                'admin_print_scripts',
            ]
        );

        return $page_hooks;
    }

    /**
     * @param $page_hooks
     *
     * @return mixed
     */
    public function admin_page_logs($page_hooks)
    {
        $this->page_hooks['backwpuplogs'] = add_submenu_page(
            'backwpup',
            __('Logs', 'backwpup'),
            __('Logs', 'backwpup'),
            'backwpup_logs',
            'backwpuplogs',
            [
                \BackWPup_Page_Logs::class,
                'page',
            ]
        );
        add_action('load-' . $this->page_hooks['backwpuplogs'], [\BackWPup_Admin::class, 'init_general']);
        add_action('load-' . $this->page_hooks['backwpuplogs'], [\BackWPup_Page_Logs::class, 'load']);
        add_action(
            'admin_print_styles-' . $this->page_hooks['backwpuplogs'],
            [
                \BackWPup_Page_Logs::class,
                'admin_print_styles',
            ]
        );
        add_action(
            'admin_print_scripts-' . $this->page_hooks['backwpuplogs'],
            [
                \BackWPup_Page_Logs::class,
                'admin_print_scripts',
            ]
        );

        return $page_hooks;
    }

    /**
     * @param $page_hooks
     *
     * @return mixed
     */
    public function admin_page_backups($page_hooks)
    {
        $this->page_hooks['backwpupbackups'] = add_submenu_page(
            'backwpup',
            __('Backups', 'backwpup'),
            __('Backups', 'backwpup'),
            'backwpup_backups',
            'backwpupbackups',
            [
                \BackWPup_Page_Backups::class,
                'page',
            ]
        );
        add_action('load-' . $this->page_hooks['backwpupbackups'], [\BackWPup_Admin::class, 'init_general']);
        add_action('load-' . $this->page_hooks['backwpupbackups'], [\BackWPup_Page_Backups::class, 'load']);
        add_action(
            'admin_print_styles-' . $this->page_hooks['backwpupbackups'],
            [
                \BackWPup_Page_Backups::class,
                'admin_print_styles',
            ]
        );
        add_action(
            'admin_print_scripts-' . $this->page_hooks['backwpupbackups'],
            [
                \BackWPup_Page_Backups::class,
                'admin_print_scripts',
            ]
        );

        return $page_hooks;
    }

    /**
     * @param $page_hooks
     *
     * @return mixed
     */
    public function admin_page_settings($page_hooks)
    {
        $this->page_hooks['backwpupsettings'] = add_submenu_page(
            'backwpup',
            esc_html__('Settings', 'backwpup'),
            esc_html__('Settings', 'backwpup'),
            'backwpup_settings',
            'backwpupsettings',
            [$this->settings, 'page']
        );
        add_action('load-' . $this->page_hooks['backwpupsettings'], [\BackWPup_Admin::class, 'init_general']);
        add_action(
            'admin_print_scripts-' . $this->page_hooks['backwpupsettings'],
            [$this->settings, 'admin_print_scripts']
        );

        return $page_hooks;
    }

	/**
	 * Admin Page Restore.
	 *
	 * @param array $page_hooks the page hooks list
	 *
	 * @return array $page_hooks
	 */
	public function admin_page_restore( $page_hooks ) {
		$this->page_hooks['backwpuprestore'] = add_submenu_page(
			'backwpup',
			esc_html__( 'Restore', 'backwpup' ),
			esc_html__( 'Restore', 'backwpup' ),
			'backwpup_restore',
			'backwpuprestore',
			[
				\BackWPup_Page_Restore::class,
				'page',
            ]
        );

		// Register the submenu page (WP take care of capability) but prevent other stuffs to be executed if user
		// doesn't have correct privileges.
		if ( ! current_user_can( 'backwpup_restore' ) ) {
			return $page_hooks;
		}

		add_action( 'load-' . $this->page_hooks['backwpuprestore'], [ self::class, 'init_general' ] );
		add_action(
			'load-' . $this->page_hooks['backwpuprestore'],
			[ \BackWPup_Page_Restore::class, 'load' ]
		);
		add_action(
			'admin_print_scripts-' . $this->page_hooks['backwpuprestore'],
			[
				\BackWPup_Page_Restore::class,
				'admin_print_scripts',
            ]
        );

        return $page_hooks;
    }

    /**
     * @param $page_hooks
     *
     * @return mixed
     */
    public function admin_page_about($page_hooks)
    {
        $this->page_hooks['backwpupabout'] = add_submenu_page(
            'backwpup',
            __('About', 'backwpup'),
            __('About', 'backwpup'),
            'backwpup',
            'backwpupabout',
            [
                \BackWPup_Page_About::class,
                'page',
            ]
        );
        add_action('load-' . $this->page_hooks['backwpupabout'], [\BackWPup_Admin::class, 'init_general']);
        add_action(
            'admin_print_styles-' . $this->page_hooks['backwpupabout'],
            [
                \BackWPup_Page_About::class,
                'admin_print_styles',
            ]
        );
        add_action(
            'admin_print_scripts-' . $this->page_hooks['backwpupabout'],
            [
                \BackWPup_Page_About::class,
                'admin_print_scripts',
            ]
        );

        return $page_hooks;
    }

    /**
     * Called on save form. Only POST allowed.
     */
    public function save_post_form()
    {
        $allowed_pages = [
            'backwpupeditjob',
            'backwpupinformation',
            'backwpupsettings',
        ];

        if (!in_array($_POST['page'], $allowed_pages, true)) {
            wp_die(esc_html__('Cheating, huh?', 'backwpup'));
        }

        //nonce check
        check_admin_referer($_POST['page'] . '_page');

        if (!current_user_can('backwpup')) {
            wp_die(esc_html__('Cheating, huh?', 'backwpup'));
        }

        //build query for redirect
        if (!isset($_POST['anchor'])) {
            $_POST['anchor'] = null;
        }
        $query_args = [];
        if (isset($_POST['page'])) {
            $query_args['page'] = $_POST['page'];
        }
        if (isset($_POST['tab'])) {
            $query_args['tab'] = $_POST['tab'];
        }
        if (isset($_POST['tab'], $_POST['nexttab']) && $_POST['tab'] !== $_POST['nexttab']) {
            $query_args['tab'] = $_POST['nexttab'];
        }

        $jobid = null;
        if (isset($_POST['jobid'])) {
            $jobid = (int) $_POST['jobid'];
            $query_args['jobid'] = $jobid;
        }

        // Call method to save data
        if ($_POST['page'] === 'backwpupeditjob') {
            BackWPup_Page_Editjob::save_post_form($_POST['tab'], $jobid);
        } elseif ($_POST['page'] === 'backwpupsettings') {
            $this->settings->save_post_form();
        }

        //Back to topic
        wp_safe_redirect(add_query_arg($query_args, network_admin_url('admin.php')) . $_POST['anchor']);

        exit;
    }

    /**
     * Overrides WordPress text in Footer.
     *
     * @param $admin_footer_text string
     *
     * @return string
     */
    public function admin_footer_text($admin_footer_text)
    {
        $default_text = $admin_footer_text;
        if (isset($_REQUEST['page']) && strstr((string) $_REQUEST['page'], 'backwpup')) {
            $admin_footer_text = <<<'EOT'
<a href="https://wpservices.fr" class="wpmedia_logo" title="Agence Oueb">
    Agence Oueb
</a>
EOT;

            if (!class_exists(\BackWPup_Pro::class, false)) {
                $admin_footer_text .= sprintf(
                    __(
                        '<a class="backwpup-get-pro" href="%s">Get BackWPup Pro now.</a>',
                        'backwpup'
                    ),
                    __('http://backwpup.com', 'backwpup')
                );
            }

            return $admin_footer_text . $default_text;
        }

        return $admin_footer_text;
    }

    /**
     * Overrides WordPress Version in Footer.
     *
     * @param $update_footer_text string
     *
     * @return string
     */
    public function update_footer($update_footer_text)
    {
        $default_text = $update_footer_text;

        if (isset($_REQUEST['page']) && strstr((string) $_REQUEST['page'], 'backwpup')) {
            $update_footer_text = '<span class="backwpup-update-footer"><a href="' . __(
                'https://wpservices.fr',
                'backwpup'
            ) . '">' . BackWPup::get_plugin_data('Name') . '</a> ' . sprintf(
                __(
                    'version %s',
                    'backwpup'
                ),
                BackWPup::get_plugin_data('Version')
            ) . '</span>';

            return $update_footer_text . $default_text;
        }

        return $update_footer_text;
    }

    /**
     *  Add filed for selecting user role in user section.
     *
     * @param $user WP_User
     */
    public function user_profile_fields($user)
    {
        global $wp_roles;

        if (!is_super_admin() && !current_user_can('backwpup_admin')) {
            return;
        }

        //user is admin and has BackWPup rights
        if ($user->has_cap('administrator') && $user->has_cap('backwpup_settings')) {
            return;
        }

        //get backwpup roles
        $backwpup_roles = [];

        foreach ($wp_roles->roles as $role => $role_value) {
            if (substr((string) $role, 0, 8) != 'backwpup') {
                continue;
            }
            $backwpup_roles[$role] = $role_value;
        }

        //only if user has other than backwpup role
        if (!empty($user->roles[0]) && in_array($user->roles[0], array_keys($backwpup_roles), true)) {
            return;
        } ?>
		<h3><?php echo BackWPup::get_plugin_data('name'); ?></h3>
		<table class="form-table">
			<tr>
				<th>
					<label for="backwpup_role"><?php _e('Add BackWPup Role', 'backwpup'); ?></label>
				</th>
				<td>
					<select name="backwpup_role" id="backwpup_role" style="display:inline-block; float:none;">
						<option
							value=""><?php _e(
            '&mdash; No additional role for BackWPup &mdash;',
            'backwpup'
        ); ?></option>
						<?php
                        foreach ($backwpup_roles as $role => $role_value) {
                            echo '<option value="' . $role . '" ' . selected(
                                $user->has_cap($role),
                                true,
                                false
                            ) . '>' . $role_value['name'] . '</option>';
                        } ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
    }

    /**
     * Save for user role adding.
     *
     * @param $user_id int
     */
    public function save_profile_update($user_id)
    {
        global $wp_roles;

        if (!is_super_admin() && !current_user_can('backwpup_admin')) {
            return;
        }

        if (empty($user_id)) {
            return;
        }

        if (!isset($_POST['backwpup_role'])) {
            return;
        }

        $backwpup_role = esc_attr($_POST['backwpup_role']);

        //get BackWPup roles
        $backwpup_roles = [];

        foreach (array_keys($wp_roles->roles) as $role) {
            if (!strstr($role, 'backwpup_')) {
                continue;
            }
            $backwpup_roles[] = $role;
        }

        //get user for adding/removing role
        $user = new WP_User($user_id);
        //a admin needs no extra role
        if ($user->has_cap('administrator') && $user->has_cap('backwpup_settings')) {
            $backwpup_role = '';
        }

        //remove BackWPup role from user if it not the actual
        foreach ($user->roles as $role) {
            if (!strstr($role, 'backwpup_')) {
                continue;
            }
            if ($role !== $backwpup_role) {
                $user->remove_role($role);
            } else {
                $backwpup_role = '';
            }
        }

        //add new role to user if it not the actual
        if ($backwpup_role && in_array($backwpup_role, $backwpup_roles, true)) {
            $user->add_role($backwpup_role);
        }
    }

	public function init() {
		$restore = new Restore();
		$restore->set_hooks()->init();

		// Add menu pages
		add_filter( 'backwpup_admin_pages', [ $this, 'admin_page_jobs' ], 2 );
		add_filter( 'backwpup_admin_pages', [ $this, 'admin_page_editjob' ], 3 );
		add_filter( 'backwpup_admin_pages', [ $this, 'admin_page_logs' ], 4 );
		add_filter( 'backwpup_admin_pages', [ $this, 'admin_page_backups' ], 5 );
		add_filter( 'backwpup_admin_pages', [ $this, 'admin_page_settings' ], 6 );
		add_filter( 'backwpup_admin_pages', [ $this, 'admin_page_restore' ], 7 );
		add_filter( 'backwpup_admin_pages', [ $this, 'admin_page_about' ], 20 );

        //Add Menu
        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'admin_menu']);
        } else {
            add_action('admin_menu', [$this, 'admin_menu']);
        }
        //add Plugin links
        add_filter('plugin_row_meta', [$this, 'plugin_links'], 10, 2);
        //add more actions
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_css']);
        //Save Form posts general
        add_action('admin_post_backwpup', [$this, 'save_post_form']);
        //Save Form posts wizard
        add_action('admin_post_backwpup_wizard', [\BackWPup_Pro_Page_Wizard::class, 'save_post_form']);
        // Save form posts for support
        add_action('admin_post_backwpup_support', [\BackWPup_Pro_Page_Support::class, 'save_post_form']);
        //Admin Footer Text replacement
        add_filter('admin_footer_text', [$this, 'admin_footer_text'], 100);
        add_filter('update_footer', [$this, 'update_footer'], 100);
        //User Profile fields
        add_action('show_user_profile', [$this, 'user_profile_fields']);
        add_action('edit_user_profile', [$this, 'user_profile_fields']);
        add_action('profile_update', [$this, 'save_profile_update']);
    }

    private function __clone()
    {
    }
}
