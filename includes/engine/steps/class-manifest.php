<?php
/**
 * Étape d'écriture du manifeste.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Décrit la sauvegarde dans manifest.json : site, versions, contenu.
 *
 * La restauration lit ce fichier pour vérifier l'archive et prévenir
 * l'administrateur d'une différence de version ou d'adresse.
 *
 * @since 0.1.0
 */
final class Manifest implements Step {

	/**
	 * Nom du fichier produit, dans le dossier temporaire.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const FILE = 'manifest.json';

	/**
	 * Version du format du manifeste.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const FORMAT = 1;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'manifest';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Backup description', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws \RuntimeException Si le manifeste ne peut pas être écrit.
	 */
	public function run( Run_Context $context ): bool {
		global $wpdb, $wp_version;

		$files = $context->run->state['steps']['files'] ?? array();

		$manifest = array(
			'format'     => self::FORMAT,
			'generator'  => 'oueb-wp-backup ' . Plugin::VERSION,
			'created_at' => gmdate( 'c', $context->run->started_at ),
			'site'       => array(
				'home'      => home_url(),
				'siteurl'   => site_url(),
				'name'      => get_bloginfo( 'name' ),
				'multisite' => is_multisite(),
				'network'   => is_multisite() ? self::network() : null,
				'abspath'   => wp_normalize_path( ABSPATH ),
			),
			'versions'   => array(
				'wordpress' => $wp_version,
				'php'       => PHP_VERSION,
				'database'  => $wpdb->db_server_info(),
			),
			'database'   => $context->job->include_database
				? array(
					'file'    => Database_Dump::FILE,
					'prefix'  => $wpdb->base_prefix,
					'charset' => $wpdb->charset,
					'collate' => $wpdb->collate,
				)
				: null,
			'files'      => array(
				'count' => (int) ( $files['files'] ?? 0 ),
				'bytes' => (int) ( $files['bytes'] ?? 0 ),
				'roots' => File_List::archive_prefixes(),
			),
			'job'        => array(
				'id'       => $context->job->id,
				'name'     => $context->job->name,
				'contents' => $context->run->state['contents'] ?? array(),
			),
		);

		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || false === file_put_contents( $context->tmp() . '/' . self::FILE, $json ) ) {
			throw new \RuntimeException( esc_html__( 'Cannot write the backup description.', 'oueb-wp-backup' ) );
		}

		return true;
	}

	/**
	 * Adresse du réseau, pour ne restaurer un réseau que sur lui-même.
	 *
	 * @since 0.1.0
	 *
	 * @return array{domain: string, path: string, sites: int} Domaine, chemin et nombre de sites.
	 */
	public static function network(): array {
		$network = get_network();

		return array(
			'domain' => null === $network ? '' : (string) $network->domain,
			'path'   => null === $network ? '/' : (string) $network->path,
			'sites'  => (int) get_sites( array( 'count' => true ) ),
		);
	}
}
