<?php
/**
 * Lecture d'une archive tar ou tar.gz.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Archive;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Lit une archive tar, compressée en gzip ou non, sans jamais la charger en mémoire.
 *
 * Un fichier gzip peut contenir plusieurs membres à la suite : l'écrivain de
 * l'extension en ferme un à chaque lot validé. Le point de reprise note le
 * début du membre où commence l'entrée, et le nombre d'octets décompressés à
 * sauter dans ce membre. Une reprise ne décompresse donc qu'un membre au plus
 * pour retrouver sa place, même dans une archive d'un seul membre faite ailleurs.
 *
 * Formats lus : ustar, noms longs GNU (type L) et en-têtes pax (type x).
 *
 * @since 0.1.0
 */
final class Tar_Reader implements Archive_Reader {

	/**
	 * Taille d'un bloc tar.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const BLOCK = 512;

	/**
	 * Octets compressés lus à la fois. Petit, car 32 Kio de zéros compressés
	 * donnent déjà 32 Mio une fois décompressés.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const INPUT_CHUNK = 32768;

	/**
	 * Octets lus à la fois dans une archive non compressée.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const PLAIN_CHUNK = 1048576;

	/**
	 * Archive compressée en gzip.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $gzip;

	/**
	 * Fichier ouvert.
	 *
	 * @since 0.1.0
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Contexte de décompression du membre en cours.
	 *
	 * @since 0.1.0
	 * @var \InflateContext|resource|null
	 */
	private $inflate = null;

	/**
	 * Octets compressés donnés au contexte en cours.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $fed = 0;

	/**
	 * Début, dans le fichier, du membre en cours de décompression.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $member_start = 0;

	/**
	 * Membres rencontrés : début dans le fichier et premier octet décompressé.
	 *
	 * @since 0.1.0
	 * @var array<int, array{0: int, 1: int}>
	 */
	private array $members = array();

	/**
	 * Octets décompressés pas encore consommés.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Octets décompressés consommés depuis l'ouverture.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $taken = 0;

	/**
	 * Fin du fichier atteinte.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $ended = false;

	/**
	 * Octets de données restant dans l'entrée en cours.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $left = 0;

	/**
	 * Octets de bourrage après les données de l'entrée en cours.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private int $padding = 0;

	/**
	 * Point de reprise de l'entrée en cours.
	 *
	 * @since 0.1.0
	 * @var array<string, int>
	 */
	private array $entry_position = array(
		'member' => 0,
		'skip'   => 0,
	);

