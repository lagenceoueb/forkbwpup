<?php
/**
 * Stockage des archives.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Un endroit où ranger les archives : dossier, S3, SFTP ou kDrive.
 *
 * Les noms passés aux méthodes sont des noms de fichiers, sans dossier :
 * chaque stockage les place dans son propre dossier.
 *
 * @since 0.1.0
 */
interface Storage {

	/**
	 * Envoie une archive, en reprenant un envoi interrompu.
	 *
	 * L'envoi s'arrête quand Transfer::should_pause() le demande, après avoir
	 * noté où il en est. Le moteur rappelle la méthode au passage suivant.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $file     Archive locale.
	 * @param string   $name     Nom de l'archive dans le stockage.
	 * @param Transfer $transfer État de l'envoi et liens avec le moteur.
	 * @return bool Vrai quand l'archive est entièrement envoyée.
	 *
	 * @throws \RuntimeException Si l'envoi échoue.
	 */
	public function upload( string $file, string $name, Transfer $transfer ): bool;

	/**
	 * Liste les fichiers du dossier du stockage.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{name: string, size: int, time: int}> Fichiers.
	 *
	 * @throws \RuntimeException Si la liste échoue.
	 */
	public function files(): array;

	/**
	 * Lit une plage d'octets d'une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   Nom de l'archive.
	 * @param int    $offset Position.
	 * @param int    $length Nombre d'octets.
	 * @return string Octets lus, moins que demandé en fin de fichier.
	 *
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function read( string $name, int $offset, int $length ): string;

	/**
	 * Supprime une archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom de l'archive.
	 *
	 * @throws \RuntimeException Si la suppression échoue.
	 */
	public function delete( string $name ): void;

	/**
	 * Vérifie la connexion et le droit d'écrire.
	 *
	 * @since 0.1.0
	 *
	 * @return string Compte rendu, lisible par un administrateur.
	 *
	 * @throws \RuntimeException Si le stockage est inaccessible.
	 */
	public function test(): string;
}
