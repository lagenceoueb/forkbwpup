<?php
/**
 * Génère languages/oueb-wp-backup.pot, le modèle de traduction du fork.
 *
 * Usage : php bin/make-pot.php
 *
 * Relève les appels __(), _e(), esc_html__(), _n(), _x() et apparentés dont le
 * domaine est « oueb-wp-backup », avec leurs commentaires « translators: ».
 * Mettez ensuite le .po français à jour depuis ce modèle avec Poedit
 * (Traduction > Mettre à jour depuis un fichier POT), qui recompile le .mo.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// Rôle de chaque argument : s = texte, p = pluriel, c = contexte, null = ignoré.
$oueb_functions = array(
	'__'         => array( 's' ),
	'_e'         => array( 's' ),
	'esc_html__' => array( 's' ),
	'esc_html_e' => array( 's' ),
	'esc_attr__' => array( 's' ),
	'esc_attr_e' => array( 's' ),
	'_x'         => array( 's', 'c' ),
	'_ex'        => array( 's', 'c' ),
	'esc_html_x' => array( 's', 'c' ),
	'esc_attr_x' => array( 's', 'c' ),
	'_n'         => array( 's', 'p', null ),
	'_nx'        => array( 's', 'p', null, 'c' ),
	'_n_noop'    => array( 's', 'p' ),
	'_nx_noop'   => array( 's', 'p', 'c' ),
);

/**
 * Renvoie la valeur d'un argument s'il est une chaîne littérale, null sinon.
 *
 * @param array $tokens Jetons de l'argument, sans espaces ni commentaires.
 * @return string|null Valeur de la chaîne.
 */
function oueb_pot_literal( $tokens ) {
	if ( 1 !== count( $tokens ) || ! is_array( $tokens[0] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[0][0] ) {
		return null;
	}

	$raw = $tokens[0][1];
	if ( "'" === $raw[0] ) {
		return strtr(
			substr( $raw, 1, -1 ),
			array(
				"\\'"   => "'",
				'\\\\' => '\\',
			)
		);
	}

	return stripcslashes( substr( $raw, 1, -1 ) );
}

/**
 * Encode une chaîne au format PO.
 *
 * @param string $text Texte.
 * @return string Texte entre guillemets, caractères spéciaux échappés.
 */
function oueb_pot_quote( $text ) {
	return '"' . strtr(
		$text,
		array(
			'\\' => '\\\\',
			'"'  => '\\"',
			"\n" => '\\n',
		)
	) . '"';
}

/**
 * Relève les textes à traduire et écrit le modèle .pot.
 *
 * @param string $root      Dossier racine de l'extension.
 * @param string $domain    Domaine de traduction.
 * @param array  $functions Fonctions de traduction et rôle de leurs arguments.
 * @return int Code de sortie : 0 si tout est lu, 1 si un texte n'est pas littéral.
 */
function oueb_make_pot( $root, $domain, $functions ) {
	$files = array( $root . '/backwpup.php' );
	foreach ( array( 'inc', 'src', 'views' ) as $dir ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
	}
	sort( $files );

	$entries = array();
	$errors  = 0;
	foreach ( $files as $file ) {
		$source = file_get_contents( $file );
		if ( false === strpos( $source, $domain ) ) {
			continue;
		}

		$relative = substr( $file, strlen( $root ) + 1 );
		$tokens   = token_get_all( $source );
		$count    = count( $tokens );
		$comment  = '';
		$c_line   = -10;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) && T_COMMENT === $token[0] && false !== stripos( $token[1], 'translators' ) ) {
				$comment = trim( preg_replace( '#^/\*+|\*+/$|^//#', '', $token[1] ) );
				$c_line  = $token[2] + substr_count( $token[1], "\n" );
			}

			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
				continue;
			}

			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( '(' !== $tokens[ $j ] ) {
				continue;
			}

			// Découpe les arguments de premier niveau.
			$depth = 0;
			$args  = array( array() );
			for ( $k = $j; $k < $count; $k++ ) {
				$part = $tokens[ $k ];
				if ( '(' === $part || '[' === $part ) {
					++$depth;
					if ( 1 === $depth && '(' === $part ) {
						continue;
					}
				}
				if ( ')' === $part || ']' === $part ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
				if ( ',' === $part && 1 === $depth ) {
					$args[] = array();
					continue;
				}
				if ( is_array( $part ) && in_array( $part[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
					continue;
				}
				$args[ count( $args ) - 1 ][] = $part;
			}

			if ( oueb_pot_literal( end( $args ) ) !== $domain ) {
				continue;
			}

			$entry = array(
				's' => null,
				'p' => null,
				'c' => null,
			);
			foreach ( $functions[ $token[1] ] as $index => $role ) {
				if ( null !== $role ) {
					$entry[ $role ] = oueb_pot_literal( isset( $args[ $index ] ) ? $args[ $index ] : array() );
				}
			}

			if ( null === $entry['s'] ) {
				fwrite( STDERR, "Texte non littéral, ignoré : {$relative}:{$token[2]}\n" );
				++$errors;
				continue;
			}

			$key = (string) $entry['c'] . "\4" . $entry['s'];
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = $entry + array(
					'refs'     => array(),
					'comments' => array(),
				);
			}
			$entries[ $key ]['refs'][] = $relative . ':' . $token[2];
			if ( '' !== $comment && $token[2] - $c_line <= 3 && ! in_array( $comment, $entries[ $key ]['comments'], true ) ) {
				$entries[ $key ]['comments'][] = $comment;
			}
		}
	}

	$lines = array(
		'# Modèle de traduction d\'Oueb WP Backup, généré par bin/make-pot.php.',
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: Oueb WP Backup\n"',
		'"MIME-Version: 1.0\n"',
		'"Content-Type: text/plain; charset=UTF-8\n"',
		'"Content-Transfer-Encoding: 8bit\n"',
		'"X-Domain: ' . $domain . '\n"',
		'',
	);
	foreach ( $entries as $entry ) {
		foreach ( $entry['comments'] as $line ) {
			$lines[] = '#. ' . $line;
		}
		$lines[] = '#: ' . implode( ' ', $entry['refs'] );
		if ( false !== strpos( $entry['s'], '%' ) ) {
			$lines[] = '#, php-format';
		}
		if ( null !== $entry['c'] ) {
			$lines[] = 'msgctxt ' . oueb_pot_quote( $entry['c'] );
		}
		$lines[] = 'msgid ' . oueb_pot_quote( $entry['s'] );
		if ( null !== $entry['p'] ) {
			$lines[] = 'msgid_plural ' . oueb_pot_quote( $entry['p'] );
			$lines[] = 'msgstr[0] ""';
			$lines[] = 'msgstr[1] ""';
		} else {
			$lines[] = 'msgstr ""';
		}
		$lines[] = '';
	}

	file_put_contents( $root . '/languages/' . $domain . '.pot', implode( "\n", $lines ) );

	fwrite( STDOUT, count( $entries ) . " textes écrits dans languages/{$domain}.pot.\n" );

	return $errors > 0 ? 1 : 0;
}

if ( 0 !== oueb_make_pot( dirname( __DIR__ ), 'oueb-wp-backup', $oueb_functions ) ) {
	exit( 1 );
}
