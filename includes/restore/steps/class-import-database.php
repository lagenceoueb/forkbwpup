<?php
/**
 * Étape d'import de la base de données.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Restore\Steps;

use Oueb\WpBackup\Database\Sql_Reader;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Schema;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Plugin;
use Oueb\WpBackup\Restore\Maintenance;
use Oueb\WpBackup\Restore\Restore_State;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Importe l'export SQL de la sauvegarde, instruction par instruction.
 *
 * Certaines données du site actuel survivent à l'import :
 *
 * - la table des exécutions, qui porte l'état de cette restauration ;
 * - les réglages de l'extension (stockages, clés, tâches), pour garder les
 *   clés créées depuis la sauvegarde ;
 * - l'adresse du site (siteurl, home), pour qu'il reste joignable ;
 * - la session de l'administrateur qui restaure, pour qu'il reste connecté ;
 * - l'activation de l'extension elle-même.
 *
 * En multisite, les réglages de l'extension sont ceux du réseau : ses lignes
 * de la table sitemeta survivent, et l'extension reste activée sur le réseau.
 *
 * Reprise : la position est notée après chaque instruction, que la base a
 * déjà validée. Après une coupure brutale entre l'instruction et cette note,
 * un INSERT est rejoué en INSERT IGNORE ; une instruction de structure fait
 * repartir du début de la table, que sa suppression puis sa recréation
 * remettent à zéro.
 *
 * @since 0.1.0
 */
final class Import_Database implements Step {

	/**
	 * Début d'une instruction INSERT simple.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const INSERT = '/^\s*INSERT\s+INTO\b/i';

	/**
	 * Interclassements de MySQL 8 inconnus de MariaDB, et leur remplaçant.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	const COLLATIONS = array(
		'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci',
		'utf8mb4_0900_as_ci' => 'utf8mb4_unicode_520_ci',
		'utf8mb4_0900_as_cs' => 'utf8mb4_bin',
		'utf8mb4_0900_bin'   => 'utf8mb4_bin',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'restore_database';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Restoring the database', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 *
	 * @throws RuntimeException Si une instruction échoue.
	 */
	public function run( Run_Context $context ): bool {
		global $wpdb;

		if ( ! Restore_State::get( $context, 'database' ) ) {
			return true;
		}

		$file = (string) Restore_State::get( $context, 'sql', '' );
		if ( null === $context->get( 'preserved' ) ) {
			$this->snapshot( $context );
			$context->set( 'offset', 0 );
			$context->set( 'table_offset', 0 );
			$context->set( 'table', '' );
			$context->set( 'clean', true );
			$context->set( 'settings', array() );
			$context->set( 'statements', 0 );
			$context->set( 'tables', array() );
			$context->checkpoint( true );
		}

		$start  = (int) $context->get( 'offset' );
		$replay = false;
		if ( ! $context->get( 'clean' ) ) {
			// Coupure brutale : l'instruction suivante a peut-être été exécutée
			// juste avant. Un INSERT est rejoué en ignorant les lignes déjà là ;
			// une instruction de structure fait repartir du début de la table.
			$peek = new Sql_Reader( $file, $start );
			$next = $peek->next();
			$peek->close();
			if ( null !== $next && preg_match( self::INSERT, $next ) ) {
				$replay = true;
			} elseif ( (int) $context->get( 'table_offset' ) < $start ) {
				$start = (int) $context->get( 'table_offset' );
				$context->logger->info(
					/* translators: %s: table name. */
					sprintf( __( 'Import interrupted: the table %s is imported again from its start.', 'oueb-wp-backup' ), (string) $context->get( 'table' ) )
				);
			}
		}
		$context->set( 'offset', $start );
		$context->set( 'clean', false );

		$suppress = $wpdb->suppress_errors( true );
		foreach ( (array) $context->get( 'settings', array() ) as $setting ) {
			$this->execute( (string) $setting, '' );
		}

		$runs   = Schema::table();
		$total  = max( 1, (int) filesize( $file ) );
		$reader = new Sql_Reader( $file, $start );

		try {
			while ( true ) {
				$before    = $reader->offset();
				$statement = $reader->next();
				if ( null === $statement ) {
					break;
				}

				$table = Sql_Reader::table_of( $statement );
				if ( Sql_Reader::starts_table( $statement ) ) {
					$this->leave_table( $context );
					$context->set( 'table_offset', $before );
					$context->set( 'table', (string) $table );
					$context->set( 'tables', array_values( array_unique( array_merge( (array) $context->get( 'tables', array() ), array( (string) $table ) ) ) ) );
				}

				if ( $runs === $table ) {
					// L'état de cette restauration vit dans cette table : elle ne change pas.
					$context->set( 'offset', $reader->offset() );
					continue;
				}

				if ( $replay ) {
					$replay    = false;
					$statement = (string) preg_replace( self::INSERT, 'INSERT IGNORE INTO', $statement, 1 );
				}
				$this->execute( $statement, (string) $table );
				if ( null === $table && preg_match( '/^\s*SET\s/i', $statement ) ) {
					$settings   = (array) $context->get( 'settings', array() );
					$settings[] = $statement;
					$context->set( 'settings', array_values( array_unique( $settings ) ) );
				}

				$context->set( 'offset', $reader->offset() );
				$context->set( 'statements', (int) $context->get( 'statements' ) + 1 );
				$context->progress( $reader->offset() / $total );
				// Chaque instruction est validée par la base : sa position est notée tout de suite.
				$context->checkpoint( true );
				Maintenance::keep();

				if ( $context->should_pause() && ! $this->in_core_table( $context ) ) {
					$context->set( 'clean', true );
					return false;
				}
			}
		} finally {
			$reader->close();
			$wpdb->suppress_errors( $suppress );
		}

		$this->leave_table( $context );
		$this->reapply( $context );
		$this->restore_session( $context );
		wp_cache_flush();
		$context->set( 'clean', true );

		$context->logger->info(
			sprintf(
				/* translators: 1: number of tables, 2: number of SQL statements. */
				__( 'Database restored: %1$s tables, %2$s statements.', 'oueb-wp-backup' ),
				number_format_i18n( count( (array) $context->get( 'tables', array() ) ) ),
				number_format_i18n( (int) $context->get( 'statements' ) )
			)
		);

		return true;
	}

