/**
 * Tableau de bord : état de la protection, sauvegarde immédiate, quoi, où,
 * quand, dernières sauvegardes et encart de l'agence.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import {
	fetchJob,
	fetchRuns,
	fetchSettings,
	fetchStorages,
	saveSettings,
} from '../api';
import { formatDate } from '../format';
import RunsTable from '../components/runs-table';
import BackupNow from './backup-now';
import ImportNotice from './import-notice';
import AgencyCard from './agency-card';
import { describeSchedule } from '../schedule/cron';
import { contentSummary, joinList } from '../content/content';

const RECENT = 5;
const ACTIVE = [ 'queued', 'running' ];
const DONE = [ 'success', 'warning' ];

/**
 * Types de stockage hébergés par un fournisseur européen retenu.
 */
const EUROPEAN = [ 's3', 'kdrive' ];

/**
 * Résume l'état de la protection d'après les dernières exécutions.
 *
 * @param {Array} runs Dernières exécutions, la plus récente d'abord.
 * @return {{tone: string, title: string, text: string, last: Object|null}} Ton, titre, détail et dernière bonne sauvegarde.
 */
export function protectionSummary( runs ) {
	const good = runs.find( ( run ) => DONE.includes( run.status ) );

	if ( ! good ) {
		return {
			tone: 'warning',
			title: __( 'Your site has no backup yet', 'oueb-wp-backup' ),
			text: __(
				'Start a first backup now. It takes a few minutes.',
				'oueb-wp-backup'
			),
			last: null,
		};
	}
	// Une sauvegarde arrêtée à la main n'est pas un échec.
	const last = runs.find(
		( run ) => ! [ ...ACTIVE, 'aborted' ].includes( run.status )
	);
	if ( last && ! DONE.includes( last.status ) ) {
		return {
			tone: 'error',
			title: __( 'The last backup failed', 'oueb-wp-backup' ),
			text: sprintf(
				/* translators: %s: date of the last good backup. */
				__(
					'The last good backup dates from %s. Open the log to see what went wrong.',
					'oueb-wp-backup'
				),
				formatDate( good.started_at )
			),
			last,
		};
	}
	const names = good.storage_names || [];
	return {
		tone: 'success',
		title: __( 'Your site is protected', 'oueb-wp-backup' ),
		text:
			names.length > 0
				? sprintf(
						/* translators: 1: date of the last good backup, 2: storage names. */
						__(
							'Last backup: %1$s, sent to %2$s.',
							'oueb-wp-backup'
						),
						formatDate( good.started_at ),
						joinList( names )
				  )
				: sprintf(
						/* translators: %s: date of the last good backup. */
						__( 'Last backup: %s.', 'oueb-wp-backup' ),
						formatDate( good.started_at )
				  ),
		last: good,
	};
}

/**
 * Icône de l'état, décorative.
 *
 * @param {Object} props      Propriétés.
 * @param {string} props.tone Ton : success, warning ou error.
 * @return {Element} SVG.
 */
function StatusIcon( { tone } ) {
	const paths = {
		success: [
			'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
			'M9 12l2 2 4-4',
		],
		warning: [
			'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
			'M12 8v4',
			'M12 16h.01',
		],
		error: [
			'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z',
			'M15 9l-6 6',
			'M9 9l6 6',
		],
	};
	return (
		<span className={ `oueb-status-icon oueb-status-icon--${ tone }` }>
			<svg
				aria-hidden="true"
				focusable="false"
				width="34"
				height="34"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				{ paths[ tone ].map( ( d ) => (
					<path key={ d } d={ d } />
				) ) }
			</svg>
		</span>
	);
}

/**
 * Affiche le tableau de bord.
 *
 * @return {Element} Tableau de bord.
 */
