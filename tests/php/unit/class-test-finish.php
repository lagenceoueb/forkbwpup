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
	 * La rotation ne touche qu'aux archives de la tâche et garde toujours la dernière.
	 */
	public function test_rotate(): void {
		Functions\when( 'wp_delete_file' )->alias( fn( $file ) => unlink( $file ) );
		$dir = sys_get_temp_dir() . '/oueb-rotate-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir );
		$files = array(
			'site_main_2026-10-01_000000.zip',
			'site_main_2026-10-02_000000.tar.gz',
			'site_main_2026-10-03_000000.zip',
			'site_job-a_2026-09-01_000000.zip',
			'notes.txt',
		);
		foreach ( $files as $file ) {
			touch( $dir . '/' . $file );
		}

		$this->assertSame( 1, Finish::rotate( $dir, 'main', 2 ) );
		$this->assertFileDoesNotExist( $dir . '/site_main_2026-10-01_000000.zip' );
		$this->assertSame( 1, Finish::rotate( $dir, 'main', 0 ), 'Keeps at least one archive.' );
		$this->assertFileExists( $dir . '/site_main_2026-10-03_000000.zip' );
		$this->assertFileExists( $dir . '/site_job-a_2026-09-01_000000.zip' );
		$this->assertFileExists( $dir . '/notes.txt' );

		exec( 'rm -rf ' . escapeshellarg( $dir ) );
	}
}