	/**
	 * Exécute une instruction.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement Instruction.
	 * @param string $table     Table visée, pour le message d'erreur.
	 *
	 * @throws RuntimeException Si elle échoue.
	 */
	private function execute( string $statement, string $table ): void {
		global $wpdb;

		// Les données binaires ou mal encodées ne doivent pas être filtrées par wpdb.
		$wpdb->check_current_query = false;
		$result                    = $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Instruction de l'export, rejouée telle quelle.
		if ( false !== $result && '' === $wpdb->last_error ) {
			return;
		}

		// Un interclassement de MySQL 8 inconnu de ce serveur est remplacé.
		$error    = (string) $wpdb->last_error;
		$replaced = strtr( $statement, self::COLLATIONS );
		if ( $replaced !== $statement ) {
			$wpdb->check_current_query = false;
			$result                    = $wpdb->query( $replaced ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Instruction de l'export, rejouée telle quelle.
			if ( false !== $result && '' === $wpdb->last_error ) {
				return;
			}
			$error = (string) $wpdb->last_error;
		}

		throw new RuntimeException(
			esc_html(
				'' === $table
					/* translators: %s: database error. */
					? sprintf( __( 'Database error: %s', 'oueb-wp-backup' ), $error )
					/* translators: 1: table name, 2: database error. */
					: sprintf( __( 'Database error in the table %1$s: %2$s', 'oueb-wp-backup' ), $table, $error )
			)
		);
	}

	/**
	 * Indique si l'import est au milieu d'une table sans laquelle WordPress ne démarre pas.
	 *
	 * Le passage suivant charge WordPress : il lui faut l'adresse du site,
	 * l'extension activée et, en multisite, le réseau et ses sites. L'import
	 * ne s'arrête donc pas entre la suppression de ces tables et la fin de
	 * leur remplissage ; elles sont petites.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @return bool Vrai pendant l'import d'une de ces tables.
	 */
	private function in_core_table( Run_Context $context ): bool {
		global $wpdb;

		$core = is_multisite()
			? array( $wpdb->options, $wpdb->sitemeta, $wpdb->site, $wpdb->blogs )
			: array( $wpdb->options );

		return in_array( (string) $context->get( 'table' ), $core, true );
	}

	/**
	 * Termine la table en cours : la table des options reprend aussitôt les valeurs gardées.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 */
	private function leave_table( Run_Context $context ): void {
		global $wpdb;

		$table = (string) $context->get( 'table' );
		if ( $wpdb->options === $table || ( is_multisite() && $wpdb->sitemeta === $table ) ) {
			$this->reapply( $context );
		}
	}

	/**
	 * Note les données du site actuel qui doivent survivre à l'import.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 */
	private function snapshot( Run_Context $context ): void {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s OR option_name IN (%s, %s)',
				$wpdb->options,
				$wpdb->esc_like( 'oueb_wp_backup_' ) . '%',
				'siteurl',
				'home'
			),
			ARRAY_A
		);

		// Base64 : une valeur sérialisée peut contenir n'importe quel octet, et l'état est en JSON.
		$preserved = array();
		foreach ( $rows as $row ) {
			$preserved[] = array( (string) $row['option_name'], base64_encode( (string) $row['option_value'] ), (string) $row['autoload'] );
		}
		$context->set( 'preserved', $preserved );

