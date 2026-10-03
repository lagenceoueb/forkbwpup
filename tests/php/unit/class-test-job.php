<?php
/**
 * Tests du modèle de tâche et de son enregistrement.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Job\Job_Repository;

/**
 * Vérifie la validation des tâches et la place de la tâche principale.
 */
final class Test_Job extends Test_Case {

	/**
	 * Simule le nettoyage des textes.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $text ) => strip_tags( (string) $text ) );
	}

	/**
	 * La tâche principale sauvegarde tout le contenu, sans le cœur de WordPress.
	 */
	public function test_main_defaults(): void {
		$job = Job::main()->to_array();

		$this->assertSame( 'main', $job['id'] );
		$this->assertTrue( $job['is_main'] );
		$this->assertTrue( $job['include_database'] );
		$this->assertTrue( $job['include_other_content'] );
		$this->assertFalse( $job['include_core'] );
		$this->assertSame( 'zip', $job['archive_format'] );
		$this->assertSame( 14, $job['keep'] );
	}

	/**
	 * Une valeur invalide ne modifie rien et nomme le champ en cause.
	 */
	public function test_invalid_change_is_atomic(): void {
		$job    = Job::main();
		$result = $job->apply(
			array(
				'keep'           => 30,
				'archive_format' => 'rar',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'archive_format', $result->get_error_data()['field'] );
		$this->assertSame( 14, $job->keep );
	}

	/**
	 * Une tâche doit sauvegarder au moins la base ou un dossier.
	 */
	public function test_empty_job_is_refused(): void {
		$result = Job::main()->apply(
			array(
				'include_database'      => false,
				'include_uploads'       => false,
				'include_themes'        => false,
				'include_plugins'       => false,
				'include_other_content' => false,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'oueb_wp_backup_empty_job', $result->get_error_code() );
	}

	/**
	 * Les motifs d'exclusion sont nettoyés, sans remontée de dossier ni doublon.
	 */
	public function test_exclude_patterns_are_cleaned(): void {
		$job = Job::main();
		$job->apply( array( 'exclude' => array( '/cache/', 'cache', '..\\etc', 'a\\b', '', 42 ) ) );

		$this->assertSame( array( 'cache', 'a/b' ), $job->exclude );
	}

	/**
	 * La durée de conservation accepte un nombre entre 1 et 365.
	 */
	public function test_keep_bounds(): void {
		$job = Job::main();

		$this->assertTrue( $job->apply( array( 'keep' => '30' ) ) );
		$this->assertSame( 30, $job->keep );
		$this->assertInstanceOf( \WP_Error::class, $job->apply( array( 'keep' => 0 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $job->apply( array( 'keep' => 366 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $job->apply( array( 'keep' => 1.5 ) ) );
	}

	/**
	 * Une option abîmée garde les valeurs valides et ignore le reste.
	 */
	public function test_from_array_tolerates_damage(): void {
		$this->assertNull( Job::from_array( array( 'id' => '../evil' ) ) );

		$job = Job::from_array(
			array(
				'id'             => 'job-a',
				'name'           => 'Nightly',
				'keep'           => 'many',
				'archive_format' => 'tar.gz',
			)
		);

		$this->assertSame( 'Nightly', $job->name );
		$this->assertSame( 14, $job->keep );
		$this->assertSame( 'tar.gz', $job->archive_format );
	}

	/**
	 * La tâche principale existe toujours, en premier, et ne se supprime pas.
	 */
	public function test_repository_keeps_main_first(): void {
		$jobs  = new Job_Repository();
		$extra = $jobs->create(
			array(
				'name'            => 'Database only',
				'include_uploads' => false,
			)
		);

		$this->assertInstanceOf( Job::class, $extra );
		$this->assertSame( array( 'main', $extra->id ), array_keys( $jobs->all() ) );
		$this->assertFalse( $jobs->get( $extra->id )->include_uploads );
		$this->assertInstanceOf( \WP_Error::class, $jobs->delete( 'main' ) );
		$this->assertTrue( $jobs->delete( $extra->id ) );
		$this->assertSame( array( 'main' ), array_keys( $jobs->all() ) );
	}

	/**
	 * Une tâche supplémentaire sans nom est refusée.
	 */
	public function test_repository_requires_a_name(): void {
		$this->assertInstanceOf( \WP_Error::class, ( new Job_Repository() )->create( array() ) );
		$this->assertArrayNotHasKey( Job_Repository::OPTION, $this->site_options );
	}
}
