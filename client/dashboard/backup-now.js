/**
 * Bouton « Sauvegarder maintenant » et suivi de l'exécution.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { abortRun, fetchRun, startRun } from '../api';
import { statusLabel, stepLabel } from '../format';

const POLL_DELAY = 2000;

/**
 * Lance une sauvegarde et affiche sa progression jusqu'à la fin.
 *
 * @param {Object}   props            Propriétés.
 * @param {Object}   props.activeRun  Exécution déjà en cours au chargement, ou null.
 * @param {Function} props.onFinished Appelée quand l'exécution se termine.
 * @return {Element} Bouton, ou progression.
 */
export default function BackupNow( { activeRun, onFinished } ) {
	const [ run, setRun ] = useState( activeRun );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const timer = useRef( null );

	useEffect( () => setRun( activeRun ), [ activeRun ] );

	useEffect( () => {
		if ( ! run || ! [ 'queued', 'running' ].includes( run.status ) ) {
			return undefined;
		}
		timer.current = window.setTimeout( () => {
			fetchRun( run.id )
				.then( ( next ) => {
					setRun( next );
					if ( ! [ 'queued', 'running' ].includes( next.status ) ) {
						speak(
							sprintf(
								/* translators: %s: backup status, such as Successful. */
								__( 'Backup finished: %s.', 'oueb-wp-backup' ),
								statusLabel( next )
							)
						);
						onFinished( next );
					}
				} )
				.catch( ( e ) => setError( e.message ) );
		}, POLL_DELAY );
		return () => window.clearTimeout( timer.current );
	}, [ run, onFinished ] );

	const start = () => {
		setBusy( true );
		setError( null );
		startRun()
			.then( ( created ) => {
				setRun( created );
				speak( __( 'Backup started.', 'oueb-wp-backup' ) );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const stop = () => {
		setBusy( true );
		abortRun( run.id )
			.then( setRun )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const running = run && [ 'queued', 'running' ].includes( run.status );

	return (
		<div className="oueb-backup-now">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ running ? (
				<div className="oueb-progress">
					<p
						className="oueb-progress__label"
						id="oueb-progress-label"
					>
						{ sprintf(
							/* translators: 1: step label, 2: percentage. */
							__( '%1$s: %2$d %%', 'oueb-wp-backup' ),
							stepLabel( run.step ),
							run.progress
						) }
					</p>
					<div
						className="oueb-progress__bar"
						role="progressbar"
						aria-labelledby="oueb-progress-label"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-valuenow={ run.progress }
					>
						<div
							className="oueb-progress__fill"
							style={ { width: `${ run.progress }%` } }
						/>
					</div>
					<p className="oueb-progress__help">
						{ __(
							'You can leave this page: the backup goes on.',
							'oueb-wp-backup'
						) }
					</p>
					<Button
						variant="secondary"
						isDestructive
						onClick={ stop }
						disabled={ busy }
					>
						{ __( 'Stop the backup', 'oueb-wp-backup' ) }
					</Button>
				</div>
			) : (
				<Button
					variant="primary"
					className="oueb-button-large"
					onClick={ start }
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Back up now', 'oueb-wp-backup' ) }
				</Button>
			) }
		</div>
	);
}
