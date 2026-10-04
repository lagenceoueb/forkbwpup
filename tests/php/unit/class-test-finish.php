<?php
/**
 * Tests du nommage et de la rotation des archives.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Engine\Steps\Finish;

/**
 * Vérifie que les archives portent un nom lisible et que la rotation garde les plus récentes.
 */
final class Test_Finish extends Test_Case {

	/**
	 * Le nom contient le site, la tâche et la date UTC.
	 */
	public function test_archive_name(): void {
		Functions\when( 'home_url' )->justReturn( 'https://Www.Example.co.uk/blog' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->assertSame(
			'www-example-co-uk_main_2026-10-03_101500.tar.gz',
			Finish::archive_name( 'main', gmmktime( 10, 15, 0, 10, 3, 2026 ), 'tar.gz' )
		);
	}

	/**
	 * La rotation ne garde que les archives les plus récentes du site et de la tâche, une au moins.
	 */
	public function test_outdated(): void {
		$names = array(
			'site_main_2026-10-01_000000.zip',
			'site_main_2026-10-02_000000.tar.gz',
			'site_main_2026-10-03_000000.zip',
			'site_main_2026-10-03_000000.zip.part',
			'other-site_main_2026-09-01_000000.zip',
			'site_job-a_2026-09-01_000000.zip',
			'notes.txt',
		);

		$this->assertSame( array( 'site_main_2026-10-01_000000.zip' ), Finish::outdated( $names, 'site_main_', 2 ) );
		$this->assertSame(
			array( 'site_main_2026-10-02_000000.tar.gz', 'site_main_2026-10-01_000000.zip' ),
			Finish::outdated( $names, 'site_main_', 0 ),
			'Keeps at least one archive.'
		);
		$this->assertSame( array(), Finish::outdated( $names, 'site_job-a_', 5 ) );
	}
}
