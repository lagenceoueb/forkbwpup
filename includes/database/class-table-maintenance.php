<?php
/**
 * Maintenance des tables du site.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Vérifie, répare ou optimise les tables du site.
 *
 * Seules les tables qui portent le préfixe de WordPress sont traitées, vues
 * exclues. MySQL et MariaDB répondent une ligne par table ; une base qui ne
 * connaît pas l'opération (InnoDB pour la réparation, SQLite) le dit dans le
 * message, sans erreur.
 *
 * @since 0.1.0
 */
final class Table_Maintenance {

	/**
	 * Opérations, et l'instruction SQL de chacune.
	 *
	 * @since 0.1.0
	 * @var array<string, string>
	 */
	const OPERATIONS = array(
		'check'    => 'CHECK TABLE',
		'repair'   => 'REPAIR TABLE',
		'optimize' => 'OPTIMIZE TABLE',
	);

	/**
	 * Liste les tables du site.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{name: string, rows: int, size: int}> Tables, avec leur nombre de lignes et leur taille estimés.
	 */
	public function tables(): array {
		global $wpdb;

		$tables = array();
		$status = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A );
		foreach ( $status as $row ) {
			$comment = strtoupper( (string) ( $row['Comment'] ?? '' ) );
			if ( 'VIEW' === $comment || empty( $row['Name'] ) ) {
				continue;
			}
			$tables[] = array(
				'name' => (string) $row['Name'],
				'rows' => (int) ( $row['Rows'] ?? 0 ),
				'size' => (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ),
			);
		}

		if ( array() === $tables ) {
			// Couche sans SHOW TABLE STATUS, comme SQLite : la liste seule.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) ) as $name ) {
				$tables[] = array(
					'name' => (string) $name,
					'rows' => 0,
					'size' => 0,
				);
			}
		}

		return $tables;
	}

	/**
	 * Lance une opération sur toutes les tables du site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $operation check, repair ou optimize.
	 * @return array<int, array{table: string, status: string, message: string}> Résultat par table : ok, unsupported, warning ou error.
	 */
	public function run( string $operation ): array {
		global $wpdb;

		if ( ! isset( self::OPERATIONS[ $operation ] ) ) {
			return array();
		}

		$results  = array();
		$suppress = $wpdb->suppress_errors( true );
		foreach ( $this->tables() as $table ) {
			$rows = $wpdb->get_results( self::OPERATIONS[ $operation ] . ' ' . Sql::identifier( $table['name'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Nom de table protégé par Sql::identifier().

			if ( '' !== $wpdb->last_error || ! is_array( $rows ) || array() === $rows ) {
				$results[] = array(
					'table'   => $table['name'],
					'status'  => 'unsupported',
					'message' => '' !== $wpdb->last_error ? (string) $wpdb->last_error : __( 'This database does not support this operation.', 'oueb-wp-backup' ),
				);
				continue;
			}

			$results[] = self::summarize( $table['name'], $rows );
		}
		$wpdb->suppress_errors( $suppress );

		return $results;
	}

	/**
	 * Résume les lignes renvoyées par MySQL pour une table.
	 *
	 * MySQL répond par lignes Msg_type (status, info, note, warning, error) et
	 * Msg_text. « OK » et « Table is already up to date » sont des réussites ;
	 * la note d'InnoDB qui recrée la table pour l'optimiser aussi. Le moteur qui
	 * ne sait pas réparer (InnoDB) donne « unsupported » : rien n'est cassé.
	 *
	 * @since 0.1.0
	 *
	 * @param string                            $table Table.
	 * @param array<int, array<string, string>> $rows  Lignes de MySQL.
	 * @return array{table: string, status: string, message: string} Résultat.
	 */
	public static function summarize( string $table, array $rows ): array {
		$status   = 'ok';
		$messages = array();
		foreach ( $rows as $row ) {
			$type = strtolower( (string) ( $row['Msg_type'] ?? '' ) );
			$text = trim( (string) ( $row['Msg_text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$messages[] = $text;

			if ( 'error' === $type || ( 'status' === $type && ! preg_match( '/^(ok|table is already up to date)$/i', $text ) ) ) {
				$status = 'error';
			} elseif ( 'warning' === $type && 'error' !== $status ) {
				$status = 'warning';
			} elseif ( 'note' === $type && false !== stripos( $text, "doesn't support" ) && 'ok' === $status ) {
				$status = 'unsupported';
			}
		}

		return array(
			'table'   => $table,
			'status'  => $status,
			'message' => implode( ' ', array_unique( $messages ) ),
		);
	}
}
