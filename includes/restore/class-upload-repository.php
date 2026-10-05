<?php
/**
 * Archives envoyées depuis le navigateur.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore;

use Oueb\WpBackup\Storage\Workspace;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reçoit une archive par morceaux, dans le dossier de travail protégé.
 *
 * Chaque envoi a un identifiant aléatoire, un fichier de données et un
 * fichier JSON qui décrit le nom et la taille annoncés. Le navigateur envoie
 * les morceaux dans l'ordre ; après une coupure, il demande où reprendre.
 * Un envoi abandonné depuis plus d'un jour est supprimé.
 *
 * @since 0.1.0
 */
final class Upload_Repository {

	/**
	 * Taille maximale d'un morceau, en octets.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_CHUNK = 8388608;

	/**
	 * Durée de vie d'un envoi abandonné, en secondes : un jour.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TTL = 86400;

	/**
	 * Dossiers de travail.
	 *
	 * @since 0.1.0
	 * @var Workspace
	 */
	private Workspace $workspace;

	/**
	 * Crée le dépôt.
	 *
	 * @since 0.1.0
	 *
	 * @param Workspace $workspace Dossiers de travail.
	 */
	public function __construct( Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/**
	 * Calcule la taille des morceaux, sous la limite des requêtes de PHP.
	 *
	 * @since 0.1.0
	 *
	 * @return int Octets par morceau.
	 */
	public static function chunk_size(): int {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $limit <= 0 ) {
			$limit = self::MAX_CHUNK;
		}

		// Marge pour les en-têtes de la requête.
		return (int) max( 262144, min( self::MAX_CHUNK, $limit - 65536 ) );
	}

	/**
	 * Commence un envoi.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Nom du fichier.
	 * @param int    $size Taille annoncée.
	 * @return array<string, mixed>|WP_Error Envoi, ou erreur 400.
	 */
	public function create( string $name, int $size ) {
		$this->clean();

		$name = sanitize_file_name( wp_basename( $name ) );
		if ( '' === $name || ! Extractor::supports( $name ) ) {
			return new WP_Error(
				'oueb_wp_backup_invalid_archive',
				__( 'Choose a zip, tar.gz or tar archive, encrypted or not.', 'oueb-wp-backup' ),
				array( 'status' => 400 )
			);
		}
		if ( $size <= 0 ) {
			return new WP_Error( 'oueb_wp_backup_invalid_archive', __( 'This file is empty.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
		}

		$id   = strtolower( wp_generate_password( 24, false, false ) );
		$meta = array(
			'id'         => $id,
			'name'       => $name,
			'size'       => $size,
			'created_at' => time(),
		);
		file_put_contents( $this->path( $id ), '' );
		file_put_contents( $this->path( $id ) . '.json', (string) wp_json_encode( $meta ) );

		return $this->describe( $meta );
	}

	/**
	 * Renvoie un envoi.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return array<string, mixed>|null Envoi : nom, taille, octets reçus, chemin ; null s'il n'existe pas.
	 */
	public function find( string $id ): ?array {
		if ( ! preg_match( '/^[a-z0-9]{24}$/', $id ) || ! is_file( $this->path( $id ) . '.json' ) ) {
			return null;
		}

		$meta = json_decode( (string) file_get_contents( $this->path( $id ) . '.json' ), true );

		return is_array( $meta ) ? $this->describe( $meta ) : null;
	}

	/**
	 * Ajoute un morceau.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id     Identifiant.
	 * @param int    $offset Position annoncée du morceau.
	 * @param string $data   Octets.
	 * @return array<string, mixed>|WP_Error Envoi mis à jour, ou erreur.
	 */
	public function append( string $id, int $offset, string $data ) {
		$upload = $this->find( $id );
		if ( null === $upload ) {
			return new WP_Error( 'oueb_wp_backup_upload_not_found', __( 'This upload no longer exists. Start it again.', 'oueb-wp-backup' ), array( 'status' => 404 ) );
		}
		if ( $offset !== $upload['received'] ) {
			return new WP_Error(
				'oueb_wp_backup_upload_offset',
				__( 'This part does not follow the previous one.', 'oueb-wp-backup' ),
				array(
					'status'   => 409,
					'received' => $upload['received'],
				)
			);
		}
		if ( strlen( $data ) > self::MAX_CHUNK || $offset + strlen( $data ) > $upload['size'] ) {
			return new WP_Error( 'oueb_wp_backup_upload_too_large', __( 'This part is larger than expected.', 'oueb-wp-backup' ), array( 'status' => 400 ) );
		}

		if ( false === file_put_contents( $this->path( $id ), $data, FILE_APPEND ) ) {
			return new WP_Error( 'oueb_wp_backup_upload_write', __( 'The server refused to write the file. Check the free space.', 'oueb-wp-backup' ), array( 'status' => 507 ) );
		}
		clearstatcache( true, $this->path( $id ) );

		return $this->find( $id );
	}

	/**
	 * Supprime un envoi.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 */
	public function delete( string $id ): void {
		if ( preg_match( '/^[a-z0-9]{24}$/', $id ) ) {
			wp_delete_file( $this->path( $id ) );
			wp_delete_file( $this->path( $id ) . '.json' );
		}
	}

	/**
	 * Supprime les envois abandonnés.
	 *
	 * @since 0.1.0
	 */
	public function clean(): void {
		foreach ( (array) glob( $this->workspace->uploads() . '/*.json' ) as $file ) {
			if ( time() - (int) filemtime( (string) $file ) > self::TTL ) {
				$this->delete( basename( (string) $file, '.json' ) );
			}
		}
	}

	/**
	 * Renvoie le chemin des données d'un envoi.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Identifiant.
	 * @return string Chemin absolu.
	 */
	private function path( string $id ): string {
		return $this->workspace->uploads() . '/' . $id;
	}

	/**
	 * Décrit un envoi pour l'API.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $meta Description enregistrée.
	 * @return array<string, mixed> Envoi.
	 */
	private function describe( array $meta ): array {
		$path = $this->path( (string) $meta['id'] );

		return array(
			'id'         => (string) $meta['id'],
			'name'       => (string) $meta['name'],
			'size'       => (int) $meta['size'],
			'received'   => is_file( $path ) ? (int) filesize( $path ) : 0,
			'chunk_size' => self::chunk_size(),
			'path'       => $path,
		);
	}
}
