<?php
/**
 * Client de l'API de cron-job.org.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Crée, met à jour et supprime les tâches planifiées sur cron-job.org.
 *
 * Le service cron-job.org est gratuit, au code ouvert et hébergé en Allemagne.
 * Il appelle l'adresse de déclenchement d'une tâche à l'heure prévue, même
 * quand personne ne visite le site.
 *
 * @since 0.1.0
 */
class Oueb_Cronjob_Org {

	/**
	 * Adresse de l'API.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const API_URL = 'https://api.cron-job.org';

	/**
	 * Clé d'API, en clair.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $api_key;

	/**
	 * Construit le client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $api_key Clé d'API créée dans la console de cron-job.org.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Crée ou met à jour la tâche distante.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $remote_id Identifiant de la tâche distante, 0 pour en créer une.
	 * @param string $title     Titre affiché dans la console de cron-job.org.
	 * @param string $url       Adresse à appeler.
	 * @param string $cron      Expression cron à cinq champs.
	 * @param string $timezone  Fuseau horaire IANA, par exemple « Europe/Paris ».
	 * @param array  $auth      Authentification HTTP, clés « enable », « user » et « password ».
	 * @return int Identifiant de la tâche distante.
	 *
	 * @throws Oueb_Cronjob_Org_Exception Si l'API refuse la demande.
	 */
	public function save_job( $remote_id, $title, $url, $cron, $timezone, $auth = array() ) {
		$job = array(
			'title'         => (string) $title,
			'url'           => (string) $url,
			'enabled'       => true,
			'saveResponses' => false,
			'requestMethod' => 0,
			'auth'          => array(
				'enable'   => ! empty( $auth['enable'] ),
				'user'     => isset( $auth['user'] ) ? (string) $auth['user'] : '',
				'password' => isset( $auth['password'] ) ? (string) $auth['password'] : '',
			),
			'schedule'      => array_merge(
				array(
					'timezone'  => (string) $timezone,
					'expiresAt' => 0,
				),
				self::cron_to_schedule( $cron )
			),
		);

		if ( $remote_id > 0 ) {
			try {
				$this->request( 'PATCH', '/jobs/' . (int) $remote_id, array( 'job' => $job ) );

				return (int) $remote_id;
			} catch ( Oueb_Cronjob_Org_Exception $e ) {
				// Tâche supprimée dans la console ou clé d'un autre compte : on la recrée.
				if ( 404 !== $e->getCode() ) {
					throw $e;
				}
			}
		}

		$response = $this->request( 'PUT', '/jobs', array( 'job' => $job ) );

		if ( empty( $response['jobId'] ) ) {
			throw new Oueb_Cronjob_Org_Exception( esc_html__( 'cron-job.org did not return a job ID.', 'oueb-wp-backup' ) );
		}

		return (int) $response['jobId'];
	}

	/**
	 * Supprime la tâche distante.
	 *
	 * @since 0.1.0
	 *
	 * @param int $remote_id Identifiant de la tâche distante.
	 *
	 * @throws Oueb_Cronjob_Org_Exception Si l'API refuse la demande, sauf tâche déjà absente.
	 */
	public function delete_job( $remote_id ) {
		try {
			$this->request( 'DELETE', '/jobs/' . (int) $remote_id );
		} catch ( Oueb_Cronjob_Org_Exception $e ) {
			if ( 404 !== $e->getCode() ) {
				throw $e;
			}
		}
	}

	/**
	 * Convertit une expression cron en planning cron-job.org.
	 *
	 * Chaque champ devient une liste de valeurs, -1 signifiant « toutes ». La
	 * conversion suit les règles de BackWPup_Cron::cron_next(), pour que
	 * cron-job.org appelle le site aux mêmes heures que WP-Cron : « * » en
	 * minutes vaut toutes les 10 minutes, et le pas d'une plage de minutes
	 * vaut au moins 5.
	 *
	 * @since 0.1.0
	 *
	 * @param string $cron Expression cron à cinq champs : minute, heure, jour, mois, jour de semaine.
	 * @return array Planning avec les clés « minutes », « hours », « mdays », « months » et « wdays ».
	 *
	 * @throws Oueb_Cronjob_Org_Exception Si un champ ne donne aucune valeur valide.
	 */
	public static function cron_to_schedule( $cron ) {
		$fields = preg_split( '/\s+/', trim( (string) $cron ) );
		$fields = array_pad( (array) $fields, 5, '*' );
		$ranges = array(
			'minutes' => array( 0, 59 ),
			'hours'   => array( 0, 23 ),
			'mdays'   => array( 1, 31 ),
			'months'  => array( 1, 12 ),
			'wdays'   => array( 0, 6 ),
		);

		$schedule = array();
		$index    = 0;
		foreach ( $ranges as $name => $range ) {
			$schedule[ $name ] = self::expand_field( $fields[ $index ], $name, $range[0], $range[1] );
			++$index;
		}

		return $schedule;
	}

