<?php
/**
 * BackWPup destination: Infomaniak kDrive via WebDAV
 *
 * @since 1.0.0
 */

/**
 * Class BackWPup_Destination_KDrive
 *
 * Handles backup storage to Infomaniak kDrive using WebDAV protocol.
 */
class BackWPup_Destination_KDrive extends BackWPup_Destinations
{
    /**
     * Get default options for kDrive destination.
     *
     * @return array Default option values
     */
    public function option_defaults(): array
    {
        return [
            'kdriveid' => '',
            'kdriveemail' => '',
            'kdrivepassword' => '',
            'kdrivedir' => trailingslashit('/Backups'),
            'kdrivemaxbackups' => 15,
            'kdrivesyncnodelete' => true,
        ];
    }

    /**
     * Display the configuration tab for kDrive destination.
     *
     * @param int $jobid The job ID
     */
    public function edit_tab(int $jobid): void
    {
        ?>
        <h3 class="title"><?php esc_html_e('Infomaniak kDrive', 'backwpup'); ?></h3>
        <p></p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="kdriveid"><?php esc_html_e('kDrive ID', 'backwpup'); ?> <span class="description"><?php esc_html_e('(required)', 'backwpup'); ?></span></label>
                </th>
                <td>
                    <input name="kdriveid" type="text" id="kdriveid"
                           value="<?php echo esc_attr(BackWPup_Option::get($jobid, 'kdriveid')); ?>"
                           class="regular-text" autocomplete="off" />
                    <p class="description">
                        <?php
                        printf(
                            esc_html__('Trouvez votre ID dans l\'URL de kDrive : %s', 'backwpup'),
                            '<code>ksuite.infomaniak.com/kdrive/app/drive/<strong>VOTRE_ID</strong>/</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="kdriveemail"><?php esc_html_e('Email Infomaniak', 'backwpup'); ?> <span class="description"><?php esc_html_e('(required)', 'backwpup'); ?></span></label>
                </th>
                <td>
                    <input name="kdriveemail" type="email" id="kdriveemail"
                           value="<?php echo esc_attr(BackWPup_Option::get($jobid, 'kdriveemail')); ?>"
                           class="regular-text" autocomplete="off" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="kdrivepassword"><?php esc_html_e('Mot de passe', 'backwpup'); ?> <span class="description"><?php esc_html_e('(required)', 'backwpup'); ?></span></label>
                </th>
                <td>
                    <input name="kdrivepassword" type="password" id="kdrivepassword"
                           value="<?php echo esc_attr(BackWPup_Encryption::decrypt(BackWPup_Option::get($jobid, 'kdrivepassword'))); ?>"
                           class="regular-text" autocomplete="new-password" />
                    <p class="description">
                        <?php esc_html_e('Si vous avez activé l\'authentification à deux facteurs (2FA), utilisez un mot de passe d\'application.', 'backwpup'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="kdrivedir"><?php esc_html_e('Dossier de destination', 'backwpup'); ?></label>
                </th>
                <td>
                    <input name="kdrivedir" type="text" id="kdrivedir"
                           value="<?php echo esc_attr(BackWPup_Option::get($jobid, 'kdrivedir')); ?>"
                           class="regular-text" />
                    <p class="description">
                        <?php esc_html_e('Chemin du dossier dans kDrive où les sauvegardes seront stockées.', 'backwpup'); ?>
                        <?php esc_html_e('Exemple : /Backups/WordPress/', 'backwpup'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="kdrivemaxbackups"><?php esc_html_e('Nombre max. de sauvegardes', 'backwpup'); ?></label>
                </th>
                <td>
                    <input name="kdrivemaxbackups" type="number" min="0" id="kdrivemaxbackups"
                           value="<?php echo esc_attr(BackWPup_Option::get($jobid, 'kdrivemaxbackups')); ?>"
                           class="small-text" />
                    <p class="description">
                        <?php esc_html_e('Nombre de sauvegardes à conserver. Les anciennes sauvegardes seront supprimées automatiquement. 0 = illimité.', 'backwpup'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save posted form data for kDrive configuration.
     *
     * @param int $jobid The job ID
     */
    public function edit_form_post_save(int $jobid): void
    {
        // Save kDrive ID
        BackWPup_Option::update(
            $jobid,
            'kdriveid',
            isset($_POST['kdriveid']) ? sanitize_text_field($_POST['kdriveid']) : ''
        );

        // Save email
        BackWPup_Option::update(
            $jobid,
            'kdriveemail',
            isset($_POST['kdriveemail']) ? sanitize_email($_POST['kdriveemail']) : ''
        );

        // Save encrypted password
        if (!empty($_POST['kdrivepassword'])) {
            BackWPup_Option::update(
                $jobid,
                'kdrivepassword',
                BackWPup_Encryption::encrypt($_POST['kdrivepassword'])
            );
        }

        // Save directory
        $dir = isset($_POST['kdrivedir']) ? sanitize_text_field($_POST['kdrivedir']) : '/Backups';
        BackWPup_Option::update($jobid, 'kdrivedir', trailingslashit($dir));

        // Save max backups
        BackWPup_Option::update(
            $jobid,
            'kdrivemaxbackups',
            !empty($_POST['kdrivemaxbackups']) ? absint($_POST['kdrivemaxbackups']) : 15
        );
    }

    /**
     * Check if the job can run with current settings.
     *
     * @param array $job_settings Job settings array
     * @return bool True if can run, false otherwise
     */
    public function can_run(array $job_settings): bool
    {
        if (empty($job_settings['kdriveid'])) {
            return false;
        }

        if (empty($job_settings['kdriveemail'])) {
            return false;
        }

        if (empty($job_settings['kdrivepassword'])) {
            return false;
        }

        return true;
    }

    /**
     * Upload backup file to kDrive via WebDAV.
     *
     * @param BackWPup_Job $job_object The job object
     * @return bool True on success, false on failure
     */
    public function job_run_archive(BackWPup_Job $job_object): bool
    {
        $job_object->substeps_todo = 2 + $job_object->backup_filesize;

        // Get configuration
        $kdrive_id = BackWPup_Option::get($job_object->job['jobid'], 'kdriveid');
        $email = BackWPup_Option::get($job_object->job['jobid'], 'kdriveemail');
        $password = BackWPup_Encryption::decrypt(BackWPup_Option::get($job_object->job['jobid'], 'kdrivepassword'));
        $dir = BackWPup_Option::get($job_object->job['jobid'], 'kdrivedir');

        if (empty($kdrive_id) || empty($email) || empty($password)) {
            $job_object->log(
                esc_html__('kDrive: Configuration incomplète !', 'backwpup'),
                E_USER_ERROR
            );
            return false;
        }

        $job_object->log(sprintf(
            esc_html__('kDrive: Connexion à %s.connect.kdrive.infomaniak.com...', 'backwpup'),
            $kdrive_id
        ));

        // Build WebDAV URL
        $base_url = sprintf('https://%s.connect.kdrive.infomaniak.com', $kdrive_id);
        $upload_url = $base_url . $dir . basename($job_object->backup_file);

        // Upload file via WebDAV
        $job_object->substeps_done = 1;

        try {
            $result = $this->webdav_upload($upload_url, $job_object->backup_file, $email, $password, $job_object);

            if ($result) {
                $job_object->substeps_done = 2 + $job_object->backup_filesize;
                $job_object->log(
                    sprintf(
                        esc_html__('kDrive: Fichier %s transféré avec succès', 'backwpup'),
                        basename($job_object->backup_file)
                    ),
                    E_USER_NOTICE
                );

                // Delete old backups if needed
                $max_backups = BackWPup_Option::get($job_object->job['jobid'], 'kdrivemaxbackups');
                if ($max_backups > 0) {
                    $this->delete_old_backups($job_object, $base_url . $dir, $email, $password, $max_backups);
                }

                return true;
            } else {
                $job_object->log(
                    esc_html__('kDrive: Échec du transfert du fichier', 'backwpup'),
                    E_USER_ERROR
                );
                return false;
            }
        } catch (Exception $e) {
            $job_object->log(
                sprintf(esc_html__('kDrive: Erreur - %s', 'backwpup'), $e->getMessage()),
                E_USER_ERROR
            );
            return false;
        }
    }

    /**
     * Upload a file via WebDAV using cURL.
     *
     * @param string $url Remote WebDAV URL
     * @param string $local_file Local file path
     * @param string $email Email for authentication
     * @param string $password Password for authentication
     * @param BackWPup_Job|null $job_object Optional job object for progress updates
     * @return bool True on success, false on failure
     * @throws Exception On cURL errors
     */
    private function webdav_upload(string $url, string $local_file, string $email, string $password, ?BackWPup_Job $job_object = null): bool
    {
        if (!file_exists($local_file)) {
            throw new Exception(sprintf('Local file not found: %s', $local_file));
        }

        $fp = fopen($local_file, 'rb');
        if (!$fp) {
            throw new Exception(sprintf('Cannot open file: %s', $local_file));
        }

        $ch = curl_init($url);
        if (!$ch) {
            fclose($fp);
            throw new Exception('Cannot initialize cURL');
        }

        $filesize = filesize($local_file);

        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $filesize,
            CURLOPT_USERPWD => $email . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 3600, // 1 hour timeout
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($job_object, $filesize) {
                if ($job_object && $upload_size > 0) {
                    $job_object->substeps_done = 1 + $uploaded;
                    $job_object->update_working_data();
                }
                return 0; // Continue upload
            },
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        if ($curl_error) {
            throw new Exception(sprintf('cURL error: %s', $curl_error));
        }

        // WebDAV PUT returns 201 (Created) or 204 (No Content) on success
        if ($http_code === 201 || $http_code === 204 || $http_code === 200) {
            return true;
        }

        throw new Exception(sprintf('HTTP error %d: %s', $http_code, $result));
    }

    /**
     * Delete old backup files, keeping only the most recent ones.
     *
     * @param BackWPup_Job $job_object Job object
     * @param string $dir_url WebDAV directory URL
     * @param string $email Email for authentication
     * @param string $password Password for authentication
     * @param int $max_backups Maximum number of backups to keep
     */
    private function delete_old_backups(BackWPup_Job $job_object, string $dir_url, string $email, string $password, int $max_backups): void
    {
        try {
            $files = $this->webdav_list($dir_url, $email, $password);
            
            // Filter only backup files owned by this job
            $backup_files = [];
            foreach ($files as $file) {
                if ($this->is_backup_archive($file['name']) && $this->is_backup_owned_by_job($file['name'], $job_object->job['jobid'])) {
                    $backup_files[] = $file;
                }
            }

            // Sort by modification time (newest first)
            usort($backup_files, function ($a, $b) {
                return $b['mtime'] <=> $a['mtime'];
            });

            // Delete files beyond the limit
            $files_to_delete = array_slice($backup_files, $max_backups);
            
            foreach ($files_to_delete as $file) {
                $delete_url = rtrim($dir_url, '/') . '/' . $file['name'];
                if ($this->webdav_delete($delete_url, $email, $password)) {
                    $job_object->log(
                        sprintf(
                            esc_html__('kDrive: Ancien backup supprimé : %s', 'backwpup'),
                            $file['name']
                        ),
                        E_USER_NOTICE
                    );
                }
            }
        } catch (Exception $e) {
            $job_object->log(
                sprintf(esc_html__('kDrive: Erreur lors de la suppression des anciens backups - %s', 'backwpup'), $e->getMessage()),
                E_USER_WARNING
            );
        }
    }

    /**
     * List files in a WebDAV directory.
     *
     * @param string $url WebDAV directory URL
     * @param string $email Email for authentication
     * @param string $password Password for authentication
     * @return array Array of files with 'name', 'size', and 'mtime' keys
     * @throws Exception On errors
     */
    private function webdav_list(string $url, string $email, string $password): array
    {
        $ch = curl_init($url);
        if (!$ch) {
            throw new Exception('Cannot initialize cURL');
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:prop><D:getlastmodified/><D:getcontentlength/></D:prop></D:propfind>';

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_HTTPHEADER => [
                'Depth: 1',
                'Content-Type: application/xml; charset=utf-8',
                'Content-Length: ' . strlen($xml),
            ],
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_USERPWD => $email . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        curl_close($ch);

        if ($curl_error) {
            throw new Exception(sprintf('cURL error: %s', $curl_error));
        }

        if ($http_code !== 207) { // 207 Multi-Status is the expected response for PROPFIND
            throw new Exception(sprintf('HTTP error %d', $http_code));
        }

        return $this->parse_propfind_response($response);
    }

    /**
     * Parse WebDAV PROPFIND XML response.
     *
     * @param string $xml XML response
     * @return array Array of files
     */
    private function parse_propfind_response(string $xml): array
    {
        $files = [];
        
        try {
            $dom = new DOMDocument();
            @$dom->loadXML($xml);
            
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            
            $responses = $xpath->query('//d:response');
            
            foreach ($responses as $response) {
                $href = $xpath->query('.//d:href', $response)->item(0);
                $size_node = $xpath->query('.//d:getcontentlength', $response)->item(0);
                $mtime_node = $xpath->query('.//d:getlastmodified', $response)->item(0);
                
                if ($href) {
                    $path = urldecode($href->nodeValue);
                    $name = basename($path);
                    
                    // Skip directories and the current directory
                    if (empty($name) || substr($path, -1) === '/') {
                        continue;
                    }
                    
                    $files[] = [
                        'name' => $name,
                        'size' => $size_node ? (int)$size_node->nodeValue : 0,
                        'mtime' => $mtime_node ? strtotime($mtime_node->nodeValue) : 0,
                    ];
                }
            }
        } catch (Exception $e) {
            // Return empty array on parse errors
            return [];
        }
        
        return $files;
    }

    /**
     * Delete a file via WebDAV.
     *
     * @param string $url WebDAV file URL
     * @param string $email Email for authentication
     * @param string $password Password for authentication
     * @return bool True on success, false on failure
     */
    private function webdav_delete(string $url, string $email, string $password): bool
    {
        $ch = curl_init($url);
        if (!$ch) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_USERPWD => $email . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // 204 No Content or 200 OK indicates successful deletion
        return ($http_code === 204 || $http_code === 200);
    }

    /**
     * Get list of backup files from kDrive.
     *
     * @param string $jobdest Job destination (unused for kDrive)
     * @return array Array of backup files
     */
    public function file_get_list(string $jobdest): array
    {
        // This method is typically called from the backups page
        // We need to extract job ID from the destination string or use a transient
        return [];
    }

    /**
     * Delete a specific backup file from kDrive.
     *
     * @param string $jobdest Job destination string
     * @param string $backupfile Backup filename
     */
    public function file_delete(string $jobdest, string $backupfile): void
    {
        // Parse job ID from destination string
        // Format: "kdrive:jobid"
        list($dest_type, $jobid) = explode(':', $jobdest);
        
        if ($dest_type !== 'kdrive' || !$jobid) {
            return;
        }

        // Get configuration
        $kdrive_id = BackWPup_Option::get($jobid, 'kdriveid');
        $email = BackWPup_Option::get($jobid, 'kdriveemail');
        $password = BackWPup_Encryption::decrypt(BackWPup_Option::get($jobid, 'kdrivepassword'));
        $dir = BackWPup_Option::get($jobid, 'kdrivedir');

        if (empty($kdrive_id) || empty($email) || empty($password)) {
            return;
        }

        $base_url = sprintf('https://%s.connect.kdrive.infomaniak.com', $kdrive_id);
        $delete_url = $base_url . $dir . $backupfile;

        $this->webdav_delete($delete_url, $email, $password);
    }
}
