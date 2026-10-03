/**
 * Sections de l'interface, dans l'ordre du menu.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Renvoie les sections de l'interface.
 *
 * @return {Array<{id: string, label: string}>} Sections, identifiant et libellé.
 */
export function getSections() {
	return [
		{ id: 'dashboard', label: __( 'Dashboard', 'oueb-wp-backup' ) },
		{ id: 'backups', label: __( 'Backups', 'oueb-wp-backup' ) },
		{ id: 'storage', label: __( 'Storage', 'oueb-wp-backup' ) },
		{ id: 'schedule', label: __( 'Schedule', 'oueb-wp-backup' ) },
		{ id: 'log', label: __( 'Log', 'oueb-wp-backup' ) },
		{ id: 'settings', label: __( 'Settings', 'oueb-wp-backup' ) },
	];
}

/**
 * Lit la section demandée dans l'ancre de l'adresse.
 *
 * L'ancre « #/settings » ouvre les réglages. Une ancre absente ou inconnue
 * ouvre le tableau de bord.
 *
 * @param {string} hash Ancre de l'adresse, par exemple « #/settings ».
 * @return {string} Identifiant de la section.
 */
export function sectionFromHash( hash ) {
	const id = String( hash || '' ).replace( /^#\/?/, '' );
	return getSections().some( ( section ) => section.id === id )
		? id
		: 'dashboard';
}
