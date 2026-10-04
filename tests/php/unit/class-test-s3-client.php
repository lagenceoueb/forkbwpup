<?php
/**
 * Tests du client S3.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Remote\Remote_Exception;
use Oueb\WpBackup\Remote\S3_Client;
use Oueb\WpBackup\Remote\Sftp_Client;
use Oueb\WpBackup\Storage\Providers;
use Oueb\WpBackup\Storage\S3_Storage;

/**
 * Vérifie la signature Version 4 sur l'exemple publié par AWS, et les calculs du stockage S3.
 */
final class Test_S3_Client extends Test_Case {

	/**
	 * Simule les fonctions d'adresse et de filtre.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Exemple « GET Object » de la documentation d'AWS Signature Version 4.
	 */
	public function test_signature_matches_aws_example(): void {
		$client  = new S3_Client(
			'https://s3.amazonaws.com',
			'us-east-1',
			'AKIAIOSFODNN7EXAMPLE',
			'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			false,
			static fn(): int => gmmktime( 0, 0, 0, 5, 24, 2013 )
		);
		$headers = $client->sign( 'GET', 'examplebucket.s3.amazonaws.com', '/test.txt', '', array( 'Range' => 'bytes=0-9' ), '' );

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$headers['authorization']
		);
	}

	/**
	 * La chaîne de requête canonique trie les noms et encode les valeurs.
	 */
	public function test_canonical_query(): void {
		$this->assertSame(
			'list-type=2&prefix=a%2Fb%20c&uploads=',
			S3_Client::canonical_query(
				array(
					'uploads'   => '',
					'prefix'    => 'a/b c',
					'list-type' => '2',
				)
			)
		);
	}

	/**
	 * Une adresse sans schéma web est refusée.
	 */
	public function test_invalid_endpoint(): void {
		$this->expectException( Remote_Exception::class );
		new S3_Client( 'ftp://example.com', 'eu', 'a', 'b' );
	}

	/**
	 * Les parties font 8 Mo au moins et restent sous 1 000 pour toute taille.
	 */
	public function test_part_size(): void {
		$mib = 1048576;
		$this->assertSame( 8 * $mib, S3_Storage::part_size( 100 * $mib ) );
		$this->assertSame( 8 * $mib, S3_Storage::part_size( 8000 * $mib ) );

		$big = 50 * 1024 * $mib;
		$this->assertLessThanOrEqual( 1000, (int) ceil( $big / S3_Storage::part_size( $big ) ) );
		$this->assertSame( 0, S3_Storage::part_size( $big ) % $mib );
	}

	/**
	 * Les régions donnent l'adresse et l'adressage de chaque fournisseur.
	 */
	public function test_provider_regions(): void {
		$this->assertSame( 'https://s3.fr-par.scw.cloud', Providers::region( 'scaleway', 'fr-par' )['endpoint'] );
		$this->assertTrue( Providers::region( 'infomaniak', 'dc3-a' )['path_style'] );
		$this->assertNull( Providers::region( 'scaleway', 'us-east-1' ) );
		$this->assertNull( Providers::region( 'aws', 'us-east-1' ) );

		$target = S3_Storage::target(
			array(
				'provider'   => 'ionos',
				'region'     => 'de',
				'endpoint'   => '',
				'path_style' => false,
			)
		);
		$this->assertSame( 'https://s3.eu-central-1.ionoscloud.com', $target['endpoint'] );
		$this->assertSame( 'de', $target['region'] );
		$this->assertTrue( $target['path_style'] );
	}

	/**
	 * L'empreinte d'une clé SSH suit le format d'OpenSSH.
	 */
	public function test_ssh_fingerprint(): void {
		$this->assertSame(
			'SHA256:+ISquqqRua2c+kti8yC6oTSOkexlOPiZoxp9pLBTONI',
			Sftp_Client::fingerprint( 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDkGVcUt5K0LySmYR6O5933nRb7uV0dvddx5POEBIRDJ' )
		);
	}
}
