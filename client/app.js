/**
 * Application d'administration : en-tête, menu des sections, section active.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SectionNav from './components/section-nav';
import Placeholder from './components/placeholder';
import SettingsScreen from './settings/settings-screen';
import { getSections, sectionFromHash } from './sections';

/**
 * Affiche l'interface et suit la section demandée dans l'adresse.
 *
 * @return {Element} Interface.
 */
export default function App() {
	const [ section, setSection ] = useState( () =>
		sectionFromHash( window.location.hash )
	);

	useEffect( () => {
		const onHashChange = () =>
			setSection( sectionFromHash( window.location.hash ) );
		window.addEventListener( 'hashchange', onHashChange );
		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	const current = getSections().find( ( item ) => item.id === section );

	return (
		<div className="oueb-app">
			<header className="oueb-app__header">
				<p className="oueb-app__title">Oueb WP Backup</p>
				<SectionNav current={ section } />
			</header>
			<main
				className="oueb-app__main"
				aria-labelledby="oueb-section-title"
			>
				<h1 id="oueb-section-title" className="oueb-app__heading">
					{ current.label }
				</h1>
				{ 'settings' === section ? (
					<SettingsScreen />
				) : (
					<Placeholder />
				) }
			</main>
			<footer className="oueb-app__footer">
				<p>
					{ __(
						'Oueb WP Backup, released under the GPL v2 or later.',
						'oueb-wp-backup'
					) }
				</p>
			</footer>
		</div>
	);
}
