<?php
/**
 * Erreur d'un service distant.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Remote;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Erreur renvoyée par un stockage distant, avec son statut HTTP s'il y en a un.
 *
 * @since 0.1.0
 */
class Remote_Exception extends RuntimeException {

	/**
	 * Code d'erreur du service, par exemple « NoSuchBucket ».
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $service_code;

	/**
	 * Crée l'erreur.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message      Message, lisible par un administrateur.
	 * @param int    $status       Statut HTTP, 0 sans réponse.
	 * @param string $service_code Code d'erreur du service.
	 */
	public function __construct( string $message, int $status = 0, string $service_code = '' ) {
		parent::__construct( $message, $status );
		$this->service_code = $service_code;
	}

	/**
	 * Renvoie le statut HTTP.
	 *
	 * @since 0.1.0
	 *
	 * @return int Statut, 0 sans réponse.
	 */
	public function status(): int {
		return (int) $this->getCode();
	}

	/**
	 * Renvoie le code d'erreur du service.
	 *
	 * @since 0.1.0
	 *
	 * @return string Code, vide s'il n'y en a pas.
	 */
	public function service_code(): string {
		return $this->service_code;
	}
}
