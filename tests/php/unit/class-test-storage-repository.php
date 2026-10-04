<?php
/**
 * Tests du dépôt des stockages.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Storage\Storage_Repository;
use Oueb\WpBackup\Storage\Workspace;

/**
 * Vérifie la validation, le chiffrement des secrets et le stockage local intégré.
 */
final class Test_Storage_Repository extends Test_Case {

	/**
	 * Dépôt testé.
	 *
	 * @var Storage_Repository
	 */
	private Storage_Repository $repository;

	/**
	 * Simule les fonctions utilisées par le dépôt.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'untrailingslashit' )->alias( fn( $path ) => rtrim( $path, '/\\' ) );
		Functions\when( 'sanitize_text_field' )->alias( fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_email' )->alias( fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'path_is_absolute' )->alias( fn( $path ) => '/' === substr( (string) $path, 0, 1 ) );
		Functions\when( 'wp_normalize_path' )->alias( fn( $path ) => str_replace( '\\', '/', (string) $path ) );

		$this->repository = new Storage_Repository( new Workspace( '/srv/uploads/oueb-wp-backup-x' ) );
	}

	/**
	 * Crée un serveur SFTP valide.
	 *
	 * @return array<string, mixed> Enregistrement.
	 */
	private function sftp(): array {
		return $this->repository->create(
			'sftp',
			array(
				'name'     => 'Serveur',
				'settings' => array(
					'host'     => 'backup.example.fr',
					'port'     => '2222',
					'user'     => 'site',
					'password' => 'mot de passe',
					'folder'   => 'sauvegardes',
				),
			)
		);
	}

	/**
	 * Le stockage local existe toujours, en premier, et ne se modifie pas.
	 */
	public function test_local_is_builtin(): void {
		$all = $this->repository->all();

		$this->assertSame( 'local', array_key_first( $all ) );
		$this->assertSame( '/srv/uploads/oueb-wp-backup-x/archives', $all['local']['settings']['path'] );
		$this->assertTrue( $this->repository->to_public( $all['local'] )['builtin'] );
		$this->assertSame( 400, $this->repository->update( 'local', array( 'name' => 'x' ) )->get_error_data()['status'] );
		$this->assertSame( 400, $this->repository->delete( 'local' )->get_error_data()['status'] );
	}

	/**
	 * Les secrets sont chiffrés en base et ne sortent jamais par l'API.
	 */
	public function test_secrets_are_encrypted(): void {
		$record = $this->sftp();

		$this->assertMatchesRegularExpression( '/^st-[a-z0-9]{8}$/', $record['id'] );
		$this->assertStringNotContainsString( 'mot de passe', serialize( $this->site_options ) );
		$this->assertSame( 2222, $record['settings']['port'] );

		$public = $this->repository->to_public( $record );
		$this->assertArrayNotHasKey( 'password', $public['settings'] );
		$this->assertTrue( $public['secrets_set']['password'] );
		$this->assertFalse( $public['secrets_set']['private_key'] );
		$this->assertSame( 'site@backup.example.fr:sauvegardes', $public['description'] );

		$this->assertSame( 'mot de passe', $this->repository->settings( $record )['password'] );
	}

	/**
	 * Un secret laissé vide garde sa valeur.
	 */
	public function test_empty_secret_keeps_value(): void {
		$record  = $this->sftp();
		$updated = $this->repository->update(
			$record['id'],
			array(
				'settings' => array(
					'password' => '',
					'folder'   => 'autre',
				),
			)
		);

		$this->assertSame( 'autre', $updated['settings']['folder'] );
		$this->assertSame( 'mot de passe', $this->repository->settings( $updated )['password'] );
	}

	/**
	 * Une valeur invalide est refusée avec le nom du champ.
	 *
	 * @dataProvider invalid_values
	 *
	 * @param string               $type   Type.
	 * @param array<string, mixed> $values Valeurs.
	 * @param string               $field  Champ attendu dans l'erreur.
	 */
	public function test_invalid_values( string $type, array $values, string $field ): void {
		$error = $this->repository->create( $type, $values );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( $field, $error->get_error_data()['field'] );
		$this->assertArrayNotHasKey( Storage_Repository::OPTION, $this->site_options );
	}

