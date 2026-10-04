<?php
/**
 * Déchiffre une archive d'Oueb WP Backup, sans WordPress.
 *
 * Usage : php oueb-decrypt.php ARCHIVE.enc CLÉ [SORTIE]
 *
 * CLÉ est la clé encodée en base64, ou le chemin d'un fichier qui la
 * contient. Sans SORTIE, l'archive déchiffrée est écrite à côté de
 * l'originale, sans l'extension .enc. Il faut PHP 7.2 ou plus récent,
 * avec Sodium.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Remplace la fonction de WordPress hors de WordPress.
	 *
	 * @param string $text Texte.
	 * @return string Texte.
	 */
	function esc_html__( $text ) {
		return $text;
	}
	/**
	 * Remplace la fonction de WordPress hors de WordPress.
	 *
	 * @param string $text Texte.
	 * @return string Texte.
	 */
	function esc_html( $text ) {
		return $text;
	}
	/**
	 * Remplace la fonction de WordPress hors de WordPress.
	 *
	 * @param string $text Texte.
	 * @return string Texte.
	 */
	function __( $text ) {
		return $text;
	}
}

require __DIR__ . '/includes/security/class-key-ring.php';
require __DIR__ . '/includes/security/class-archive-cipher.php';

use Oueb\WpBackup\Security\Archive_Cipher;
use Oueb\WpBackup\Security\Key_Ring;

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage : php oueb-decrypt.php ARCHIVE.enc CLÉ [SORTIE]\n" );
	exit( 2 );
}

$oueb_source = $argv[1];
$oueb_key    = is_file( $argv[2] ) ? (string) file_get_contents( $argv[2] ) : $argv[2];
$oueb_target = $argv[3] ?? preg_replace( '/\.enc$/', '', $oueb_source );
$oueb_raw    = Key_Ring::decode( $oueb_key );

if ( null === $oueb_raw ) {
	fwrite( STDERR, "Cette clé n'est pas valide : collez-la telle qu'elle a été enregistrée.\n" );
	exit( 2 );
}
if ( $oueb_target === $oueb_source ) {
	fwrite( STDERR, "Indiquez un fichier de sortie différent de l'archive.\n" );
	exit( 2 );
}

$oueb_in  = fopen( $oueb_source, 'rb' );
$oueb_out = fopen( $oueb_target, 'xb' );
if ( false === $oueb_in || false === $oueb_out ) {
	fwrite( STDERR, "Impossible d'ouvrir l'archive, ou le fichier de sortie existe déjà.\n" );
	exit( 1 );
}

try {
	Archive_Cipher::decrypt(
		static fn( int $length ): string => (string) fread( $oueb_in, $length ),
		static function ( string $plain ) use ( $oueb_out ): void {
			if ( strlen( $plain ) !== fwrite( $oueb_out, $plain ) ) {
				throw new RuntimeException( "L'écriture a échoué : vérifiez l'espace libre sur le disque." );
			}
		},
		static fn( string $id ): ?string => Key_Ring::id( $oueb_raw ) === $id ? $oueb_raw : null
	);
} catch ( Throwable $oueb_error ) {
	fclose( $oueb_out );
	unlink( $oueb_target );
	fwrite( STDERR, $oueb_error->getMessage() . "\n" );
	exit( 1 );
}

fclose( $oueb_out );
fwrite( STDOUT, 'Archive déchiffrée : ' . $oueb_target . "\n" );
