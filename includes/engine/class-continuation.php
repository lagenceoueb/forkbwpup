<?php
/**
 * Relance d'une exécution dans une nouvelle requête.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Demande au site un nouveau passage d'une exécution.
 *
 * Le moteur envoie une requête non bloquante à la route REST
 * runs/{id}/continue, avec un jeton à usage unique. Seule l'empreinte du
 * jeton est enregistrée. Si la requête n'arrive pas (pare-feu, authentification
 * HTTP), le cron de surveillance prend le relais.
 *
 * @since 0.1.0
 */
final class Continuation {

	/**
	 * Exécutions.
	 *
	 * @since 0.1.0
	 * @var Run_Repository
	 */
	private Run_Repository $runs;

	/**
	 * Construit la relance.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Repository $runs Exécutions.
	 */
	public function __construct( Run_Repository $runs ) {
		$this->runs = $runs;
	}

	/**
	 * Demande un nouveau passage.
	 *
	 * @since 0.1.0
	 *
	 * @param Run $run Exécution.
	 */
	public function spawn( Run $run ): void {
		$token               = wp_generate_password( 40, false, false );
		$run->continue_token = hash( 'sha256', $token );
		$this->runs->save( $run );

		$url = rest_url( 'oueb-wp-backup/v1/runs/' . $run->id . '/continue' );

		/**
		 * Filtre la requête de relance d'une exécution.
		 *
		 * Renvoyer false empêche la relance : le cron de surveillance la fera.
		 *
		 * @since 0.1.0
		 *
		 * @param array|false $args Arguments de wp_remote_post().
		 * @param Run         $run  Exécution.
		 */
		$args = apply_filters(
			'oueb_wp_backup_continuation_request',
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				'body'      => array( 'token' => $token ),
				// Requête du site vers lui-même : un certificat local auto-signé ne doit pas la bloquer.
				'sslverify' => false,
			),
			$run
		);

		if ( is_array( $args ) ) {
			wp_remote_post( $url, $args );
		}
	}

	/**
	 * Vérifie et consomme le jeton d'une relance.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run   Exécution.
	 * @param string $token Jeton reçu.
	 * @return bool Vrai si le jeton est le bon : il ne servira plus.
	 */
	public function consume( Run $run, string $token ): bool {
		if ( '' === $run->continue_token || '' === $token || ! hash_equals( $run->continue_token, hash( 'sha256', $token ) ) ) {
			return false;
		}

		$run->continue_token = '';
		$this->runs->save( $run );

		return true;
	}
}