	/**
	 * Valeurs refusées.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function invalid_values(): array {
		$s3 = array(
			'provider'   => 'scaleway',
			'region'     => 'fr-par',
			'bucket'     => 'mes-sauvegardes',
			'access_key' => 'SCW123',
			'secret_key' => 'secret',
		);

		return array(
			'type inconnu'          => array( 'dropbox', array( 'name' => 'x' ), 'type' ),
			'nom vide'              => array( 's3', array( 'settings' => $s3 ), 'name' ),
			'fournisseur écarté'    => array(
				's3',
				array(
					'name'     => 'x',
					'settings' => array( 'provider' => 'aws' ) + $s3,
				),
				'provider',
			),
			'région d’un autre'     => array(
				's3',
				array(
					'name'     => 'x',
					'settings' => array( 'region' => 'gra' ) + $s3,
				),
				'region',
			),
			'bucket invalide'       => array(
				's3',
				array(
					'name'     => 'x',
					'settings' => array( 'bucket' => 'A_B' ) + $s3,
				),
				'bucket',
			),
			'adresse en HTTP'       => array(
				's3',
				array(
					'name'     => 'x',
					'settings' => array(
						'provider' => 'custom',
						'endpoint' => 'http://s3.example.com',
					) + $s3,
				),
				'endpoint',
			),
			'dossier remontant'     => array(
				's3',
				array(
					'name'     => 'x',
					'settings' => array( 'folder' => '../autre' ) + $s3,
				),
				'folder',
			),
			'clé secrète manquante' => array(
				's3',
				array(
					'name'     => 'x',
					'settings' => array( 'secret_key' => '' ) + $s3,
				),
				'secret_key',
			),
			'port hors bornes'      => array(
				'sftp',
				array(
					'name'     => 'x',
					'settings' => array(
						'host'     => 'h',
						'user'     => 'u',
						'password' => 'p',
						'port'     => 70000,
					),
				),
				'port',
			),
			'clé privée manquante'  => array(
				'sftp',
				array(
					'name'     => 'x',
					'settings' => array(
						'host' => 'h',
						'user' => 'u',
						'auth' => 'key',
					),
				),
				'private_key',
			),
			'identifiant kDrive'    => array(
				'kdrive',
				array(
					'name'     => 'x',
					'settings' => array(
						'drive_id' => 'abc',
						'email'    => 'a@b.fr',
						'password' => 'p',
					),
				),
				'drive_id',
			),
			'e-mail kDrive'         => array(
				'kdrive',
				array(
					'name'     => 'x',
					'settings' => array(
						'drive_id' => '12',
						'email'    => 'non',
						'password' => 'p',
					),
				),
				'email',
			),
			'chemin relatif'        => array(
				'folder',
				array(
					'name'     => 'x',
					'settings' => array( 'path' => 'backups' ),
				),
				'path',
			),
		);
	}

	/**
	 * L'empreinte d'un serveur SFTP n'est retenue qu'une fois.
	 */
	public function test_fingerprint_is_remembered_once(): void {
		$record = $this->sftp();
		$this->repository->remember_fingerprint( $record['id'], 'SHA256:premiere' );
		$this->repository->remember_fingerprint( $record['id'], 'SHA256:seconde' );

		$this->assertSame( 'SHA256:premiere', $this->repository->get( $record['id'] )['settings']['fingerprint'] );
	}

	/**
	 * Un stockage supprimé disparaît de la liste.
	 */
	public function test_delete(): void {
		$record = $this->sftp();

		$this->assertTrue( $this->repository->delete( $record['id'] ) );
		$this->assertNull( $this->repository->get( $record['id'] ) );
		$this->assertSame( 404, $this->repository->delete( $record['id'] )->get_error_data()['status'] );
	}
}
