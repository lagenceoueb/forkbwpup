/**
 * Point d'entrée de l'interface d'administration d'Oueb WP Backup.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './app';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'oueb-wp-backup-root' );
	if ( container ) {
		createRoot( container ).render( <App /> );
	}
} );
