<?php
/**
 * Écriture d'une archive par lots.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Écrit une archive en plusieurs passages, en ajoutant toujours à la fin.
 *
 * La méthode commit() renvoie un point de reprise : de simples tailles de fichiers. Une
 * reprise rouvre l'archive avec ce point, ce qui tronque ce qui a été écrit
 * après. Les fichiers ajoutés depuis le dernier commit() sont alors perdus, et
 * l'étape d'archive les ajoute de nouveau.
 *
 * @since 0.1.0
 */
interface Archive_Writer {

	/**
	 * Ouvre l'archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $path       Chemin de l'archive.
	 * @param array<string, int> $checkpoint Point de reprise du dernier commit(), vide pour une archive neuve.
	 *
	 * @throws \RuntimeException Si l'archive ne s'ouvre pas.
	 */
	public function open( string $path, array $checkpoint ): void;

	/**
	 * Ajoute un fichier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source Chemin du fichier.
	 * @param string $name   Nom dans l'archive.
	 *
	 * @throws \RuntimeException Si le fichier ne peut pas être ajouté.
	 */
	public function add_file( string $source, string $name ): void;

	/**
	 * Écrit les ajouts sur le disque.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Point de reprise, à passer au prochain open().
	 *
	 * @throws \RuntimeException Si l'écriture échoue.
	 */
	public function commit(): array;

	/**
	 * Termine l'archive : plus aucun ajout possible.
	 *
	 * @since 0.1.0
	 *
	 * @return int Taille finale de l'archive.
	 *
	 * @throws \RuntimeException Si l'écriture échoue.
	 */
	public function finish(): int;
}