export default function DashboardScreen() {
	const [ runs, setRuns ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ storages, setStorages ] = useState( [] );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = useCallback( () => {
		fetchRuns( RECENT, 1, 'backup' )
			.then( ( result ) => setRuns( result.runs ) )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	useEffect( () => {
		load();
		Promise.all( [ fetchJob(), fetchStorages(), fetchSettings() ] )
			.then( ( [ main, list, values ] ) => {
				setJob( main );
				setStorages( list );
				setSettings( values );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ load ] );

	if ( error ) {
		return <Notice status="error">{ error }</Notice>;
	}
	if ( ! runs || ! job || ! settings ) {
		return (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const summary = protectionSummary( runs );
	const active = runs.find( ( run ) => ACTIVE.includes( run.status ) );
	const what = contentSummary( job );
	const chosen = storages.filter( ( storage ) =>
		job.storages.includes( storage.id )
	);
	const [ first, ...others ] = chosen;

	const skipSetup = () => {
		saveSettings( { setup_done: true } )
			.then( setSettings )
			.catch( ( e ) => setError( e.message ) );
	};
	const hideAgency = () => {
		saveSettings( { show_agency_card: false } )
			.then( ( values ) => {
				setSettings( values );
				speak(
					__(
						'Card hidden. You can show it again in the settings.',
						'oueb-wp-backup'
					)
				);
			} )
			.catch( ( e ) => setError( e.message ) );
	};

	return (
		<>
			<ImportNotice />

			{ ! settings.setup_done && ! summary.last && (
				<section
					className="oueb-card oueb-setup-prompt"
					aria-labelledby="oueb-setup-title"
				>
					<h2 id="oueb-setup-title" className="oueb-card__title">
						{ __(
							'Set up your backups in four steps',
							'oueb-wp-backup'
						) }
					</h2>
					<p>
						{ __(
							'Content, storage, frequency, then a first backup to check that everything works. It takes about five minutes.',
							'oueb-wp-backup'
						) }
					</p>
					<div className="oueb-form__actions">
						<a
							className="components-button is-primary"
							href="#/setup"
						>
							{ __( 'Start the setup', 'oueb-wp-backup' ) }
						</a>
						<Button variant="tertiary" onClick={ skipSetup }>
							{ __(
								'I will set it up myself',
								'oueb-wp-backup'
							) }
						</Button>
					</div>
				</section>
			) }

			<section
				className={ `oueb-card oueb-hero oueb-card--${ summary.tone }` }
				aria-labelledby="oueb-status-title"
			>
				<StatusIcon tone={ summary.tone } />
				<div className="oueb-hero__text">
					<h2 id="oueb-status-title" className="oueb-hero__title">
						{ summary.title }
					</h2>
					<p className="oueb-hero__lead">{ summary.text }</p>
					{ job.next_run && (
						<p className="oueb-hero__next">
							{ sprintf(
								/* translators: %s: date and time. */
								__( 'Next backup: %s.', 'oueb-wp-backup' ),
								formatDate( job.next_run )
							) }
						</p>
					) }
				</div>
				<div className="oueb-hero__actions">
					<BackupNow
						activeRun={ active || null }
						onFinished={ load }
					/>
					{ summary.last && (
						<a href={ `#/log/${ summary.last.id }` }>
							{ __(
								'See the log of the last backup',
								'oueb-wp-backup'
							) }
						</a>
					) }
				</div>
			</section>

			<div className="oueb-grid">
				<section
					className="oueb-card oueb-card--fact"
					aria-labelledby="oueb-what"
				>
					<h2 id="oueb-what" className="oueb-card__label">
						{ __( 'What is backed up', 'oueb-wp-backup' ) }
					</h2>
					<ul className="oueb-facts">
						{ what.saved.map( ( line ) => (
							<li key={ line } className="oueb-facts__yes">
								{ line }
							</li>
						) ) }
						<li className="oueb-facts__no">{ what.excluded }</li>
					</ul>
					<a href="#/content" className="oueb-card__link">
						{ __( 'Change the content', 'oueb-wp-backup' ) }
					</a>
				</section>

				<section
					className="oueb-card oueb-card--fact"
					aria-labelledby="oueb-where"
				>
					<h2 id="oueb-where" className="oueb-card__label">
						{ __( 'Where', 'oueb-wp-backup' ) }
					</h2>
					<p className="oueb-card__value">
						{ first
							? first.name
							: __( 'Nowhere yet', 'oueb-wp-backup' ) }
					</p>
					{ first && first.description && (
						<p className="oueb-card__muted">
							{ first.description }
						</p>
					) }
					{ first && EUROPEAN.includes( first.type ) && (
						<ul className="oueb-tags">
							<li className="oueb-tag">
								{ __( 'Europe', 'oueb-wp-backup' ) }
							</li>
							<li className="oueb-tag">
								{ __(
									'Renewable electricity',
									'oueb-wp-backup'
								) }
							</li>
						</ul>
					) }
					<p className="oueb-card__muted">
						{ others.length > 0
							? sprintf(
									/* translators: %s: storage names. */
									_n(
										'Additional copy: %s',
										'Additional copies: %s',
										others.length,
										'oueb-wp-backup'
									),
									joinList(
										others.map( ( item ) => item.name )
									)
							  )
							: __( 'Additional copy: none', 'oueb-wp-backup' ) }
					</p>
					<a href="#/storage" className="oueb-card__link">
						{ __( 'Change the storage', 'oueb-wp-backup' ) }
					</a>
				</section>

				<section
					className="oueb-card oueb-card--fact"
					aria-labelledby="oueb-when"
				>
					<h2 id="oueb-when" className="oueb-card__label">
						{ __( 'When', 'oueb-wp-backup' ) }
					</h2>
					<p className="oueb-card__value">
						{ describeSchedule( job ) }
					</p>
					<p className="oueb-card__muted">
						{ job.keep === 1
							? __(
									'Only the last backup is kept. Older ones are deleted automatically.',
									'oueb-wp-backup'
							  )
							: sprintf(
									/* translators: %d: number of backups kept. */
									__(
										'The last %d backups are kept. Older ones are deleted automatically.',
										'oueb-wp-backup'
									),
									job.keep
							  ) }
					</p>
					<a href="#/schedule" className="oueb-card__link">
						{ __( 'Change the schedule', 'oueb-wp-backup' ) }
					</a>
				</section>
			</div>

			<section className="oueb-card" aria-labelledby="oueb-recent">
				<div className="oueb-card__head">
					<h2 id="oueb-recent" className="oueb-card__title">
						{ __( 'Recent backups', 'oueb-wp-backup' ) }
					</h2>
					{ runs.length > 0 && (
						<a href="#/backups">
							{ __( 'See all backups', 'oueb-wp-backup' ) }
						</a>
					) }
				</div>
				<RunsTable
					runs={ runs }
					caption={ __( 'Recent backups', 'oueb-wp-backup' ) }
				/>
			</section>

			{ settings.show_agency_card && (
				<AgencyCard onHide={ hideAgency } />
			) }
		</>
	);
}
