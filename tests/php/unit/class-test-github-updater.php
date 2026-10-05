<?php
/**
 * Tests des mises à jour depuis GitHub.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Oueb\WpBackup\Update\Github_Updater;

/**
 * Lecture d'une release de l'API GitHub.
 */
final class Test_Github_Updater extends Test_Case {

	/**
	 * Fabrique une release.
	 *
	 * @param string               $tag    Étiquette.
	 * @param array<string, mixed> $extra  Champs à changer.
	 * @return array<string, mixed> Release.
	 */
	private static function release( string $tag, array $extra = array() ): array {
		return array_merge(
			array(
				'tag_name'   => $tag,
				'html_url'   => 'https://github.com/lagenceoueb/forkbwpup/releases/tag/' . $tag,
				'draft'      => false,
				'prerelease' => false,
				'assets'     => array(
					array(
						'name'                 => 'notes.txt',
						'browser_download_url' => 'https://github.com/lagenceoueb/forkbwpup/releases/download/' . $tag . '/notes.txt',
					),
					array(
						'name'                 => 'oueb-wp-backup.zip',
						'browser_download_url' => 'https://github.com/lagenceoueb/forkbwpup/releases/download/' . $tag . '/oueb-wp-backup.zip',
					),
				),
			),
			$extra
		);
	}

	/**
	 * Une release plus récente, publiée, avec son zip, devient une mise à jour.
	 */
	public function test_offer(): void {
		$offer = Github_Updater::offer( self::release( 'v0.2.0' ), '0.1.0' );
		$this->assertSame( '0.2.0', $offer['version'] );
		$this->assertSame( 'https://github.com/lagenceoueb/forkbwpup/releases/download/v0.2.0/oueb-wp-backup.zip', $offer['package'] );
		$this->assertSame( 'oueb-wp-backup', $offer['slug'] );

		$this->assertFalse( Github_Updater::offer( self::release( 'v0.1.0' ), '0.1.0' ) );
		$this->assertFalse( Github_Updater::offer( self::release( 'v0.0.9' ), '0.1.0' ) );
		$this->assertFalse( Github_Updater::offer( self::release( 'v0.2.0', array( 'prerelease' => true ) ), '0.1.0' ) );
		$this->assertFalse( Github_Updater::offer( self::release( 'v0.2.0', array( 'draft' => true ) ), '0.1.0' ) );
		$this->assertFalse( Github_Updater::offer( self::release( 'nightly' ), '0.1.0' ) );
		$this->assertFalse( Github_Updater::offer( self::release( 'v0.2.0', array( 'assets' => array() ) ), '0.1.0' ) );
		$this->assertFalse(
			Github_Updater::offer(
				self::release(
					'v0.2.0',
					array(
						'assets' => array(
							array(
								'name'                 => 'oueb-wp-backup.zip',
								'browser_download_url' => 'https://exemple.fr/oueb-wp-backup.zip',
							),
						),
					)
				),
				'0.1.0'
			)
		);
	}
}
