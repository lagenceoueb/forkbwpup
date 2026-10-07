/**
 * Écran du contenu sauvegardé par une tâche.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { fetchJob, saveJob } from '../api';
import ContentFields, { draftFromJob } from './content-fields';
import { contentParts, hasContent, parseExclusions } from './content';

/**
 * Convertit le brouillon en modifications de la tâche.
 *
 * @param {Object} draft Brouillon des champs.
 * @return {Object} Modifications.
 */
export function changesFromDraft( draft ) {
	const changes = {
		exclude: parseExclusions( draft.exclusions ),
		archive_format: draft.archive_format,
	};
	contentParts().forEach( ( part ) => {
		changes[ part.key ] = Boolean( draft[ part.key ] );
	} );
	return changes;
}

/**
 * Règle le contenu d'une tâche et l'enregistre.
 *
 * @param {Object}   props         Propriétés.
 * @param {string}   props.jobId   Tâche, la principale par défaut.
 * @param {Function} props.onSaved Appelée avec la tâche enregistrée.
 * @return {Element} Formulaire.
 */
export default function ContentScreen( { jobId = 'main', onSaved } ) {
	const [ draft, setDraft ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		setDraft( null );
		fetchJob( jobId )
			.then( ( job ) => setDraft( draftFromJob( job ) ) )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [ jobId ] );

	if ( ! draft ) {
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

	const save = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setNotice( null );
		saveJob( jobId, changesFromDraft( draft ) )
			.then( ( job ) => {
				setDraft( draftFromJob( job ) );
				const message = __( 'Content saved.', 'oueb-wp-backup' );
				setNotice( { status: 'success', message } );
				speak( message );
				if ( onSaved ) {
					onSaved( job );
				}
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
				{ __( 'Content of the backup', 'oueb-wp-backup' ) }
			</h2>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<ContentFields value={ draft } onChange={ setDraft } />
			<div className="oueb-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy || ! hasContent( draft ) }
				>
					{ __( 'Save the content', 'oueb-wp-backup' ) }
				</Button>
			</div>
		</form>
	);
}
