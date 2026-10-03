<?php
/**
 * Déclenchement des tâches par cron-job.org.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Renvoie le fuseau horaire du site au format attendu par cron-job.org.
 *
 * Un décalage fixe en heures entières devient un fuseau « Etc/GMT », dont le
 * signe est inversé par convention. Un décalage en demi-heure retombe sur UTC.
 *
 * @since 0.1.0
 *
 * @return string Fuseau horaire IANA.
 */
function oueb_cronjob_org_timezone() {
	$timezone = wp_timezone_string();

	if ( false !== strpos( $timezone, '/' ) || 'UTC' === $timezone ) {
		return $timezone;
	}

	$offset = (float) get_option( 'gmt_offset' );
	if ( floor( $offset ) !== $offset ) {
		return 'UTC';
	}

	return 0.0 === $offset ? 'UTC' : sprintf( 'Etc/GMT%+d', -1 * (int) $offset );
}

/**
 * Aligne la tâche cron-job.org sur le mode de déclenchement d'une tâche.
 *
 * Crée ou met à jour la tâche distante quand le mode « cron-job.org » est
 * choisi, la supprime sinon. Les erreurs s'affichent dans l'administration.
 *
 * @since 0.1.0
 *
 * @param int $jobid Identifiant de la tâche.
 */
function oueb_cronjob_org_sync( $jobid ) {
	$remote_id = (int) BackWPup_Option::get( $jobid, 'cronjoborgid' );

	if ( 'cronjoborg' !== BackWPup_Option::get( $jobid, 'activetype' ) ) {
		if ( $remote_id > 0 ) {
			oueb_cronjob_org_remove( $jobid );
		}

		return;
	}

	$api_key = (string) BackWPup_Encryption::decrypt( (string) get_site_option( 'oueb_cronjob_org_key', '' ) );
	if ( '' === $api_key ) {
		BackWPup_Admin::message( esc_html__( 'Enter your cron-job.org API key to schedule this job.', 'oueb-wp-backup' ), true );

		return;
	}

	$url = BackWPup_Job::get_jobrun_url( 'runext', $jobid );

	try {
		$client    = new Oueb_Cronjob_Org( $api_key );
		$remote_id = $client->save_job(
			$remote_id,
			sprintf( '%1$s, %2$s', get_bloginfo( 'name' ), BackWPup_Option::get( $jobid, 'name' ) ),
			$url['url'],
			(string) BackWPup_Option::get( $jobid, 'cron' ),
			oueb_cronjob_org_timezone()
		);
		BackWPup_Option::update( $jobid, 'cronjoborgid', $remote_id );
	} catch ( Oueb_Cronjob_Org_Exception $e ) {
		/* translators: %s: error message. */
		BackWPup_Admin::message( esc_html( sprintf( __( 'cron-job.org: %s', 'oueb-wp-backup' ), $e->getMessage() ) ), true );
	}
}

/**
 * Supprime la tâche cron-job.org d'une tâche, si elle existe.
 *
 * @since 0.1.0
 *
 * @param int $jobid Identifiant de la tâche.
 */
function oueb_cronjob_org_remove( $jobid ) {
	$remote_id = (int) BackWPup_Option::get( $jobid, 'cronjoborgid' );
	$api_key   = (string) BackWPup_Encryption::decrypt( (string) get_site_option( 'oueb_cronjob_org_key', '' ) );

	if ( $remote_id < 1 || '' === $api_key ) {
		return;
	}

	try {
		( new Oueb_Cronjob_Org( $api_key ) )->delete_job( $remote_id );
		BackWPup_Option::update( $jobid, 'cronjoborgid', 0 );
	} catch ( Oueb_Cronjob_Org_Exception $e ) {
		/* translators: %s: error message. */
		BackWPup_Admin::message( esc_html( sprintf( __( 'cron-job.org: %s', 'oueb-wp-backup' ), $e->getMessage() ) ), true );
	}
}

/**
 * Affiche le choix « cron-job.org » dans l'onglet de planification.
 *
 * @since 0.1.0
 *
 * @param int $jobid Identifiant de la tâche.
 */
function oueb_cronjob_org_fields( $jobid ) {
	$has_key = '' !== (string) get_site_option( 'oueb_cronjob_org_key', '' );
	?>
	<label for="idactivetype-cronjoborg">
		<input class="radio" type="radio" <?php checked( 'cronjoborg', BackWPup_Option::get( $jobid, 'activetype' ) ); ?> name="activetype" id="idactivetype-cronjoborg" value="cronjoborg" aria-describedby="cronjoborg-help" />
		<?php esc_html_e( 'with cron-job.org, a free and open source service', 'oueb-wp-backup' ); ?>
	</label>
	<p class="description" id="cronjoborg-help">
		<?php esc_html_e( 'The job starts at the exact time, even when nobody visits the site. The plugin sends cron-job.org the trigger link and the schedule of this job, nothing else.', 'oueb-wp-backup' ); ?>
	</p>
	<p>
		<label for="cronjoborgkey"><?php esc_html_e( 'cron-job.org API key', 'oueb-wp-backup' ); ?></label><br/>
		<input type="password" id="cronjoborgkey" name="cronjoborgkey" class="regular-text" autocomplete="new-password" value="" aria-describedby="cronjoborgkey-help" />
	</p>
	<p class="description" id="cronjoborgkey-help">
		<?php
		if ( $has_key ) {
			esc_html_e( 'A key is saved for all jobs. Leave empty to keep it.', 'oueb-wp-backup' );
		} else {
			esc_html_e( 'Create a free account on cron-job.org, then generate a key in Settings, API.', 'oueb-wp-backup' );
		}
		?>
	</p>
	<?php
}
