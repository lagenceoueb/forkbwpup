<?php
/**
 * Écriture de valeurs SQL.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Convertit des identifiants et des valeurs en SQL pour MySQL et MariaDB.
 *
 * L'échappement ne dépend pas de la connexion : l'export reste le même que
 * la base soit servie par mysqli ou par une autre couche, comme SQLite.
 *
 * @since 0.1.0
 */
final class Sql {

	/**
	 * Types de colonne écrits sans guillemets quand la valeur est numérique.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const NUMERIC_TYPES = '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/i';

	/**
	 * Types de colonne binaires, écrits en hexadécimal.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const BINARY_TYPES = '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/i';

	/**
	 * Types entiers, utilisables pour une pagination par clé.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const INTEGER_TYPES = '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/i';

	/**
	 * Protège un identifiant (table, colonne).
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Identifiant.
	 * @return string Identifiant entre accents graves.
	 */
	public static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Écrit une valeur selon le type de sa colonne.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $value Valeur lue en base.
	 * @param string      $type  Type de la colonne, tel que SHOW COLUMNS le donne.
	 * @return string Littéral SQL.
	 */
	public static function value( ?string $value, string $type ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( preg_match( self::BINARY_TYPES, $type ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}

		if ( preg_match( self::NUMERIC_TYPES, $type ) && is_numeric( $value ) ) {
			return $value;
		}

		return self::quote( $value );
	}

	/**
	 * Écrit une chaîne entre apostrophes, échappée comme le fait MySQL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Chaîne.
	 * @return string Littéral SQL.
	 */
	public static function quote( string $value ): string {
		return "'" . strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		) . "'";
	}

	/**
	 * Indique si un type de colonne est entier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Type de la colonne.
	 * @return bool Vrai pour les types entiers.
	 */
	public static function is_integer_type( string $type ): bool {
		return 1 === preg_match( self::INTEGER_TYPES, $type );
	}

	/**
	 * Retire la clause DEFINER d'une vue, qui empêcherait l'import sur un autre serveur.
	 *
	 * @since 0.1.0
	 *
	 * @param string $create Instruction CREATE VIEW.
	 * @return string Instruction sans DEFINER.
	 */
	public static function strip_definer( string $create ): string {
		return (string) preg_replace( '/\s*DEFINER\s*=\s*(`[^`]*`|\S+)@(`[^`]*`|\S+)/i', '', $create );
	}
}
