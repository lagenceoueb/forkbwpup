<?php
/**
 * Étape de vérification de l'archive à restaurer.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore\Steps;

use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Engine\Step_Failure;
use Oueb\WpBackup\Engine\Steps\Archive;
use Oueb\WpBackup\Engine\Steps\Database_Dump;
use Oueb\WpBackup\Engine\Steps\Manifest;
use Oueb\WpBackup\Restore\Extractor;
use Oueb\WpBackup\Restore\Restore_State;

defined( 'ABSPATH' ) || exit;

/**
 * Lit le manifeste et l'export de la base, puis vérifie que l'archive
 * convient à ce site.
 *
 * Rien n'est encore modifié : un refus ici laisse le site intact. Les
 * différences sans danger (adresse, versions) sont notées dans le journal.
 *
 * @since 0.1.0
 */
final class Check implements Step {

	/**
	 * Natures de fichiers d'une tâche, dans l'ordre des cases à cocher.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const CONTENTS = array( 'uploads', 'themes', 'plugins', 'other_content', 'core' );

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_check';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Checking the archive', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws Step_Failure Si l'archive ne convient pas à ce site.
	 */
	public function run( Run_Context $context ): bool {
		$dir      = Restore_State::dir( $context );
		$database = (bool) Restore_State::get( $context, 'database' );
		$prefix   = Archive::DATA_DIR . '/';

		$done = Extractor::extract(
			$context,
			(string) Restore_State::get( $context, 'file' ),
			(string) Restore_State::get( $context, 'name' ),
			static function ( array $entry ) use ( $dir, $database, $prefix ): ?string {
				if ( $prefix . Manifest::FILE === $entry['name'] ) {
					return $dir . '/' . Manifest::FILE;
				}
				if ( $database && $prefix . Database_Dump::FILE === $entry['name'] ) {
					return $dir . '/' . Database_Dump::FILE;
				}
				return null;
			},
			// Les fichiers de la sauvegarde ouvrent l'archive : la suite n'est pas lue ici.
			// Une archive refaite à la main peut porter l'entrée du dossier, sans barre finale.
			static fn( array $entry ): bool => 0 !== strpos( rtrim( $entry['name'], '/' ) . '/', $prefix )
		);
		if ( ! $done ) {
			return false;
		}

		$this->verify( $context, $dir );

		return true;
	}

