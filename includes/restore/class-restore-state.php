<?php
/**
 * État partagé des étapes d'une restauration.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore;

use Oueb\WpBackup\Engine\Run_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Lit et écrit les réglages et résultats communs aux étapes d'une restauration.
 *
 * Ils vivent dans la clé « restore » de l'état de l'exécution : source,
 * parties à restaurer, archive en clair, résumé du manifeste.
 *
 * @since 0.1.0
 */
final class Restore_State {

	/**
	 * Lit une valeur.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context  Contexte.
	 * @param string      $key      Clé.
	 * @param mixed       $fallback Valeur si la clé est absente.
	 * @return mixed Valeur.
	 */
	public static function get( Run_Context $context, string $key, $fallback = null ) {
		return $context->run->state['restore'][ $key ] ?? $fallback;
	}

	/**
	 * Écrit une valeur.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @param string      $key     Clé.
	 * @param mixed       $value   Valeur, sérialisable en JSON.
	 */
	public static function set( Run_Context $context, string $key, $value ): void {
		$context->run->state['restore'][ $key ] = $value;
	}

	/**
	 * Renvoie le dossier de travail de la restauration.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @return string Chemin absolu, créé au besoin.
	 */
	public static function dir( Run_Context $context ): string {
		$dir = $context->tmp() . '/restore';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}
}
