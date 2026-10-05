/**
 * Application d'administration : en-tête, menu des sections, section active.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SectionNav from './components/section-nav';
import Placeholder from './components/placeholder';
import SettingsScreen from './settings/settings-screen';
import DashboardScreen from './dashboard/dashboard-screen';
import BackupsScreen from './backups/backups-screen';
import LogScreen from './log/log-screen';
import StorageScreen from './storage/storage-screen';
import ScheduleScreen from './schedule/schedule-screen';
import RestoreScreen from './restore/restore-screen';
import ContentScreen from './content/content-screen';
import JobsScreen from './jobs/jobs-screen';
import SetupScreen from './setup/setup-screen';
import { findRoute, paramFromHash, sectionFromHash } from './sections';

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
		case 'storage':
			return <StorageScreen />;
		case 'schedule':
			return <ScheduleScreen />;
		case 'restore':
			return <RestoreScreen runId={ param } />;
		case 'content':
			return <ContentScreen />;
		case 'jobs':
			return <JobsScreen jobId={ param } />;
		case 'setup':
			return <SetupScreen />;
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
	const current = findRoute( section );

	return (
		<div className="oueb-app">
			<header className="oueb-app__header">
				<p className="oueb-app__title">Oueb WP Backup</p>
				<SectionNav current={ current.parent || section } />
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
					{ sprintf(
						/* translators: %s: version number. */
						__(
							'Oueb WP Backup %s, fork of BackWPup 4.1.7, released under the GPL v2 or later.',
							'oueb-wp-backup'
						),
						( window.ouebWpBackup &&
							window.ouebWpBackup.version ) ||
							''
					) }{ ' ' }
					<a href="https://github.com/lagenceoueb/oueb-wp-backup">
						{ __( 'Source code and origin', 'oueb-wp-backup' ) }
					</a>
				</p>
				<p>{ __( 'Made by L’agence Oueb', 'oueb-wp-backup' ) }</p>
			</footer>
		</div>
	);
}
