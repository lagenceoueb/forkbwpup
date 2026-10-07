/**
 * Stockages et nombre de copies d'une tâche.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { saveJob } from '../api';

/**
 * Choisit les stockages d'une tâche et le nombre de sauvegardes gardées.
 *
 * @param {Object}   props          Propriétés.
 * @param {Object}   props.job      Tâche.
 * @param {Array}    props.storages Stockages existants.
 * @param {Function} props.onSaved  Appelée avec la tâche enregistrée.
 * @return {Element} Formulaire.
 */
export default function JobStorages( { job, storages, onSaved } ) {
	const [ chosen, setChosen ] = useState( job.storages );
	const [ keep, setKeep ] = useState( String( job.keep ) );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const toggle = ( id ) => ( checked ) =>
		setChosen(
			checked
				? [ ...chosen, id ]
				: chosen.filter( ( item ) => item !== id )
		);

	const save = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setNotice( null );
		saveJob( job.id, { storages: chosen, keep: Number( keep ) } )
			.then( ( saved ) => {
				const message = __( 'Storages saved.', 'oueb-wp-backup' );
				setNotice( { status: 'success', message } );
				speak( message );
				onSaved( saved );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', message: e.message } );
				speak( e.message, 'assertive' );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<form className="oueb-card oueb-form" onSubmit={ save } noValidate>
			<h2 className="oueb-card__title">
				{ __( 'Where', 'oueb-wp-backup' ) }
			</h2>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<fieldset className="oueb-fieldset">
				<legend className="oueb-fieldset__legend">
					{ __( 'Send the archive to', 'oueb-wp-backup' ) }
				</legend>
				{ storages.map( ( storage ) => (
					<CheckboxControl
						key={ storage.id }
						__nextHasNoMarginBottom
						label={ storage.name }
						checked={ chosen.includes( storage.id ) }
						onChange={ toggle( storage.id ) }
					/>
				) ) }
				{ chosen.length === 0 && (
					<p className="oueb-help" role="alert">
						{ __(
							'Choose at least one storage.',
							'oueb-wp-backup'
						) }
					</p>
				) }
			</fieldset>
			<TextControl
				__nextHasNoMarginBottom
				type="number"
				min={ 1 }
				max={ 365 }
				label={ __(
					'Backups to keep in each storage',
					'oueb-wp-backup'
				) }
				value={ keep }
				onChange={ setKeep }
			/>
			<div className="oueb-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy || chosen.length === 0 }
				>
					{ __( 'Save the storages', 'oueb-wp-backup' ) }
				</Button>
			</div>
		</form>
	);
}