		$network = array();
		if ( is_multisite() ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT meta_key, meta_value FROM %i WHERE site_id = %d AND meta_key LIKE %s',
					$wpdb->sitemeta,
					get_current_network_id(),
					$wpdb->esc_like( 'oueb_wp_backup_' ) . '%'
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$network[] = array( (string) $row['meta_key'], base64_encode( (string) $row['meta_value'] ) );
			}
		}
		$context->set( 'preserved_network', $network );

		$user    = (int) Restore_State::get( $context, 'user_id', 0 );
		$session = $user > 0
			? $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 1', $wpdb->usermeta, $user, 'session_tokens' ) )
			: null;
		$context->set( 'session', null === $session ? '' : base64_encode( (string) $session ) );
	}

	/**
	 * Remet les réglages gardés et réactive l'extension.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 */
	private function reapply( Run_Context $context ): void {
		global $wpdb;

		// Les réglages de l'extension sont ceux du site actuel, pas ceux de la sauvegarde.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'oueb_wp_backup_' ) . '%' ) );
		foreach ( (array) $context->get( 'preserved', array() ) as $row ) {
			list( $name, $value, $autoload ) = $row;
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => (string) base64_decode( (string) $value, true ),
					'autoload'     => $autoload,
				)
			);
		}

		if ( is_multisite() ) {
			$this->reapply_network( $context );
			wp_cache_flush();
			return;
		}

		$raw    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'active_plugins' ) );
		$active = null === $raw ? array() : maybe_unserialize( (string) $raw );
		$active = is_array( $active ) ? $active : array();
		if ( ! in_array( Plugin::basename(), $active, true ) ) {
			$active[] = Plugin::basename();
			sort( $active );
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'active_plugins' ) );
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => 'active_plugins',
					'option_value' => maybe_serialize( array_values( $active ) ),
					'autoload'     => 'yes',
				)
			);
		}

		wp_cache_flush();
	}

	/**
	 * Remet les réglages du réseau et l'activation de l'extension sur le réseau.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 */
	private function reapply_network( Run_Context $context ): void {
		global $wpdb;

		$site = get_current_network_id();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE site_id = %d AND meta_key LIKE %s', $wpdb->sitemeta, $site, $wpdb->esc_like( 'oueb_wp_backup_' ) . '%' ) );
		foreach ( (array) $context->get( 'preserved_network', array() ) as $row ) {
			list( $key, $value ) = $row;
			$wpdb->insert(
				$wpdb->sitemeta,
				array(
					'site_id'    => $site,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Réglage du réseau gardé avant l'import.
					'meta_value' => (string) base64_decode( (string) $value, true ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Idem.
				)
			);
		}

		$raw    = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s LIMIT 1', $wpdb->sitemeta, $site, 'active_sitewide_plugins' ) );
		$active = null === $raw ? array() : maybe_unserialize( (string) $raw );
		$active = is_array( $active ) ? $active : array();
		if ( ! isset( $active[ Plugin::basename() ] ) ) {
			$active[ Plugin::basename() ] = time();
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE site_id = %d AND meta_key = %s', $wpdb->sitemeta, $site, 'active_sitewide_plugins' ) );
			$wpdb->insert(
				$wpdb->sitemeta,
				array(
					'site_id'    => $site,
					'meta_key'   => 'active_sitewide_plugins', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Une seule ligne par réseau.
					'meta_value' => maybe_serialize( $active ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Idem.
				)
			);
		}
	}

	/**
	 * Remet la session de l'administrateur qui restaure, si son compte existe dans la sauvegarde.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 */
	private function restore_session( Run_Context $context ): void {
		global $wpdb;

		$user    = (int) Restore_State::get( $context, 'user_id', 0 );
		$session = (string) $context->get( 'session', '' );
		if ( $user <= 0 || '' === $session ) {
			return;
		}

		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ID = %d', $wpdb->users, $user ) );
		if ( 0 === $exists ) {
			$context->logger->warning( __( 'Your account does not exist in this backup: log in again with an account of the restored site.', 'oueb-wp-backup' ) );
			return;
		}

		$wpdb->delete(
			$wpdb->usermeta,
			array(
				'user_id'  => $user,
				'meta_key' => 'session_tokens', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Une seule ligne, par utilisateur.
			)
		);
		$wpdb->insert(
			$wpdb->usermeta,
			array(
				'user_id'    => $user,
				'meta_key'   => 'session_tokens', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Une seule ligne, par utilisateur.
				'meta_value' => (string) base64_decode( $session, true ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Valeur gardée avant l'import.
			)
		);
	}
}
