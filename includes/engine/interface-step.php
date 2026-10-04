<?php
/**
 * Étape d'une exécution.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Une étape de sauvegarde, capable de reprendre là où elle s'est arrêtée.
 *
 * La méthode run() travaille jusqu'à la fin de l'étape ou jusqu'à la limite de temps.
 * Tout ce qu'il faut pour reprendre doit se trouver dans l'état de l'étape
 * (Run_Context::get() et set()) au moment où run() rend la main : le
 * processus peut s'arrêter juste après.
 *
 * @since 0.1.0
 */
interface Step {

	/**
	 * Renvoie l'identifiant de l'étape, stable d'une version à l'autre.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifiant, par exemple « database ».
	 */
	public function id(): string;

	/**
	 * Renvoie le libellé affiché pendant l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @return string Libellé traduit.
	 */
	public function label(): string;

	/**
	 * Fait avancer l'étape.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 * @return bool Vrai si l'étape est terminée, faux s'il faut un autre passage.
	 *
	 * @throws \Throwable Si l'étape échoue : le moteur la retente.
	 */
	public function run( Run_Context $context ): bool;
}
