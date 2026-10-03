<?php
/**
 * Destination de sauvegarde SFTP.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Envoie les sauvegardes sur un serveur SFTP administré par le client.
 *
 * @since 0.1.0
 */
class BackWPup_Destination_Sftp extends BackWPup_Destinations {

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
			'sftphost'        => '',
			'sftpport'        => 22,
			'sftpuser'        => '',
			'sftpauth'        => 'password',
			'sftppass'        => '',
			'sftpkey'         => '',
			'sftpkeypass'     => '',
			'sftpfingerprint' => '',
			'sftpdir'         => trailingslashit( sanitize_title_with_dashes( get_bloginfo( 'name' ) ) ),
			'sftpmaxbackups'  => 15,
			'sftptimeout'     => 30,
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
		$auth        = BackWPup_Option::get( $jobid, 'sftpauth' );
		$fingerprint = (string) BackWPup_Option::get( $jobid, 'sftpfingerprint' );
		$keep_hint   = __( 'Leave empty to keep the saved value.', 'oueb-wp-backup' );
		?>
		<h3 class="title"><?php esc_html_e( 'SFTP server', 'oueb-wp-backup' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="sftphost"><?php esc_html_e( 'Server', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="sftphost" name="sftphost" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'sftphost' ) ); ?>" aria-describedby="sftphost-help" />
					<p class="description" id="sftphost-help"><?php esc_html_e( 'Domain name or IP address, without sftp://.', 'oueb-wp-backup' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sftpport"><?php esc_html_e( 'Port', 'oueb-wp-backup' ); ?></label></th>
				<td><input id="sftpport" name="sftpport" type="number" min="1" max="65535" step="1" class="small-text" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'sftpport' ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="sftpuser"><?php esc_html_e( 'Username', 'oueb-wp-backup' ); ?></label></th>
				<td><input id="sftpuser" name="sftpuser" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'sftpuser' ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authentication', 'oueb-wp-backup' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Authentication', 'oueb-wp-backup' ); ?></legend>
						<p><label><input type="radio" name="sftpauth" value="password" <?php checked( 'key' !== $auth ); ?> /> <?php esc_html_e( 'Password', 'oueb-wp-backup' ); ?></label></p>
						<p><label><input type="radio" name="sftpauth" value="key" <?php checked( 'key', $auth ); ?> /> <?php esc_html_e( 'Private key (recommended)', 'oueb-wp-backup' ); ?></label></p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sftppass"><?php esc_html_e( 'Password', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="sftppass" name="sftppass" type="password" class="regular-text" autocomplete="new-password" value="" aria-describedby="sftppass-help" />
					<p class="description" id="sftppass-help"><?php echo esc_html( $keep_hint ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sftpkey"><?php esc_html_e( 'Private key', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<textarea id="sftpkey" name="sftpkey" rows="6" class="large-text code" autocomplete="off" aria-describedby="sftpkey-help"></textarea>
					<p class="description" id="sftpkey-help"><?php esc_html_e( 'Paste the whole key, including the BEGIN and END lines.', 'oueb-wp-backup' ); ?> <?php echo esc_html( $keep_hint ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sftpkeypass"><?php esc_html_e( 'Key passphrase', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="sftpkeypass" name="sftpkeypass" type="password" class="regular-text" autocomplete="new-password" value="" aria-describedby="sftpkeypass-help" />
					<p class="description" id="sftpkeypass-help"><?php esc_html_e( 'Only if the key is protected by a passphrase.', 'oueb-wp-backup' ); ?> <?php echo esc_html( $keep_hint ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Server fingerprint', 'oueb-wp-backup' ); ?></th>
				<td>
					<?php if ( '' === $fingerprint ) : ?>
						<p><?php esc_html_e( 'Saved at the first successful connection. The plugin then refuses any server whose key differs.', 'oueb-wp-backup' ); ?></p>
					<?php else : ?>
						<p><code><?php echo esc_html( $fingerprint ); ?></code></p>
						<p><label><input type="checkbox" name="sftpfingerprintreset" value="1" /> <?php esc_html_e( 'Forget this fingerprint (only after a server reinstallation)', 'oueb-wp-backup' ); ?></label></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h3 class="title"><?php esc_html_e( 'Backup settings', 'oueb-wp-backup' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="sftpdir"><?php esc_html_e( 'Folder on the server', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="sftpdir" name="sftpdir" type="text" class="regular-text" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'sftpdir' ) ); ?>" aria-describedby="sftpdir-help" />
					<p class="description" id="sftpdir-help"><?php esc_html_e( 'Relative to the home folder of the user, or absolute if it starts with /. Created if missing.', 'oueb-wp-backup' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sftpmaxbackups"><?php esc_html_e( 'Backups to keep', 'oueb-wp-backup' ); ?></label></th>
				<td>
					<input id="sftpmaxbackups" name="sftpmaxbackups" type="number" min="0" step="1" class="small-text" value="<?php echo esc_attr( BackWPup_Option::get( $jobid, 'sftpmaxbackups' ) ); ?>" aria-describedby="sftpmaxbackups-help" />
					<p class="description" id="sftpmaxbackups-help"><?php esc_html_e( 'The oldest backups of this job are deleted beyond this number. 0 keeps them all.', 'oueb-wp-backup' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Enregistre les réglages envoyés par le formulaire.
	 *
	 * BackWPup_Admin::save_post_form() vérifie déjà le nonce et la capacité ;
	 * la méthode les revérifie, car elle peut être appelée d'ailleurs.
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

		$host = isset( $_POST['sftphost'] ) ? sanitize_text_field( wp_unslash( $_POST['sftphost'] ) ) : '';
		$host = preg_replace( '#^sftp://#i', '', $host );

		if ( BackWPup_Option::get( $jobid, 'sftphost' ) !== $host ) {
			// Un autre serveur a forcément une autre clé.
			BackWPup_Option::update( $jobid, 'sftpfingerprint', '' );
		}
		BackWPup_Option::update( $jobid, 'sftphost', $host );

		$port = isset( $_POST['sftpport'] ) ? absint( $_POST['sftpport'] ) : 22;
		BackWPup_Option::update( $jobid, 'sftpport', ( $port > 0 && $port < 65536 ) ? $port : 22 );

		BackWPup_Option::update( $jobid, 'sftpuser', isset( $_POST['sftpuser'] ) ? sanitize_text_field( wp_unslash( $_POST['sftpuser'] ) ) : '' );
		BackWPup_Option::update( $jobid, 'sftpauth', ( isset( $_POST['sftpauth'] ) && 'key' === $_POST['sftpauth'] ) ? 'key' : 'password' );

		// Les secrets ne sont jamais réaffichés : un champ vide garde la valeur enregistrée.
		foreach ( array( 'sftppass', 'sftpkey', 'sftpkeypass' ) as $secret ) {
			if ( ! empty( $_POST[ $secret ] ) ) {
				$value = oueb_sanitize_secret( wp_unslash( $_POST[ $secret ] ) );
				BackWPup_Option::update( $jobid, $secret, BackWPup_Encryption::encrypt( $value ) );
			}
		}

		if ( ! empty( $_POST['sftpfingerprintreset'] ) ) {
			BackWPup_Option::update( $jobid, 'sftpfingerprint', '' );
		}

		$dir = isset( $_POST['sftpdir'] ) ? sanitize_text_field( wp_unslash( $_POST['sftpdir'] ) ) : '';
		$dir = str_replace( array( '\\', '//' ), '/', trim( $dir ) );
		BackWPup_Option::update( $jobid, 'sftpdir', '' === $dir ? '' : trailingslashit( $dir ) );

		BackWPup_Option::update( $jobid, 'sftpmaxbackups', isset( $_POST['sftpmaxbackups'] ) ? absint( $_POST['sftpmaxbackups'] ) : 15 );
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
		if ( empty( $job_settings['sftphost'] ) || empty( $job_settings['sftpuser'] ) ) {
			return false;
		}

		return 'key' === $job_settings['sftpauth'] ? ! empty( $job_settings['sftpkey'] ) : ! empty( $job_settings['sftppass'] );
	}

	/**
	 * Envoie l'archive de la tâche sur le serveur.
	 *
	 * @since 0.1.0
	 *
	 * @param BackWPup_Job $job_object Tâche en cours.
	 * @return bool Vrai si l'étape est terminée.
	 */
	public function job_run_archive( BackWPup_Job $job_object ): bool {
		$job_object->substeps_todo = 2 + $job_object->backup_filesize;
		$step                      = $job_object->steps_data[ $job_object->step_working ];
		$local_file                = $job_object->backup_folder . $job_object->backup_file;

		if ( $step['SAVE_STEP_TRY'] !== $step['STEP_TRY'] ) {
			$job_object->log(
				sprintf(
					/* translators: %d: attempt number. */
					__( '%d. Trying to send backup file to the SFTP server&#160;&hellip;', 'oueb-wp-backup' ),
					$step['STEP_TRY']
				)
			);
		}

		try {
			$client = $this->connect( (int) $job_object->job['jobid'] );
			$job_object->log(
				sprintf(
					/* translators: 1: server name, 2: key fingerprint. */
					__( 'Connected to the SFTP server %1$s (key %2$s).', 'oueb-wp-backup' ),
					$job_object->job['sftphost'],
					$client->get_fingerprint()
				)
			);

			$dir         = untrailingslashit( (string) $job_object->job['sftpdir'] );
			$remote_file = ( '' === $dir ? '' : $dir . '/' ) . $job_object->backup_file;
			$client->ensure_dir( $dir );

			$resume = $job_object->substeps_done > 0 && $client->size( $remote_file ) > 0;
			if ( $resume ) {
				$job_object->log( __( 'Resuming the interrupted upload.', 'oueb-wp-backup' ) );
			}

			$last_save = time();
			$client->upload(
				$remote_file,
				$local_file,
				$resume,
				static function ( $sent ) use ( $job_object, &$last_save ) {
					$job_object->substeps_done = (int) $sent;
					if ( time() - $last_save >= self::PROGRESS_INTERVAL ) {
						$job_object->update_working_data();
						$last_save = time();
					}
				}
			);

			if ( $client->size( $remote_file ) !== (int) filesize( $local_file ) ) {
				$job_object->log( __( 'The file size on the SFTP server does not match the local file.', 'oueb-wp-backup' ), E_USER_ERROR );
				$job_object->substeps_done = 0;

				return false;
			}

			$job_object->substeps_done = 1 + $job_object->backup_filesize;
			/* translators: %s: file path on the server. */
			$job_object->log( sprintf( __( 'Backup transferred to %s.', 'oueb-wp-backup' ), $remote_file ) );

			BackWPup_Option::update(
				$job_object->job['jobid'],
				'lastbackupdownloadurl',
				$this->download_url( (int) $job_object->job['jobid'], $remote_file )
			);

			$this->update_list( $client, $job_object, true );
		} catch ( Oueb_Sftp_Exception $e ) {
			$job_object->log( E_USER_ERROR, $e->getMessage(), $e->getFile(), $e->getLine() );

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
	 * Met à jour la liste des sauvegardes depuis le serveur.
	 *
	 * @since 0.1.0
	 *
	 * @param BackWPup_Job|int $job Tâche en cours ou identifiant de tâche.
	 */
	public function file_update_list( $job ): void {
		$jobid = $job instanceof BackWPup_Job ? (int) $job->job['jobid'] : (int) $job;

		try {
			$this->update_list( $this->connect( $jobid ), $jobid, false );
		} catch ( Oueb_Sftp_Exception $e ) {
			BackWPup_Admin::message( 'SFTP: ' . esc_html( $e->getMessage() ), true );
		}
	}

	/**
	 * Supprime une sauvegarde sur le serveur.
	 *
	 * @since 0.1.0
	 *
	 * @param string $jobdest    Identifiant « tâche_destination ».
	 * @param string $backupfile Chemin du fichier sur le serveur.
	 */
	public function file_delete( string $jobdest, string $backupfile ): void {
		$jobid = (int) strtok( $jobdest, '_' );
		$files = (array) get_site_transient( 'backwpup_' . strtolower( $jobdest ) );

		try {
			if ( $this->connect( $jobid )->delete( $backupfile ) ) {
				foreach ( $files as $index => $file ) {
					if ( is_array( $file ) && $file['file'] === $backupfile ) {
						unset( $files[ $index ] );
					}
				}
			}
		} catch ( Oueb_Sftp_Exception $e ) {
			BackWPup_Admin::message( 'SFTP: ' . esc_html( $e->getMessage() ), true );
		}

		set_site_transient( 'backwpup_' . strtolower( $jobdest ), $files, YEAR_IN_SECONDS );
	}

	/**
	 * Ouvre une connexion avec les réglages d'une tâche.
	 *
	 * Mémorise l'empreinte du serveur à la première connexion réussie.
	 *
	 * @since 0.1.0
	 *
	 * @param int $jobid Identifiant de la tâche.
	 * @return Oueb_Sftp_Client Connexion ouverte.
	 */
	public function connect( $jobid ) {
		$fingerprint = (string) BackWPup_Option::get( $jobid, 'sftpfingerprint' );

		$client = new Oueb_Sftp_Client(
			array(
				'host'        => (string) BackWPup_Option::get( $jobid, 'sftphost' ),
				'port'        => (int) BackWPup_Option::get( $jobid, 'sftpport' ),
				'user'        => (string) BackWPup_Option::get( $jobid, 'sftpuser' ),
				'auth'        => (string) BackWPup_Option::get( $jobid, 'sftpauth' ),
				'password'    => (string) BackWPup_Encryption::decrypt( (string) BackWPup_Option::get( $jobid, 'sftppass' ) ),
				'private_key' => (string) BackWPup_Encryption::decrypt( (string) BackWPup_Option::get( $jobid, 'sftpkey' ) ),
				'passphrase'  => (string) BackWPup_Encryption::decrypt( (string) BackWPup_Option::get( $jobid, 'sftpkeypass' ) ),
				'fingerprint' => $fingerprint,
				'timeout'     => (int) BackWPup_Option::get( $jobid, 'sftptimeout' ),
			)
		);

		if ( '' === $fingerprint ) {
			BackWPup_Option::update( $jobid, 'sftpfingerprint', $client->get_fingerprint() );
		}

		return $client;
	}

	/**
	 * Relit le dossier distant, supprime les sauvegardes en trop et met la liste en cache.
	 *
	 * @since 0.1.0
	 *
	 * @param Oueb_Sftp_Client $client Connexion ouverte.
	 * @param BackWPup_Job|int $job    Tâche en cours ou identifiant de tâche.
	 * @param bool             $rotate Vrai pour supprimer les sauvegardes au-delà de la limite.
	 */
	private function update_list( $client, $job, $rotate ) {
		$job_object = $job instanceof BackWPup_Job ? $job : null;
		$jobid      = $job_object ? (int) $job_object->job['jobid'] : (int) $job;
		$dir        = untrailingslashit( (string) BackWPup_Option::get( $jobid, 'sftpdir' ) );
		$prefix     = '' === $dir ? '' : $dir . '/';
		$host       = (string) BackWPup_Option::get( $jobid, 'sftphost' );
		$port       = (int) BackWPup_Option::get( $jobid, 'sftpport' );
		$files      = array();
		$owned      = array();

		foreach ( $client->list_files( $dir ) as $entry ) {
			$path    = $prefix . $entry['name'];
			$files[] = array(
				'folder'      => 'sftp://' . $host . ':' . $port . '/' . $dir,
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

		$keep = (int) BackWPup_Option::get( $jobid, 'sftpmaxbackups' );
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
					/* translators: %s: file path on the server. */
					$job_object->log( sprintf( __( 'Cannot delete %s on the SFTP server.', 'oueb-wp-backup' ), $path ), E_USER_ERROR );
				}
			}

			if ( $deleted > 0 ) {
				/* translators: %d: number of deleted files. */
				$job_object->log( sprintf( _n( '%d file deleted on the SFTP server.', '%d files deleted on the SFTP server.', $deleted, 'oueb-wp-backup' ), $deleted ) );
			}
		}

		set_site_transient( 'backwpup_' . $jobid . '_sftp', array_values( $files ), YEAR_IN_SECONDS );
	}

	/**
	 * Construit l'adresse de téléchargement d'une sauvegarde.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $jobid Identifiant de la tâche.
	 * @param string $path  Chemin du fichier sur le serveur.
	 * @return string Adresse dans l'administration.
	 */
	private function download_url( $jobid, $path ) {
		return add_query_arg(
			array(
				'page'       => 'backwpupbackups',
				'action'     => 'downloadsftp',
				'file'       => rawurlencode( $path ),
				'local_file' => rawurlencode( basename( $path ) ),
				'jobid'      => (int) $jobid,
			),
			network_admin_url( 'admin.php' )
		);
	}
}
