<?php
/**
 * Journal d'une exécution.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Écrit et relit le journal d'une exécution.
 *
 * Une ligne par message : heure UTC au format ISO 8601, niveau, texte, séparés
 * par des tabulations. Les avertissements et les erreurs sont comptés dans
 * l'exécution, pour l'état affiché dans l'interface.
 *
 * @since 0.1.0
 */
final class Logger {

	/**
	 * Niveaux reconnus.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	const LEVELS = array( 'info', 'warning', 'error' );

	/**
	 * Exécution journalisée.
	 *
	 * @since 0.1.0
	 * @var Run
	 */
	private Run $run;

	/**
	 * Fichier du journal.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $file;

	/**
	 * Construit le journal d'une exécution.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run      Exécution.
	 * @param string $logs_dir Dossier des journaux.
	 */
	public function __construct( Run $run, string $logs_dir ) {
		$this->run  = $run;
		$this->file = self::path( $run, $logs_dir );
	}

	/**
	 * Calcule le fichier du journal d'une exécution.
	 *
	 * Le jeton de l'exécution entre dans le nom du fichier : son adresse ne se
	 * devine pas, même si le dossier n'était pas protégé.
	 *
	 * @since 0.1.0
	 *
	 * @param Run    $run      Exécution.
	 * @param string $logs_dir Dossier des journaux.
	 * @return string Chemin absolu.
	 */
	public static function path( Run $run, string $logs_dir ): string {
		return untrailingslashit( $logs_dir ) . '/run-' . $run->id . '-' . $run->log_token . '.log';
	}

	/**
	 * Note une information.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message.
	 */
	public function info( string $message ): void {
		$this->write( 'info', $message );
	}

	/**
	 * Note un avertissement : la sauvegarde continue, mais il manquera quelque chose.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message.
	 */
	public function warning( string $message ): void {
		++$this->run->warnings;
		$this->write( 'warning', $message );
	}

	/**
	 * Note une erreur.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message.
	 */
	public function error( string $message ): void {
		++$this->run->errors;
		$this->write( 'error', $message );
	}

	/**
	 * Ajoute une ligne au journal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $level   Niveau.
	 * @param string $message Message, sur une ligne.
	 */
	private function write( string $level, string $message ): void {
		// Les messages des exceptions arrivent échappés pour le HTML : le journal garde le texte brut.
		$message = wp_specialchars_decode( $message, ENT_QUOTES );
		$line    = gmdate( 'Y-m-d\TH:i:s\Z' ) . "\t" . $level . "\t" . str_replace( array( "\r", "\n", "\t" ), ' ', $message ) . "\n";
		file_put_contents( $this->file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Relit un journal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Fichier du journal.
	 * @return array<int, array{time: string, level: string, message: string}> Entrées, dans l'ordre.
	 */
	public static function read( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$entries = array();
		foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$parts = explode( "\t", $line, 3 );
			if ( 3 === count( $parts ) && in_array( $parts[1], self::LEVELS, true ) ) {
				$entries[] = array(
					'time'    => $parts[0],
					'level'   => $parts[1],
					'message' => $parts[2],
				);
			}
		}

		return $entries;
	}
}
