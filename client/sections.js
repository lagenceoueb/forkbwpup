/**
 * Sections de l'interface, dans l'ordre du menu.
 */

/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';

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
		{
			id: 'restore',
			label: _x( 'Restore', 'section of the admin', 'oueb-wp-backup' ),
		},
		{ id: 'log', label: __( 'Log', 'oueb-wp-backup' ) },
		{ id: 'settings', label: __( 'Settings', 'oueb-wp-backup' ) },
	];
}

/**
 * Renvoie les pages hors du menu, rattachées à une section du menu.
 *
 * @return {Array<{id: string, label: string, parent: string}>} Pages : identifiant, titre, section du menu.
 */
export function getPages() {
	return [
		{
			id: 'content',
			label: __( 'Content of the backup', 'oueb-wp-backup' ),
			parent: 'dashboard',
		},
		{
			id: 'setup',
			label: __( 'Setup', 'oueb-wp-backup' ),
			parent: 'dashboard',
		},
		{
			id: 'jobs',
			label: __( 'Additional backups', 'oueb-wp-backup' ),
			parent: 'settings',
		},
	];
}

/**
 * Renvoie une section ou une page par son identifiant.
 *
 * @param {string} id Identifiant.
 * @return {{id: string, label: string, parent?: string}|undefined} Section ou page.
 */
export function findRoute( id ) {
	return [ ...getSections(), ...getPages() ].find(
		( item ) => item.id === id
	);
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
	const id = String( hash || '' )
		.replace( /^#\/?/, '' )
		.split( '/' )[ 0 ];
	return findRoute( id ) ? id : 'dashboard';
}

/**
 * Lit le paramètre qui suit la section dans l'ancre.
 *
 * L'ancre « #/log/12 » ouvre le journal de l'exécution 12.
 *
 * @param {string} hash Ancre de l'adresse.
 * @return {string} Paramètre, vide s'il n'y en a pas.
 */
export function paramFromHash( hash ) {
	return String( hash || '' )
		.replace( /^#\/?/, '' )
		.split( '/' )
		.slice( 1 )
		.join( '/' );
}