	/**
	 * Lit le manifeste et décide de ce qui sera restauré.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @param string      $dir     Dossier des fichiers extraits.
	 *
	 * @throws Step_Failure Si l'archive ne convient pas à ce site.
	 */
	private function verify( Run_Context $context, string $dir ): void {
		global $wpdb, $wp_version;

		$json     = is_file( $dir . '/' . Manifest::FILE ) ? (string) file_get_contents( $dir . '/' . Manifest::FILE ) : '';
		$manifest = json_decode( $json, true );
		if ( ! is_array( $manifest ) || ! isset( $manifest['format'] ) ) {
			throw new Step_Failure( esc_html__( 'This archive was not made by Oueb WP Backup: it has no backup description. Nothing was changed.', 'oueb-wp-backup' ) );
		}
		if ( (int) $manifest['format'] > Manifest::FORMAT ) {
			throw new Step_Failure( esc_html__( 'This archive was made by a newer version of Oueb WP Backup. Update the extension, then start again. Nothing was changed.', 'oueb-wp-backup' ) );
		}
		$database = (bool) Restore_State::get( $context, 'database' );
		$files    = (bool) Restore_State::get( $context, 'files' );

		$refusal = self::network_error(
			$manifest,
			$database,
			is_multisite(),
			is_multisite() ? Manifest::network() : array(
				'domain' => '',
				'path'   => '',
			)
		);
		if ( '' !== $refusal ) {
			throw new Step_Failure( esc_html( $refusal ) );
		}

		if ( $database && ( empty( $manifest['database'] ) || ! is_file( $dir . '/' . Database_Dump::FILE ) ) ) {
			$database = false;
			$context->logger->warning( __( 'This archive contains no database: the database stays as it is.', 'oueb-wp-backup' ) );
		}
		if ( $database && (string) ( $manifest['database']['prefix'] ?? '' ) !== $wpdb->base_prefix ) {
			throw new Step_Failure(
				esc_html(
					sprintf(
						/* translators: 1: table prefix in the archive, 2: table prefix of this site. */
						__( 'The tables of this archive start with %1$s, those of this site with %2$s. Restore the files only, or change the prefix in wp-config.php. Nothing was changed.', 'oueb-wp-backup' ),
						(string) ( $manifest['database']['prefix'] ?? '' ),
						$wpdb->base_prefix
					)
				)
			);
		}
		if ( $files && (int) ( $manifest['files']['count'] ?? 0 ) <= 0 ) {
			$files = false;
			$context->logger->warning( __( 'This archive contains no files: the files stay as they are.', 'oueb-wp-backup' ) );
		}
		if ( ! $database && ! $files ) {
			throw new Step_Failure( esc_html__( 'There is nothing to restore from this archive. Nothing was changed.', 'oueb-wp-backup' ) );
		}

		$home = (string) ( $manifest['site']['home'] ?? '' );
		if ( '' !== $home && untrailingslashit( $home ) !== untrailingslashit( home_url() ) ) {
			$context->logger->warning(
				sprintf(
					/* translators: 1: address of the backed up site, 2: address of this site. */
					__( 'This backup comes from %1$s. The site keeps its address, %2$s, but links inside the content still point to the old address.', 'oueb-wp-backup' ),
					$home,
					home_url()
				)
			);
		}
		$from = (string) ( $manifest['versions']['wordpress'] ?? '' );
		if ( '' !== $from && version_compare( $from, (string) $wp_version, '>' ) ) {
			$context->logger->warning(
				sprintf(
					/* translators: 1: WordPress version of the backup, 2: WordPress version of this site. */
					__( 'This backup was made with WordPress %1$s, newer than this site (%2$s). Restore the WordPress files too, or update WordPress after the restore.', 'oueb-wp-backup' ),
					$from,
					$wp_version
				)
			);
		}

		Restore_State::set( $context, 'database', $database );
		Restore_State::set( $context, 'files', $files );
		Restore_State::set( $context, 'sql', $database ? $dir . '/' . Database_Dump::FILE : '' );
		Restore_State::set( $context, 'roots', isset( $manifest['files']['roots'] ) ? (array) $manifest['files']['roots'] : array( 'core' => '' ) );
		Restore_State::set( $context, 'files_bytes', (int) ( $manifest['files']['bytes'] ?? 0 ) );
		Restore_State::set(
			$context,
			'manifest',
			array(
				'created_at' => (string) ( $manifest['created_at'] ?? '' ),
				'home'       => $home,
				'wordpress'  => $from,
			)
		);

		// La sauvegarde préalable couvre ce que la restauration va remplacer.
		$contents = (array) ( $manifest['job']['contents'] ?? array() );
		foreach ( self::CONTENTS as $part ) {
			$property                  = 'include_' . $part;
			$context->job->{$property} = $files && ! empty( $contents[ $part ] );
		}
		$context->run->state['job'] = $context->job->to_array();

		$context->logger->info(
			sprintf(
				/* translators: 1: backup date, 2: what is restored. */
				__( 'Backup of %1$s checked. Restoring: %2$s.', 'oueb-wp-backup' ),
				(string) ( $manifest['created_at'] ?? '?' ),
				implode(
					', ',
					array_filter(
						array(
							$database ? __( 'database', 'oueb-wp-backup' ) : '',
							$files ? __( 'files', 'oueb-wp-backup' ) : '',
						)
					)
				)
			)
		);
	}

	/**
	 * Dit pourquoi la base d'une sauvegarde ne convient pas à ce réseau, ou à ce site seul.
	 *
	 * Les adresses des sites d'un réseau vivent dans ses tables : sa base ne
	 * peut pas changer d'adresse comme celle d'un site seul. Les fichiers seuls
	 * passent d'un site à un autre.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>                $manifest  Manifeste.
	 * @param bool                                $database  La base sera restaurée.
	 * @param bool                                $multisite Ce site est un réseau.
	 * @param array{domain: string, path: string} $here    Adresse de ce réseau.
	 * @return string Raison du refus, vide si la base convient.
	 */
	public static function network_error( array $manifest, bool $database, bool $multisite, array $here ): string {
		$from_network = ! empty( $manifest['site']['multisite'] );
		if ( ! $database || ( ! $from_network && ! $multisite ) ) {
			return '';
		}
		if ( ! $multisite ) {
			return __( 'This backup comes from a multisite network, and this site is a single site. Restore the files only, or restore it onto its network. Nothing was changed.', 'oueb-wp-backup' );
		}
		if ( ! $from_network ) {
			return __( 'This backup comes from a single site, and this site is a multisite network. Restore the files only. Nothing was changed.', 'oueb-wp-backup' );
		}

		$network = (array) ( $manifest['site']['network'] ?? array() );
		$address = static fn( array $n ): string => (string) ( $n['domain'] ?? '' ) . (string) ( $n['path'] ?? '' );
		if ( $address( $network ) === $address( $here ) ) {
			return '';
		}

		return sprintf(
			/* translators: 1: address of the backed up network, 2: address of this network. */
			__( 'This backup comes from the network %1$s, and this network is %2$s. A network database restores only onto the same address. Restore the files only. Nothing was changed.', 'oueb-wp-backup' ),
			$address( $network ),
			$address( $here )
		);
	}
}
