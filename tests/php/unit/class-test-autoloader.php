<?php
/**
 * Tests de l'autoloader.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Autoloader;

/**
 * Vérifie la correspondance entre classes et fichiers.
 */
final class Test_Autoloader extends Test_Case {

	/**
	 * Une classe de l'extension trouve son fichier au format WPCS.
	 */
	public function test_finds_class_files(): void {
		$includes = dirname( __DIR__, 3 ) . '/includes';

		$this->assertSame( $includes . '/class-plugin.php', Autoloader::find( 'Oueb\\WpBackup\\Plugin' ) );
		$this->assertSame( $includes . '/security/class-secret-box.php', Autoloader::find( 'Oueb\\WpBackup\\Security\\Secret_Box' ) );
		$this->assertSame( $includes . '/rest/class-settings-controller.php', Autoloader::find( 'Oueb\\WpBackup\\Rest\\Settings_Controller' ) );
	}

	/**
	 * Une classe hors de l'espace de noms, ou absente, est ignorée.
	 */
	public function test_ignores_unknown_classes(): void {
		$this->assertNull( Autoloader::find( 'Other\\Plugin' ) );
		$this->assertNull( Autoloader::find( 'Oueb\\WpBackup\\Does_Not_Exist' ) );
		$this->assertNull( Autoloader::find( 'Oueb\\WpBackupPlugin' ) );
	}
}
