<?php
/**
 * Tests de la maintenance des tables.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Database\Table_Maintenance;

/**
 * Lecture des réponses de MySQL et MariaDB.
 */
final class Test_Table_Maintenance extends Test_Case {

	/**
	 * Fabrique une ligne de réponse.
	 *
	 * @param string $type Msg_type.
	 * @param string $text Msg_text.
	 * @return array<string, string> Ligne.
	 */
	private static function row( string $type, string $text ): array {
		return array(
			'Table'    => 'db.wp_posts',
			'Op'       => 'check',
			'Msg_type' => $type,
			'Msg_text' => $text,
		);
	}

	/**
	 * Réponses réelles de MariaDB 10.11 sur InnoDB et MyISAM.
	 */
	public function test_summarize(): void {
		$this->assertSame(
			array(
				'table'   => 'wp_posts',
				'status'  => 'ok',
				'message' => 'OK',
			),
			Table_Maintenance::summarize( 'wp_posts', array( self::row( 'status', 'OK' ) ) )
		);
		$this->assertSame( 'ok', Table_Maintenance::summarize( 't', array( self::row( 'status', 'Table is already up to date' ) ) )['status'] );
		$this->assertSame(
			'ok',
			Table_Maintenance::summarize(
				't',
				array(
					self::row( 'note', 'Table does not support optimize, doing recreate + analyze instead' ),
					self::row( 'status', 'OK' ),
				)
			)['status']
		);
		$this->assertSame( 'unsupported', Table_Maintenance::summarize( 't', array( self::row( 'note', "The storage engine for the table doesn't support repair" ) ) )['status'] );
		$this->assertSame( 'warning', Table_Maintenance::summarize( 't', array( self::row( 'warning', '1 client is using or hasn\'t closed the table properly' ), self::row( 'status', 'OK' ) ) )['status'] );

		$broken = Table_Maintenance::summarize(
			't',
			array(
				self::row( 'warning', 'Size of datafile is: 12 Should be: 16' ),
				self::row( 'error', 'Corrupt' ),
				self::row( 'status', 'Corrupt' ),
			)
		);
		$this->assertSame( 'error', $broken['status'] );
		$this->assertSame( 'Size of datafile is: 12 Should be: 16 Corrupt', $broken['message'] );
	}
}
