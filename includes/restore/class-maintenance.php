<?php
/**
 * Mode maintenance pendant une restauration.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Met le site en maintenance avec le fichier .maintenance de WordPress.
 *
 * Deux sortes de requêtes passent quand même : celles qui portent le cookie
 * remis à l'administrateur qui a lancé la restauration, pour suivre la
 * progression, et la relance du moteur, qui porte son propre jeton.
 *
 * WordPress ignore un fichier .maintenance de plus de dix minutes. La
 * restauration le touche à chaque lot : si elle s'arrête net, le site sort
 * seul de la maintenance au bout de dix minutes.
 *
 * @since 0.1.0
 */
final class Maintenance {

	/**
	 * Cookie de l'administrateur qui suit la restauration.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const COOKIE = 'oueb_wp_backup_restore';

	/**
	 * Marque qui distingue le fichier de l'extension de celui d'une mise à jour.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const MARK = 'Oueb WP Backup';

	/**
	 * Intervalle minimal entre deux mises à jour de la date du fichier, en secondes.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TOUCH_EVERY = 30;

	/**
	 * Dernière mise à jour de la date du fichier (horodatage).
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private static int $touched_at = 0;

	/**
	 * Renvoie le chemin du fichier .maintenance.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	public static function path(): string {
		return ABSPATH . '.maintenance';
	}

	/**
	 * Met le site en maintenance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token_hash Empreinte SHA-256 du cookie de l'administrateur.
	 *
	 * @throws RuntimeException Si le fichier ne peut pas être écrit.
	 */
	public static function start( string $token_hash ): void {
		if ( false === file_put_contents( self::path(), self::contents( $token_hash ) ) ) {
			throw new RuntimeException( esc_html__( 'Cannot put the site in maintenance mode: the WordPress folder is not writable.', 'oueb-wp-backup' ) );
		}
		self::$touched_at = time();
	}

	/**
	 * Repousse la fin automatique de la maintenance, toutes les 30 secondes au plus.
	 *
	 * @since 0.1.0
	 */
	public static function keep(): void {
		if ( time() - self::$touched_at < self::TOUCH_EVERY || ! self::is_ours() ) {
			return;
		}
		self::$touched_at = time();
		touch( self::path() );
		clearstatcache( true, self::path() );
	}

	/**
	 * Sort de la maintenance, si c'est l'extension qui l'a déclenchée.
	 *
	 * @since 0.1.0
	 */
	public static function end(): void {
		if ( self::is_ours() ) {
			wp_delete_file( self::path() );
		}
	}

	/**
	 * Indique si le fichier .maintenance vient de l'extension.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Vrai s'il porte la marque de l'extension.
	 */
	public static function is_ours(): bool {
		$path = self::path();
		if ( ! is_file( $path ) ) {
			return false;
		}

		return false !== strpos( (string) file_get_contents( $path, false, null, 0, 200 ), self::MARK );
	}

	/**
	 * Écrit le code du fichier .maintenance.
	 *
	 * WordPress l'inclut avant de charger les extensions. Il fixe $upgrading
	 * à la date du fichier, ou à zéro pour les requêtes autorisées, ce qui
	 * désactive la maintenance pour elles seules.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token_hash Empreinte SHA-256 du cookie de l'administrateur.
	 * @return string Code PHP.
	 */
	public static function contents( string $token_hash ): string {
		$hash = preg_replace( '/[^a-f0-9]/', '', strtolower( $token_hash ) );

		return '<?php' . "\n"
			. '// ' . self::MARK . " : restauration en cours. Ce fichier disparaît à la fin.\n"
			. '$upgrading = (int) filemtime( __FILE__ );' . "\n"
			. '$oueb_wp_backup_uri = isset( $_SERVER[\'REQUEST_URI\'] ) ? rawurldecode( (string) $_SERVER[\'REQUEST_URI\'] ) : \'\';' . "\n"
			. 'if ( isset( $_COOKIE[\'' . self::COOKIE . '\'] ) && hash_equals( \'' . $hash . '\', hash( \'sha256\', (string) $_COOKIE[\'' . self::COOKIE . '\'] ) ) ) {' . "\n"
			. "\t" . '$upgrading = 0;' . "\n"
			. '} elseif ( preg_match( \'#oueb-wp-backup/v1/runs/\\d+/continue#\', $oueb_wp_backup_uri ) ) {' . "\n"
			. "\t" . '// La relance porte son propre jeton, vérifié par l\'extension.' . "\n"
			. "\t" . '$upgrading = 0;' . "\n"
			. "}\n"
			. 'unset( $oueb_wp_backup_uri );' . "\n";
	}

	/**
	 * Crée le cookie de suivi d'une restauration.
	 *
	 * @since 0.1.0
	 *
	 * @return array{token: string, hash: string} Valeur du cookie et son empreinte.
	 */
	public static function new_token(): array {
		$token = wp_generate_password( 40, false, false );

		return array(
			'token' => $token,
			'hash'  => hash( 'sha256', $token ),
		);
	}
}
