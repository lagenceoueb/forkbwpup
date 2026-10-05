/**
 * Chiffrement des archives : activation et clés.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	Spinner,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { createKey, exportKey, fetchJob, fetchKeys, saveJob } from '../api';
import { formatDate } from '../format';

/**
 * Propose le téléchargement d'une clé dans un fichier texte.
 *
 * @param {{id: string, key: string}} key Clé.
 */
function downloadKey( key ) {
	const blob = new window.Blob(
		[
			`Oueb WP Backup\n${ __( 'Encryption key', 'oueb-wp-backup' ) } ${
				key.id
			}\n${ key.key }\n`,
		],
		{ type: 'text/plain' }
	);
	const link = document.createElement( 'a' );
	link.href = window.URL.createObjectURL( blob );
	link.download = `oueb-wp-backup-key-${ key.id }.txt`;
	link.click();
	window.URL.revokeObjectURL( link.href );
}

/**
 * Règle le chiffrement de la sauvegarde principale et gère les clés.
 *
 * @return {Element} Carte.
 */
export default function EncryptionCard() {
	const [ job, setJob ] = useState( null );
	const [ keys, setKeys ] = useState( null );
	const [ shown, setShown ] = useState( null );
	const [ importing, setImporting ] = useState( false );
	const [ imported, setImported ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const show = ( next ) => {
		setNotice( next );
		speak( next.message, 'error' === next.status ? 'assertive' : 'polite' );
	};

	const reload = () =>
		fetchKeys()
			.then( setKeys )
			.catch( ( e ) => show( { status: 'error', message: e.message } ) );

	useEffect( () => {
		Promise.all( [ fetchJob(), fetchKeys() ] )
			.then( ( [ main, list ] ) => {
				setJob( main );
				setKeys( list );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [] );

	if ( ! job || ! keys ) {
		return (
			<div className="oueb-card">
				<p className="oueb-loading">
					<Spinner />
					{ __( 'Loading…', 'oueb-wp-backup' ) }
				</p>
			</div>
		);
	}

	const generate = () => {
		setBusy( true );
		return createKey()
			.then( ( key ) => {
				setShown( key );
				show( {
					status: 'warning',
					message: __(
						'New key created. Save it now outside the site.',
						'oueb-wp-backup'
					),
				} );
				return reload();
			} )
			.catch( ( e ) => show( { status: 'error', message: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const toggle = async ( encrypt ) => {
		setBusy( true );
		try {
			if ( encrypt && keys.length === 0 ) {
				await generate();
			}
			const saved = await saveJob( job.id, { encrypt } );
			setJob( saved );
			if ( ! encrypt || keys.length > 0 ) {
				show( {
					status: 'success',
					message: encrypt
						? __(
								'The next backups will be encrypted.',
								'oueb-wp-backup'
						  )
						: __(
								'The next backups will not be encrypted.',
								'oueb-wp-backup'
						  ),
				} );
			}
		} catch ( e ) {
			show( { status: 'error', message: e.message } );
		} finally {
			setBusy( false );
		}
	};

	const reveal = ( id ) => {
		exportKey( id )
			.then( setShown )
			.catch( ( e ) => show( { status: 'error', message: e.message } ) );
	};

	const add = () => {
		setBusy( true );
		createKey( imported.trim() )
			.then( ( key ) => {
				setImporting( false );
				setImported( '' );
				show( {
					status: 'success',
					message: sprintf(
						/* translators: %s: key identifier. */
						__(
							'Key %s added. It now encrypts the new backups.',
							'oueb-wp-backup'
						),
						key.id
					),
				} );
				return reload();
			} )
			.catch( ( e ) => show( { status: 'error', message: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<section
			className="oueb-card oueb-form"
			aria-labelledby="oueb-encryption-title"
		>
			<h2 id="oueb-encryption-title" className="oueb-card__title">
				{ __( 'Encryption', 'oueb-wp-backup' ) }
			</h2>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Encrypt the archives of the main backup',
					'oueb-wp-backup'
				) }
				help={ __(
					'Archives are encrypted on this server before leaving it: the storage providers cannot read them. Without the key, nobody can restore them, not even the agency.',
					'oueb-wp-backup'
				) }
				checked={ job.encrypt }
				disabled={ busy }
				onChange={ toggle }
			/>

			{ shown && (
				<div
					className="oueb-key"
					role="group"
					aria-labelledby="oueb-key-title"
				>
					<p id="oueb-key-title">
						<strong>
							{ sprintf(
								/* translators: %s: key identifier. */
								__( 'Key %s', 'oueb-wp-backup' ),
								shown.id
							) }
						</strong>
					</p>
					<p>
						{ __(
							'Keep this key outside the site, for example in a password manager. If the site is lost, the key is the only way to decrypt the backups.',
							'oueb-wp-backup'
						) }
					</p>
					<code className="oueb-key__value">{ shown.key }</code>
					<div className="oueb-form__actions">
						<Button
							variant="secondary"
							onClick={ () => downloadKey( shown ) }
						>
							{ __( 'Download the key', 'oueb-wp-backup' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () =>
								window.navigator.clipboard
									?.writeText( shown.key )
									.then( () =>
										speak(
											__(
												'Key copied.',
												'oueb-wp-backup'
											)
										)
									)
							}
						>
							{ __( 'Copy the key', 'oueb-wp-backup' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setShown( null ) }
						>
							{ __( 'Hide the key', 'oueb-wp-backup' ) }
						</Button>
					</div>
				</div>
			) }

			{ keys.length > 0 && (
				<div className="oueb-table-wrap">
					<table className="oueb-table">
						<caption className="screen-reader-text">
							{ __( 'Encryption keys', 'oueb-wp-backup' ) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Key', 'oueb-wp-backup' ) }
								</th>
								<th scope="col">
									{ __( 'Created', 'oueb-wp-backup' ) }
								</th>
								<th scope="col">
									{ __( 'Use', 'oueb-wp-backup' ) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __( 'Actions', 'oueb-wp-backup' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ [ ...keys ].reverse().map( ( key ) => (
								<tr key={ key.id }>
									<th scope="row">
										<code>{ key.id }</code>
									</th>
									<td>
										{ formatDate(
											new Date(
												key.created * 1000
											).toISOString()
										) }
									</td>
									<td>
										{ key.active
											? __(
													'New backups',
													'oueb-wp-backup'
											  )
											: __(
													'Older backups only',
													'oueb-wp-backup'
											  ) }
									</td>
									<td>
										<Button
											variant="link"
											onClick={ () => reveal( key.id ) }
											aria-label={ sprintf(
												/* translators: %s: key identifier. */
												__(
													'Show the key %s',
													'oueb-wp-backup'
												),
												key.id
											) }
										>
											{ __( 'Show', 'oueb-wp-backup' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<div className="oueb-form__actions">
				<Button
					variant="secondary"
					onClick={ generate }
					disabled={ busy }
				>
					{ __( 'Create a new key', 'oueb-wp-backup' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ () => setImporting( ! importing ) }
					aria-expanded={ importing }
				>
					{ __( 'Add an existing key', 'oueb-wp-backup' ) }
				</Button>
			</div>
			{ keys.length > 0 && (
				<p className="oueb-help">
					{ __(
						'A new key encrypts the next backups. The older keys stay here to decrypt the older backups.',
						'oueb-wp-backup'
					) }
				</p>
			) }

			{ importing && (
				<div className="oueb-key-import">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Key to add', 'oueb-wp-backup' ) }
						help={ __(
							'Paste a key saved from this site or from another one, for example to decrypt its backups here.',
							'oueb-wp-backup'
						) }
						className="oueb-field--code"
						rows={ 2 }
						value={ imported }
						onChange={ setImported }
					/>
					<Button
						variant="primary"
						onClick={ add }
						disabled={ busy || '' === imported.trim() }
					>
						{ __( 'Add the key', 'oueb-wp-backup' ) }
					</Button>
				</div>
			) }
		</section>
	);
}
