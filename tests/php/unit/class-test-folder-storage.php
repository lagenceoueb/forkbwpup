<?php
/**
 * Tests du stockage dans un dossier et de l'étape d'envoi.
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
use Oueb\WpBackup\Engine\Step_Failure;
use Oueb\WpBackup\Engine\Steps\Store;
use Oueb\WpBackup\Job\Job;
use Oueb\WpBackup\Storage\Folder_Storage;
use Oueb\WpBackup\Storage\Storage;
use Oueb\WpBackup\Storage\Storage_Repository;
use Oueb\WpBackup\Storage\Transfer;
use Oueb\WpBackup\Storage\Workspace;
use RuntimeException;

/**
 * Copie, reprise, rotation et répartition entre stockages.
 */
final class Test_Folder_Storage extends Test_Case {

	/**
	 * Dossier de travail du test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Prépare un dossier temporaire et les fonctions de fichiers.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/oueb-folder-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );

		Functions\when( 'untrailingslashit' )->alias( fn( $path ) => rtrim( $path, '/\\' ) );
		Functions\when( 'wp_normalize_path' )->alias( fn( $path ) => str_replace( '\\', '/', (string) $path ) );
		Functions\when( 'wp_mkdir_p' )->alias( fn( $dir ) => is_dir( $dir ) || mkdir( $dir, 0777, true ) );
		Functions\when( 'wp_delete_file' )->alias( fn( $file ) => is_file( $file ) && unlink( $file ) );
		Functions\when( 'wp_specialchars_decode' )->alias( fn( $text ) => html_entity_decode( (string) $text, ENT_QUOTES ) );
		Functions\when( 'home_url' )->justReturn( 'https://exemple.fr' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * Supprime le dossier temporaire.
	 */
	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tearDown();
	}

	/**
	 * Crée un fichier de données aléatoires.
	 *
	 * @param int $size Taille.
	 * @return string Chemin.
	 */
	private function source( int $size ): string {
		$file = $this->dir . '/source.zip';
		file_put_contents( $file, random_bytes( $size ) );

		return $file;
	}

	/**
	 * La copie reprend là où elle s'est arrêtée et donne un fichier identique.
	 */
	public function test_copy_resumes_after_pause(): void {
		$file     = $this->source( Folder_Storage::CHUNK * 2 + 1000 );
		$storage  = new Folder_Storage( array( 'path' => $this->dir . '/copies' ) );
		$transfer = new class( null, 'folder' ) extends Transfer {
			/**
			 * Demande une pause après chaque morceau.
			 *
			 * @return bool Toujours vrai.
			 */
			public function should_pause(): bool {
				return true;
			}
		};

		$passes = 1;
		while ( ! $storage->upload( $file, 'exemple-fr_main_2026-10-04_120000.zip', $transfer ) ) {
			++$passes;
		}

		$this->assertSame( 3, $passes );
		$this->assertFileEquals( $file, $this->dir . '/copies/exemple-fr_main_2026-10-04_120000.zip' );
		$this->assertFileDoesNotExist( $this->dir . '/copies/exemple-fr_main_2026-10-04_120000.zip.part' );
		$this->assertFileExists( $this->dir . '/copies/.htaccess' );
		$this->assertContains( 'exemple-fr_main_2026-10-04_120000.zip', array_column( $storage->files(), 'name' ) );
		$this->assertNotContains( '.htaccess', array_column( $storage->files(), 'name' ) );
		$this->assertSame( file_get_contents( $file, false, null, 10, 20 ), $storage->read( 'exemple-fr_main_2026-10-04_120000.zip', 10, 20 ) );
	}

	/**
	 * Quand l'archive locale ne sert plus, elle est déplacée.
	 */
	public function test_move_when_allowed(): void {
		$file    = $this->source( 1000 );
		$storage = new Folder_Storage( array( 'path' => $this->dir . '/archives' ) );

		$this->assertTrue( $storage->upload( $file, 'a.zip', new Transfer( null, 'local', true ) ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertFileExists( $this->dir . '/archives/a.zip' );
	}

	/**
	 * Un stockage en échec est retenté, puis abandonné ; les autres reçoivent l'archive.
	 */
	public function test_store_skips_a_failing_storage(): void {
		$context = $this->context( array( 'broken', 'local' ) );
		$store   = new Store( $this->repository( array( 'broken' => $this->broken() ) ), 2 );

		$this->assertFalse( $store->run( $context ), 'First failure: retried at the next pass.' );
		$this->assertTrue( $store->run( $context ) );

		$this->assertSame( array( 'local' ), $context->get( 'stored' ) );
		$this->assertSame( 2, $context->run->errors + $context->run->warnings );
		$this->assertFileExists( $this->dir . '/workspace/archives/' . $context->get( 'name' ) );
		$this->assertMatchesRegularExpression( '/^exemple-fr_main_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $context->get( 'name' ) );
	}

	/**
	 * Si aucun stockage ne reçoit l'archive, l'exécution échoue sans nouvel essai.
	 */
	public function test_store_fails_when_nothing_is_stored(): void {
		$context = $this->context( array( 'broken' ) );
		$store   = new Store( $this->repository( array( 'broken' => $this->broken() ) ), 1 );

		$this->expectException( Step_Failure::class );
		$store->run( $context );
	}

	/**
	 * Construit un contexte d'exécution avec une archive prête.
	 *
	 * @param string[] $storages Stockages de la tâche.
	 * @return Run_Context Contexte.
	 */
	private function context( array $storages ): Run_Context {
		$run             = new Run();
		$run->id         = 3;
		$run->started_at = time();
		$job             = Job::main();
		$job->storages   = $storages;
		$workspace       = new Workspace( $this->dir . '/workspace' );
		mkdir( $this->dir . '/workspace/tmp/run-3', 0777, true );
		file_put_contents( $this->dir . '/workspace/tmp/run-3/archive.zip', random_bytes( 2000 ) );

		$context = new Run_Context( $run, $job, $workspace, new Logger( $run, $this->dir ), new Deadline( 30 ) );
		$context->for_step( 'store' );

		return $context;
	}

	/**
	 * Construit un dépôt qui renvoie des stockages simulés, en plus du local.
	 *
	 * @param array<string, Storage> $fakes Stockages simulés, par identifiant.
	 * @return Storage_Repository Dépôt.
	 */
	private function repository( array $fakes ): Storage_Repository {
		return new class( new Workspace( $this->dir . '/workspace' ), $fakes ) extends Storage_Repository {
			/**
			 * Stockages simulés.
			 *
			 * @var array<string, Storage>
			 */
			private array $fakes;

			/**
			 * Crée le dépôt.
			 *
			 * @param Workspace              $workspace Dossiers de travail.
			 * @param array<string, Storage> $fakes     Stockages simulés.
			 */
			public function __construct( Workspace $workspace, array $fakes ) {
				parent::__construct( $workspace );
				$this->fakes = $fakes;
			}

			/**
			 * Renvoie un enregistrement.
			 *
			 * @param string $id Identifiant.
			 * @return array<string, mixed>|null Enregistrement.
			 */
			public function get( string $id ): ?array {
				return isset( $this->fakes[ $id ] ) ? array( 'name' => $id ) : parent::get( $id );
			}

			/**
			 * Renvoie un stockage.
			 *
			 * @param string $id Identifiant.
			 * @return Storage|null Stockage.
			 */
			public function instance( string $id ): ?Storage {
				return $this->fakes[ $id ] ?? parent::instance( $id );
			}
		};
	}

	/**
	 * Construit un stockage qui échoue toujours.
	 *
	 * @return Storage Stockage.
	 */
	private function broken(): Storage {
		return new class() implements Storage {
			/**
			 * Échoue.
			 *
			 * @param string   $file     Archive.
			 * @param string   $name     Nom.
			 * @param Transfer $transfer État.
			 * @return bool Faux, pour un fichier vide.
			 *
			 * @throws RuntimeException Pour tout fichier.
			 */
			public function upload( string $file, string $name, Transfer $transfer ): bool {
				if ( '' !== $file ) {
					throw new RuntimeException( 'Service indisponible' );
				}
				return false;
			}

			/**
			 * Liste vide.
			 *
			 * @return array<int, array{name: string, size: int, time: int}> Fichiers.
			 */
			public function files(): array {
				return array();
			}

			/**
			 * Lecture vide.
			 *
			 * @param string $name   Nom.
			 * @param int    $offset Position.
			 * @param int    $length Longueur.
			 * @return string Octets.
			 */
			public function read( string $name, int $offset, int $length ): string {
				return '';
			}

			/**
			 * Suppression sans effet.
			 *
			 * @param string $name Nom.
			 */
			public function delete( string $name ): void {
			}

			/**
			 * Test sans effet.
			 *
			 * @return string Compte rendu.
			 */
			public function test(): string {
				return '';
			}
		};
	}
}
