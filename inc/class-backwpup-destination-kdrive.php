<?php
/**
 * Destination de sauvegarde Infomaniak kDrive.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Envoie les sauvegardes sur un kDrive d'Infomaniak, par WebDAV.
 *
 * Reprise de la branche feature/kdrive-destination, corrigée : identifiant
 * limité aux chiffres, mot de passe d'application, secret jamais réaffiché,
 * liste et suppression depuis l'administration, envoi en flux.
 *
 * @since 0.1.0
 */
class BackWPup_Destination_KDrive extends BackWPup_Destinations {

	/**
	 * Intervalle minimal entre deux enregistrements de la progression, en secondes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const PROGRESS_INTERVAL = 2;

	/**
	 * Renvoie les réglages par défaut d'une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @return array Réglages par défaut.
	 */
	public function option_defaults(): array {
		return array(
			'kdriveid'         => '',
			'kdriveemail'      => '',
			'kdrivepassword'   => '',
			'kdrivedir'        => 'Sauvegardes/' . sanitize_title_with_dashes( get_bloginfo( 'name' ) ) . '/',
			'kdrivemaxbackups' => 15,
		);
	}

	/**
	 * Affiche le formulaire de réglage de la destination.
	 *
	 * @since 0.1.0
	 *
	 * @param int $jobid Identifiant de la tâche.
	 */
	public function edit_tab( int $jobid ): void {
		$has_password = '' !== (string) BackWPup_Option::get( $jobid, 'kdrivepassword' );
		?>
		<h3 class="title"><?php esc_html_e( 'Infomaniak kDrive', 'oueb-wp-backup' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="kdriveid"><?php esc_html_e( 'kDrive ID', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="kdriveid" name="kdriveid" type="text" inputmode="numeric" pattern="[0-9]+" class="regular-text" autocomplete="off" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'kdriveid' ) ); ?>" aria-describedby="kdriveid-help" />
					<p class="description" id="kdriveid-help"><?php esc_html_e( 'The number in the address of your kDrive: ksuite.infomaniak.com/kdrive/app/drive/NUMBER.', 'oueb-wp-backup' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="kdriveemail"><?php esc_html_e( 'Infomaniak email address', 'oueb-wp-backup' ); ?></label></th>
				<td><input id="kdriveemail" name="kdriveemail" type="email" class="regular-text" autocomplete="off" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'kdriveemail' ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="kdrivepassword"><?php esc_html_e( 'Application password', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="kdrivepassword" name="kdrivepassword" type="password" class="regular-text" autocomplete="new-password" value="" aria-describedby="kdrivepassword-help" />
					<p class="description" id="kdrivepassword-help">
						<?php esc_html_e( 'Create an application password in your Infomaniak account, under Security. Never use your main password: an application password can be revoked without touching your account.', 'oueb-wp-backup' ); ?>
						<?php
						if ( $has_password ) {
							esc_html_e( 'Leave empty to keep the saved value.', 'oueb-wp-backup' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>

		<h3 class="title"><?php esc_html_e( 'Backup settings', 'oueb-wp-backup' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="kdrivedir"><?php esc_html_e( 'Folder in kDrive', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="kdrivedir" name="kdrivedir" type="text" class="regular-text" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'kdrivedir' ) ); ?>" aria-describedby="kdrivedir-help" />
					<p class="description" id="kdrivedir-help"><?php esc_html_e( 'Created if missing.', 'oueb-wp-backup' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="kdrivemaxbackups"><?php esc_html_e( 'Backups to keep', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="kdrivemaxbackups" name="kdrivemaxbackups" type="number" min="0" step="1" class="small-text" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'kdrivemaxbackups' ) ); ?>" aria-describedby="kdrivemaxbackups-help" />
					<p class="description" id="kdrivemaxbackups-help"><?php esc_html_e( 'The oldest backups of this job are deleted beyond this number. 0 keeps them all.', 'oueb-wp-backup' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Enregistre les réglages envoyés par le formulaire.
	 *
	 * @since 0.1.0
	 *
	 * @param int $jobid Identifiant de la tâche.
	 */
	public function edit_form_post_save( int $jobid ): void {
		if ( ! current_user_can( 'backwpup_jobs_edit' )
			|| ! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'backwpupeditjob_page' )
		) {
			return;
		}

		// Seuls des chiffres : l'identifiant entre dans le nom d'hôte de connexion.
		$id = isset( $_POST['kdriveid'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['kdriveid'] ) ) ) : '';
		BackWPup_Option::update( $jobid, 'kdriveid', $id );

		BackWPup_Option::update( $jobid, 'kdriveemail', isset( $_POST['kdriveemail'] ) ? sanitize_email( wp_unslash( $_POST['kdriveemail'] ) ) : '' );

		if ( ! empty( $_POST['kdrivepassword'] ) ) {
			BackWPup_Option::update( $jobid, 'kdrivepassword', BackWPup_Encryption::encrypt( oueb_sanitize_secret( wp_unslash( $_POST['kdrivepassword'] ) ) ) );
		}

		$dir = isset( $_POST['kdrivedir'] ) ? sanitize_text_field( wp_unslash( $_POST['kdrivedir'] ) ) : '';
		$dir = trim( str_replace( array( '\\', '//' ), '/', $dir ), '/' );
		BackWPup_Option::update( $jobid, 'kdrivedir', '' === $dir ? '' : $dir . '/' );

		BackWPup_Option::update( $jobid, 'kdrivemaxbackups', isset( $_POST['kdrivemaxbackups'] ) ? absint( $_POST['kdrivemaxbackups'] ) : 15 );
	}

	/**
	 * Indique si la tâche a les réglages nécessaires.
	 *
	 * @since 0.1.0
	 *
	 * @param array $job_settings Réglages de la tâche.
	 * @return bool Vrai si la destination peut être utilisée.
	 */
	public function can_run( array $job_settings ): bool {
		return ! empty( $job_settings['kdriveid'] ) && ctype_digit( (string) $job_settings['kdriveid'] )
			&& ! empty( $job_settings['kdriveemail'] ) && ! empty( $job_settings['kdrivepassword'] );
	}

	/**
	 * Envoie l'archive de la tâche sur le kDrive.
	 *
	 * @since 0.1.0
	 *
	 * @param BackWPup_Job $job_object Tâche en cours.
	 * @return bool Vrai si l'étape est terminée.
	 */
	public function job_run_archive( BackWPup_Job $job_object ): bool {
		$job_object->substeps_todo = 2 + $job_object->backup_filesize;
		$step                      = $job_object->steps_data[ $job_object->step_working ];
		$jobid                     = (int) $job_object->job['jobid'];
		$local_file                = $job_object->backup_folder . $job_object->backup_file;

		if ( $step['SAVE_STEP_TRY'] !== $step['STEP_TRY'] ) {
			$job_object->log(
				sprintf(
					/* translators: %d: attempt number. */
					__( '%d. Trying to send backup file to kDrive&#160;&hellip;', 'oueb-wp-backup' ),
					$step['STEP_TRY']
				)
			);
		}

		try {
			$client      = $this->client( $jobid );
			$dir         = untrailingslashit( (string) $job_object->job['kdrivedir'] );
			$remote_file = ( '' === $dir ? '' : $dir . '/' ) . $job_object->backup_file;

			$client->ensure_dir( $dir );

			$last_save = time();
			$client->upload(
				$remote_file,
				$local_file,
				static function ( $sent ) use ( $job_object, &$last_save ) {
					$job_object->substeps_done = (int) $sent;
					if ( time() - $last_save >= self::PROGRESS_INTERVAL ) {
						$job_object->update_working_data();
						$last_save = time();
					}
				}
			);

			if ( $client->size( $remote_file ) !== (int) filesize( $local_file ) ) {
				$job_object->log( __( 'The file size on kDrive does not match the local file.', 'oueb-wp-backup' ), E_USER_ERROR );
				$job_object->substeps_done = 0;

				return false;
			}

			$job_object->substeps_done = 1 + $job_object->backup_filesize;
			/* translators: %s: file path in kDrive. */
			$job_object->log( sprintf( __( 'Backup transferred to kDrive: %s.', 'oueb-wp-backup' ), $remote_file ) );

			BackWPup_Option::update( $jobid, 'lastbackupdownloadurl', $this->download_url( $jobid, $remote_file ) );

			$this->update_list( $client, $job_object, true );
		} catch ( Oueb_Webdav_Exception $e ) {
			/* translators: %s: error message. */
			$job_object->log( E_USER_ERROR, sprintf( __( 'kDrive: %s', 'oueb-wp-backup' ), $e->getMessage() ), $e->getFile(), $e->getLine() );

			return false;
		}

		$job_object->substeps_done = 2 + $job_object->backup_filesize;

		return true;
	}

	/**
	 * Renvoie la liste des sauvegardes mise en cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $jobdest Identifiant « tâche_destination ».
	 * @return array Fichiers connus.
	 */
	public function file_get_list( string $jobdest ): array {
		return array_filter( (array) get_site_transient( 'backwpup_' . strtolower( $jobdest ) ) );
	}

	/**
	 * Met à jour la liste des sauvegardes depuis le kDrive.
	 *
	 * @since 0.1.0
	 *
	 * @param BackWPup_Job|int $job Tâche en cours ou identifiant de tâche.
	 */
	public function file_update_list( $job ): void {
		$jobid = $job instanceof BackWPup_Job ? (int) $job->job['jobid'] : (int) $job;

		try {
			$this->update_list( $this->client( $jobid ), $jobid, false );
		} catch ( Oueb_Webdav_Exception $e ) {
			BackWPup_Admin::message( 'kDrive: ' . esc_html( $e->getMessage() ), true );
		}
	}

	/**
	 * Supprime une sauvegarde sur le kDrive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $jobdest    Identifiant « tâche_destination », par exemple « 3_KDRIVE ».
	 * @param string $backupfile Chemin du fichier dans le kDrive.
	 */
	public function file_delete( string $jobdest, string $backupfile ): void {
		$jobid = (int) strtok( $jobdest, '_' );
		$files = (array) get_site_transient( 'backwpup_' . strtolower( $jobdest ) );

		try {
			if ( $this->client( $jobid )->delete( $backupfile ) ) {
				foreach ( $files as $index => $file ) {
					if ( is_array( $file ) && $file['file'] === $backupfile ) {
						unset( $files[ $index ] );
					}
				}
			}
		} catch ( Oueb_Webdav_Exception $e ) {
			BackWPup_Admin::message( 'kDrive: ' . esc_html( $e->getMessage() ), true );
		}

		set_site_transient( 'backwpup_' . strtolower( $jobdest ), $files, YEAR_IN_SECONDS );
	}

	/**
	 * Construit le client WebDAV d'une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param int $jobid Identifiant de la tâche.
	 * @return Oueb_Webdav_Client Client prêt à l'emploi.
	 *
	 * @throws Oueb_Webdav_Exception Si l'identifiant du kDrive est invalide.
	 */
	public function client( $jobid ) {
		$drive_id = (string) BackWPup_Option::get( $jobid, 'kdriveid' );

		if ( ! ctype_digit( $drive_id ) ) {
			throw new Oueb_Webdav_Exception( esc_html__( 'The kDrive ID must contain digits only.', 'oueb-wp-backup' ) );
		}

		/**
		 * Filtre l'adresse WebDAV du kDrive.
		 *
		 * @since 0.1.0
		 *
		 * @param string $url      Adresse WebDAV.
		 * @param string $drive_id Identifiant du kDrive.
		 */
		$url = apply_filters( 'oueb_kdrive_webdav_url', 'https://' . $drive_id . '.connect.kdrive.infomaniak.com', $drive_id );

		return new Oueb_Webdav_Client(
			$url,
			(string) BackWPup_Option::get( $jobid, 'kdriveemail' ),
			(string) BackWPup_Encryption::decrypt( (string) BackWPup_Option::get( $jobid, 'kdrivepassword' ) )
		);
	}

	/**
	 * Relit le dossier, supprime les sauvegardes en trop et met la liste en cache.
	 *
	 * @since 0.1.0
	 *
	 * @param Oueb_Webdav_Client $client Client WebDAV.
	 * @param BackWPup_Job|int   $job    Tâche en cours ou identifiant de tâche.
	 * @param bool               $rotate Vrai pour supprimer les sauvegardes au-delà de la limite.
	 */
	private function update_list( $client, $job, $rotate ) {
		$job_object = $job instanceof BackWPup_Job ? $job : null;
		$jobid      = $job_object ? (int) $job_object->job['jobid'] : (int) $job;
		$dir        = untrailingslashit( (string) BackWPup_Option::get( $jobid, 'kdrivedir' ) );
		$prefix     = '' === $dir ? '' : $dir . '/';
		$files      = array();
		$owned      = array();

		foreach ( $client->list_files( $dir ) as $entry ) {
			$path    = $prefix . $entry['name'];
			$files[] = array(
				'folder'      => 'kDrive: /' . $dir,
				'file'        => $path,
				'filename'    => $entry['name'],
				'downloadurl' => $this->download_url( $jobid, $path ),
				'filesize'    => $entry['size'],
				'time'        => $entry['mtime'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ),
			);

			if ( $this->is_backup_archive( $entry['name'] ) && $this->is_backup_owned_by_job( $entry['name'], $jobid ) ) {
				$owned[ $path ] = $entry['mtime'];
			}
		}

		$keep = (int) BackWPup_Option::get( $jobid, 'kdrivemaxbackups' );
		if ( $rotate && $job_object && $keep > 0 && count( $owned ) > $keep ) {
			asort( $owned );
			$deleted = 0;

			foreach ( array_slice( array_keys( $owned ), 0, count( $owned ) - $keep ) as $path ) {
				if ( $client->delete( $path ) ) {
					++$deleted;
					$files = array_filter(
						$files,
						static function ( $file ) use ( $path ) {
							return $file['file'] !== $path;
						}
					);
				} else {
					/* translators: %s: file path in kDrive. */
					$job_object->log( sprintf( __( 'Cannot delete %s on kDrive.', 'oueb-wp-backup' ), $path ), E_USER_ERROR );
				}
			}

			if ( $deleted > 0 ) {
				/* translators: %d: number of deleted files. */
				$job_object->log( sprintf( _n( '%d file deleted on kDrive.', '%d files deleted on kDrive.', $deleted, 'oueb-wp-backup' ), $deleted ) );
			}
		}

		set_site_transient( 'backwpup_' . $jobid . '_kdrive', array_values( $files ), YEAR_IN_SECONDS );
	}

	/**
	 * Construit l'adresse de téléchargement d'une sauvegarde.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $jobid Identifiant de la tâche.
	 * @param string $path  Chemin du fichier dans le kDrive.
	 * @return string Adresse dans l'administration.
	 */
	private function download_url( $jobid, $path ) {
		return add_query_arg(
			array(
				'page'       => 'backwpupbackups',
				'action'     => 'downloadkdrive',
				'file'       => rawurlencode( $path ),
				'local_file' => rawurlencode( basename( $path ) ),
				'jobid'      => (int) $jobid,
			),
			network_admin_url( 'admin.php' )
		);
	}
}
