<?php
/**
 * Tests du découpage d'un fichier SQL.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Database\Sql;
use Oueb\WpBackup\Database\Sql_Reader;

/**
 * Découpe des fichiers SQL piégés : points-virgules dans les chaînes, commentaires, reprise.
 */
final class Test_Sql_Reader extends Test_Case {

	/**
	 * Fichier SQL du test.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Crée le chemin du fichier.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->file = sys_get_temp_dir() . '/oueb-sql-' . bin2hex( random_bytes( 6 ) ) . '.sql';
	}

	/**
	 * Supprime le fichier.
	 */
	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
		parent::tearDown();
	}

	/**
	 * Lit toutes les instructions d'un fichier.
	 *
	 * @param string $sql    Contenu.
	 * @param int    $offset Position de départ.
	 * @return string[] Instructions.
	 */
	private function split( string $sql, int $offset = 0 ): array {
		file_put_contents( $this->file, $sql );
		$reader     = new Sql_Reader( $this->file, $offset );
		$statements = array();
		while ( null !== ( $statement = $reader->next() ) ) {
			$statements[] = $statement;
		}
		$reader->close();

		return $statements;
	}

	/**
	 * Les points-virgules des chaînes, des identifiants et des commentaires ne coupent pas.
	 */
	public function test_semicolons_inside_strings_and_comments(): void {
		$sql = "-- Oueb WP Backup; export\n"
			. "SET NAMES utf8mb4;\n"
			. "# commentaire; dièse\n"
			. "INSERT INTO `t;1` VALUES ('a;b','it\\'s;',\"x;\\\"y\",'deux '';'' fois');\n"
			. "/*!40101 SET @x = 1; */;\n"
			. "CREATE TABLE `t` (\n  `id` int -- colonne; clé\n);\n"
			. 'SELECT 1-1;SELECT 2 --';

		$this->assertSame(
			array(
				'SET NAMES utf8mb4',
				"INSERT INTO `t;1` VALUES ('a;b','it\\'s;',\"x;\\\"y\",'deux '';'' fois')",
				'/*!40101 SET @x = 1; */',
				"CREATE TABLE `t` (\n  `id` int \n)",
				'SELECT 1-1',
				'SELECT 2',
			),
			$this->split( $sql )
		);
	}

	/**
	 * Un export de l'extension se relit à l'identique, même à cheval sur plusieurs lectures.
	 */
	public function test_values_written_by_sql_are_split_back(): void {
		$values = array( "a;\nb", "c'\\;", str_repeat( 'é;', 700000 ), "\0\x1a\r" );
		$sql    = '';
		$parts  = array();
		foreach ( $values as $value ) {
			$statement = 'INSERT INTO `t` VALUES (' . Sql::quote( $value ) . ')';
			$parts[]   = $statement;
			$sql      .= $statement . ";\n";
		}

		$this->assertSame( $parts, $this->split( $sql ) );
	}

	/**
	 * La reprise à offset() redonne exactement les instructions suivantes.
	 */
	public function test_resume_at_offset(): void {
		$sql = "DROP TABLE IF EXISTS `a`;\nCREATE TABLE `a` (id int);\nINSERT INTO `a` VALUES (1);\nINSERT INTO `a` VALUES (2);\n";
		file_put_contents( $this->file, $sql );

		$reader = new Sql_Reader( $this->file );
		$reader->next();
		$reader->next();
		$offset = $reader->offset();
		$reader->close();

		$this->assertSame( array( 'INSERT INTO `a` VALUES (1)', 'INSERT INTO `a` VALUES (2)' ), $this->split( $sql, $offset ) );
	}

	/**
	 * La table visée se lit dans les instructions de structure et de données.
	 */
	public function test_table_of(): void {
		$this->assertSame( 'wp_options', Sql_Reader::table_of( 'DROP TABLE IF EXISTS `wp_options`' ) );
		$this->assertSame( 'wp_posts', Sql_Reader::table_of( "CREATE TABLE `wp_posts` (\n id int)" ) );
		$this->assertSame( 'a`b', Sql_Reader::table_of( 'INSERT INTO `a``b` (`x`) VALUES (1)' ) );
		$this->assertSame( 'wp_v', Sql_Reader::table_of( 'CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `wp_v` AS select 1' ) );
		$this->assertSame( 'wp_users', Sql_Reader::table_of( 'insert into wp_users values (1)' ) );
		$this->assertNull( Sql_Reader::table_of( 'SET NAMES utf8mb4' ) );
		$this->assertTrue( Sql_Reader::starts_table( 'DROP VIEW IF EXISTS `wp_v`' ) );
		$this->assertFalse( Sql_Reader::starts_table( 'CREATE TABLE `wp_v` (id int)' ) );
	}
}
