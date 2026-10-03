<?php
/**
 * Tests du contexte d'exécution des étapes.
 *
 * @package Oueb_WP_Backup
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Tests;

use Brain\Monkey\Functions;
use Oueb\WpBackup\Engine\Deadline;
use Oueb\WpBackup\Engine\Logger;
use Oueb\WpBackup\Engine\Run;
use Oueb\WpBackup\Engine\Run_Context;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Storage\Workspace;

/**
 * Vérifie l'état par étape, les points de reprise et la demande d'arrêt.
 */
final class Test_Run_Context extends Test_Case {

	/**
	 * Crée un contexte sur une exécution neuve.
	 *
	 * @return Run_Context Contexte.
	 */
	private function context(): Run_Context {
		Functions\when( 'untrailingslashit' )->alias( fn( $path ) => rtrim( $path, '/\\' ) );
		$run     = new Run();
		$run->id = 7;

		return new Run_Context( $run, Job::main(), new Workspace( sys_get_temp_dir() ), new Logger( $run, sys_get_temp_dir() ), new Deadline( 30 ) );
	}

	/**
	 * Chaque étape lit et écrit son propre état.
	 */
	public function test_state_is_per_step(): void {
		$context = $this->context();
		$context->for_step( 'database' );
		$context->set( 'rows', 10 );
		$context->progress( 1.7 );
		$context->for_step( 'files' );

		$this->assertNull( $context->get( 'rows' ) );
		$this->assertSame( 3, $context->get( 'rows', 3 ) );
		$this->assertSame( 10, $context->run->state['steps']['database']['rows'] );
		$this->assertSame( 1.0, $context->run->state['steps']['database']['_progress'] );
	}

	/**
	 * Le point de reprise n'enregistre pas plus d'une fois toutes les deux secondes.
	 */
	public function test_checkpoint_is_throttled(): void {
		$context = $this->context();
		$saves   = 0;
		$context->checkpoint();
		$context->save_with(
			function () use ( &$saves ) {
				++$saves;
			}
		);

		$context->checkpoint();
		$context->checkpoint();

		$this->assertSame( 0, $saves );
	}

	/**
	 * La demande d'arrêt est lue une fois, puis gardée en mémoire.
	 */
	public function test_abort_is_cached(): void {
		$context = $this->context();
		$this->assertFalse( $context->abort_requested() );

		$calls = 0;
		$context->watch_abort(
			function ( $id ) use ( &$calls ) {
				++$calls;
				return 7 === $id;
			}
		);

		$this->assertTrue( $context->abort_requested() );
		$this->assertTrue( $context->abort_requested() );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Entre deux étapes, la base est relue sans attendre.
	 */
	public function test_fresh_abort_check(): void {
		$context = $this->context();
		$asked   = false;
		$calls   = 0;
		$context->watch_abort(
			function () use ( &$asked, &$calls ) {
				++$calls;
				return $asked;
			}
		);

		$this->assertFalse( $context->abort_requested() );
		$asked = true;
		$this->assertFalse( $context->abort_requested(), 'Throttled read keeps the old answer.' );
		$this->assertTrue( $context->abort_requested( true ) );
		$this->assertSame( 2, $calls );
	}

	/**
	 * Une étape rend la main dès que l'arrêt est demandé.
	 */
	public function test_should_pause_on_abort(): void {
		$context = $this->context();
		$this->assertFalse( $context->should_pause() );

		$context->watch_abort( fn() => true );
		$this->assertTrue( $context->should_pause() );
	}
}
