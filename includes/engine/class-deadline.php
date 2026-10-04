<?php
/**
 * Limite de temps d'un passage.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Dit à une étape quand rendre la main.
 *
 * Une étape vérifie reached() entre deux unités de travail (un lot de lignes,
 * un fichier) et s'arrête proprement : le moteur enregistre son état et
 * relance l'exécution dans une nouvelle requête.
 *
 * @since 0.1.0
 */
final class Deadline {

	/**
	 * Heure limite, en secondes (microtime).
	 *
	 * @since 0.1.0
	 * @var float
	 */
	private float $until;

	/**
	 * Construit une limite.
	 *
	 * @since 0.1.0
	 *
	 * @param float $seconds Temps disponible à partir de maintenant.
	 */
	public function __construct( float $seconds ) {
		$this->until = microtime( true ) + max( 0.0, $seconds );
	}

	/**
	 * Indique si la limite est atteinte.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai s'il faut rendre la main.
	 */
	public function reached(): bool {
		return microtime( true ) >= $this->until;
	}

	/**
	 * Renvoie le temps restant.
	 *
	 * @since 0.1.0
	 *
	 * @return float Secondes restantes, 0 si la limite est passée.
	 */
	public function remaining(): float {
		return max( 0.0, $this->until - microtime( true ) );
	}
}
