<?php
/**
 * État d'un envoi vers un stockage.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use Oueb\WpBackup\Engine\Run_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Relie un stockage au moteur pendant un envoi.
 *
 * L'état est rangé dans celui de l'étape, sous une clé propre au stockage :
 * il survit aux passages et aux coupures.
 *
 * @since 0.1.0
 */
class Transfer {

	/**
	 * Contexte de l'exécution, null hors du moteur.
	 *
	 * @since 0.1.0
	 * @var Run_Context|null
	 */
	private ?Run_Context $context;

	/**
	 * Clé de l'état dans celui de l'étape.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $key;

	/**
	 * État, quand il n'y a pas de contexte.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	private array $state = array();

	/**
	 * Vrai si l'archive locale peut être déplacée plutôt que copiée.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $may_move;

	/**
	 * Part de l'étape déjà faite avant cet envoi, de 0 à 1.
	 *
	 * @since 0.1.0
	 * @var float
	 */
	private float $from = 0.0;

	/**
	 * Part de l'étape que représente cet envoi, de 0 à 1.
	 *
	 * @since 0.1.0
	 * @var float
	 */
	private float $share = 1.0;

	/**
	 * Crée le lien.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context|null $context    Contexte, null pour un envoi hors moteur.
	 * @param string           $storage_id Stockage.
	 * @param bool             $may_move   Vrai si l'archive locale ne sert plus après cet envoi.
	 */
	public function __construct( ?Run_Context $context, string $storage_id, bool $may_move = false ) {
		$this->context  = $context;
		$this->key      = 'transfer_' . $storage_id;
		$this->may_move = $may_move;
	}

	/**
	 * Situe cet envoi dans l'étape, pour la barre de progression.
	 *
	 * @since 0.1.0
	 *
	 * @param int $index Rang du stockage, à partir de 0.
	 * @param int $count Nombre de stockages.
	 */
	public function place( int $index, int $count ): void {
		$count       = max( 1, $count );
		$this->from  = $index / $count;
		$this->share = 1 / $count;
	}

	/**
	 * Lit une valeur de l'état.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     Nom.
	 * @param mixed  $fallback Valeur si elle est absente.
	 * @return mixed Valeur.
	 */
	public function get( string $name, $fallback = null ) {
		$state = null === $this->context ? $this->state : (array) $this->context->get( $this->key, array() );

		return $state[ $name ] ?? $fallback;
	}

	/**
	 * Écrit une valeur dans l'état.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Nom.
	 * @param mixed  $value Valeur, sérialisable en JSON.
	 */
	public function set( string $name, $value ): void {
		if ( null === $this->context ) {
			$this->state[ $name ] = $value;
			return;
		}

		$state          = (array) $this->context->get( $this->key, array() );
		$state[ $name ] = $value;
		$this->context->set( $this->key, $state );
	}

	/**
	 * Efface l'état, pour recommencer l'envoi de zéro.
	 *
	 * @since 0.1.0
	 */
	public function reset(): void {
		if ( null === $this->context ) {
			$this->state = array();
			return;
		}

		$this->context->set( $this->key, array() );
	}

	/**
	 * Indique si l'envoi doit rendre la main.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai si le temps du passage est écoulé ou l'arrêt demandé.
	 */
	public function should_pause(): bool {
		return null !== $this->context && $this->context->should_pause();
	}

	/**
	 * Enregistre l'état, après un morceau validé par le stockage.
	 *
	 * @since 0.1.0
	 *
	 * @param int $sent  Octets envoyés.
	 * @param int $total Taille de l'archive.
	 */
	public function checkpoint( int $sent, int $total ): void {
		$this->set( 'sent', $sent );
		if ( null !== $this->context ) {
			$this->context->progress( $this->from + $this->share * ( $total > 0 ? $sent / $total : 1.0 ) );
			$this->context->checkpoint();
		}
	}

	/**
	 * Enregistre l'état sans attendre.
	 *
	 * Sert après une opération qu'il ne faut pas refaire, comme l'ouverture
	 * d'un envoi S3 : refaite à chaque coupure, elle laisserait des parties
	 * orphelines, facturées par le fournisseur.
	 *
	 * @since 0.1.0
	 */
	public function save(): void {
		if ( null !== $this->context ) {
			$this->context->checkpoint( true );
		}
	}

	/**
	 * Signale une longue opération en cours, pour garder le verrou.
	 *
	 * @since 0.1.0
	 */
	public function keep_alive(): void {
		if ( null !== $this->context ) {
			$this->context->keep_alive();
		}
	}

	/**
	 * Indique si l'archive locale peut être déplacée.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai si elle ne sert plus après cet envoi.
	 */
	public function may_move(): bool {
		return $this->may_move;
	}
}
