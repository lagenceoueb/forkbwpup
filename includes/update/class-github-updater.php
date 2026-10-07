<?php
/**
 * Mises à jour depuis les releases GitHub.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Update;

use Oueb\WpBackup\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Propose la dernière release GitHub comme mise à jour de l'extension.
 *
 * L'en-tête Update URI de l'extension désigne github.com : WordPress demande
 * alors la mise à jour au filtre update_plugins_github.com, et jamais à
 * wordpress.org. La classe lit la dernière release, garde la réponse
 * 12 heures, et donne le zip construit par la CI. Ce fichier est retiré de
 * la version publiée sur wordpress.org, qui gère ses propres mises à jour.
 *
 * @since 0.1.0
 */
final class Github_Updater {

	/**
	 * Dépôt GitHub, propriétaire/nom.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const REPOSITORY = 'lagenceoueb/forkbwpup';

	/**
	 * Nom du zip joint à chaque release par la CI.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const ASSET = 'oueb-wp-backup.zip';

	/**
	 * Transient réseau qui garde la dernière release.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CACHE = 'oueb_wp_backup_github_release';

	/**
	 * Durée de vie du cache, en secondes : 12 heures.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TTL = 43200;

	/**
	 * Durée de vie du cache après un échec, en secondes : une heure.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const TTL_ERROR = 3600;

	/**
	 * Branche les filtres de mise à jour.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		/**
		 * Filtre l'activation des mises à jour depuis GitHub.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Vrai pour chercher les mises à jour sur GitHub.
		 */
		if ( ! apply_filters( 'oueb_wp_backup_github_updates', true ) ) {
			return;
		}

		add_filter( 'update_plugins_github.com', array( self::class, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( self::class, 'forget' ), 10, 0 );
	}

	/**
	 * Renvoie la mise à jour de l'extension, s'il y en a une.
	 *
	 * @since 0.1.0
	 *
	 * @param array|false          $update      Mise à jour trouvée par une autre extension.
	 * @param array<string, mixed> $plugin_data En-têtes de l'extension.
	 * @param string               $plugin_file Fichier de l'extension, relatif au dossier des extensions.
	 * @return array|false Mise à jour, ou false.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( Plugin::basename() !== $plugin_file || ! empty( $update ) ) {
			return $update;
		}
		unset( $plugin_data );

		$release = self::release();
		if ( null === $release ) {
			return false;
		}

		return self::offer( $release, Plugin::VERSION );
	}

	/**
	 * Traduit une release en mise à jour pour WordPress.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $release Release, telle que l'API de GitHub la décrit.
	 * @param string               $current Version installée.
	 * @return array<string, mixed>|false Mise à jour, ou false si la release n'est pas plus récente ou n'a pas de zip.
	 */
	public static function offer( array $release, string $current ) {
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return false;
		}

		$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) || ! version_compare( $version, $current, '>' ) ) {
			return false;
		}

		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
				$package = (string) ( $asset['browser_download_url'] ?? '' );
			}
		}
		if ( 0 !== strpos( $package, 'https://github.com/' ) ) {
			return false;
		}

		return array(
			'id'           => 'https://github.com/' . self::REPOSITORY,
			'slug'         => 'oueb-wp-backup',
			'version'      => $version,
			'url'          => (string) ( $release['html_url'] ?? 'https://github.com/' . self::REPOSITORY ),
			'package'      => $package,
			'requires'     => '6.6',
			'requires_php' => '8.1',
		);
	}

	/**
	 * Remplit la fenêtre « Voir les détails » de la mise à jour.
	 *
	 * @since 0.1.0
	 *
	 * @param false|object|array $result Résultat d'une autre extension.
	 * @param string             $action Action demandée à l'API des extensions.
	 * @param object             $args   Arguments, dont le slug.
	 * @return false|object|array Détails de l'extension, ou le résultat reçu.
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'oueb-wp-backup' !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = self::release();
		$offer   = null === $release ? false : self::offer( $release, '0' );
		if ( false === $offer ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Oueb WP Backup',
			'slug'          => 'oueb-wp-backup',
			'version'       => $offer['version'],
			'author'        => '<a href="https://lagenceoueb.tech">L’agence Oueb</a>',
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'requires'      => $offer['requires'],
			'requires_php'  => $offer['requires_php'],
			'last_updated'  => (string) ( $release['published_at'] ?? '' ),
			'download_link' => $offer['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( (string) ( $release['body'] ?? '' ) ) ),
			),
		);
	}

	/**
	 * Oublie la release gardée, après une mise à jour.
	 *
	 * @since 0.1.0
	 */
	public static function forget(): void {
		delete_site_transient( self::CACHE );
	}

	/**
	 * Lit la dernière release, depuis le cache ou depuis GitHub.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>|null Release, ou null si GitHub ne répond pas.
	 */
	private static function release(): ?array {
		$cached = get_site_transient( self::CACHE );
		// « Vérifier à nouveau », dans Tableau de bord > Mises à jour, passe outre le cache.
		$forced = isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture seule, comme WordPress.
		if ( is_array( $cached ) && ! $forced ) {
			return array() === $cached ? null : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$release  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $release ) ) {
			// Échec : un tableau vide évite de redemander à chaque page pendant une heure.
			set_site_transient( self::CACHE, array(), self::TTL_ERROR );
			return null;
		}

		$keep = array_intersect_key( $release, array_flip( array( 'tag_name', 'html_url', 'body', 'published_at', 'draft', 'prerelease', 'assets' ) ) );
		set_site_transient( self::CACHE, $keep, self::TTL );

		return $keep;
	}
}
