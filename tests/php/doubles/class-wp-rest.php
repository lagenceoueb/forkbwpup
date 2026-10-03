<?php
/**
 * Doublures des classes REST de WordPress.
 *
 * @package Oueb_WP_Backup
 */

/**
 * Contrôleur REST réduit au schéma.
 */
abstract class WP_REST_Controller {

	/**
	 * Espace de noms.
	 *
	 * @var string
	 */
	protected $namespace;

	/**
	 * Base des routes.
	 *
	 * @var string
	 */
	protected $rest_base;

	/**
	 * Renvoie le schéma public.
	 *
	 * @return array Schéma.
	 */
	public function get_public_item_schema() {
		return $this->get_item_schema();
	}
}

/**
 * Constantes des méthodes HTTP.
 */
class WP_REST_Server {
	const READABLE = 'GET';
	const EDITABLE = 'POST, PUT, PATCH';
}

/**
 * Requête REST réduite au corps JSON.
 */
class WP_REST_Request {

	/**
	 * Corps JSON décodé.
	 *
	 * @var mixed
	 */
	private $json;

	/**
	 * Construit la requête.
	 *
	 * @param mixed $json Corps JSON décodé.
	 */
	public function __construct( $json = null ) {
		$this->json = $json;
	}

	/**
	 * Renvoie le corps JSON décodé.
	 *
	 * @return mixed Corps.
	 */
	public function get_json_params() {
		return $this->json;
	}
}
