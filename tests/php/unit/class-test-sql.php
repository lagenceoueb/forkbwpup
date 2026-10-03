<?php
/**
 * Tests de l'écriture des valeurs SQL.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Database\Sql;

/**
 * Vérifie que l'export écrit des littéraux que MySQL relit à l'identique.
 */
final class Test_Sql extends Test_Case {

	/**
	 * Chaque type de colonne a son écriture.
	 *
	 * @dataProvider values
	 *
	 * @param string|null $value    Valeur lue.
	 * @param string      $type     Type de colonne.
	 * @param string      $expected Littéral attendu.
	 */
	public function test_value( ?string $value, string $type, string $expected ): void {
		$this->assertSame( $expected, Sql::value( $value, $type ) );
	}

	/**
	 * Valeurs et littéraux attendus.
	 *
	 * @return array<string, array{0: string|null, 1: string, 2: string}>
	 */
	public static function values(): array {
		return array(
			'null'             => array( null, 'varchar(10)', 'NULL' ),
			'integer'          => array( '42', 'bigint(20) unsigned', '42' ),
			'decimal'          => array( '-1.5', 'decimal(10,2)', '-1.5' ),
			'numeric garbage'  => array( '1;DROP', 'int(11)', "'1;DROP'" ),
			'binary as hex'    => array( "\x00\xffA", 'longblob', sprintf( '0x%s', '00ff41' ) ),
			'empty binary'     => array( '', 'varbinary(16)', "''" ),
			'escaped string'   => array( "l'a\\b\n\"\0\x1a\r", 'longtext', "'l\\'a\\\\b\\n\\\"\\0\\Z\\r'" ),
			'utf-8 untouched'  => array( 'Sauvegardé ✓', 'text', "'Sauvegardé ✓'" ),
			'date stays quote' => array( '2026-10-03', 'date', "'2026-10-03'" ),
		);
	}

	/**
	 * Un accent grave dans un nom est doublé.
	 */
	public function test_identifier(): void {
		$this->assertSame( '`wp_a``b`', Sql::identifier( 'wp_a`b' ) );
	}

	/**
	 * Seuls les types entiers permettent la pagination par clé.
	 */
	public function test_is_integer_type(): void {
		$this->assertTrue( Sql::is_integer_type( 'bigint(20) unsigned' ) );
		$this->assertTrue( Sql::is_integer_type( 'int' ) );
		$this->assertFalse( Sql::is_integer_type( 'varchar(20)' ) );
		$this->assertFalse( Sql::is_integer_type( 'decimal(10,2)' ) );
	}

	/**
	 * La clause DEFINER disparaît des vues.
	 */
	public function test_strip_definer(): void {
		$this->assertSame(
			'CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS select 1',
			Sql::strip_definer( 'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v` AS select 1' )
		);
	}
}
