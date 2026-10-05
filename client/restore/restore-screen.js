/**
 * Écran de restauration : choix de la sauvegarde, options, confirmation, suivi.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import {
	fetchRuns,
	fetchStorageFiles,
	fetchStorages,
	startRestore,
} from '../api';
import { contentsLabel, formatDate, formatSize } from '../format';
import ArchiveUpload from './archive-upload';
import RestoreProgress from './restore-progress';
import { isSupportedArchive, restorableRuns } from './restore';

/**
 * Laisse choisir une archive dans un stockage.
 *
 * @param {Object}   props          Propriétés.
 * @param {Array}    props.storages Stockages.
 * @param {Object}   props.value    Choix : storage_id et name.
 * @param {Function} props.onChange Appelée avec le nouveau choix.
 * @return {Element} Listes du stockage et de ses archives.
 */
function StoragePicker( { storages, value, onChange } ) {
	const [ files, setFiles ] = useState( null );
	const [ error, setError ] = useState( null );
	const storageId = value.storage_id || ( storages[ 0 ] && storages[ 0 ].id );

	useEffect( () => {
		if ( ! storageId ) {
			return;
		}
		setFiles( null );
		setError( null );
		fetchStorageFiles( storageId )
			.then( ( list ) =>
				setFiles(
					list.filter( ( file ) => isSupportedArchive( file.name ) )
				)
			)
			.catch( ( e ) => setError( e.message ) );
	}, [ storageId ] );

	return (
		<>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Storage', 'oueb-wp-backup' ) }
				value={ storageId }
				options={ storages.map( ( storage ) => ( {
					value: storage.id,
					label: storage.name,
				} ) ) }
				onChange={ ( id ) => onChange( { storage_id: id, name: '' } ) }
			/>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! error && ! files && (
				<p className="oueb-loading">
					<Spinner />
					{ __( 'Reading the storage…', 'oueb-wp-backup' ) }
				</p>
			) }
			{ files && files.length === 0 && (
				<p className="oueb-empty">
					{ __( 'No archive in this storage.', 'oueb-wp-backup' ) }
				</p>
			) }
			{ files && files.length > 0 && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Archive', 'oueb-wp-backup' ) }
					value={ value.name }
					options={ [
						{
							value: '',
							label: __( 'Choose an archive', 'oueb-wp-backup' ),
							disabled: true,
						},
						...files.map( ( file ) => ( {
							value: file.name,
							label: sprintf(
								/* translators: 1: file name, 2: size. */
								__( '%1$s (%2$s)', 'oueb-wp-backup' ),
								file.name,
								formatSize( file.size )
							),
						} ) ),
					] }
					onChange={ ( name ) =>
						onChange( { storage_id: storageId, name } )
					}
				/>
			) }
		</>
	);
}

/**
 * Décrit une sauvegarde de l'historique pour une liste.
 *
 * @param {Object} run Exécution.
 * @return {string} Date, contenu et taille.
 */
function runLabel( run ) {
	return sprintf(
		/* translators: 1: backup date, 2: content, such as Full site, 3: size. */
		__( '%1$s – %2$s – %3$s', 'oueb-wp-backup' ),
		formatDate( run.started_at ),
		run.job_id === 'pre-restore'
			? __( 'Before a restore', 'oueb-wp-backup' )
			: contentsLabel( run.contents ),
		formatSize( run.archive_size )
	);
}

/**
 * Affiche l'écran de restauration.
 *
 * @param {Object} props       Propriétés.
 * @param {string} props.runId Sauvegarde demandée dans l'adresse, vide sinon.
 * @return {Element} Écran.
 */