	/**
	 * Développe un champ cron en liste de valeurs.
	 *
	 * Reconnaît « * », « *\/n », « a », « a-b », « a-b/n » et leurs listes
	 * séparées par des virgules.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Champ cron.
	 * @param string $name  Nom du champ : « minutes », « hours », « mdays », « months » ou « wdays ».
	 * @param int    $min   Valeur minimale du champ.
	 * @param int    $max   Valeur maximale du champ.
	 * @return int[] Valeurs, ou array( -1 ) pour « toutes ».
	 *
	 * @throws Oueb_Cronjob_Org_Exception Si le champ ne donne aucune valeur valide.
	 */
	private static function expand_field( $field, $name, $min, $max ) {
		$values = array();

		foreach ( explode( ',', (string) $field ) as $part ) {
			$step = 1;
			if ( false !== strpos( $part, '/' ) ) {
				list( $part, $step ) = explode( '/', $part, 2 );
				$step                = max( 1, (int) $step );
			}

			if ( '*' === $part ) {
				$start = $min;
				if ( 'minutes' === $name ) {
					$step = max( 10, $step );
				} elseif ( 'mdays' === $name || 'months' === $name ) {
					// BackWPup part du pas pour les jours et les mois : « */2 » donne 2, 4, 6, etc.
					$start = $step;
				}
				$values = array_merge( $values, range( $start, $max, $step ) );
			} elseif ( preg_match( '/^(\d+)-(\d+)$/', $part, $matches ) ) {
				if ( 'minutes' === $name ) {
					$step = max( 5, $step );
				}
				if ( (int) $matches[1] <= (int) $matches[2] ) {
					$values = array_merge( $values, range( (int) $matches[1], (int) $matches[2], $step ) );
				}
			} elseif ( ctype_digit( $part ) ) {
				$values[] = (int) $part;
			}
		}

		$kept = array();
		foreach ( array_unique( $values ) as $value ) {
			// Le dimanche s'écrit aussi 7 en cron.
			if ( 'wdays' === $name && 7 === $value ) {
				$value = 0;
			}
			if ( $value >= $min && $value <= $max ) {
				$kept[] = $value;
			}
		}
		$kept = array_values( array_unique( $kept ) );
		sort( $kept );

		if ( empty( $kept ) ) {
			throw new Oueb_Cronjob_Org_Exception(
				esc_html(
					sprintf(
						/* translators: %s: cron field, such as "*\/5". */
						__( 'The schedule field %s cannot be sent to cron-job.org. Check the schedule of this job.', 'oueb-wp-backup' ),
						$field
					)
				)
			);
		}

		return count( $kept ) === $max - $min + 1 ? array( -1 ) : $kept;
	}

	/**
	 * Envoie une requête à l'API.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $method Méthode HTTP.
	 * @param string     $path   Chemin, par exemple « /jobs ».
	 * @param array|null $body   Corps à encoder en JSON.
	 * @return array Réponse décodée.
	 *
	 * @throws Oueb_Cronjob_Org_Exception Sur une erreur de transport ou un statut hors 2xx.
	 */
	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: technical error message. */
			throw new Oueb_Cronjob_Org_Exception( esc_html( sprintf( __( 'The site cannot reach cron-job.org (%s). Check that your host allows outgoing connections.', 'oueb-wp-backup' ), $response->get_error_message() ) ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status > 299 ) {
			$messages = array(
				401 => __( 'cron-job.org refused the API key.', 'oueb-wp-backup' ),
				403 => __( 'cron-job.org refused the API key.', 'oueb-wp-backup' ),
				404 => __( 'The job no longer exists on cron-job.org.', 'oueb-wp-backup' ),
				429 => __( 'Too many requests to cron-job.org. Try again in a few minutes.', 'oueb-wp-backup' ),
			);
			/* translators: %d: HTTP status code. */
			$message = isset( $messages[ $status ] ) ? $messages[ $status ] : sprintf( __( 'cron-job.org answered with error %d.', 'oueb-wp-backup' ), $status );

			throw new Oueb_Cronjob_Org_Exception( esc_html( $message ), (int) $status );
		}

		return is_array( $data ) ? $data : array();
	}
}
