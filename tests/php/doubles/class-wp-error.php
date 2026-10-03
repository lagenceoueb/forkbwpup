<?php
/**
 * Doublure de WP_Error.
 *
 * @package Oueb_WP_Backup
 */

/**
 * Erreur de WordPress réduite au code, au message et aux données.
 */
class WP_Error {

	/**
	 * Code de l'erreur.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * Message de l'erreur.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Données de l'erreur.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Construit l'erreur.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param mixed  $data    Données.
	 */
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = (array) $data;
	}

	/**
	 * Renvoie le code.
	 *
	 * @return string Code.
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * Renvoie les données.
	 *
	 * @return array Données.
	 */
	public function get_error_data() {
		return $this->data;
	}
}
