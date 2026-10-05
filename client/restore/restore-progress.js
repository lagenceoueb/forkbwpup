/**
 * Suivi d'une restauration.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { abortRun, fetchLog, fetchRun } from '../api';
import { formatTime } from '../format';
import { isCommitted, restoreStepLabel } from './restore';

const POLL_DELAY = 2000;
const LOG_LINES = 6;

/**
 * Indique si une erreur de l'API vient d'une session perdue.
 *
 * @param {Object} error Erreur de apiFetch.
 * @return {boolean} Vrai pour une session expirée ou un jeton refusé.
 */
function isLoggedOut( error ) {
	const status = error && error.data && error.data.status;
	return (
		status === 401 ||
		status === 403 ||
		( error && error.code === 'rest_cookie_invalid_nonce' )
	);
}

/**
 * Affiche la progression d'une restauration jusqu'à la fin.
 *
 * @param {Object}   props       Propriétés.
 * @param {Object}   props.run   Exécution de restauration.
 * @param {Function} props.onEnd Appelée pour revenir au choix d'une sauvegarde.
 * @return {Element} Progression, puis résultat.
 */
export default function RestoreProgress( { run: initial, onEnd } ) {
	const [ run, setRun ] = useState( initial );
	const [ log, setLog ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ loggedOut, setLoggedOut ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const timer = useRef( null );
	const lastStep = useRef( initial.step );

	const active = [ 'queued', 'running' ].includes( run.status );
	const committed = isCommitted( run );

	useEffect( () => {
		if ( ! active || loggedOut ) {
			return undefined;
		}
		timer.current = window.setTimeout( () => {
			Promise.all( [ fetchRun( run.id ), fetchLog( run.id ) ] )
				.then( ( [ next, entries ] ) => {
					setError( null );
					setLog( entries.slice( -LOG_LINES ) );
					if ( next.step && next.step !== lastStep.current ) {
						lastStep.current = next.step;
						speak( restoreStepLabel( next.step ) );
					}
					if ( ! [ 'queued', 'running' ].includes( next.status ) ) {
						speak(
							next.status === 'success' ||
								next.status === 'warning'
								? __( 'Restore finished.', 'oueb-wp-backup' )
								: __( 'The restore failed.', 'oueb-wp-backup' ),
							'assertive'
						);
					}
					setRun( next );
				} )
				.catch( ( e ) => {
					if ( isLoggedOut( e ) ) {
						setLoggedOut( true );
						return;
					}
					// Coupure passagère : le suivi réessaie au tour suivant.
					setError( e.message );
					setRun( { ...run } );
				} );
		}, POLL_DELAY );
		return () => window.clearTimeout( timer.current );
	}, [ run, active, loggedOut ] );

	const stop = () => {
		setBusy( true );
		abortRun( run.id )
			.then( setRun )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const login = addQueryArgs( 'wp-login.php', {
		redirect_to: window.location.href,
	} );

	if ( loggedOut ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				<p>
					{ __(
						'Your session ended with the restore of the database. The restore goes on without you.',
						'oueb-wp-backup'
					) }
				</p>
				<p>
					<a href={ login }>
						{ __(
							'Log in with an account of the restored site',
							'oueb-wp-backup'
						) }
					</a>
				</p>
			</Notice>
		);
	}

	if ( ! active ) {
		const ok = run.status === 'success' || run.status === 'warning';
		const safety = run.restore && run.restore.safety_run;
		return (
			<div className="oueb-restore-result">
				<Notice
					status={ ok ? 'success' : 'error' }
					isDismissible={ false }
				>
					<p>
						{ ok &&
							__(
								'Restore finished. The site is out of maintenance mode.',
								'oueb-wp-backup'
							) }
						{ ! ok &&
							run.status === 'aborted' &&
							__(
								'Restore stopped before any change to the site.',
								'oueb-wp-backup'
							) }
						{ ! ok &&
							run.status === 'failed' &&
							( committed
								? __(
										'The restore failed while it was changing the site. The site may be half restored.',
										'oueb-wp-backup'
								  )
								: __(
										'The restore failed before any change to the site.',
										'oueb-wp-backup'
								  ) ) }
					</p>
					{ run.status === 'warning' && (
						<p>
							{ __(
								'Some points need your attention: read the log.',
								'oueb-wp-backup'
							) }
						</p>
					) }
				</Notice>
				<p className="oueb-restore-result__links">
					<a href={ `#/log/${ run.id }` }>
						{ __(
							'Read the log of the restore',
							'oueb-wp-backup'
						) }
					</a>
					{ safety > 0 && (
						<a href={ `#/restore/${ safety }` }>
							{ ok
								? __(
										'Go back to the site as it was before',
										'oueb-wp-backup'
								  )
								: __(
										'Restore the backup made just before',
										'oueb-wp-backup'
								  ) }
						</a>
					) }
				</p>
				<Button variant="secondary" onClick={ onEnd }>
					{ __( 'Restore another backup', 'oueb-wp-backup' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="oueb-progress oueb-restore-progress">
			{ error && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: error message. */
						__(
							'The progress cannot be read right now (%s). The page tries again.',
							'oueb-wp-backup'
						),
						error
					) }
				</Notice>
			) }
			<p className="oueb-progress__label" id="oueb-restore-label">
				{ sprintf(
					/* translators: 1: step label, 2: percentage. */
					__( '%1$s: %2$d %%', 'oueb-wp-backup' ),
					restoreStepLabel( run.step ),
					run.progress
				) }
			</p>
			<div
				className="oueb-progress__bar"
				role="progressbar"
				aria-labelledby="oueb-restore-label"
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
				{ committed
					? __(
							'The site is in maintenance mode. Visitors see a short message until the end. You can leave this page: the restore goes on.',
							'oueb-wp-backup'
					  )
					: __(
							'Nothing is changed yet. You can leave this page: the restore goes on.',
							'oueb-wp-backup'
					  ) }
			</p>
			{ log.length > 0 && (
				<ol className="oueb-log oueb-log--short">
					{ log.map( ( entry, index ) => (
						<li
							key={ index }
							className={ `oueb-log__entry oueb-log__entry--${ entry.level }` }
						>
							<time dateTime={ entry.time }>
								{ formatTime( entry.time ) }
							</time>
							<span className="oueb-log__message">
								{ entry.message }
							</span>
						</li>
					) ) }
				</ol>
			) }
			{ ! committed && (
				<Button
					variant="secondary"
					isDestructive
					onClick={ stop }
					disabled={ busy }
				>
					{ __( 'Stop the restore', 'oueb-wp-backup' ) }
				</Button>
			) }
		</div>
	);
}
