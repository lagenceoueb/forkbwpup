<?php
/**
 * Lecture d'une archive en plusieurs passages.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Parcourt les entrées d'une archive dans l'ordre, et sait reprendre le
 * parcours à une entrée donnée.
 *
 * La méthode position() renvoie le point de reprise de la dernière entrée
 * rendue par next() : de simples nombres, enregistrables en JSON. Un open()
 * avec ce point, suivi de next(), rend de nouveau cette entrée.
 *
 * @since 0.1.0
 */
interface Archive_Reader {

	/**
	 * Ouvre l'archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $path     Chemin de l'archive.
	 * @param array<string, int> $position Point de reprise renvoyé par position(), vide pour partir du début.
	 *
	 * @throws \RuntimeException Si l'archive ne s'ouvre pas.
	 */
	public function open( string $path, array $position ): void;

	/**
	 * Passe à l'entrée suivante, en sautant ce qui reste de l'entrée en cours.
	 *
	 * @since 0.1.0
	 *
	 * @return array{name: string, type: string, size: int, mtime: int}|null Entrée (type file, dir ou other), null à la fin.
	 *
	 * @throws \RuntimeException Si l'archive est abîmée.
	 */
	public function next(): ?array;

	/**
	 * Lit les données de l'entrée en cours.
	 *
	 * @since 0.1.0
	 *
	 * @param int $length Nombre d'octets au plus.
	 * @return string Données, chaîne vide à la fin de l'entrée.
	 *
	 * @throws \RuntimeException Si l'archive est abîmée ou tronquée.
	 */
	public function read( int $length ): string;

	/**
	 * Renvoie le point de reprise de l'entrée en cours.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Point de reprise.
	 */
	public function position(): array;

	/**
	 * Ferme l'archive.
	 *
	 * @since 0.1.0
	 */
	public function close(): void;
}
