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
import DashboardScreen from './dashboard/dashboard-screen';
import BackupsScreen from './backups/backups-screen';
import LogScreen from './log/log-screen';
import { getSections, paramFromHash, sectionFromHash } from './sections';

/**
 * Renvoie l'écran d'une section.
 *
 * @param {Object} props         Propriétés.
 * @param {string} props.section Section.
 * @param {string} props.param   Paramètre lu dans l'ancre.
 * @return {Element} Écran.
 */
function Screen( { section, param } ) {
	switch ( section ) {
		case 'dashboard':
			return <DashboardScreen />;
		case 'backups':
			return <BackupsScreen />;
		case 'log':
			return <LogScreen runId={ param } />;
		case 'settings':
			return <SettingsScreen />;
		default:
			return <Placeholder />;
	}
}

/**
 * Affiche l'interface et suit la section demandée dans l'adresse.
 *
 * @return {Element} Interface.
 */
export default function App() {
	const [ hash, setHash ] = useState( window.location.hash );

	useEffect( () => {
		const onHashChange = () => setHash( window.location.hash );
		window.addEventListener( 'hashchange', onHashChange );
		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	const section = sectionFromHash( hash );
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
				<Screen section={ section } param={ paramFromHash( hash ) } />
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
