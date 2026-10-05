<?php
/**
 * Tests de l'import des tâches de BackWPup.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Engine\Steps\File_List;
use Oueb\WpBackup\Job\Job_Repository;
use Oueb\WpBackup\Legacy\Legacy_Import;
use Oueb\WpBackup\Legacy\Legacy_Secret;
use Oueb\WpBackup\Storage\Storage_Repository;
use Oueb\WpBackup\Storage\Workspace;

/**
 * Secrets chiffrés par le vrai code de BackWPup, conversion des tâches et rapport.
 */
final class Test_Legacy_Import extends Test_Case {

	/**
	 * Valeurs chiffrées par BackWPup 4.1.7, avec DB_NAME « wp72 », DB_USER et DB_PASSWORD « wp ».
	 *
	 * La valeur OpenSSL vient d'un serveur où BackWPup a choisi aes-128-cbc,
	 * faute de trouver « AES-256-CTR » en majuscules dans la liste d'OpenSSL 3.
	 *
	 * @var array<string, string>
	 */
	const SAMPLES = array(
		'openssl'  => '$BackWPup$OSSL$vqvaEMupRc7ca5sQOkacCEv+MM+2xLKCo76BTzhg1GQ=',
		'fallback' => '$BackWPup$ENC1$0NSrV8fIWaeWqqbKgUXR+A==',
		'custom'   => '$BackWPup$OSSL$$0A0yItBXyT7v7iHvezF5VY8Gg++cCG/G9lH5cutMD7SA=',
	);