export default function RestoreScreen( { runId } ) {
	const [ backups, setBackups ] = useState( null );
	const [ storages, setStorages ] = useState( [] );
	const [ current, setCurrent ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ origin, setOrigin ] = useState( 'history' );
	const [ selectedRun, setSelectedRun ] = useState( runId || '' );
	const [ remote, setRemote ] = useState( { storage_id: '', name: '' } );
	const [ upload, setUpload ] = useState( null );
	const [ database, setDatabase ] = useState( true );
	const [ files, setFiles ] = useState( true );
	const [ safety, setSafety ] = useState( true );
	const [ confirming, setConfirming ] = useState( false );
	const [ starting, setStarting ] = useState( false );
	const [ startError, setStartError ] = useState( null );
	const [ version, setVersion ] = useState( 0 );

	useEffect( () => setSelectedRun( runId || '' ), [ runId ] );

	useEffect( () => {
		setBackups( null );
		Promise.all( [
			fetchRuns( 100, 1, 'backup' ),
			fetchRuns( 1, 1, 'restore' ),
			fetchStorages(),
		] )
			.then( ( [ history, restores, list ] ) => {
				const runs = restorableRuns( history.runs );
				setBackups( runs );
				setStorages( list );
				const last = restores.runs[ 0 ];
				if ( last && [ 'queued', 'running' ].includes( last.status ) ) {
					setCurrent( last );
				}
				if ( runs.length === 0 ) {
					setOrigin( list.length > 1 ? 'storage' : 'upload' );
				}
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ version ] );

	if ( error ) {
		return <Notice status="error">{ error }</Notice>;
	}
	if ( ! backups ) {
		return (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	if ( current ) {
		return (
			<section className="oueb-card">
				<h2 className="oueb-card__title">
					{ sprintf(
						/* translators: %s: archive name. */
						__( 'Restoring %s', 'oueb-wp-backup' ),
						( current.restore && current.restore.archive ) || ''
					) }
				</h2>
				<RestoreProgress
					run={ current }
					onEnd={ () => {
						setCurrent( null );
						setUpload( null );
						setVersion( version + 1 );
					} }
				/>
			</section>
		);
	}

	const runChoice =
		backups.find( ( run ) => String( run.id ) === String( selectedRun ) ) ||
		backups[ 0 ];

	let source = null;
	let sourceName = '';
	if ( origin === 'history' && runChoice ) {
		source = { type: 'run', run_id: runChoice.id };
		sourceName = runLabel( runChoice );
	} else if ( origin === 'storage' && remote.name ) {
		source = {
			type: 'storage',
			storage_id: remote.storage_id || storages[ 0 ].id,
			name: remote.name,
		};
		sourceName = remote.name;
	} else if ( origin === 'upload' && upload ) {
		source = { type: 'upload', upload_id: upload.id };
		sourceName = upload.name;
	}

	const ready = source && ( database || files );

	const start = () => {
		setStarting( true );
		setStartError( null );
		startRestore( { source, database, files, safety } )
			.then( ( run ) => {
				setConfirming( false );
				speak( __( 'Restore started.', 'oueb-wp-backup' ) );
				setCurrent( run );
			} )
			.catch( ( e ) => setStartError( e.message ) )
			.finally( () => setStarting( false ) );
	};

	const origins = [
		{
			value: 'history',
			label: __( 'A backup from the list', 'oueb-wp-backup' ),
		},
		{
			value: 'storage',
			label: __( 'An archive in a storage', 'oueb-wp-backup' ),
		},
		{
			value: 'upload',
			label: __( 'An archive from your computer', 'oueb-wp-backup' ),
		},
	];

	return (
		<>
			<section className="oueb-card oueb-form">
				<h2 className="oueb-card__title">
					{ __( '1. Choose the backup', 'oueb-wp-backup' ) }
				</h2>
				<RadioControl
					label={ __( 'Where is the backup?', 'oueb-wp-backup' ) }
					selected={ origin }
					options={ origins }
					onChange={ setOrigin }
				/>

				{ origin === 'history' &&
					( backups.length > 0 ? (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Backup', 'oueb-wp-backup' ) }
							value={ runChoice ? String( runChoice.id ) : '' }
							options={ backups.map( ( run ) => ( {
								value: String( run.id ),
								label: runLabel( run ),
							} ) ) }
							onChange={ setSelectedRun }
						/>
					) : (
						<p className="oueb-empty">
							{ __(
								'No backup with an available archive in the list.',
								'oueb-wp-backup'
							) }
						</p>
					) ) }

				{ origin === 'storage' && (
					<StoragePicker
						storages={ storages }
						value={ remote }
						onChange={ setRemote }
					/>
				) }

				{ origin === 'upload' && (
					<ArchiveUpload upload={ upload } onUploaded={ setUpload } />
				) }
			</section>

			<section className="oueb-card oueb-form">
				<h2 className="oueb-card__title">
					{ __( '2. Choose what to restore', 'oueb-wp-backup' ) }
				</h2>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Database', 'oueb-wp-backup' ) }
					help={ __(
						'Content, comments, users and settings. The settings of Oueb WP Backup and the address of the site stay as they are now.',
						'oueb-wp-backup'
					) }
					checked={ database }
					onChange={ setDatabase }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Files', 'oueb-wp-backup' ) }
					help={ __(
						'Media, themes, extensions and other files of the backup. Files added since then stay. wp-config.php and Oueb WP Backup are never replaced.',
						'oueb-wp-backup'
					) }
					checked={ files }
					onChange={ setFiles }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Back up the current site first (recommended)',
						'oueb-wp-backup'
					) }
					help={ __(
						'If the restore does not give the expected result, you can go back to the site as it is now. The backup stays on this server and appears in the list.',
						'oueb-wp-backup'
					) }
					checked={ safety }
					onChange={ setSafety }
				/>
				{ ! database && ! files && (
					<p className="oueb-help" role="alert">
						{ __(
							'Choose the database, the files, or both.',
							'oueb-wp-backup'
						) }
					</p>
				) }
			</section>

			<section className="oueb-card oueb-form">
				<h2 className="oueb-card__title">
					{ __( '3. Restore', 'oueb-wp-backup' ) }
				</h2>
				<p>
					{ __(
						'The site goes into maintenance mode while it is changed: visitors see a short message. Your session stays open if your account exists in the backup.',
						'oueb-wp-backup'
					) }
				</p>
				<Button
					variant="primary"
					className="oueb-button-large"
					disabled={ ! ready }
					onClick={ () => setConfirming( true ) }
				>
					{ __( 'Restore…', 'oueb-wp-backup' ) }
				</Button>
			</section>

			{ confirming && (
				<Modal
					title={ __( 'Restore this backup?', 'oueb-wp-backup' ) }
					onRequestClose={ () => setConfirming( false ) }
				>
					<p>{ sourceName }</p>
					<ul className="oueb-restore-summary">
						{ database && (
							<li>
								{ __(
									'The database of the site is replaced.',
									'oueb-wp-backup'
								) }
							</li>
						) }
						{ files && (
							<li>
								{ __(
									'The files of the backup replace those of the site.',
									'oueb-wp-backup'
								) }
							</li>
						) }
						<li>
							{ safety
								? __(
										'The current site is backed up first.',
										'oueb-wp-backup'
								  )
								: __(
										'The current site is not backed up first: changes since the backup are lost.',
										'oueb-wp-backup'
								  ) }
						</li>
					</ul>
					{ startError && (
						<Notice status="error" isDismissible={ false }>
							{ startError }
						</Notice>
					) }
					<div className="oueb-form__actions">
						<Button
							variant="primary"
							isDestructive
							onClick={ start }
							isBusy={ starting }
							disabled={ starting }
						>
							{ __( 'Restore now', 'oueb-wp-backup' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Cancel', 'oueb-wp-backup' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
