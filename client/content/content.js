/**
 * Contenu d'une sauvegarde : parties du site, exclusions, résumé.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Parties du site qu'une tâche peut sauvegarder, dans l'ordre d'affichage.
 *
 * @return {Array<{key: string, label: string, help: string}>} Parties : champ de la tâche, libellé, aide.
 */
export function contentParts() {
	return [
		{
			key: 'include_database',
			label: __( 'Database', 'oueb-wp-backup' ),
			help: __(
				'Content, comments, users and settings.',
				'oueb-wp-backup'
			),
		},
		{
			key: 'include_uploads',
			label: __( 'Media', 'oueb-wp-backup' ),
			help: __( 'Images and files added to the site.', 'oueb-wp-backup' ),
		},
		{
			key: 'include_themes',
			label: __( 'Themes', 'oueb-wp-backup' ),
			help: '',
		},
		{
			key: 'include_plugins',
			label: __( 'Extensions', 'oueb-wp-backup' ),
			help: '',
		},
		{
			key: 'include_other_content',
			label: __( 'Other files of wp-content', 'oueb-wp-backup' ),
			help: __(
				'Translations, extension data and files outside the folders above.',
				'oueb-wp-backup'
			),
		},
		{
			key: 'include_core',
			label: __( 'WordPress files', 'oueb-wp-backup' ),
			help: __(
				'Rarely useful: WordPress can be downloaded again. wp-config.php is included.',
				'oueb-wp-backup'
			),
		},
	];
}

/**
 * Joint des libellés en une liste lisible : « a, b et c ».
 *
 * @param {string[]} items Libellés.
 * @return {string} Liste.
 */
export function joinList( items ) {
	if ( items.length <= 1 ) {
		return items.join( '' );
	}
	return sprintf(
		/* translators: 1: comma-separated items, 2: last item. */
		__( '%1$s and %2$s', 'oueb-wp-backup' ),
		items.slice( 0, -1 ).join( ', ' ),
		items[ items.length - 1 ]
	);
}

/**
 * Résume ce qu'une tâche sauvegarde, pour le tableau de bord.
 *
 * @param {Object} job Tâche.
 * @return {{saved: string[], excluded: string}} Lignes sauvegardées, exclusions résumées.
 */
export function contentSummary( job ) {
	if ( ! job ) {
		return { saved: [], excluded: '' };
	}
	const parts = contentParts();
	const saved = [];
	if ( job.include_database ) {
		saved.push( parts[ 0 ].label );
	}
	const files = parts
		.slice( 1 )
		.filter( ( part ) => job[ part.key ] )
		.map( ( part, index ) =>
			index === 0 ? part.label : part.label.toLowerCase()
		);
	if ( files.length > 0 ) {
		saved.push( joinList( files ) );
	}

	const count = ( job.exclude || [] ).length;
	const excluded =
		count > 0
			? sprintf(
					/* translators: %d: number of exclusion rules. */
					__(
						'Excluded: cache, backups of other extensions and %d rule of yours',
						'oueb-wp-backup'
					),
					count
			  )
			: __(
					'Excluded: cache and backups of other extensions',
					'oueb-wp-backup'
			  );

	return { saved, excluded };
}

/**
 * Lit les exclusions saisies, une par ligne.
 *
 * @param {string} text Texte saisi.
 * @return {string[]} Motifs, sans doublon ni ligne vide.
 */
export function parseExclusions( text ) {
	const seen = new Set();
	return String( text || '' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim().replace( /^\/+|\/+$/g, '' ) )
		.filter( ( line ) => {
			if ( ! line || seen.has( line ) ) {
				return false;
			}
			seen.add( line );
			return true;
		} );
}

/**
 * Indique si une tâche sauvegarde au moins quelque chose.
 *
 * @param {Object} job Tâche, ou brouillon de tâche.
 * @return {boolean} Vrai si la base ou une partie des fichiers est cochée.
 */
export function hasContent( job ) {
	return contentParts().some( ( part ) => Boolean( job && job[ part.key ] ) );
}