	/**
	 * Crée le lecteur.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $gzip Vrai pour une archive tar.gz.
	 */
	public function __construct( bool $gzip ) {
		$this->gzip = $gzip;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string             $path     Chemin de l'archive.
	 * @param array<string, int> $position Point de reprise.
	 *
	 * @throws RuntimeException Si l'archive ne s'ouvre pas.
	 */
	public function open( string $path, array $position ): void {
		$handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false;
		if ( false === $handle ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read %s.', 'oueb-wp-backup' ), $path ) ) );
		}

		$this->handle       = $handle;
		$this->member_start = max( 0, (int) ( $position['member'] ?? 0 ) );
		$this->members      = array( array( $this->member_start, 0 ) );
		$this->buffer       = '';
		$this->taken        = 0;
		$this->ended        = false;
		$this->left         = 0;
		$this->padding      = 0;
		$this->inflate      = null;
		$this->fed          = 0;
		fseek( $this->handle, $this->member_start );

		$this->discard( max( 0, (int) ( $position['skip'] ?? 0 ) ) );
		$this->entry_position = $this->here();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @throws RuntimeException Si l'archive est abîmée.
	 */
	public function next(): ?array {
		$this->discard( $this->left + $this->padding );
		$this->left    = 0;
		$this->padding = 0;

		$long_name = null;
		$position  = null;
		while ( true ) {
			if ( null === $position ) {
				$position = $this->here();
			}

			$header = $this->take( self::BLOCK );
			if ( '' === $header || str_repeat( "\0", self::BLOCK ) === $header ) {
				return null;
			}
			if ( self::BLOCK !== strlen( $header ) ) {
				throw new RuntimeException( esc_html__( 'The archive is truncated.', 'oueb-wp-backup' ) );
			}
			if ( ! self::checksum_ok( $header ) ) {
				throw new RuntimeException( esc_html__( 'The archive is damaged: a file header is invalid.', 'oueb-wp-backup' ) );
			}

			$type = $header[156];
			$size = self::size( substr( $header, 124, 12 ) );
			$pad  = ( self::BLOCK - $size % self::BLOCK ) % self::BLOCK;

			// Nom long GNU, ou en-tête pax : ils décrivent l'entrée qui suit.
			if ( 'L' === $type || 'x' === $type || 'g' === $type ) {
				$data = $this->take( $size );
				$this->discard( $pad );
				if ( 'L' === $type ) {
					$long_name = rtrim( $data, "\0" );
				} elseif ( 'x' === $type ) {
					$path = self::pax_path( $data );
					if ( null !== $path ) {
						$long_name = $path;
					}
				}
				continue;
			}

			$name = self::field( substr( $header, 0, 100 ) );
			if ( "ustar\0" === substr( $header, 257, 6 ) ) {
				$prefix = self::field( substr( $header, 345, 155 ) );
				if ( '' !== $prefix ) {
					$name = $prefix . '/' . $name;
				}
			}
			if ( null !== $long_name ) {
				$name = $long_name;
			}

			if ( '0' === $type || "\0" === $type || '7' === $type ) {
				$kind = 'file';
			} elseif ( '5' === $type ) {
				$kind = 'dir';
			} else {
				$kind = 'other';
			}

			$this->left           = $size;
			$this->padding        = $pad;
			$this->entry_position = $position;

			return array(
				'name'  => $name,
				'type'  => $kind,
				'size'  => $size,
				'mtime' => (int) octdec( self::field( substr( $header, 136, 12 ) ) ),
			);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param int $length Nombre d'octets au plus.
	 *
	 * @throws RuntimeException Si l'archive est tronquée.
	 */
	public function read( int $length ): string {
		if ( $this->left <= 0 ) {
			return '';
		}

		$data = $this->take( (int) min( $length, $this->left ) );
		if ( '' === $data ) {
			throw new RuntimeException( esc_html__( 'The archive is truncated.', 'oueb-wp-backup' ) );
		}
		$this->left -= strlen( $data );

		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function position(): array {
		return $this->entry_position;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle );
		}
		$this->handle  = null;
		$this->inflate = null;
		$this->buffer  = '';
	}

	/**
	 * Calcule le point de reprise de l'octet décompressé courant.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Début du membre et octets à y sauter.
	 */
	private function here(): array {
		if ( ! $this->gzip ) {
			return array(
				'member' => $this->member_start + $this->taken,
				'skip'   => 0,
			);
		}

		$found = $this->members[0];
		foreach ( $this->members as $member ) {
			if ( $member[1] <= $this->taken ) {
				$found = $member;
			}
		}

		return array(
			'member' => $found[0],
			'skip'   => $this->taken - $found[1],
		);
	}

	/**
	 * Consomme des octets décompressés.
	 *
	 * @since 0.1.0
	 *
	 * @param int $length Nombre d'octets.
	 * @return string Octets, moins si l'archive se termine avant.
	 */
	private function take( int $length ): string {
		if ( $length <= 0 ) {
			return '';
		}

		$filled = strlen( $this->buffer ) >= $length;
		while ( ! $filled && $this->fill() ) {
			$filled = strlen( $this->buffer ) >= $length;
		}

		$data          = (string) substr( $this->buffer, 0, $length );
		$this->buffer  = (string) substr( $this->buffer, strlen( $data ) );
		$this->taken  += strlen( $data );

		return $data;
	}

	/**
	 * Saute des octets décompressés, par morceaux.
	 *
	 * @since 0.1.0
	 *
	 * @param int $length Nombre d'octets.
	 *
	 * @throws RuntimeException Si l'archive est tronquée.
	 */
	private function discard( int $length ): void {
		while ( $length > 0 ) {
			$data = $this->take( (int) min( $length, self::PLAIN_CHUNK ) );
			if ( '' === $data ) {
				throw new RuntimeException( esc_html__( 'The archive is truncated.', 'oueb-wp-backup' ) );
			}
			$length -= strlen( $data );
		}
	}

	/**
	 * Ajoute des octets décompressés au tampon.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Faux à la fin de l'archive.
	 *
	 * @throws RuntimeException Si la décompression échoue.
	 */
	private function fill(): bool {
		if ( $this->ended || null === $this->handle ) {
			return false;
		}

		if ( ! $this->gzip ) {
			$data = (string) fread( $this->handle, self::PLAIN_CHUNK );
			if ( '' === $data ) {
				$this->ended = true;
				return false;
			}
			$this->buffer .= $data;
			return true;
		}

		$input = (string) fread( $this->handle, self::INPUT_CHUNK );
		if ( '' === $input ) {
			$this->ended = true;
			if ( null !== $this->inflate ) {
				throw new RuntimeException( esc_html__( 'The archive is truncated.', 'oueb-wp-backup' ) );
			}
			return false;
		}

		while ( '' !== $input ) {
			if ( null === $this->inflate ) {
				// Après le dernier membre, des zéros de bourrage ou rien d'autre.
				if ( "\x1f\x8b" !== substr( $input, 0, 2 ) ) {
					$this->ended = true;
					return '' !== $this->buffer;
				}
				$this->inflate = inflate_init( ZLIB_ENCODING_GZIP );
				$this->fed     = 0;
			}

			$output = inflate_add( $this->inflate, $input, ZLIB_SYNC_FLUSH );
			if ( false === $output ) {
				throw new RuntimeException( esc_html__( 'The archive is damaged: it cannot be decompressed.', 'oueb-wp-backup' ) );
			}
			$this->fed    += strlen( $input );
			$this->buffer .= $output;

			if ( ZLIB_STREAM_END !== inflate_get_status( $this->inflate ) ) {
				break;
			}

			// Fin du membre : ce qui reste de l'entrée commence le membre suivant.
			$used                = (int) inflate_get_read_len( $this->inflate );
			$rest                = $this->fed - $used;
			$input               = $rest > 0 ? (string) substr( $input, -$rest ) : '';
			$this->member_start += $used;
			$this->members[]     = array( $this->member_start, $this->taken + strlen( $this->buffer ) );
			$this->inflate       = null;

			// Seuls les membres encore utiles à un point de reprise sont gardés.
			while ( isset( $this->members[1] ) && $this->members[1][1] <= $this->taken ) {
				array_shift( $this->members );
			}
		}

		return true;
	}

	/**
	 * Vérifie la somme de contrôle d'un en-tête.
	 *
	 * @since 0.1.0
	 *
	 * @param string $header En-tête de 512 octets.
	 * @return bool Vrai si elle est juste.
	 */
	private static function checksum_ok( string $header ): bool {
		$expected = (int) octdec( self::field( substr( $header, 148, 8 ) ) );
		$blank    = substr_replace( $header, '        ', 148, 8 );
		$sum      = 0;
		for ( $i = 0; $i < self::BLOCK; $i++ ) {
			$sum += ord( $blank[ $i ] );
		}

		return $sum === $expected;
	}

	/**
	 * Lit la taille d'une entrée : octale, ou binaire au-delà de 8 Gio.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Champ de 12 octets.
	 * @return int Taille.
	 */
	private static function size( string $field ): int {
		if ( ord( $field[0] ) & 0x80 ) {
			$size = 0;
			for ( $i = 1; $i < 12; $i++ ) {
				$size = ( $size << 8 ) | ord( $field[ $i ] );
			}
			return $size;
		}

		return (int) octdec( self::field( $field ) );
	}

	/**
	 * Lit un champ texte : jusqu'au premier octet nul, sans espaces autour.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Champ.
	 * @return string Valeur.
	 */
	private static function field( string $field ): string {
		$end = strpos( $field, "\0" );

		return trim( false === $end ? $field : substr( $field, 0, $end ) );
	}

	/**
	 * Lit le chemin d'un en-tête pax.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data Enregistrements « longueur clé=valeur\n ».
	 * @return string|null Chemin, null s'il n'y en a pas.
	 */
	private static function pax_path( string $data ): ?string {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			$space = strpos( $data, ' ', $offset );
			if ( false === $space ) {
				break;
			}
			$size = (int) substr( $data, $offset, $space - $offset );
			if ( $size <= 0 ) {
				break;
			}
			$record = substr( $data, $space + 1, $size - ( $space - $offset ) - 2 );
			if ( 0 === strpos( $record, 'path=' ) ) {
				return substr( $record, 5 );
			}
			$offset += $size;
		}

		return null;
	}
}
