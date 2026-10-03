<?php
/**
 * Exception levée par le client S3 léger.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Erreur renvoyée par un service compatible S3, ou erreur de transport.
 *
 * @since 0.1.0
 */
class Oueb_S3_Exception extends Exception {

	/**
	 * Code d'erreur S3, par exemple « NoSuchBucket » ou « AccessDenied ».
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $s3_code;

	/**
	 * Construit l'exception.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message     Message lisible.
	 * @param string $s3_code     Code d'erreur S3, vide pour une erreur de transport.
	 * @param int    $http_status Code de statut HTTP, 0 pour une erreur de transport.
	 */
	public function __construct( $message, $s3_code = '', $http_status = 0 ) {
		parent::__construct( $message, (int) $http_status );
		$this->s3_code = (string) $s3_code;
	}

	/**
	 * Renvoie le code d'erreur S3.
	 *
	 * @since 0.1.0
	 *
	 * @return string Code d'erreur S3, vide pour une erreur de transport.
	 */
	public function get_s3_code() {
		return $this->s3_code;
	}
}
