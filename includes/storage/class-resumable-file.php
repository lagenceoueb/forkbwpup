<?php
/**
 * Fichier écrit par morceaux, repris après une coupure.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Ouvre un fichier en écriture à partir de sa dernière taille validée.
 *
 * Une étape note la taille du fichier quand l'état correspondant est
 * enregistré. Si le processus s'arrête ensuite au milieu d'une écriture, la
 * reprise tronque le fichier à cette taille : rien n'est écrit deux fois.
 *
 * @since 0.1.0
 */
final class Resumable_File {

	/**
	 * Ouvre le fichier, tronqué à la taille validée, curseur à la fin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path      Chemin du fichier.
	 * @param int    $committed Taille validée, en octets.
	 * @return resource Fichier ouvert.
	 *
	 * @throws RuntimeException Si le fichier ne s'ouvre pas.
	 */
	public static function open( string $path, int $committed ) {
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot write to %s.', 'oueb-wp-backup' ), $path ) ) );
		}

		ftruncate( $handle, max( 0, $committed ) );
		fseek( $handle, 0, SEEK_END );

		return $handle;
	}

	/**
	 * Écrit des données, en vérifiant que tout est passé.
	 *
	 * @since 0.1.0
	 *
	 * @param resource $handle Fichier ouvert.
	 * @param string   $data   Données.
	 *
	 * @throws RuntimeException Si le disque refuse l'écriture, par exemple quand il est plein.
	 */
	public static function write( $handle, string $data ): void {
		$length = strlen( $data );
		if ( 0 === $length ) {
			return;
		}

		if ( fwrite( $handle, $data ) !== $length ) {
			throw new RuntimeException( esc_html__( 'The disk refused to write. Check the free space on the server.', 'oueb-wp-backup' ) );
		}
	}

	/**
	 * Lit exactement le nombre d'octets demandé, ou jusqu'à la fin du fichier.
	 *
	 * Un flux qui n'est pas un simple fichier rend au plus 8 Ko par lecture :
	 * une partie S3 serait alors trop courte, et l'objet assemblé faux.
	 *
	 * @since 0.1.0
	 *
	 * @param resource $handle Fichier ouvert.
	 * @param int      $length Nombre d'octets.
	 * @return string Octets lus, moins que demandé seulement en fin de fichier.
	 */
	public static function read( $handle, int $length ): string {
		$data = '';
		$left = $length;
		while ( $left > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, $left );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
			$left -= strlen( $chunk );
		}

		return $data;
	}

	/**
	 * Vide les tampons et renvoie la taille à valider.
	 *
	 * @since 0.1.0
	 *
	 * @param resource $handle Fichier ouvert.
	 * @return int Taille du fichier, en octets.
	 */
	public static function commit( $handle ): int {
		fflush( $handle );

		return (int) ftell( $handle );
	}
}
