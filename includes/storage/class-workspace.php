<?php
/**
 * Dossiers de travail de l'extension.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Storage;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Gère le dossier où l'extension écrit ses fichiers : fichiers temporaires
 * des exécutions, journaux et archives gardées sur le serveur.
 *
 * Le dossier se trouve dans les téléversements, sous un nom suivi d'un
 * suffixe aléatoire : son adresse ne se devine pas. Il est en plus protégé
 * pour Apache (.htaccess), IIS (web.config) et contre le listage (index.php).
 * Sur Nginx, seul le suffixe protège : le readme recommande une règle de refus.
 *
 * @since 0.1.0
 */
final class Workspace {

	/**
	 * Option qui garde le suffixe du dossier.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION = 'oueb_wp_backup_workspace';

	/**
	 * Dossier racine, sans barre oblique finale.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $root;

	/**
	 * Construit l'espace de travail.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $root Dossier racine, ou null pour le dossier par défaut dans les téléversements.
	 */
	public function __construct( ?string $root = null ) {
		$this->root = untrailingslashit( $root ?? self::default_root() );
	}

	/**
	 * Calcule le dossier par défaut, dans les téléversements du site principal.
	 *
	 * @since 0.1.0
	 *
	 * @return string Dossier racine.
	 */
	public static function default_root(): string {
		$suffix = (string) get_site_option( self::OPTION, '' );
		if ( ! preg_match( '/^[a-z0-9]{16}$/', $suffix ) ) {
			$suffix = strtolower( wp_generate_password( 16, false, false ) );
			update_site_option( self::OPTION, $suffix );
		}

		$switched = is_multisite() && ! is_main_site();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		$uploads = wp_upload_dir( null, false );
		if ( $switched ) {
			restore_current_blog();
		}

		$root = $uploads['basedir'] . '/oueb-wp-backup-' . $suffix;

		/**
		 * Filtre le dossier de travail de l'extension.
		 *
		 * @since 0.1.0
		 *
		 * @param string $root Dossier racine, dans les téléversements.
		 */
		return (string) apply_filters( 'oueb_wp_backup_workspace_dir', $root );
	}

	/**
	 * Renvoie le dossier racine.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * Renvoie le dossier temporaire d'une exécution, créé au besoin.
	 *
	 * @since 0.1.0
	 *
	 * @param int $run_id Identifiant de l'exécution.
	 * @return string Chemin absolu.
	 */
	public function tmp( int $run_id ): string {
		return $this->ensure( 'tmp/run-' . $run_id );
	}

	/**
	 * Renvoie le dossier des journaux, créé au besoin.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	public function logs(): string {
		return $this->ensure( 'logs' );
	}

	/**
	 * Renvoie le dossier des archives gardées sur le serveur, créé au besoin.
	 *
	 * @since 0.1.0
	 *
	 * @return string Chemin absolu.
	 */
	public function archives(): string {
		return $this->ensure( 'archives' );
	}

	/**
	 * Supprime le dossier temporaire d'une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param int $run_id Identifiant de l'exécution.
	 */
	public function clean_tmp( int $run_id ): void {
		self::remove_tree( $this->root . '/tmp/run-' . $run_id );
	}

	/**
	 * Crée un sous-dossier protégé.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Chemin relatif à la racine.
	 * @return string Chemin absolu.
	 *
	 * @throws RuntimeException Si le dossier ne peut pas être créé.
	 */
	private function ensure( string $relative ): string {
		$this->protect();

		$dir = $this->root . '/' . $relative;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException(
				esc_html( /* translators: %s: folder path. */
					esc_html( sprintf( __( 'Cannot create the folder %s. Check the permissions of the uploads folder.', 'oueb-wp-backup' ), $dir ) )
				)
			);
		}

		return $dir;
	}

	/**
	 * Crée la racine et ses fichiers de protection, s'ils manquent.
	 *
	 * @since 0.1.0
	 *
	 * @throws RuntimeException Si la racine ne peut pas être créée.
	 */
	private function protect(): void {
		if ( is_file( $this->root . '/.htaccess' ) ) {
			return;
		}

		if ( ! is_dir( $this->root ) && ! wp_mkdir_p( $this->root ) ) {
			throw new RuntimeException(
				esc_html( /* translators: %s: folder path. */
					esc_html( sprintf( __( 'Cannot create the folder %s. Check the permissions of the uploads folder.', 'oueb-wp-backup' ), $this->root ) )
				)
			);
		}

		self::protect_dir( $this->root );
	}

	/**
	 * Dépose dans un dossier les fichiers qui en refusent l'accès web.
	 *
	 * Apache et IIS les appliquent. Nginx les ignore : le readme donne la
	 * règle à ajouter.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Dossier existant.
	 */
	public static function protect_dir( string $dir ): void {
		$files = array(
			'.htaccess'  => "# Oueb WP Backup : accès web refusé.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence.\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $content );
			}
		}
	}

	/**
	 * Supprime un dossier et son contenu.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Dossier.
	 */
	public static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
