/**
 * Règles de la restauration, sans interface : étapes, morceaux d'envoi, archives.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Étapes après lesquelles le site est modifié : la restauration ne s'arrête plus.
 */
const COMMITTED_STEPS = [
	'restore_maintenance',
	'restore_database',
	'restore_files',
	'restore_complete',
];

/**
 * Extensions d'archive acceptées.
 */
const EXTENSIONS = [ '.zip', '.tar.gz', '.tgz', '.tar' ];

/**
 * Renvoie le libellé d'une étape de restauration.
 *
 * Les étapes de la sauvegarde préalable portent les identifiants d'une
 * sauvegarde ordinaire : elles se lisent comme « Sauvegarde du site actuel ».
 *
 * @param {string} step Identifiant de l'étape.
 * @return {string} Libellé.
 */
export function restoreStepLabel( step ) {
	const labels = {
		restore_fetch: __( 'Getting the archive', 'oueb-wp-backup' ),
		restore_decrypt: __( 'Decrypting the archive', 'oueb-wp-backup' ),
		restore_check: __( 'Checking the archive', 'oueb-wp-backup' ),
		restore_safety: __( 'Backing up the current site', 'oueb-wp-backup' ),
		restore_maintenance: __( 'Maintenance mode', 'oueb-wp-backup' ),
		restore_database: __( 'Restoring the database', 'oueb-wp-backup' ),
		restore_files: __( 'Restoring the files', 'oueb-wp-backup' ),
		restore_complete: __( 'Ending the restore', 'oueb-wp-backup' ),
	};
	if ( labels[ step ] ) {
		return labels[ step ];
	}
	if ( [ 'database', 'files', 'manifest', 'archive' ].includes( step ) ) {
		return labels.restore_safety;
	}
	return __( 'Preparing', 'oueb-wp-backup' );
}

/**
 * Indique si la restauration modifie déjà le site.
 *
 * @param {Object} run Exécution de restauration.
 * @return {boolean} Vrai si elle ne peut plus être arrêtée.
 */
export function isCommitted( run ) {
	return Boolean(
		run &&
			( ( run.restore && run.restore.committed ) ||
				COMMITTED_STEPS.includes( run.step ) )
	);
}

/**
 * Indique si un nom de fichier désigne une archive que l'extension sait lire.
 *
 * @param {string} name Nom du fichier.
 * @return {boolean} Vrai pour zip, tar.gz, tgz et tar, chiffrés ou non.
 */
export function isSupportedArchive( name ) {
	const lower = String( name || '' )
		.toLowerCase()
		.replace( /\.enc$/, '' );
	return EXTENSIONS.some( ( extension ) => lower.endsWith( extension ) );
}

/**
 * Découpe un fichier en morceaux, à partir des octets déjà reçus.
 *
 * @param {number} size      Taille du fichier.
 * @param {number} chunkSize Taille d'un morceau.
 * @param {number} received  Octets déjà reçus par le serveur.
 * @return {Array<{start: number, end: number}>} Morceaux restants, fin exclue.
 */
export function chunksFrom( size, chunkSize, received = 0 ) {
	const chunks = [];
	const step = Math.max( 1, Number( chunkSize ) || 1 );
	for ( let start = Math.max( 0, received ); start < size; start += step ) {
		chunks.push( { start, end: Math.min( size, start + step ) } );
	}
	return chunks;
}

/**
 * Garde les sauvegardes dont l'archive est encore disponible.
 *
 * @param {Array} runs Exécutions.
 * @return {Array} Sauvegardes restaurables.
 */
export function restorableRuns( runs ) {
	return ( runs || [] ).filter(
		( run ) =>
			run.kind !== 'restore' &&
			run.archive_file &&
			run.download_url &&
			! [ 'queued', 'running' ].includes( run.status )
	);
}
