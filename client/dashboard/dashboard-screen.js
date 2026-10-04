/**
 * Tableau de bord : état de la protection, sauvegarde immédiate, dernières sauvegardes.
 */

/**
 * WordPress dependencies
 */
import { Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { fetchJob, fetchRuns, fetchStorages } from '../api';
import { contentsLabel, formatDate } from '../format';
import RunsTable from '../components/runs-table';
import BackupNow from './backup-now';
import { describeSchedule } from '../schedule/cron';

const RECENT = 5;
const ACTIVE = [ 'queued', 'running' ];
const DONE = [ 'success', 'warning' ];

/**
 * Résume l'état de la protection d'après les dernières exécutions.
 *
 * @param {Array} runs Dernières exécutions, la plus récente d'abord.
 * @return {{tone: string, title: string, text: string}} Ton, titre et détail.
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
		};
	}
	return {
		tone: 'success',
		title: __( 'Your site is protected', 'oueb-wp-backup' ),
		text: sprintf(
			/* translators: %s: date of the last good backup. */
			__( 'Last backup: %s.', 'oueb-wp-backup' ),
			formatDate( good.started_at )
		),
	};
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
	const [ error, setError ] = useState( null );

	const load = useCallback( () => {
		fetchRuns( RECENT )
			.then( ( result ) => setRuns( result.runs ) )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	useEffect( () => {
		load();
		fetchJob()
			.then( setJob )
			.catch( ( e ) => setError( e.message ) );
		fetchStorages()
			.then( setStorages )
			.catch( ( e ) => setError( e.message ) );
	}, [ load ] );

	if ( error ) {
		return <Notice status="error">{ error }</Notice>;
	}
	if ( ! runs ) {
		return (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const summary = protectionSummary( runs );
	const active = runs.find( ( run ) => ACTIVE.includes( run.status ) );

	return (
		<>
			<section
				className={ `oueb-card oueb-card--status oueb-card--${ summary.tone }` }
				aria-labelledby="oueb-status-title"
			>
				<h2 id="oueb-status-title" className="oueb-card__title">
					{ summary.title }
				</h2>
				<p>{ summary.text }</p>
				<BackupNow activeRun={ active || null } onFinished={ load } />
			</section>

			<div className="oueb-grid">
				<section className="oueb-card" aria-labelledby="oueb-what">
					<h2 id="oueb-what" className="oueb-card__title">
						{ __( 'What', 'oueb-wp-backup' ) }
					</h2>
					<p>
						{ job
							? contentsLabel( {
									database: job.include_database,
									uploads: job.include_uploads,
									themes: job.include_themes,
									plugins: job.include_plugins,
									other_content: job.include_other_content,
									core: job.include_core,
							  } )
							: '' }
					</p>
				</section>
				<section className="oueb-card" aria-labelledby="oueb-where">
					<h2 id="oueb-where" className="oueb-card__title">
						{ __( 'Where', 'oueb-wp-backup' ) }
					</h2>
					<ul className="oueb-where">
						{ storages
							.filter( ( storage ) =>
								job?.storages.includes( storage.id )
							)
							.map( ( storage ) => (
								<li key={ storage.id }>{ storage.name }</li>
							) ) }
					</ul>
					<p>
						<a href="#/storage">
							{ __( 'Choose the storages', 'oueb-wp-backup' ) }
						</a>
					</p>
				</section>
				<section className="oueb-card" aria-labelledby="oueb-when">
					<h2 id="oueb-when" className="oueb-card__title">
						{ __( 'When', 'oueb-wp-backup' ) }
					</h2>
					<p>{ describeSchedule( job ) }</p>
					{ job?.next_run && (
						<p>
							{ sprintf(
								/* translators: %s: date and time. */
								__( 'Next backup: %s.', 'oueb-wp-backup' ),
								formatDate( job.next_run )
							) }
						</p>
					) }
					<p>
						<a href="#/schedule">
							{ __( 'Change the schedule', 'oueb-wp-backup' ) }
						</a>
					</p>
				</section>
			</div>

			<section className="oueb-card" aria-labelledby="oueb-recent">
				<h2 id="oueb-recent" className="oueb-card__title">
					{ __( 'Recent backups', 'oueb-wp-backup' ) }
				</h2>
				<RunsTable
					runs={ runs }
					caption={ __( 'Recent backups', 'oueb-wp-backup' ) }
				/>
				{ runs.length > 0 && (
					<p>
						<a href="#/backups">
							{ __( 'See all backups', 'oueb-wp-backup' ) }
						</a>
					</p>
				) }
			</section>
		</>
	);
}
