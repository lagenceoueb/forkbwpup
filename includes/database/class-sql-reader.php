<?php
/**
 * Lecture d'un fichier SQL, instruction par instruction.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Database;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Découpe un fichier SQL en instructions, sans le charger en mémoire.
 *
 * Le point-virgule ne termine une instruction qu'en dehors des chaînes, des
 * identifiants entre accents graves et des commentaires. Les commentaires de
 * ligne (« -- » et « # ») sont retirés. Les commentaires de bloc restent :
 * MySQL exécute ceux de la forme « /*!40101 … *\/ ».
 *
 * La commande DELIMITER des clients MySQL n'est pas reconnue.
 *
 * @since 0.1.0
 */
final class Sql_Reader {

	/**
	 * Octets lus à la fois.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const CHUNK = 1048576;

	/**
	 * Taille maximale d'une instruction.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_STATEMENT = 67108864;

	/**
	 * Fichier ouvert.
	 *
	 * @since 0.1.0
	 * @var resource
	 */
	private $handle;

	/**
	 * Octets lus et pas encore découpés.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Position, dans le fichier, du premier octet du tampon.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $buffer_offset;

	/**
	 * Fin du fichier atteinte.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $eof = false;

	/**
	 * Ouvre le fichier à une position.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path   Fichier SQL.
	 * @param int    $offset Position du début d'une instruction, renvoyée par offset().
	 *
	 * @throws RuntimeException Si le fichier ne s'ouvre pas.
	 */
	public function __construct( string $path, int $offset = 0 ) {
		$handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false;
		if ( false === $handle ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $path ) ) );
		}

		$this->handle        = $handle;
		$this->buffer_offset = max( 0, $offset );
		fseek( $this->handle, $this->buffer_offset );
	}

	/**
	 * Ferme le fichier.
	 *
	 * @since 0.1.0
	 */
	public function close(): void {
		fclose( $this->handle );
	}

	/**
	 * Renvoie la position qui suit la dernière instruction lue.
	 *
	 * @since 0.1.0
	 *
	 * @return int Position dans le fichier.
	 */
	public function offset(): int {
		return $this->buffer_offset;
	}

	/**
	 * Lit l'instruction suivante.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Instruction sans le point-virgule final, null à la fin du fichier.
	 *
	 * @throws RuntimeException Si une instruction dépasse la taille maximale.
	 */
	public function next(): ?string {
		$statement = '';
		$pos       = 0;
		$quote     = '';
		$comment   = '';

		while ( true ) {
			$length = strlen( $this->buffer );

			if ( $pos >= $length - 2 && ! $this->eof ) {
				// Deux octets d'avance suffisent pour décider d'un « -- » ou d'un « '' ».
				$this->fill();
				continue;
			}

			if ( $pos >= $length ) {
				$rest = trim( $statement . substr( $this->buffer, 0, $pos ) );
				$this->consume( $pos );
				return '' === $rest ? null : $rest;
			}

			if ( strlen( $statement ) + $pos > self::MAX_STATEMENT ) {
				throw new RuntimeException( esc_html__( 'The SQL file contains a statement that is too large to import.', 'oueb-wp-backup' ) );
			}

			if ( 'line' === $comment ) {
				$end = strpos( $this->buffer, "\n", $pos );
				if ( false === $end ) {
					$pos = $length;
					if ( $this->eof ) {
						$this->consume( $pos );
						$pos = 0;
					}
					continue;
				}
				// Le commentaire de ligne disparaît de l'instruction, pas son saut de ligne.
				$this->consume( $end );
				$pos     = 0;
				$comment = '';
				continue;
			}

			if ( 'block' === $comment ) {
				$end = strpos( $this->buffer, '*/', $pos );
				if ( false === $end ) {
					$pos = max( $pos, $length - 1 );
					if ( $this->eof ) {
						$pos = $length;
					}
					continue;
				}
				$pos     = $end + 2;
				$comment = '';
				continue;
			}

			if ( '' !== $quote ) {
				$stops = '`' === $quote ? '`' : $quote . '\\';
				$pos  += strcspn( $this->buffer, $stops, $pos );
				if ( $this->needs_more( $pos, $length ) ) {
					continue;
				}
				$char = $this->buffer[ $pos ];
				if ( '\\' === $char ) {
					$pos += 2;
				} elseif ( isset( $this->buffer[ $pos + 1 ] ) && $quote === $this->buffer[ $pos + 1 ] ) {
					$pos += 2;
				} else {
					++$pos;
					$quote = '';
				}
				continue;
			}

			$pos += strcspn( $this->buffer, "'\"`;-#/", $pos );
			if ( $this->needs_more( $pos, $length ) ) {
				continue;
			}

			$char = $this->buffer[ $pos ];
			$next = $this->buffer[ $pos + 1 ] ?? '';

			if ( ';' === $char ) {
				$statement .= substr( $this->buffer, 0, $pos );
				$this->consume( $pos + 1 );
				$statement = trim( $statement );
				if ( '' === $statement ) {
					$pos = 0;
					continue;
				}
				return $statement;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				++$pos;
			} elseif ( '#' === $char || ( '-' === $char && '-' === $next && ( '' === ( $this->buffer[ $pos + 2 ] ?? '' ) || ctype_space( $this->buffer[ $pos + 2 ] ) ) ) ) {
				$statement .= substr( $this->buffer, 0, $pos );
				$this->consume( $pos );
				$pos     = 0;
				$comment = 'line';
			} elseif ( '/' === $char && '*' === $next ) {
				$comment = 'block';
				$pos    += 2;
			} else {
				++$pos;
			}
		}
	}

	/**
	 * Renvoie la table visée par une instruction de structure ou de données.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement Instruction.
	 * @return string|null Nom de la table, null pour une autre instruction.
	 */
	public static function table_of( string $statement ): ?string {
		$pattern = '/^\s*(?:DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?|DROP\s+VIEW\s+(?:IF\s+EXISTS\s+)?|CREATE\s+(?:TABLE|(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW)\s+(?:IF\s+NOT\s+EXISTS\s+)?|INSERT\s+(?:IGNORE\s+)?INTO\s+|REPLACE\s+INTO\s+|LOCK\s+TABLES\s+|ALTER\s+TABLE\s+)(`(?:[^`]|``)+`|[\w$]+)/i';
		if ( ! preg_match( $pattern, $statement, $match ) ) {
			return null;
		}

		$name = $match[1];
		if ( '`' === $name[0] ) {
			$name = str_replace( '``', '`', substr( $name, 1, -1 ) );
		}

		return $name;
	}

	/**
	 * Indique si une instruction ouvre la section d'une table : sa suppression.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement Instruction.
	 * @return bool Vrai pour un DROP TABLE ou un DROP VIEW.
	 */
	public static function starts_table( string $statement ): bool {
		return 1 === preg_match( '/^\s*DROP\s+(TABLE|VIEW)\b/i', $statement );
	}

	/**
	 * Indique s'il faut lire la suite avant de décider : fin du tampon proche.
	 *
	 * @since 0.1.0
	 *
	 * @param int $pos    Position dans le tampon.
	 * @param int $length Longueur du tampon.
	 * @return bool Vrai s'il faut lire la suite.
	 */
	private function needs_more( int $pos, int $length ): bool {
		return $pos >= $length || ( $pos >= $length - 2 && ! $this->eof );
	}

	/**
	 * Ajoute des octets au tampon.
	 *
	 * @since 0.1.0
	 */
	private function fill(): void {
		$data = fread( $this->handle, self::CHUNK );
		if ( false === $data || '' === $data ) {
			$this->eof = true;
			return;
		}
		$this->buffer .= $data;
	}

	/**
	 * Retire le début du tampon.
	 *
	 * @since 0.1.0
	 *
	 * @param int $length Octets à retirer.
	 */
	private function consume( int $length ): void {
		$this->buffer         = (string) substr( $this->buffer, $length );
		$this->buffer_offset += $length;
	}
}
