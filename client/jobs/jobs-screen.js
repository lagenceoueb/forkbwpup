/**
 * Tâches supplémentaires, en mode avancé.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import {
	createJob,
	deleteJob,
	fetchJobs,
	fetchRuns,
	fetchStorages,
	saveJob,
	startRun,
} from '../api';
import { contentsLabel, formatDate, statusLabel } from '../format';
import { describeSchedule } from '../schedule/cron';
import ContentScreen from '../content/content-screen';
import ScheduleScreen from '../schedule/schedule-screen';
import JobStorages from './job-storages';

/**
 * Résume le contenu d'une tâche.
 *
 * @param {Object} job Tâche.
 * @return {string} Résumé.
 */
function jobContents( job ) {
	return contentsLabel( {
		database: job.include_database,
		uploads: job.include_uploads,
		themes: job.include_themes,
		plugins: job.include_plugins,
		other_content: job.include_other_content,
		core: job.include_core,
	} );
}

/**
 * Affiche une tâche supplémentaire et ses réglages.
 *
 * @param {Object} props       Propriétés.
 * @param {string} props.jobId Tâche.
 * @return {Element} Page de la tâche.
 */
function JobScreen( { jobId } ) {
	const [ job, setJob ] = useState( null );
	const [ storages, setStorages ] = useState( [] );
	const [ name, setName ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ removing, setRemoving ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		Promise.all( [ fetchJobs(), fetchStorages() ] )
			.then( ( [ jobs, list ] ) => {
				const found = jobs.find( ( item ) => item.id === jobId );
				if ( ! found ) {
					setNotice( {
						status: 'error',
						message: __(
							'This backup job does not exist.',
							'oueb-wp-backup'
						),
					} );
					return;
				}
				setJob( found );
				setName( found.name );
				setStorages( list );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [ jobId ] );

	if ( ! job ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const rename = ( event ) => {
		event.preventDefault();
		setBusy( true );
		saveJob( job.id, { name } )
			.then( ( saved ) => {
				setJob( saved );
				const message = __( 'Name saved.', 'oueb-wp-backup' );
				setNotice( { status: 'success', message } );
				speak( message );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			)
			.finally( () => setBusy( false ) );
	};

	const encrypt = ( checked ) => {
		saveJob( job.id, { encrypt: checked } )
			.then( ( saved ) => {
				setJob( saved );
				speak(
					checked
						? __( 'Encryption on.', 'oueb-wp-backup' )
						: __( 'Encryption off.', 'oueb-wp-backup' )
				);
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	};

	const remove = () => {
		deleteJob( job.id )
			.then( () => {
				window.location.hash = '#/jobs';
			} )
			.catch( ( e ) => {
				setRemoving( false );
				setNotice( { status: 'error', message: e.message } );
			} );
	};

	return (
		<>
			<p>
				<a href="#/jobs">
					{ __( 'All additional backups', 'oueb-wp-backup' ) }
				</a>
			</p>
			<form className="oueb-card oueb-form" onSubmit={ rename }>
				<h2 className="oueb-card__title">{ job.name }</h2>
				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'oueb-wp-backup' ) }
					value={ name }
					onChange={ setName }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Encrypt the archives', 'oueb-wp-backup' ) }
					help={ __(
						'With the active key of the settings. Without the key, an encrypted archive is lost.',
						'oueb-wp-backup'
					) }
					checked={ Boolean( job.encrypt ) }
					onChange={ encrypt }
				/>
				<div className="oueb-form__actions">
					<Button
						variant="secondary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! name.trim() }
					>
						{ __( 'Save the name', 'oueb-wp-backup' ) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => setRemoving( true ) }
					>
						{ __( 'Delete this job', 'oueb-wp-backup' ) }
					</Button>
				</div>
			</form>

			<ContentScreen jobId={ job.id } onSaved={ setJob } />
			<JobStorages job={ job } storages={ storages } onSaved={ setJob } />
			<ScheduleScreen jobId={ job.id } />

			{ removing && (
				<Modal
					title={ __( 'Delete this job?', 'oueb-wp-backup' ) }
					onRequestClose={ () => setRemoving( false ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: job name. */
							__(
								'“%s” will no longer run. Its backups stay in their storages and in the list.',
								'oueb-wp-backup'
							),
							job.name
						) }
					</p>
					<div className="oueb-form__actions">
						<Button
							variant="primary"
							isDestructive
							onClick={ remove }
						>
							{ __( 'Delete the job', 'oueb-wp-backup' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setRemoving( false ) }
						>
							{ __( 'Cancel', 'oueb-wp-backup' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

/**
 * Liste les tâches supplémentaires, ou affiche celle demandée.
 *
 * @param {Object} props       Propriétés.
 * @param {string} props.jobId Tâche demandée dans l'adresse, vide pour la liste.
 * @return {Element} Écran.
 */
export default function JobsScreen( { jobId } ) {
	const [ jobs, setJobs ] = useState( null );
	const [ runs, setRuns ] = useState( [] );
	const [ storages, setStorages ] = useState( [] );
	const [ name, setName ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		Promise.all( [
			fetchJobs(),
			fetchRuns( 100, 1, 'backup' ),
			fetchStorages(),
		] )
			.then( ( [ list, history, available ] ) => {
				setJobs( list.filter( ( job ) => ! job.is_main ) );
				setRuns( history.runs );
				setStorages( available );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [] );

	useEffect( () => {
		if ( ! jobId ) {
			load();
		}
	}, [ jobId, load ] );

	if ( jobId ) {
		return <JobScreen jobId={ jobId } />;
	}
	if ( ! jobs ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const create = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setNotice( null );
		createJob( { name: name.trim(), storages: [ 'local' ] } )
			.then( ( job ) => {
				window.location.hash = `#/jobs/${ job.id }`;
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', message: e.message } );
				setBusy( false );
			} );
	};

	const run = ( job ) => {
		startRun( job.id )
			.then( () => {
				const message = sprintf(
					/* translators: %s: job name. */
					__( 'Backup “%s” started.', 'oueb-wp-backup' ),
					job.name
				);
				setNotice( { status: 'success', message } );
				speak( message );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	};

	const storageNames = ( job ) =>
		storages
			.filter( ( storage ) => job.storages.includes( storage.id ) )
			.map( ( storage ) => storage.name )
			.join( ', ' );

	return (
		<>
			<section className="oueb-card">
				<p>
					{ __(
						'The main backup covers most sites. Add a job here for another rhythm or another storage, such as the database every hour. Jobs imported from BackWPup land here too.',
						'oueb-wp-backup'
					) }
				</p>
				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }
				{ jobs.length === 0 ? (
					<p className="oueb-empty">
						{ __( 'No additional backup yet.', 'oueb-wp-backup' ) }
					</p>
				) : (
					<div className="oueb-table-wrap">
						<table className="oueb-table">
							<caption className="screen-reader-text">
								{ __( 'Additional backups', 'oueb-wp-backup' ) }
							</caption>
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Name', 'oueb-wp-backup' ) }
									</th>
									<th scope="col">
										{ __( 'Content', 'oueb-wp-backup' ) }
									</th>
									<th scope="col">
										{ __( 'Stored in', 'oueb-wp-backup' ) }
									</th>
									<th scope="col">
										{ __( 'When', 'oueb-wp-backup' ) }
									</th>
									<th scope="col">
										{ __(
											'Last backup',
											'oueb-wp-backup'
										) }
									</th>
									<th scope="col">
										<span className="screen-reader-text">
											{ __(
												'Actions',
												'oueb-wp-backup'
											) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ jobs.map( ( job ) => {
									const last = runs.find(
										( item ) => item.job_id === job.id
									);
									return (
										<tr key={ job.id }>
											<th scope="row">
												<a
													href={ `#/jobs/${ job.id }` }
												>
													{ job.name }
												</a>
											</th>
											<td>{ jobContents( job ) }</td>
											<td>{ storageNames( job ) }</td>
											<td>{ describeSchedule( job ) }</td>
											<td>
												{ last
													? `${ formatDate(
															last.started_at
													  ) } (${ statusLabel(
															last
													  ) })`
													: __(
															'Never',
															'oueb-wp-backup'
													  ) }
											</td>
											<td className="oueb-table__actions">
												<Button
													variant="link"
													onClick={ () => run( job ) }
													aria-label={ sprintf(
														/* translators: %s: job name. */
														__(
															'Back up now with “%s”',
															'oueb-wp-backup'
														),
														job.name
													) }
												>
													{ __(
														'Back up now',
														'oueb-wp-backup'
													) }
												</Button>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }
			</section>

			<form className="oueb-card oueb-form" onSubmit={ create }>
				<h2 className="oueb-card__title">
					{ __( 'Add a backup job', 'oueb-wp-backup' ) }
				</h2>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'oueb-wp-backup' ) }
					help={ __(
						'For instance: Database every hour.',
						'oueb-wp-backup'
					) }
					value={ name }
					onChange={ setName }
				/>
				<div className="oueb-form__actions">
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! name.trim() }
					>
						{ __( 'Create the job', 'oueb-wp-backup' ) }
					</Button>
				</div>
			</form>
		</>
	);
}
