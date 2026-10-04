<?php
/**
 * Client de l'API de cron-job.org.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Crée, met à jour et supprime une tâche chez cron-job.org.
 *
 * Le service cron-job.org est gratuit, au code ouvert et hébergé en Allemagne. La
 * tâche distante appelle le lien de déclenchement du site aux heures prévues.
 *
 * @since 0.1.0
 */
final class Cronjob_Org_Client {

	/**
	 * Adresse de l'API.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const API_URL = 'https://api.cron-job.org';

	/**
	 * Clé d'API.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $api_key;

	/**
	 * Crée le client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $api_key Clé d'API.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Crée ou met à jour une tâche.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $remote_id Tâche distante existante, 0 pour en créer une.
	 * @param string               $title     Titre affiché dans la console de cron-job.org.
	 * @param string               $url       Adresse appelée.
	 * @param array<string, int[]> $schedule  Planification au format de cron-job.org.
	 * @param string               $timezone  Fuseau horaire IANA.
	 * @return int Identifiant de la tâche distante.
	 *
	 * @throws Remote_Exception Si cron-job.org refuse.
	 */
	public function save_job( int $remote_id, string $title, string $url, array $schedule, string $timezone ): int {
		$job = array(
			'title'         => $title,
			'url'           => $url,
			'enabled'       => true,
			'saveResponses' => false,
			'requestMethod' => 0,
			'schedule'      => array(
				'timezone'  => $timezone,
				'expiresAt' => 0,
			) + $schedule,
		);

		if ( $remote_id > 0 ) {
			try {
				$this->request( 'PATCH', '/jobs/' . $remote_id, array( 'job' => $job ) );
				return $remote_id;
			} catch ( Remote_Exception $error ) {
				// Tâche supprimée dans la console, ou clé d'un autre compte : elle est recréée.
				if ( 404 !== $error->status() ) {
					throw $error;
				}
			}
		}

		$response = $this->request( 'PUT', '/jobs', array( 'job' => $job ) );
		if ( empty( $response['jobId'] ) ) {
			throw new Remote_Exception( esc_html__( 'cron-job.org did not return a job ID.', 'oueb-wp-backup' ) );
		}

		return (int) $response['jobId'];
	}

	/**
	 * Supprime une tâche. Une tâche déjà absente n'est pas une erreur.
	 *
	 * @since 0.1.0
	 *
	 * @param int $remote_id Tâche distante.
	 *
	 * @throws Remote_Exception Si cron-job.org refuse.
	 */
	public function delete_job( int $remote_id ): void {
		try {
			$this->request( 'DELETE', '/jobs/' . $remote_id );
		} catch ( Remote_Exception $error ) {
			if ( 404 !== $error->status() ) {
				throw $error;
			}
		}
	}

	/**
	 * Envoie une requête à l'API.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $method Méthode.
	 * @param string                    $path   Chemin.
	 * @param array<string, mixed>|null $body   Corps, encodé en JSON.
	 * @return array<string, mixed> Réponse décodée.
	 *
	 * @throws Remote_Exception Si l'API répond par une erreur.
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		/**
		 * Filtre l'adresse de l'API de cron-job.org, pour les essais.
		 *
		 * @since 0.1.0
		 *
		 * @param string $url Adresse de l'API.
		 */
		$base     = (string) apply_filters( 'oueb_wp_backup_cronjob_org_api', self::API_URL );
		$response = Http::request(
			$base . $path,
			array(
				'method'  => $method,
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => null === $body ? '' : (string) wp_json_encode( $body ),
			),
			'cronjob_org'
		);

		if ( $response['status'] < 200 || $response['status'] > 299 ) {
			$messages = array(
				401 => __( 'cron-job.org refused the API key.', 'oueb-wp-backup' ),
				403 => __( 'cron-job.org refused the API key.', 'oueb-wp-backup' ),
				404 => __( 'The job no longer exists on cron-job.org.', 'oueb-wp-backup' ),
				429 => __( 'Too many requests to cron-job.org. Try again in a few minutes.', 'oueb-wp-backup' ),
			);
			throw new Remote_Exception(
				esc_html(
					$messages[ $response['status'] ] ?? sprintf(
						/* translators: %d: HTTP status code. */
						__( 'cron-job.org answered with the HTTP error %d.', 'oueb-wp-backup' ),
						$response['status']
					)
				),
				(int) $response['status']
			);
		}

		$data = json_decode( $response['body'], true );

		return is_array( $data ) ? $data : array();
	}
}
