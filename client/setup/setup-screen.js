/**
 * Assistant de première configuration, en quatre étapes.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	RadioControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import {
	fetchJob,
	fetchStorageTypes,
	fetchStorages,
	saveJob,
	saveSettings,
	startRun,
	testStorage,
} from '../api';
import ContentFields, { draftFromJob } from '../content/content-fields';
import { contentParts, contentSummary, hasContent } from '../content/content';
import {
	cronFromPreset,
	describeSchedule,
	presetFromCron,
} from '../schedule/cron';
import StorageForm from '../storage/storage-form';

/**
 * Étapes de l'assistant.
 *
 * @return {string[]} Libellés, dans l'ordre.
 */
export function setupSteps() {
	return [
		__( 'Content', 'oueb-wp-backup' ),
		__( 'Storage', 'oueb-wp-backup' ),
		__( 'Frequency', 'oueb-wp-backup' ),
		__( 'Check', 'oueb-wp-backup' ),
	];
}

/**
 * Fréquences proposées par l'assistant.
 *
 * @return {Array<{value: string, label: string}>} Fréquences.
 */
function frequencies() {
	return [
		{
			value: 'twice',
			label: __(
				'Twice a day: a shop or a site with many orders or comments',
				'oueb-wp-backup'
			),
		},
		{
			value: 'daily',
			label: __(
				'Every day: a site updated every day',
				'oueb-wp-backup'
			),
		},
		{
			value: 'weekly',
			label: __(
				'Every week: a site updated now and then',
				'oueb-wp-backup'
			),
		},
		{
			value: 'monthly',
			label: __(
				'Every month: a showcase site that rarely changes',
				'oueb-wp-backup'
			),
		},
	];
}

/**
 * Guide la première configuration de la sauvegarde principale.
 *
 * Chaque étape enregistre ce qu'elle règle : quitter l'assistant en route ne
 * perd rien.
 *
 * @return {Element} Assistant.
 */
