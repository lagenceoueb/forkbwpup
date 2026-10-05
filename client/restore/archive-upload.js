/**
 * Envoi d'une archive par morceaux, avec reprise.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import {
	createUpload,
	deleteUpload,
	fetchUpload,
	sendUploadChunk,
} from '../api';
import { formatSize } from '../format';
import { chunksFrom, isSupportedArchive } from './restore';

/**
 * Laisse choisir une archive sur l'ordinateur et l'envoie au serveur.
 *
 * Après une coupure, « Reprendre l'envoi » demande au serveur ce qu'il a
 * reçu et repart de là.
 *
 * @param {Object}   props            Propriétés.
 * @param {Object}   props.upload     Envoi terminé, ou null.
 * @param {Function} props.onUploaded Appelée avec l'envoi terminé, ou null quand il est retiré.
 * @return {Element} Sélection et progression de l'envoi.
 */
export default function ArchiveUpload( { upload, onUploaded } ) {
	const [ file, setFile ] = useState( null );
	const [ current, setCurrent ] = useState( null );
	const [ sent, setSent ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const input = useRef( null );

	const choose = ( event ) => {
		const chosen = event.target.files && event.target.files[ 0 ];
		setError( null );
		setCurrent( null );
		setSent( 0 );
		onUploaded( null );
		if ( chosen && ! isSupportedArchive( chosen.name ) ) {
			setFile( null );
			setError(
				__(
					'Choose a zip, tar.gz or tar archive, encrypted or not.',
					'oueb-wp-backup'
				)
			);
			return;
		}
		setFile( chosen || null );
	};

	const send = async () => {
		setBusy( true );
		setError( null );
		try {
			let state = current
				? await fetchUpload( current.id )
				: await createUpload( file.name, file.size );
			setCurrent( state );
			for ( const chunk of chunksFrom(
				file.size,
				state.chunk_size,
				state.received
			) ) {
				state = await sendUploadChunk(
					state.id,
					chunk.start,
					file.slice( chunk.start, chunk.end )
				);
				setSent( state.received );
			}
			setCurrent( state );
			onUploaded( state );
			speak( __( 'Archive uploaded.', 'oueb-wp-backup' ) );
		} catch ( e ) {
			setError(
				sprintf(
					/* translators: %s: error message. */
					__(
						'The upload stopped: %s Resume it when the connection is back.',
						'oueb-wp-backup'
					),
					e.message
				)
			);
		} finally {
			setBusy( false );
		}
	};

	const remove = () => {
		if ( current ) {
			deleteUpload( current.id ).catch( () => {} );
		}
		setFile( null );
		setCurrent( null );
		setSent( 0 );
		onUploaded( null );
		if ( input.current ) {
			input.current.value = '';
		}
	};

	const percent =
		file && file.size ? Math.floor( ( sent / file.size ) * 100 ) : 0;

	return (
		<div className="oueb-upload">
			<label htmlFor="oueb-restore-file" className="oueb-upload__label">
				{ __( 'Archive on your computer', 'oueb-wp-backup' ) }
			</label>
			<input
				ref={ input }
				id="oueb-restore-file"
				type="file"
				accept=".zip,.gz,.tgz,.tar,.enc"
				aria-describedby="oueb-restore-file-help"
				onChange={ choose }
				disabled={ busy }
			/>
			<p id="oueb-restore-file-help" className="oueb-help">
				{ __(
					'zip, tar.gz or tar, encrypted (.enc) or not. Large files go in several parts: an interrupted upload resumes where it stopped.',
					'oueb-wp-backup'
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ file && ! upload && (
				<div className="oueb-progress">
					{ ( busy || sent > 0 ) && (
						<>
							<p
								className="oueb-progress__label"
								id="oueb-upload-label"
							>
								{ sprintf(
									/* translators: 1: bytes sent, 2: file size, 3: percentage. */
									__(
										'Uploaded: %1$s of %2$s (%3$d %%)',
										'oueb-wp-backup'
									),
									formatSize( sent ),
									formatSize( file.size ),
									percent
								) }
							</p>
							<div
								className="oueb-progress__bar"
								role="progressbar"
								aria-labelledby="oueb-upload-label"
								aria-valuemin="0"
								aria-valuemax="100"
								aria-valuenow={ percent }
							>
								<div
									className="oueb-progress__fill"
									style={ { width: `${ percent }%` } }
								/>
							</div>
						</>
					) }
					<Button
						variant="secondary"
						onClick={ send }
						isBusy={ busy }
						disabled={ busy }
					>
						{ current
							? __( 'Resume the upload', 'oueb-wp-backup' )
							: sprintf(
									/* translators: %s: file size. */
									__( 'Upload (%s)', 'oueb-wp-backup' ),
									formatSize( file.size )
							  ) }
					</Button>
				</div>
			) }

			{ upload && (
				<p className="oueb-upload__done">
					{ sprintf(
						/* translators: 1: file name, 2: file size. */
						__( '%1$s uploaded, %2$s.', 'oueb-wp-backup' ),
						upload.name,
						formatSize( upload.size )
					) }{ ' ' }
					<Button variant="link" onClick={ remove }>
						{ __( 'Choose another archive', 'oueb-wp-backup' ) }
					</Button>
				</p>
			) }
		</div>
	);
}
