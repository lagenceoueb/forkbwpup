<?php

use Inpsyde\BackWPupShared\File\MimeTypeExtractor;

/**
 * Backup destination for S3 compatible storage, through Oueb_S3_Client.
 */
class BackWPup_Destination_S3 extends BackWPup_Destinations
{
    public function option_defaults(): array
    {
        return [
            's3base_url' => '',
            's3base_multipart' => true,
            's3base_pathstylebucket' => false,
            's3accesskey' => '',
            's3secretkey' => '',
            's3bucket' => '',
            's3region' => 'scaleway-fr-par',
            's3ssencrypt' => '',
            's3storageclass' => '',
            's3dir' => trailingslashit(sanitize_file_name(get_bloginfo('name'))),
            's3maxbackups' => 15,
            's3syncnodelete' => true,
        ];
    }

    public function edit_tab(int $jobid): void
    {
        ?>
		<h3 class="title">
			<?php esc_html_e('S3 Service', 'backwpup'); ?>
		</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="s3region">
                        <?php esc_html_e('Select a S3 service', 'backwpup'); ?>
                    </label>
                </th>
                <td>
                    <select name="s3region"
                            id="s3region"
                            title="<?php esc_attr_e('S3 Region', 'backwpup'); ?>">
                        <?php foreach (BackWPup_S3_Destination::options() as $id => $option) { ?>
                            <option value="<?php echo esc_attr($id); ?>"
                                <?php selected($id, BackWPup_Option::get($jobid, 's3region')); ?>
                            >
                                <?php echo esc_html($option['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <?php oueb_storage_providers_cards(); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="s3base_url">
                        <?php esc_html_e('Or a S3 Server URL', 'backwpup'); ?>
                    </label>
                </th>
                <td>
                    <div class="card" style="margin-top:0;padding:10px">
                        <table class="form-table">
                            <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="s3base_url-s3"><?php esc_html_e('Endpoint', 'backwpup'); ?><span
                                            style="color:red">*</span></label>
                                </th>
                                <td>
                                    <input
                                        id="s3base_url"
                                        name="s3base_url"
                                        type="text"
                                        value="<?php echo esc_attr(
            BackWPup_Option::get($jobid, 's3base_url')
        ); ?>"
                                        class="regular-text"
                                        autocomplete="off"
                                    />
                                    <p class="description"><?php esc_html_e(
            'Leave it empty to use a destination from S3 service list',
            'backwpup'
        ); ?></p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="s3base_region"><?php esc_html_e(
            'Region',
            'backwpup'
        ); ?><span style="color:red">*</span></label>
                                </th>
                                <td>
                                    <input type="text" name="s3base_region" value="<?php echo esc_attr(
            BackWPup_Option::get($jobid, 's3base_region')
        ); ?>" class="regular-text" autocomplete="off">
                                    <p class="description"><?php esc_html_e(
            'Specify the S3 region, such as "fr-par"',
            'backwpup'
        ); ?></p>
                                </td>
                            </tr>
                            </tbody>

                            <tbody class="custom_s3_advanced">
                            <tr>
                                <th scope="row"><?php esc_html_e('Multipart', 'backwpup'); ?></th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text">
                                            <span><?php esc_html_e(
            'Multipart',
            'backwpup'
        ); ?></span>
                                        </legend>
                                        <label for="s3base_multipart">
                                            <input name="s3base_multipart" type="checkbox"
                                                   value="1"<?php echo checked(
            BackWPup_Option::get(
                                                $jobid,
                                                's3base_multipart'
                                            ),
            true
        ); ?>>
                                            <?php esc_html_e(
                                                'Destination supports multipart',
                                                'backwpup'
                                            ); ?> </label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e(
                                                'Pathstyle-Only Bucket',
                                                'backwpup'
                                            ); ?></th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text">
                                            <span><?php esc_html_e(
                                                'Pathstyle-Only Bucket',
                                                'backwpup'
                                            ); ?></span>
                                        </legend>
                                        <label
                                            for="s3base_pathstylebucket">
                                            <input name="s3base_pathstylebucket" type="checkbox"
                                                   value="1"<?php echo checked(
                                                BackWPup_Option::get(
                                                       $jobid,
                                                       's3base_pathstylebucket'
                                                   ),
                                                true
                                            ); ?>>
                                            <?php esc_html_e(
                                                       'Destination provides only Pathstyle buckets',
                                                       'backwpup'
                                                   ); ?>    </label>
                                        <p class="description"><?php esc_html_e(
                                                       'Example: http://s3.example.com/bucket-name',
                                                       'backwpup'
                                                   ); ?></p>

                                    </fieldset>
                                </td>
                            </tr>
                            </tbody><!-- advanced section-->
                        </table>
                    </div>
                </td><!-- custom s3 section-->
            </tr>
        </table>

		<h3 class="title">
			<?php esc_html_e('S3 Access Keys', 'backwpup'); ?>
		</h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="s3accesskey">
						<?php esc_html_e('Access Key', 'backwpup'); ?>
					</label>
				</th>
				<td>
					<input id="s3accesskey"
					       name="s3accesskey"
					       type="text"
					       value="<?php echo esc_attr(BackWPup_Option::get($jobid, 's3accesskey')); ?>"
					       class="regular-text"
					       autocomplete="off"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="s3secretkey"><?php esc_html_e('Secret Key', 'backwpup'); ?></label></th>
				<td>
					<input id="s3secretkey" name="s3secretkey" type="password"
					       value="<?php echo esc_attr(BackWPup_Encryption::decrypt(BackWPup_Option::get(
                                               $jobid,
                                               's3secretkey'
                                           ))); ?>" class="regular-text" autocomplete="off"/>
				</td>
			</tr>
		</table>

		<h3 class="title">
			<?php esc_html_e('S3 Bucket', 'backwpup'); ?>
		</h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="s3bucketselected">
						<?php esc_html_e('Bucket selection', 'backwpup'); ?>
					</label>
				</th>
				<td>
					<input id="s3bucketselected"
					       name="s3bucketselected"
					       type="hidden"
					       value="<?php echo esc_attr(BackWPup_Option::get($jobid, 's3bucket')); ?>"
					/>
                    <?php
                    if (BackWPup_Option::get($jobid, 's3accesskey')
                        && BackWPup_Option::get($jobid, 's3secretkey')
                    ) {
                        $this->edit_ajax(
                            [
                                's3accesskey' => BackWPup_Option::get($jobid, 's3accesskey'),
                                's3secretkey' => BackWPup_Option::get($jobid, 's3secretkey'),
                                's3bucketselected' => BackWPup_Option::get($jobid, 's3bucket'),
                                's3region' => BackWPup_Option::get($jobid, 's3region'),
                                's3base_url' => BackWPup_Option::get($jobid, 's3base_url'),
                                's3base_region' => BackWPup_Option::get($jobid, 's3base_region'),
                                's3base_multipart' => BackWPup_Option::get(
                                    $jobid,
                                    's3base_multipart'
                                ),
                                's3base_pathstylebucket' => BackWPup_Option::get(
                                    $jobid,
                                    's3base_pathstylebucket'
                                ),
                            ]
                        );
                    } ?>
                </td>
			</tr>
			<tr>
				<th scope="row">
					<label for="s3newbucket">
						<?php esc_html_e('Create a new bucket', 'backwpup'); ?>
					</label>
				</th>
				<td>
					<input id="s3newbucket"
					       name="s3newbucket"
					       type="text"
					       value=""
                           size="63"
					       class="regular-text"
					       autocomplete="off"
					/>
				</td>
			</tr>
		</table>

		<h3 class="title">
			<?php esc_html_e('S3 Backup settings', 'backwpup'); ?>
		</h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="ids3dir">
						<?php esc_html_e('Folder in bucket', 'backwpup'); ?>
					</label>
				</th>
				<td>
					<input id="ids3dir"
					       name="s3dir"
					       type="text"
					       value="<?php echo esc_attr(BackWPup_Option::get($jobid, 's3dir')); ?>"
					       class="regular-text"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e('File deletion', 'backwpup'); ?></th>
				<td>
					<?php
                    if (BackWPup_Option::get($jobid, 'backuptype') === 'archive') {
                        ?>
						<label for="ids3maxbackups">
							<input id="ids3maxbackups"
							       name="s3maxbackups"
							       type="number"
							       min="0"
							       step="1"
							       value="<?php echo esc_attr(BackWPup_Option::get($jobid, 's3maxbackups')); ?>"
							       class="small-text"
							/>
							&nbsp;<?php esc_html_e('Number of files to keep in folder.', 'backwpup'); ?>
						</label>
						<p>
							<?php _e(
                            '<strong>Warning</strong>: Files belonging to this job are now tracked. Old backup archives which are untracked will not be automatically deleted.',
                            'backwpup'
                        ); ?>
						</p>
					<?php
                    } else { ?>
						<label for="ids3syncnodelete">
							<input class="checkbox" value="1"
							       type="checkbox"
								<?php checked(BackWPup_Option::get($jobid, 's3syncnodelete'), true); ?>
								   name="s3syncnodelete"
								   id="ids3syncnodelete"
							/>
							<?php esc_html_e('Do not delete files while syncing to destination!', 'backwpup'); ?>
						</label>
					<?php } ?>
				</td>
			</tr>
		</table>

		<h3 class="title"><?php esc_html_e('Storage options', 'backwpup'); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="ids3storageclass">
						<?php esc_html_e('Storage class', 'backwpup'); ?>
					</label>
				</th>
				<td>
                    <?php $storageClass = BackWPup_Option::get($jobid, 's3storageclass'); ?>
					<select name="s3storageclass"
					        id="ids3storageclass"
					        title="<?php esc_attr_e('Storage class', 'backwpup'); ?>">
						<option value=""
							<?php selected('', $storageClass, true); ?>>
							<?php esc_html_e('Standard', 'backwpup'); ?>
						</option>
						<option value="STANDARD_IA"
							<?php selected('STANDARD_IA', $storageClass, true); ?>>
							<?php esc_html_e('Standard-Infrequent Access', 'backwpup'); ?>
						</option>
                        <option value="ONEZONE_IA"
                            <?php selected('ONEZONE_IA', $storageClass, true); ?>>
                            <?php esc_html_e('One Zone-Infrequent Access', 'backwpup'); ?>
                        </option>
                        <option value="REDUCED_REDUNDANCY"
							<?php selected('REDUCED_REDUNDANCY', $storageClass, true); ?>>
							<?php esc_html_e('Reduced Redundancy', 'backwpup'); ?>
						</option>
                        <option value="INTELLIGENT_TIERING"
                                <?php selected('INTELLIGENT_TIERING', $storageClass, true); ?>>
                            <?php esc_html_e('Intelligent-Tiering', 'backwpup'); ?>
                        </option>
                        <option value="GLACIER_IR"
                                <?php selected('GLACIER_IR', $storageClass, true); ?>>
                            <?php esc_html_e('Glacier Instant Retrieval', 'backwpup'); ?>
                        </option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ids3ssencrypt">
						<?php esc_html_e('Server side encryption', 'backwpup'); ?>
					</label>
				</th>
				<td>
					<input class="checkbox"
					       value="AES256"
					       type="checkbox"
						<?php checked(BackWPup_Option::get($jobid, 's3ssencrypt'), 'AES256'); ?>
						   name="s3ssencrypt"
						   id="ids3ssencrypt"
					/>
					<?php esc_html_e('Save files encrypted (AES256) on server.', 'backwpup'); ?>
				</td>
			</tr>
		</table>

		<?php
    }

    public function edit_ajax(array $args = []): void
    {
        $error = '';
        $error_code = '';
        $buckets_list = [];
        $ajax = false;

        if (!$args) {
            if (!current_user_can('backwpup_jobs_edit')) {
                wp_die(-1);
            }
            check_ajax_referer('backwpup_ajax_nonce');
            $args = [];
            $args['s3accesskey'] = sanitize_text_field($_POST['s3accesskey']);
            $args['s3secretkey'] = sanitize_text_field($_POST['s3secretkey']);
            $args['s3bucketselected'] = sanitize_text_field($_POST['s3bucketselected']);
            $args['s3region'] = sanitize_text_field($_POST['s3region']);
            $args['s3base_url'] = backwpup_esc_url_default_secure($_POST['s3base_url'], ['http', 'https']);
            $args['s3base_region'] = sanitize_text_field($_POST['s3base_region']);
            $args['s3base_multipart'] = sanitize_text_field($_POST['s3base_multipart']);
            $args['s3base_pathstylebucket'] = sanitize_text_field($_POST['s3base_pathstylebucket']);
            $ajax = true;
        }

        if ($args['s3base_url']) {
            $args['s3region'] = $args['s3base_url'];
        }

        echo '<span id="s3bucketerror" class="bwu-message-error">';

        if (!empty($args['s3accesskey']) && !empty($args['s3secretkey'])) {
            if (empty($args['s3base_url'])) {
                $aws_destination = BackWPup_S3_Destination::fromOption($args['s3region']);
            } else {
                $options = [
                    'label' => __('Custom S3 destination', 'backwpup'),
                    'endpoint' => $args['s3base_url'],
                    'region' => $args['s3base_region'],
                    'multipart' => !empty($args['s3base_multipart']) ? true : false,
                    'only_path_style_bucket' => !empty($args['s3base_pathstylebucket']) ? true : false,
                ];
                $aws_destination = BackWPup_S3_Destination::fromOptionArray($options);
            }

            try {
                $s3 = $aws_destination->client($args['s3accesskey'], $args['s3secretkey']);
                $buckets_list = $s3->list_buckets();
            } catch (Oueb_S3_Exception $e) {
                $error = $e->getMessage();
                $error_code = $e->get_s3_code();
            }
        }

        if (empty($args['s3accesskey'])) {
            esc_html_e('Missing access key!', 'backwpup');
        } elseif (empty($args['s3secretkey'])) {
            esc_html_e('Missing secret access key!', 'backwpup');
        } elseif ($error_code === 'AccessDenied') {
            echo '<input type="text" name="s3bucket" id="s3bucket" value="' . esc_attr($args['s3bucketselected']) . '" >';
        } elseif (!empty($error)) {
            echo esc_html($error);
        } elseif (empty($buckets_list)) {
            esc_html_e('No bucket found!', 'backwpup');
        }
        echo '</span>';

        if (!empty($buckets_list)) {
            echo '<select name="s3bucket" id="s3bucket">';

            foreach ($buckets_list as $bucket) {
                echo '<option ' . selected($args['s3bucketselected'], $bucket, false) . '>'
                     . esc_html($bucket)
                     . '</option>';
            }
            echo '</select>';
        }

        if ($ajax) {
            exit();
        }
    }

    public function edit_form_post_save(int $jobid): void
    {
        BackWPup_Option::update($jobid, 's3accesskey', sanitize_text_field($_POST['s3accesskey']));
        BackWPup_Option::update(
            $jobid,
            's3secretkey',
            isset($_POST['s3secretkey'])
                ? BackWPup_Encryption::encrypt($_POST['s3secretkey'])
                : ''
        );
        BackWPup_Option::update(
            $jobid,
            's3base_url',
            isset($_POST['s3base_url'])
                ? backwpup_esc_url_default_secure($_POST['s3base_url'], ['http', 'https'])
                : ''
        );
        BackWPup_Option::update(
            $jobid,
            's3base_region',
            isset($_POST['s3base_region']) ? sanitize_text_field($_POST['s3base_region']) : ''
        );
        BackWPup_Option::update(
            $jobid,
            's3base_multipart',
            isset($_POST['s3base_multipart']) ? '1' : ''
        );
        BackWPup_Option::update(
            $jobid,
            's3base_pathstylebucket',
            isset($_POST['s3base_pathstylebucket']) ? '1' : ''
        );
        BackWPup_Option::update($jobid, 's3region', sanitize_text_field($_POST['s3region']));
        BackWPup_Option::update($jobid, 's3storageclass', sanitize_text_field($_POST['s3storageclass']));
        BackWPup_Option::update(
            $jobid,
            's3ssencrypt',
            (isset($_POST['s3ssencrypt']) && $_POST['s3ssencrypt'] === 'AES256') ? 'AES256' : ''
        );
        BackWPup_Option::update(
            $jobid,
            's3bucket',
            isset($_POST['s3bucket']) ? sanitize_text_field($_POST['s3bucket']) : ''
        );

        $_POST['s3dir'] = trailingslashit(str_replace(
            '//',
            '/',
            str_replace('\\', '/', trim(sanitize_text_field($_POST['s3dir'])))
        ));
        if (strpos($_POST['s3dir'], '/') === 0) {
            $_POST['s3dir'] = substr($_POST['s3dir'], 1);
        }
        if ($_POST['s3dir'] === '/') {
            $_POST['s3dir'] = '';
        }
        BackWPup_Option::update($jobid, 's3dir', $_POST['s3dir']);

        BackWPup_Option::update(
            $jobid,
            's3maxbackups',
            !empty($_POST['s3maxbackups']) ? absint($_POST['s3maxbackups']) : 0
        );
        BackWPup_Option::update($jobid, 's3syncnodelete', !empty($_POST['s3syncnodelete']));

        //create new bucket
        if (!empty($_POST['s3newbucket'])) {
            try {
                $region = BackWPup_Option::get($jobid, 's3base_url');
                if (empty($region)) {
                    $region = BackWPup_Option::get($jobid, 's3region');
                    $aws_destination = BackWPup_S3_Destination::fromOption($region);
                } else {
                    $aws_destination = BackWPup_S3_Destination::fromJobId($jobid);
                }

                $s3 = $aws_destination->client(
                    BackWPup_Option::get($jobid, 's3accesskey'),
                    BackWPup_Option::get($jobid, 's3secretkey')
                );
                $s3->create_bucket(sanitize_text_field($_POST['s3newbucket']));
                BackWPup_Admin::message(
                    sprintf(
                        __('Bucket %1$s created.', 'backwpup'),
                        sanitize_text_field($_POST['s3newbucket'])
                    )
                );
            } catch (Oueb_S3_Exception $e) {
                BackWPup_Admin::message(esc_html($e->getMessage()), true);
            }
            BackWPup_Option::update($jobid, 's3bucket', sanitize_text_field($_POST['s3newbucket']));
        }
    }

    public function file_delete(string $jobdest, string $backupfile): void
    {
        $files = get_site_transient('backwpup_' . strtolower($jobdest));
        [$jobid, $dest] = explode('_', $jobdest);

        if (BackWPup_Option::get($jobid, 's3accesskey') && BackWPup_Option::get(
            $jobid,
            's3secretkey'
        ) && BackWPup_Option::get($jobid, 's3bucket')) {
            try {
                $region = BackWPup_Option::get($jobid, 's3base_url');
                if (empty($region)) {
                    $region = BackWPup_Option::get($jobid, 's3region');
                    $aws_destination = BackWPup_S3_Destination::fromOption($region);
                } else {
                    $aws_destination = BackWPup_S3_Destination::fromJobId($jobid);
                }

                $s3 = $aws_destination->client(
                    BackWPup_Option::get($jobid, 's3accesskey'),
                    BackWPup_Option::get($jobid, 's3secretkey')
                );

                $s3->delete_object(BackWPup_Option::get($jobid, 's3bucket'), $backupfile);
                //update file list
                foreach ((array) $files as $key => $file) {
                    if (is_array($file) && $file['file'] === $backupfile) {
                        unset($files[$key]);
                    }
                }
                unset($s3);
            } catch (Oueb_S3_Exception $e) {
                BackWPup_Admin::message(sprintf(__('S3 Service API: %s', 'backwpup'), esc_html($e->getMessage())), true);
            }
        }

        set_site_transient('backwpup_' . strtolower($jobdest), $files, YEAR_IN_SECONDS);
    }

    /**
     * {@inheritdoc}
     */
    public function file_get_list(string $jobdest): array
    {
        $list = (array) get_site_transient('backwpup_' . strtolower($jobdest));

        return array_filter($list);
    }

    /**
     * File Update List.
     *
     * Update the list of files in the transient.
     *
     * @param BackWPup_Job|int $job    Either the job object or job ID
     * @param bool             $delete whether to delete old backups
     */
    public function file_update_list($job, bool $delete = false): void
    {
        if ($job instanceof BackWPup_Job) {
            $job_object = $job;
            $jobid = $job->job['jobid'];
        } else {
            $job_object = null;
            $jobid = $job;
        }

        if (empty(BackWPup_Option::get($jobid, 's3base_url'))) {
            $aws_destination = BackWPup_S3_Destination::fromOption((string) BackWPup_Option::get($jobid, 's3region'));
        } else {
            $aws_destination = BackWPup_S3_Destination::fromJobId((int) $jobid);
        }
        $s3 = $aws_destination->client(
            BackWPup_Option::get($jobid, 's3accesskey'),
            BackWPup_Option::get($jobid, 's3secretkey')
        );

        $backupfilelist = [];
        $filecounter = 0;
        $files = [];
        $bucket = (string) BackWPup_Option::get($jobid, 's3bucket');
        $objects = $s3->list_objects($bucket, (string) BackWPup_Option::get($jobid, 's3dir'));

        if (!empty($objects)) {
            foreach ($objects as $object) {
                $file = basename($object['key']);
                $changetime = strtotime($object['last_modified']) + (get_option('gmt_offset') * 3600);

                if ($this->is_backup_archive($file) && $this->is_backup_owned_by_job($file, $jobid)) {
                    $backupfilelist[$changetime] = $file;
                }

                $files[$filecounter]['folder'] = $s3->object_url($bucket, dirname($object['key']));
                $files[$filecounter]['file'] = $object['key'];
                $files[$filecounter]['filename'] = basename($object['key']);

                if (!empty($object['storage_class'])) {
                    $files[$filecounter]['info'] = sprintf(
                        __('Storage Class: %s', 'backwpup'),
                        $object['storage_class']
                    );
                }

                $files[$filecounter]['downloadurl'] = network_admin_url('admin.php') . '?page=backwpupbackups&action=downloads3&file=' . rawurlencode($object['key']) . '&local_file=' . rawurlencode(basename($object['key'])) . '&jobid=' . $jobid;
                $files[$filecounter]['filesize'] = $object['size'];
                $files[$filecounter]['time'] = $changetime;

                ++$filecounter;
            }
        }

        if ($delete && $job_object && $job_object->job['s3maxbackups'] > 0 && is_object($s3)) { //Delete old backups
            if (count($backupfilelist) > $job_object->job['s3maxbackups']) {
                ksort($backupfilelist);
                $numdeltefiles = 0;

                while ($file = array_shift($backupfilelist)) {
                    if (count($backupfilelist) < $job_object->job['s3maxbackups']) {
                        break;
                    }
                    //delete files on S3
                    try {
                        $s3->delete_object($job_object->job['s3bucket'], $job_object->job['s3dir'] . $file);
                        $deleted = true;
                    } catch (Oueb_S3_Exception $e) {
                        $deleted = false;
                    }

                    if ($deleted) {
                        foreach ($files as $key => $filedata) {
                            if ($filedata['file'] == $job_object->job['s3dir'] . $file) {
                                unset($files[$key]);
                            }
                        }
                        ++$numdeltefiles;
                    } else {
                        $job_object->log(
                            sprintf(
                                __('Cannot delete backup from %s.', 'backwpup'),
                                $s3->object_url($job_object->job['s3bucket'], $job_object->job['s3dir'] . $file)
                            ),
                            E_USER_ERROR
                        );
                    }
                }

                if ($numdeltefiles > 0) {
                    $job_object->log(sprintf(
                        _n(
                            'One file deleted on S3 Bucket.',
                            '%d files deleted on S3 Bucket',
                            $numdeltefiles,
                            'backwpup'
                        ),
                        $numdeltefiles
                    ));
                }
            }
        }
        set_site_transient('backwpup_' . $jobid . '_s3', $files, YEAR_IN_SECONDS);
    }

    public function job_run_archive(BackWPup_Job $job_object): bool
    {
        $job_object->substeps_todo = 2 + $job_object->backup_filesize;

        if ($job_object->steps_data[$job_object->step_working]['SAVE_STEP_TRY'] != $job_object->steps_data[$job_object->step_working]['STEP_TRY']) {
            $job_object->log(
                sprintf(
                    __('%d. Trying to send backup file to S3 Service&#160;&hellip;', 'backwpup'),
                    $job_object->steps_data[$job_object->step_working]['STEP_TRY']
                )
            );
        }

        $step = &$job_object->steps_data[$job_object->step_working];
        $bucket = (string) $job_object->job['s3bucket'];
        $key = $job_object->job['s3dir'] . $job_object->backup_file;
        $local_file = $job_object->backup_folder . $job_object->backup_file;

        try {
            if (empty($job_object->job['s3base_url'])) {
                $aws_destination = BackWPup_S3_Destination::fromOption((string) $job_object->job['s3region']);
            } else {
                $aws_destination = BackWPup_S3_Destination::fromJobId((int) $job_object->job['jobid']);
            }

            $s3 = $aws_destination->client(
                $job_object->job['s3accesskey'],
                $job_object->job['s3secretkey']
            );

            if ($step['SAVE_STEP_TRY'] != $step['STEP_TRY'] && $job_object->substeps_done < $job_object->backup_filesize) {
                if (!$s3->bucket_exists($bucket)) {
                    $job_object->log(sprintf(
                        __('S3 Bucket "%s" does not exist!', 'backwpup'),
                        $bucket
                    ), E_USER_ERROR);

                    return true;
                }

                $job_object->log(
                    sprintf(
                        __('Connected to S3 Bucket "%1$s" in %2$s', 'backwpup'),
                        $bucket,
                        $aws_destination->region() !== '' ? $aws_destination->region() : $aws_destination->endpoint()
                    )
                );

                if ($aws_destination->supportsMultipart() && empty($step['UploadId'])) {
                    // Parts of interrupted uploads are billed until they are aborted.
                    $job_object->log(__('Checking for not aborted multipart Uploads&#160;&hellip;', 'backwpup'));

                    foreach ($s3->list_multipart_uploads($bucket, (string) $job_object->job['s3dir']) as $upload) {
                        $s3->abort_multipart_upload($bucket, $upload['key'], $upload['upload_id']);
                        $job_object->log(sprintf(__('Upload for %s aborted.', 'backwpup'), $upload['key']));
                    }
                }

                $job_object->log(__('Starting upload to S3 Service&#160;&hellip;', 'backwpup'));
            }

            //Calculate chunk size for S3 uploads. Limits see: https://docs.aws.amazon.com/AmazonS3/latest/userguide/qfacts.html
            $chunk_size = 1024 * 1024 * 5;
            if (1000 < ceil($job_object->backup_filesize / $chunk_size)) {
                $chunk_size = $chunk_size * 2;
            }
            if (10000 < ceil($job_object->backup_filesize / $chunk_size)) {
                $chunk_size = (int) ceil($job_object->backup_filesize / 10000);
            }

            $headers = [
                'content-type' => MimeTypeExtractor::fromFilePath($local_file),
                'x-amz-meta-backuptime' => date('Y-m-d H:i:s', $job_object->start_time),
            ];
            if (!empty($job_object->job['s3ssencrypt'])) {
                $headers['x-amz-server-side-encryption'] = $job_object->job['s3ssencrypt'];
            }
            if (!empty($job_object->job['s3storageclass'])) {
                $headers['x-amz-storage-class'] = $job_object->job['s3storageclass'];
            }

            if (!$aws_destination->supportsMultipart() || $job_object->backup_filesize <= $chunk_size) {
                $body = file_get_contents($local_file);
                if ($body === false) {
                    $job_object->log(__('Can not open source file for transfer.', 'backwpup'), E_USER_ERROR);

                    return false;
                }

                $s3->put_object($bucket, $key, $body, $headers);
                unset($body);
            } else {
                $file_handle = fopen($local_file, 'rb');
                if (!$file_handle) {
                    $job_object->log(__('Can not open source file for transfer.', 'backwpup'), E_USER_ERROR);

                    return false;
                }

                fseek($file_handle, $job_object->substeps_done);

                try {
                    if (empty($step['UploadId'])) {
                        $step['UploadId'] = $s3->create_multipart_upload($bucket, $key, $headers);
                        $step['Parts'] = [];
                        $step['Part'] = 1;
                    }

                    while (!feof($file_handle)) {
                        $part_data = fread($file_handle, $chunk_size);
                        if ($part_data === false || $part_data === '') {
                            break;
                        }

                        $part_number = $step['Part'];
                        $chunk_upload_start = microtime(true);
                        $etag = $s3->upload_part($bucket, $key, $step['UploadId'], $part_number, $part_data);
                        $chunk_upload_time = microtime(true) - $chunk_upload_start;

                        $job_object->substeps_done += strlen($part_data);
                        $step['Parts'][$part_number - 1] = [
                            'ETag' => $etag,
                            'PartNumber' => $part_number,
                        ];
                        ++$step['Part'];

                        $time_remaining = $job_object->do_restart_time();
                        if ($time_remaining < $chunk_upload_time) {
                            $job_object->do_restart_time(true);
                        }
                        $job_object->update_working_data();
                    }

                    $s3->complete_multipart_upload($bucket, $key, $step['UploadId'], $step['Parts']);
                } catch (Oueb_S3_Exception $e) {
                    $job_object->log(
                        E_USER_ERROR,
                        sprintf(__('S3 Service API: %s', 'backwpup'), $e->getMessage()),
                        $e->getFile(),
                        $e->getLine()
                    );

                    if (!empty($step['UploadId'])) {
                        try {
                            $s3->abort_multipart_upload($bucket, $key, $step['UploadId']);
                        } catch (Oueb_S3_Exception $abort_error) {
                            // The next run aborts the leftover upload before starting.
                            unset($abort_error);
                        }
                    }
                    unset($step['UploadId'], $step['Parts'], $step['Part']);

                    $job_object->substeps_done = 0;
                    fclose($file_handle);

                    return false;
                }
                fclose($file_handle);
            }

            $result = $s3->head_object($bucket, $key);

            if (isset($result['content-length']) && (int) $result['content-length'] === (int) filesize($local_file)) {
                $job_object->substeps_done = 1 + $job_object->backup_filesize;
                $job_object->log(
                    sprintf(
                        __('Backup transferred to %s.', 'backwpup'),
                        $s3->object_url($bucket, $key)
                    )
                );

                if (!empty($job_object->job['jobid'])) {
                    BackWPup_Option::update(
                        $job_object->job['jobid'],
                        'lastbackupdownloadurl',
                        network_admin_url('admin.php') . '?page=backwpupbackups&action=downloads3&file=' . rawurlencode($key) . '&jobid=' . $job_object->job['jobid']
                    );
                }
            } else {
                $job_object->log(
                    sprintf(
                        __('Cannot transfer backup to S3! (%1$d) %2$s', 'backwpup'),
                        0,
                        __('The file size on the S3 service does not match the local file.', 'backwpup')
                    ),
                    E_USER_ERROR
                );
            }
        } catch (Oueb_S3_Exception $e) {
            $job_object->log(
                E_USER_ERROR,
                sprintf(__('S3 Service API: %s', 'backwpup'), $e->getMessage()),
                $e->getFile(),
                $e->getLine()
            );

            return false;
        }

        try {
            $this->file_update_list($job_object, true);
        } catch (Oueb_S3_Exception $e) {
            $job_object->log(
                E_USER_ERROR,
                sprintf(__('S3 Service API: %s', 'backwpup'), $e->getMessage()),
                $e->getFile(),
                $e->getLine()
            );

            return false;
        }
        $job_object->substeps_done = 2 + $job_object->backup_filesize;

        return true;
    }

    public function can_run(array $job_settings): bool
    {
        if (empty($job_settings['s3accesskey'])) {
            return false;
        }

        return !(empty($job_settings['s3secretkey']));
    }

    public function edit_inline_js(): void
    {
        ?>
		<script type="text/javascript">
			jQuery( document ).ready( function ( $ ) {
				function awsgetbucket() {
					var data = {
						action          : 'backwpup_dest_s3',
						s3accesskey     : $( 'input[name="s3accesskey"]' ).val(),
						s3secretkey     : $( 'input[name="s3secretkey"]' ).val(),
						s3bucketselected: $( 'input[name="s3bucketselected"]' ).val(),
						s3base_url      : $( 'input[name="s3base_url"]' ).val(),
						s3region        : $( '#s3region' ).val(),
						s3base_region      : $( 'input[name="s3base_region"]' ).val(),
						s3base_multipart      : $( 'input[name="s3base_multipart"]' ).is(':checked'),
						s3base_pathstylebucket      : $( 'input[name="s3base_pathstylebucket"]' ).is(':checked'),
						_ajax_nonce     : $( '#backwpupajaxnonce' ).val()
					};
					$.post( ajaxurl, data, function ( response ) {
						$( '#s3bucketerror' ).remove();
						$( '#s3bucket' ).remove();
						$( '#s3bucketselected' ).after( response );
					} );
				}

				$( 'select[name="s3region"]' ).change( function () {
					awsgetbucket();
				} );
				$( 'input[name="s3accesskey"], input[name="s3secretkey"], input[name="s3base_url"]' ).backwpupDelayKeyup( function () {
					awsgetbucket();
				} );

			} );
		</script>
		<?php
    }
}