export default function SetupScreen() {
	const [ step, setStep ] = useState( 0 );
	const [ job, setJob ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ storages, setStorages ] = useState( [] );
	const [ meta, setMeta ] = useState( null );
	const [ storageId, setStorageId ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const [ schedule, setSchedule ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const heading = useRef( null );

	useEffect( () => {
		Promise.all( [ fetchJob(), fetchStorages(), fetchStorageTypes() ] )
			.then( ( [ main, list, types ] ) => {
				setJob( main );
				setDraft( draftFromJob( main ) );
				setStorages( list );
				setMeta( types );
				setStorageId(
					main.storages.find( ( id ) => id !== 'local' ) ||
						main.storages[ 0 ] ||
						'local'
				);
				const preset = presetFromCron( main.schedule );
				setSchedule( {
					...preset,
					preset: [ 'twice', 'daily', 'weekly', 'monthly' ].includes(
						preset.preset
					)
						? preset.preset
						: 'daily',
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [] );

	// Le titre de l'étape reçoit le focus : un lecteur d'écran l'annonce.
	useEffect( () => {
		if ( heading.current ) {
			heading.current.focus();
		}
	}, [ step ] );

	if ( ! job || ! draft || ! schedule ) {
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

	const steps = setupSteps();
	const save = ( changes, next ) => {
		setBusy( true );
		setNotice( null );
		return saveJob( job.id, changes )
			.then( ( saved ) => {
				setJob( saved );
				setStep( next );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', message: e.message } );
				speak( e.message, 'assertive' );
			} )
			.finally( () => setBusy( false ) );
	};

	const saveContent = () => {
		const changes = {};
		contentParts().forEach( ( part ) => {
			changes[ part.key ] = Boolean( draft[ part.key ] );
		} );
		save( changes, 1 );
	};
	const saveStorage = () => save( { storages: [ storageId ] }, 2 );
	const saveSchedule = () =>
		save( { trigger: 'wpcron', schedule: cronFromPreset( schedule ) }, 3 );

	const test = () => {
		setBusy( true );
		setNotice( null );
		testStorage( storageId )
			.then( ( result ) => {
				setNotice( { status: 'success', message: result.message } );
				speak( result.message );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', message: e.message } );
				speak( e.message, 'assertive' );
			} )
			.finally( () => setBusy( false ) );
	};

	const finish = ( backup ) => {
		setBusy( true );
		const first = backup ? startRun( job.id ) : Promise.resolve();
		first
			.then( () => saveSettings( { setup_done: true } ) )
			.then( () => {
				window.location.hash = '#/dashboard';
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', message: e.message } );
				setBusy( false );
			} );
	};

	const storage = storages.find( ( item ) => item.id === storageId );

	return (
		<div className="oueb-setup">
			<nav aria-label={ __( 'Steps of the setup', 'oueb-wp-backup' ) }>
				<ol className="oueb-steps">
					{ steps.map( ( label, index ) => (
						<li
							key={ label }
							className={ `oueb-steps__item${
								index <= step ? ' is-reached' : ''
							}` }
							aria-current={ index === step ? 'step' : undefined }
						>
							<span className="screen-reader-text">
								{ index < step &&
									__( 'Done:', 'oueb-wp-backup' ) + ' ' }
								{ index === step &&
									__( 'Current step:', 'oueb-wp-backup' ) +
										' ' }
							</span>
							{ `${ index + 1 }. ${ label }` }
						</li>
					) ) }
				</ol>
			</nav>

			<section className="oueb-card oueb-form">
				<h2
					className="oueb-card__title"
					tabIndex={ -1 }
					ref={ heading }
				>
					{
						[
							__( 'What should be backed up?', 'oueb-wp-backup' ),
							__(
								'Where should the backups go?',
								'oueb-wp-backup'
							),
							__( 'How often?', 'oueb-wp-backup' ),
							__( 'Check and start', 'oueb-wp-backup' ),
						][ step ]
					}
				</h2>

				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }

				{ step === 0 && (
					<>
						<ContentFields
							value={ draft }
							onChange={ setDraft }
							advanced={ false }
						/>
						<p className="oueb-help">
							{ __(
								'The database and the content folders are enough for most sites.',
								'oueb-wp-backup'
							) }
						</p>
						<div className="oueb-form__actions">
							<Button
								variant="primary"
								onClick={ saveContent }
								isBusy={ busy }
								disabled={ busy || ! hasContent( draft ) }
							>
								{ __( 'Next', 'oueb-wp-backup' ) }
							</Button>
						</div>
					</>
				) }

				{ step === 1 && ! adding && (
					<>
						<RadioControl
							label={ __( 'Storage', 'oueb-wp-backup' ) }
							selected={ storageId }
							options={ storages.map( ( item ) => ( {
								value: item.id,
								label: item.description
									? `${ item.name } (${ item.description })`
									: item.name,
							} ) ) }
							onChange={ setStorageId }
						/>
						{ storageId === 'local' && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'A backup kept only on this server is lost with the server. Add a storage elsewhere.',
									'oueb-wp-backup'
								) }
							</Notice>
						) }
						<div className="oueb-form__actions">
							<Button
								variant="secondary"
								onClick={ () => setAdding( true ) }
							>
								{ __( 'Add a storage', 'oueb-wp-backup' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ saveStorage }
								isBusy={ busy }
								disabled={ busy || ! storageId }
							>
								{ __( 'Next', 'oueb-wp-backup' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setStep( 0 ) }
							>
								{ __( 'Back', 'oueb-wp-backup' ) }
							</Button>
						</div>
					</>
				) }

				{ step === 1 && adding && meta && (
					<StorageForm
						meta={ meta }
						storage={ null }
						onCancel={ () => setAdding( false ) }
						onSaved={ ( saved, result ) => {
							setAdding( false );
							setNotice( {
								status: result.status,
								message: `${ saved.name } : ${ result.message }`,
							} );
							fetchStorages().then( ( list ) => {
								setStorages( list );
								setStorageId( saved.id );
							} );
						} }
					/>
				) }

				{ step === 2 && (
					<>
						<RadioControl
							label={ __( 'Back up the site', 'oueb-wp-backup' ) }
							selected={ schedule.preset }
							options={ frequencies() }
							onChange={ ( preset ) =>
								setSchedule( { ...schedule, preset } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="time"
							label={ __( 'Time', 'oueb-wp-backup' ) }
							help={ __(
								'Choose a quiet hour, at night for most sites.',
								'oueb-wp-backup'
							) }
							value={ schedule.time }
							onChange={ ( time ) =>
								setSchedule( { ...schedule, time } )
							}
						/>
						<div className="oueb-form__actions">
							<Button
								variant="primary"
								onClick={ saveSchedule }
								isBusy={ busy }
								disabled={ busy || ! schedule.time }
							>
								{ __( 'Next', 'oueb-wp-backup' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setStep( 1 ) }
							>
								{ __( 'Back', 'oueb-wp-backup' ) }
							</Button>
						</div>
					</>
				) }

				{ step === 3 && (
					<>
						<dl className="oueb-summary">
							<dt>{ __( 'Content', 'oueb-wp-backup' ) }</dt>
							<dd>
								{ contentSummary( job ).saved.join( ', ' ) }
							</dd>
							<dt>{ __( 'Storage', 'oueb-wp-backup' ) }</dt>
							<dd>{ storage ? storage.name : '' }</dd>
							<dt>{ __( 'Frequency', 'oueb-wp-backup' ) }</dt>
							<dd>{ describeSchedule( job ) }</dd>
						</dl>
						<p>
							{ sprintf(
								/* translators: %s: storage name. */
								__(
									'Test the connection to %s, then start a first backup: it shows that everything works.',
									'oueb-wp-backup'
								),
								storage ? storage.name : ''
							) }
						</p>
						<div className="oueb-form__actions">
							<Button
								variant="secondary"
								onClick={ test }
								isBusy={ busy }
								disabled={ busy }
							>
								{ __( 'Test the storage', 'oueb-wp-backup' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ () => finish( true ) }
								disabled={ busy }
							>
								{ __(
									'Start the first backup',
									'oueb-wp-backup'
								) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => finish( false ) }
								disabled={ busy }
							>
								{ __(
									'Finish without a backup',
									'oueb-wp-backup'
								) }
							</Button>
						</div>
					</>
				) }
			</section>
		</div>
	);
}