	/**
	 * Définit les accès à la base qui servent de clé, et simule WordPress.
	 */
	protected function setUp(): void {
		parent::setUp();
		foreach ( array(
			'DB_NAME'        => 'wp72',
			'DB_USER'        => 'wp',
			'DB_PASSWORD'    => 'wp',
			'WP_CONTENT_DIR' => '/srv/www/wp-content',
			'WP_PLUGIN_DIR'  => '/srv/www/wp-content/plugins',
		) as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		Functions\when( 'untrailingslashit' )->alias( fn( $path ) => rtrim( (string) $path, '/\\' ) );
		Functions\when( 'wp_normalize_path' )->alias( fn( $path ) => str_replace( '\\', '/', (string) $path ) );
		Functions\when( 'sanitize_text_field' )->alias( fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_email' )->alias( fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'path_is_absolute' )->alias( fn( $path ) => '/' === substr( (string) $path, 0, 1 ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_theme_root' )->justReturn( '/srv/www/wp-content/themes' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/srv/www/wp-content/uploads' ) );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Les trois formes de BackWPup se déchiffrent ; une mauvaise clé donne null.
	 */
	public function test_secrets_written_by_backwpup(): void {
		$this->assertSame( 'secret-s3-é', Legacy_Secret::decrypt( self::SAMPLES['openssl'] ) );
		$this->assertSame( 'mot de passe ✓', Legacy_Secret::decrypt( self::SAMPLES['fallback'] ) );
		$this->assertSame( 'avec cle perso', Legacy_Secret::decrypt( self::SAMPLES['custom'], 'ma-cle-perso' ) );
		$this->assertNull( Legacy_Secret::decrypt( self::SAMPLES['custom'], 'mauvaise cle' ) );
		$this->assertSame( 'en clair', Legacy_Secret::decrypt( 'en clair' ) );
		$this->assertNull( Legacy_Secret::decrypt( '$BackWPup$XXXX$abc' ) );
	}

	/**
	 * Les exclusions et dossiers de BackWPup deviennent des motifs depuis la racine.
	 */
	public function test_exclusions_and_paths(): void {
		$prefixes = File_List::archive_prefixes();
		$this->assertSame(
			array( '*.tmp*', '*.log', ltrim( $prefixes['core'] . '/logs', '/' ), $prefixes['plugins'] . '/backwpup', $prefixes['uploads'] . '/cache' ),
			Legacy_Import::exclusions(
				array(
					'fileexclude'              => '.tmp, *.log,',
					'backupuploadsexcludedirs' => array( 'cache', '../etc' ),
					'backuppluginsexcludedirs' => array( 'backwpup' ),
					'backuprootexcludedirs'    => array( 'logs' ),
				)
			)
		);
		$this->assertSame( '/srv/www/wp-content/uploads/backwpup-x-backups', Legacy_Import::absolute_path( 'uploads/backwpup-x-backups/' ) );
		$this->assertSame( '/home/site/backups', Legacy_Import::absolute_path( '/home/site/backups/' ) );
		$this->assertSame( '/srv/www/wp-content', Legacy_Import::absolute_path( '' ) );
	}

	/**
	 * L'import crée les tâches et un stockage par destination, une seule fois, sans toucher BackWPup.
	 */
	public function test_run_imports_jobs_and_reports(): void {
		$jobs = array(
			1 => array(
				'name'          => 'Quotidienne',
				'type'          => array( 'DBDUMP', 'FILE' ),
				'destinations'  => array( 'S3', 'FTP' ),
				'activetype'    => 'wpcron',
				'cron'          => '30 2 * * *',
				'archiveformat' => '.tar.bz2',
				's3region'      => 'scaleway-fr-par',
				's3accesskey'   => 'KEY',
				's3secretkey'   => self::SAMPLES['openssl'],
				's3bucket'      => 'seau',
				's3dir'         => 'site/',
				's3maxbackups'  => 7,
				'backuproot'    => false,
				'backupcontent' => true,
				'backupplugins' => true,
				'backupthemes'  => true,
				'backupuploads' => true,
			),
			2 => array(
				'name'          => 'Base',
				'type'          => array( 'DBDUMP' ),
				'destinations'  => array( 'S3', 'DROPBOX' ),
				'activetype'    => 'easycron',
				'cron'          => 'pas une expression',
				'archiveformat' => '.zip',
				's3region'      => 'scaleway-fr-par',
				's3accesskey'   => 'KEY',
				's3secretkey'   => self::SAMPLES['openssl'],
				's3bucket'      => 'seau',
				's3dir'         => 'site/',
				's3maxbackups'  => 3,
			),
			3 => array(
				'name'         => 'Vérification seule',
				'type'         => array( 'DBCHECK' ),
				'destinations' => array(),
			),
			4 => array(
				'name'         => 'Dropbox',
				'type'         => array( 'DBDUMP' ),
				'destinations' => array( 'DROPBOX' ),
				'activetype'   => 'wpcron',
				'cron'         => '0 4 * * *',
			),
		);
		$this->site_options['backwpup_jobs']                    = $jobs;
		$this->site_options['backwpup_cfg_jobmaxexecutiontime'] = 45;
		$this->site_options['oueb_cronjob_org_key']             = 'cle-cronjob';

		$storages = new Storage_Repository( new Workspace( '/srv/www/wp-content/uploads/oueb-wp-backup-x' ) );
		$repo     = new Job_Repository();
		$import   = new Legacy_Import( $repo, $storages );

		$summary = $import->summary();
		$this->assertTrue( $summary['available'] );
		$this->assertSame( array( 'S3' ), $summary['jobs'][0]['supported'] );
		$this->assertSame( array( 'FTP' ), $summary['jobs'][0]['unsupported'] );

		$report = $import->run();

		// Le même S3 sert aux deux tâches : un seul stockage.
		$s3 = array_values( array_filter( $storages->all(), fn( $record ) => 's3' === $record['type'] ) );
		$this->assertCount( 1, $s3 );
		$settings = $storages->settings( $s3[0] );
		$this->assertSame( 'scaleway', $settings['provider'] );
		$this->assertSame( 'fr-par', $settings['region'] );
		$this->assertSame( 'site', $settings['folder'] );
		$this->assertSame( 'secret-s3-é', $settings['secret_key'] );

		$daily = $repo->get( $report[0]['job_id'] );
		$this->assertSame( 'Quotidienne', $daily->name );
		$this->assertSame( 'wpcron', $daily->trigger );
		$this->assertSame( '30 2 * * *', $daily->schedule );
		$this->assertSame( 'tar.gz', $daily->archive_format );
		$this->assertSame( 7, $daily->keep );
		$this->assertFalse( $daily->include_core );
		$this->assertTrue( $daily->include_uploads );
		$this->assertCount( 2, $report[0]['notes'] );

		$database = $repo->get( $report[1]['job_id'] );
		$this->assertSame( 'manual', $database->trigger );
		$this->assertSame( '0 3 * * *', $database->schedule );
		$this->assertFalse( $database->include_uploads );
		$this->assertSame( $daily->storages, $database->storages );

		$this->assertSame( '', $report[2]['job_id'] );

		// Sans stockage repris, la tâche ne part pas seule et garde ses archives ici.
		$dropbox = $repo->get( $report[3]['job_id'] );
		$this->assertSame( array( 'local' ), $dropbox->storages );
		$this->assertSame( 'manual', $dropbox->trigger );

		$this->assertSame( array( 'max_execution_time', 'cronjob_org_key' ), $report[4]['settings'] );
		$this->assertSame( 'done', $import->summary()['status'] );

		// BackWPup n'a pas changé.
		$this->assertSame( $jobs, $this->site_options['backwpup_jobs'] );
	}
}
