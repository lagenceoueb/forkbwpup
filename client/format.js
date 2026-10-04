/**
 * Mise en forme des données affichées : tailles, dates, états, contenus.
 */

/**
 * WordPress dependencies
 */
import { dateI18n, getSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Écrit une taille en octets de façon lisible.
 *
 * @param {number} bytes  Taille en octets.
 * @param {string} locale Langue pour les nombres.
 * @return {string} Taille, par exemple « 412 Mo ».
 */
export function formatSize( bytes, locale = document.documentElement.lang ) {
	let value = Math.max( 0, Number( bytes ) || 0 );
	let unit = 0;
	while ( value >= 1024 && unit < 3 ) {
		value /= 1024;
		unit++;
	}
	const number = new Intl.NumberFormat( locale || undefined, {
		maximumFractionDigits: value < 10 && unit > 0 ? 1 : 0,
	} ).format( value );

	switch ( unit ) {
		case 0:
			/* translators: %s: size in bytes. */
			return sprintf( __( '%s B', 'oueb-wp-backup' ), number );
		case 1:
			/* translators: %s: size in kilobytes. */
			return sprintf( __( '%s KB', 'oueb-wp-backup' ), number );
		case 2:
			/* translators: %s: size in megabytes. */
			return sprintf( __( '%s MB', 'oueb-wp-backup' ), number );
		default:
			/* translators: %s: size in gigabytes. */
			return sprintf( __( '%s GB', 'oueb-wp-backup' ), number );
	}
}

/**
 * Écrit une date ISO 8601 selon les réglages du site.
 *
 * @param {string} iso Date ISO 8601.
 * @return {string} Date et heure.
 */
export function formatDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	const { formats } = getSettings();
	return dateI18n( formats.datetime, iso );
}

/**
 * Renvoie le libellé d'un état d'exécution.
 *
 * @param {Object} run Exécution.
 * @return {string} Libellé.
 */
export function statusLabel( run ) {
	switch ( run.status ) {
		case 'queued':
			return __( 'Waiting', 'oueb-wp-backup' );
		case 'running':
			return __( 'Running', 'oueb-wp-backup' );
		case 'success':
			return __( 'Successful', 'oueb-wp-backup' );
		case 'warning':
			return sprintf(
				/* translators: %d: number of warnings. */
				_n(
					'%d warning',
					'%d warnings',
					run.warnings,
					'oueb-wp-backup'
				),
				run.warnings
			);
		case 'aborted':
			return __( 'Stopped', 'oueb-wp-backup' );
		default:
			return __( 'Failed', 'oueb-wp-backup' );
	}
}

/**
 * Résume le contenu d'une sauvegarde.
 *
 * @param {Object} contents Contenu : database, uploads, themes, plugins, other_content, core.
 * @return {string} Résumé.
 */
export function contentsLabel( contents = {} ) {
	const files = [
		'uploads',
		'themes',
		'plugins',
		'other_content',
		'core',
	].filter( ( key ) => contents[ key ] );
	if ( contents.database && files.length >= 4 ) {
		return __( 'Full site', 'oueb-wp-backup' );
	}
	if ( contents.database && files.length === 0 ) {
		return __( 'Database only', 'oueb-wp-backup' );
	}
	if ( ! contents.database ) {
		return __( 'Files only', 'oueb-wp-backup' );
	}
	return __( 'Database and some files', 'oueb-wp-backup' );
}

/**
 * Renvoie le libellé d'une étape.
 *
 * @param {string} step Identifiant de l'étape.
 * @return {string} Libellé.
 */
export function stepLabel( step ) {
	const labels = {
		database: __( 'Exporting the database', 'oueb-wp-backup' ),
		files: __( 'Listing the files', 'oueb-wp-backup' ),
		manifest: __( 'Describing the backup', 'oueb-wp-backup' ),
		archive: __( 'Creating the archive', 'oueb-wp-backup' ),
		finish: __( 'Storing the archive', 'oueb-wp-backup' ),
	};
	return labels[ step ] || __( 'Preparing', 'oueb-wp-backup' );
}

/**
 * Écrit l'heure d'une date ISO 8601, à la seconde près.
 *
 * @param {string} iso Date ISO 8601.
 * @return {string} Heure.
 */
export function formatTime( iso ) {
	return iso ? dateI18n( 'H:i:s', iso ) : '';
}
