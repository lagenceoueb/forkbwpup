<?php
/**
 * Étape d'export de la base de données.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine\Steps;

use Oueb\WpBackup\Database\Sql;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Engine\Step;
use Oueb\WpBackup\Storage\Resumable_File;

defined( 'ABSPATH' ) || exit;

/**
 * Exporte les tables du site en SQL, par lots, dans database.sql.
 *
 * Seules les tables qui portent le préfixe de WordPress sont exportées, les
 * vues après les tables. Une table avec une clé primaire entière est lue par
 * plages de clé, les autres par décalage. La taille des lots s'adapte au poids
 * des lignes, autour de 2 Mo. Après chaque lot, la taille du fichier et la
 * position de lecture sont notées ensemble : une reprise repart de là, sans
 * ligne écrite deux fois.
 *
 * @since 0.1.0
 */
final class Database_Dump implements Step {

	/**
	 * Nom du fichier produit, dans le dossier temporaire.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const FILE = 'database.sql';

	/**
	 * Poids visé pour un lot de lignes, en octets.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const BATCH_BYTES = 2097152;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'database';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Database export', 'oueb-wp-backup' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte de l'exécution.
	 */
	public function run( Run_Context $context ): bool {
		global $wpdb;

		$file = $context->tmp() . '/' . self::FILE;

		if ( null === $context->get( 'tables' ) ) {
			$context->set( 'tables', $this->list_tables() );
			$context->set( 'table', 0 );
			$context->set( 'size', 0 );
			$context->set( 'rows', 0 );
			$context->logger->info(
				sprintf(
					/* translators: %d: number of tables. */
					_n( 'Exporting %d table.', 'Exporting %d tables.', count( $context->get( 'tables' ) ), 'oueb-wp-backup' ),
					count( $context->get( 'tables' ) )
				)
			);
		}

		$tables = (array) $context->get( 'tables' );
		$count  = count( $tables );
		$handle = Resumable_File::open( $file, (int) $context->get( 'size', 0 ) );

		try {
			if ( 0 === (int) $context->get( 'size', 0 ) ) {
				Resumable_File::write( $handle, $this->header() );
				$context->set( 'size', Resumable_File::commit( $handle ) );
			}

			while ( (int) $context->get( 'table' ) < $count ) {
				$index = (int) $context->get( 'table' );
				$table = $tables[ $index ];

				if ( ! $context->get( 'started' ) ) {
					Resumable_File::write( $handle, $this->create_statement( $table['name'], $table['view'] ) );
					$context->set( 'started', true );
					$context->set( 'cursor', null );
					$context->set( 'offset', 0 );
					$context->set( 'batch', 500 );
					$context->set( 'size', Resumable_File::commit( $handle ) );
				}

				$finished = $table['view'] || $this->dump_rows( $context, $handle, $table['name'] );

				if ( ! $finished ) {
					$context->progress( $index / max( 1, count( $tables ) ) );
					return false;
				}

				Resumable_File::write( $handle, "\n" );
				$context->set( 'size', Resumable_File::commit( $handle ) );
				$context->set( 'table', $index + 1 );
				$context->set( 'started', false );
				$context->progress( ( $index + 1 ) / max( 1, count( $tables ) ) );

				if ( $context->should_pause() && $index + 1 < count( $tables ) ) {
					return false;
				}
			}

			Resumable_File::write( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
			$context->set( 'size', Resumable_File::commit( $handle ) );
		} finally {
			fclose( $handle );
			$wpdb->flush();
		}

		$context->logger->info(
			sprintf(
				/* translators: 1: number of rows, 2: file size. */
				__( 'Database exported: %1$s rows, %2$s.', 'oueb-wp-backup' ),
				number_format_i18n( (int) $context->get( 'rows' ) ),
				size_format( (int) $context->get( 'size' ), 1 )
			)
		);

		return true;
	}

	/**
	 * Écrit les lignes d'une table jusqu'à la fin ou jusqu'à la limite de temps.
	 *
	 * @since 0.1.0
	 *
	 * @param Run_Context $context Contexte.
	 * @param resource    $handle  Fichier SQL.
	 * @param string      $table   Table.
	 * @return bool Vrai si toutes les lignes sont écrites.
	 *
	 * @throws \RuntimeException Si la table ne peut pas être lue.
	 */
	private function dump_rows( Run_Context $context, $handle, string $table ): bool {
		global $wpdb;

		$columns = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$types   = array();
		$primary = array();
		foreach ( $columns as $column ) {
			$types[ $column['Field'] ] = (string) $column['Type'];
			if ( 'PRI' === $column['Key'] ) {
				$primary[] = $column['Field'];
			}
		}
		$by_key = 1 === count( $primary ) && Sql::is_integer_type( $types[ $primary[0] ] );

		while ( true ) {
			$limit = (int) $context->get( 'batch', 500 );

			if ( $by_key ) {
				$cursor = $context->get( 'cursor' );
				$rows   = null === $cursor
					? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i ASC LIMIT %d', $table, $primary[0], $limit ), ARRAY_A )
					: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $table, $primary[0], (string) $cursor, $primary[0], $limit ), ARRAY_A );
			} elseif ( array() !== $primary ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i ORDER BY ' . implode( ', ', array_fill( 0, count( $primary ), '%i' ) ) . ' LIMIT %d OFFSET %d',
						array_merge( array( $table ), $primary, array( $limit, (int) $context->get( 'offset', 0 ) ) )
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, $limit, (int) $context->get( 'offset', 0 ) ), ARRAY_A );
			}

			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException(
					/* translators: 1: table name, 2: database error. */
					esc_html( sprintf( __( 'Cannot read the table %1$s: %2$s', 'oueb-wp-backup' ), $table, $wpdb->last_error ) )
				);
			}

			$rows = (array) $rows;
			if ( array() === $rows ) {
				return true;
			}

			$sql = $this->insert_statement( $table, $rows, $types );
			Resumable_File::write( $handle, $sql );

			$last = end( $rows );
			$context->set( 'cursor', $by_key ? (string) $last[ $primary[0] ] : null );
			$context->set( 'offset', (int) $context->get( 'offset', 0 ) + count( $rows ) );
			$context->set( 'rows', (int) $context->get( 'rows', 0 ) + count( $rows ) );
			$context->set( 'size', Resumable_File::commit( $handle ) );
			$context->checkpoint();

			// Vise des lots d'environ 2 Mo, entre 10 et 5 000 lignes.
			$average = max( 1, (int) ( strlen( $sql ) / count( $rows ) ) );
			$context->set( 'batch', max( 10, min( 5000, (int) ( self::BATCH_BYTES / $average ) ) ) );

			$wpdb->flush();

			if ( count( $rows ) < $limit ) {
				return true;
			}

			if ( $context->should_pause() ) {
				return false;
			}
		}
	}

	/**
	 * Liste les tables à exporter : celles du préfixe de WordPress, vues en dernier.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{name: string, view: bool}> Tables.
	 */
	private function list_tables(): array {
		global $wpdb;

		$tables = array();
		$views  = array();
		foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
			$name = (string) $row[0];
			if ( 0 !== strpos( $name, $wpdb->base_prefix ) ) {
				continue;
			}
			if ( isset( $row[1] ) && 'VIEW' === strtoupper( (string) $row[1] ) ) {
				$views[] = array(
					'name' => $name,
					'view' => true,
				);
			} else {
				$tables[] = array(
					'name' => $name,
					'view' => false,
				);
			}
		}

		/**
		 * Filtre les tables exportées.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, array{name: string, view: bool}> $tables Tables, vues en dernier.
		 */
		return array_values( (array) apply_filters( 'oueb_wp_backup_tables', array_merge( $tables, $views ) ) );
	}

	/**
	 * Écrit la suppression et la création d'une table ou d'une vue.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table Table.
	 * @param bool   $view  Vrai pour une vue.
	 * @return string Instructions SQL.
	 *
	 * @throws \RuntimeException Si la structure ne peut pas être lue.
	 */
	private function create_statement( string $table, bool $view ): string {
		global $wpdb;

		$row = $view
			? $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE VIEW %i', $table ), ARRAY_N )
			: $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );

		if ( ! is_array( $row ) || empty( $row[1] ) ) {
			throw new \RuntimeException(
				/* translators: %s: table name. */
				esc_html( sprintf( __( 'Cannot read the structure of the table %s.', 'oueb-wp-backup' ), $table ) )
			);
		}

		$name = Sql::identifier( $table );

		return '-- ' . ( $view ? 'View' : 'Table' ) . ' ' . $name . "\n"
			. ( $view ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ' ) . $name . ";\n"
			. ( $view ? Sql::strip_definer( (string) $row[1] ) : (string) $row[1] ) . ";\n\n";
	}

	/**
	 * Écrit un INSERT de plusieurs lignes.
	 *
	 * @since 0.1.0
	 *
	 * @param string                           $table Table.
	 * @param array<int, array<string, mixed>> $rows  Lignes.
	 * @param array<string, string>            $types Type de chaque colonne.
	 * @return string Instruction SQL.
	 */
	private function insert_statement( string $table, array $rows, array $types ): string {
		$columns = array_keys( $rows[0] );
		$values  = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $columns as $column ) {
				$cells[] = Sql::value( null === $row[ $column ] ? null : (string) $row[ $column ], $types[ $column ] ?? '' );
			}
			$values[] = '(' . implode( ',', $cells ) . ')';
		}

		return 'INSERT INTO ' . Sql::identifier( $table ) . ' (' . implode( ',', array_map( array( Sql::class, 'identifier' ), $columns ) ) . ") VALUES\n"
			. implode( ",\n", $values ) . ";\n";
	}

	/**
	 * Écrit l'en-tête du fichier SQL.
	 *
	 * @since 0.1.0
	 *
	 * @return string En-tête.
	 */
	private function header(): string {
		global $wpdb;

		$charset = $wpdb->charset ? $wpdb->charset : 'utf8mb4';

		return "-- Oueb WP Backup, database export\n"
			. '-- Site: ' . home_url() . "\n"
			. '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n"
			. 'SET NAMES ' . preg_replace( '/[^a-z0-9_]/i', '', $charset ) . ";\n"
			. "SET FOREIGN_KEY_CHECKS = 0;\n"
			. "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
	}
}
