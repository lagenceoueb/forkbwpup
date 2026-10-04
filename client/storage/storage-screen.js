/**
 * Section Stockage : où partent les sauvegardes.
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
	deleteStorage,
	fetchJob,
	fetchStorages,
	fetchStorageTypes,
	saveJob,
	testStorage,
} from '../api';
import StorageForm from './storage-form';
import StorageFiles from './storage-files';

/**
 * Gère les stockages et ceux de la sauvegarde principale.
 *
 * @return {Element} Écran.
 */
export default function StorageScreen() {
	const [ storages, setStorages ] = useState( null );
	const [ meta, setMeta ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ keep, setKeep ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ files, setFiles ] = useState( null );
	const [ removing, setRemoving ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	const show = ( next ) => {
		setNotice( next );
		if ( next ) {
			speak(
				next.message,
				'error' === next.status ? 'assertive' : 'polite'
			);
		}
	};

	const load = useCallback( () => {
		Promise.all( [ fetchStorages(), fetchStorageTypes(), fetchJob() ] )
			.then( ( [ list, types, main ] ) => {
				setStorages( list );
				setMeta( types );
				setJob( main );
				setKeep( String( main.keep ) );
			} )
			.catch( ( e ) => show( { status: 'error', message: e.message } ) );
	}, [] );

	useEffect( load, [ load ] );

	if ( ! storages || ! meta || ! job ) {
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

	const updateJob = ( changes, message ) => {
		setBusy( 'job' );
		saveJob( job.id, changes )
			.then( ( saved ) => {
				setJob( saved );
				setKeep( String( saved.keep ) );
				show( { status: 'success', message } );
			} )
			.catch( ( e ) => show( { status: 'error', message: e.message } ) )
			.finally( () => setBusy( '' ) );
	};

	const toggle = ( storage, checked ) => {
		const next = checked
			? [ ...job.storages, storage.id ]
			: job.storages.filter( ( id ) => id !== storage.id );
		updateJob(
			{ storages: next },
			checked
				? sprintf(
						/* translators: %s: storage name. */
						__(
							'The main backup will also be sent to %s.',
							'oueb-wp-backup'
						),
						storage.name
				  )
				: sprintf(
						/* translators: %s: storage name. */
						__(
							'The main backup will no longer be sent to %s.',
							'oueb-wp-backup'
						),
						storage.name
				  )
		);
	};

	const test = ( storage ) => {
		setBusy( storage.id );
		testStorage( storage.id )
			.then( ( result ) =>
				show( {
					status: 'success',
					message: `${ storage.name } : ${ result.message }`,
				} )
			)
			.catch( ( e ) =>
				show( {
					status: 'error',
					message: `${ storage.name } : ${ e.message }`,
				} )
			)
			.finally( () => setBusy( '' ) );
	};

	const remove = () => {
		const storage = removing;
		setRemoving( null );
		setBusy( storage.id );
		deleteStorage( storage.id )
			.then( () => {
				show( {
					status: 'success',
					message: sprintf(
						/* translators: %s: storage name. */
						__(
							'Storage %s deleted. Its backups were not deleted.',
							'oueb-wp-backup'
						),
						storage.name
					),
				} );
				load();
			} )
			.catch( ( e ) => show( { status: 'error', message: e.message } ) )
			.finally( () => setBusy( '' ) );
	};

	if ( editing ) {
		return (
			<StorageForm
				meta={ meta }
				storage={ 'new' === editing ? null : editing }
				onCancel={ () => setEditing( null ) }
				onSaved={ ( saved, result ) => {
					setEditing( null );
					show( {
						status: result.status,
						message: `${ saved.name } : ${ result.message }`,
					} );
					load();
				} }
			/>
		);
	}

	const remote = job.storages.some( ( id ) => 'local' !== id );

	return (
		<>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ ! remote && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The main backup stays on this server only. A disk failure or a hack would take the site and its backups at once. Add a storage outside this server.',
						'oueb-wp-backup'
					) }
				</Notice>
			) }

			<section
				className="oueb-card"
				aria-labelledby="oueb-storages-title"
			>
				<h2 id="oueb-storages-title" className="oueb-card__title">
					{ __( 'Storages', 'oueb-wp-backup' ) }
				</h2>
				<ul className="oueb-storages">
					{ storages.map( ( storage ) => {
						const used = job.storages.includes( storage.id );
						const onlyOne = used && job.storages.length === 1;
						return (
							<li key={ storage.id } className="oueb-storage">
								<div className="oueb-storage__head">
									<h3 className="oueb-storage__name">
										{ storage.name }
									</h3>
									<p className="oueb-storage__type">
										{ storage.type_label }
										{ storage.builtin &&
											' · ' +
												__(
													'complementary copy only',
													'oueb-wp-backup'
												) }
									</p>
									<p className="oueb-storage__where">
										<code>{ storage.description }</code>
									</p>
								</div>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ __(
										'Send the main backup here',
										'oueb-wp-backup'
									) }
									help={
										onlyOne
											? __(
													'The backup needs at least one storage.',
													'oueb-wp-backup'
											  )
											: undefined
									}
									checked={ used }
									disabled={ onlyOne || 'job' === busy }
									onChange={ ( checked ) =>
										toggle( storage, checked )
									}
								/>
								<div className="oueb-storage__actions">
									<Button
										variant="secondary"
										isBusy={ storage.id === busy }
										disabled={ '' !== busy }
										onClick={ () => test( storage ) }
									>
										{ __( 'Test', 'oueb-wp-backup' ) }
										<span className="screen-reader-text">{ ` ${ storage.name }` }</span>
									</Button>
									<Button
										variant="tertiary"
										aria-expanded={ files === storage.id }
										onClick={ () =>
											setFiles(
												files === storage.id
													? null
													: storage.id
											)
										}
									>
										{ files === storage.id
											? __(
													'Hide the backups',
													'oueb-wp-backup'
											  )
											: __(
													'Show the backups',
													'oueb-wp-backup'
											  ) }
										<span className="screen-reader-text">{ ` ${ storage.name }` }</span>
									</Button>
									{ ! storage.builtin && (
										<>
											<Button
												variant="tertiary"
												onClick={ () =>
													setEditing( storage )
												}
											>
												{ __(
													'Edit',
													'oueb-wp-backup'
												) }
												<span className="screen-reader-text">{ ` ${ storage.name }` }</span>
											</Button>
											<Button
												variant="tertiary"
												isDestructive
												disabled={ '' !== busy }
												onClick={ () =>
													setRemoving( storage )
												}
											>
												{ __(
													'Delete',
													'oueb-wp-backup'
												) }
												<span className="screen-reader-text">{ ` ${ storage.name }` }</span>
											</Button>
										</>
									) }
								</div>
								{ files === storage.id && (
									<StorageFiles storage={ storage } />
								) }
							</li>
						);
					} ) }
				</ul>
				<Button variant="primary" onClick={ () => setEditing( 'new' ) }>
					{ __( 'Add a storage', 'oueb-wp-backup' ) }
				</Button>
			</section>

			<form
				className="oueb-card oueb-form"
				onSubmit={ ( event ) => {
					event.preventDefault();
					updateJob(
						{ keep: Number( keep ) },
						__(
							'Number of backups to keep saved.',
							'oueb-wp-backup'
						)
					);
				} }
			>
				<h2 className="oueb-card__title">
					{ __( 'Rotation', 'oueb-wp-backup' ) }
				</h2>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					max={ 365 }
					label={ __(
						'Backups to keep in each storage',
						'oueb-wp-backup'
					) }
					help={ __(
						'After each backup, the oldest backups of this site beyond this number are deleted. Backups of other sites are never touched.',
						'oueb-wp-backup'
					) }
					value={ keep }
					onChange={ setKeep }
				/>
				<div className="oueb-form__actions">
					<Button
						variant="primary"
						type="submit"
						isBusy={ 'job' === busy }
						disabled={ '' !== busy }
					>
						{ __( 'Save', 'oueb-wp-backup' ) }
					</Button>
				</div>
			</form>

			{ removing && (
				<Modal
					title={ sprintf(
						/* translators: %s: storage name. */
						__( 'Delete the storage %s?', 'oueb-wp-backup' ),
						removing.name
					) }
					onRequestClose={ () => setRemoving( null ) }
				>
					<p>
						{ __(
							'The backups it contains stay in place. You can add the storage again later to find them.',
							'oueb-wp-backup'
						) }
					</p>
					<div className="oueb-form__actions">
						<Button
							variant="primary"
							isDestructive
							onClick={ remove }
						>
							{ __( 'Delete the storage', 'oueb-wp-backup' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setRemoving( null ) }
						>
							{ __( 'Cancel', 'oueb-wp-backup' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
