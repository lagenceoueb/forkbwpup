/**
 * Journal d'une exécution.
 */

/**
 * WordPress dependencies
 */
import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { fetchLog, fetchRuns } from '../api';
import { formatDate, formatTime, statusLabel } from '../format';

const LEVELS = {
	info: () => __( 'Information', 'oueb-wp-backup' ),
	warning: () => __( 'Warning', 'oueb-wp-backup' ),
	error: () => __( 'Error', 'oueb-wp-backup' ),
};

/**
 * Affiche le journal de l'exécution choisie.
 *
 * @param {Object} props       Propriétés.
 * @param {string} props.runId Exécution demandée dans l'adresse, vide pour la dernière.
 * @return {Element} Journal.
 */
export default function LogScreen( { runId } ) {
	const [ runs, setRuns ] = useState( null );
	const [ selected, setSelected ] = useState( runId );
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => setSelected( runId ), [ runId ] );

	useEffect( () => {
		fetchRuns( 50 )
			.then( ( result ) => setRuns( result.runs ) )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	const current = selected || ( runs && runs[ 0 ] && String( runs[ 0 ].id ) );

	useEffect( () => {
		if ( ! current ) {
			return;
		}
		setEntries( null );
		fetchLog( current )
			.then( setEntries )
			.catch( ( e ) => setError( e.message ) );
	}, [ current ] );

	if ( error ) {
		return <Notice status="error">{ error }</Notice>;
	}
	if ( ! runs ) {
		return (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}
	if ( runs.length === 0 ) {
		return (
			<p className="oueb-empty">
				{ __( 'No backup yet, so no log.', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const options = runs.map( ( run ) => ( {
		value: String( run.id ),
		label:
			run.kind === 'restore'
				? sprintf(
						/* translators: 1: date, 2: status. */
						__( 'Restore of %1$s (%2$s)', 'oueb-wp-backup' ),
						formatDate( run.started_at ),
						statusLabel( run )
				  )
				: `${ formatDate( run.started_at ) } (${ statusLabel( run ) })`,
	} ) );
	if ( ! options.some( ( option ) => option.value === current ) ) {
		options.unshift( { value: current, label: `#${ current }` } );
	}

	return (
		<section className="oueb-card">
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Backup', 'oueb-wp-backup' ) }
				value={ current }
				options={ options }
				onChange={ ( value ) => {
					window.location.hash = `#/log/${ value }`;
				} }
			/>
			{ ! entries && (
				<p className="oueb-loading">
					<Spinner />
					{ __( 'Loading…', 'oueb-wp-backup' ) }
				</p>
			) }
			{ entries && entries.length === 0 && (
				<p className="oueb-empty">
					{ __( 'This log is empty.', 'oueb-wp-backup' ) }
				</p>
			) }
			{ entries && entries.length > 0 && (
				<ol className="oueb-log">
					{ entries.map( ( entry, index ) => (
						<li
							key={ index }
							className={ `oueb-log__entry oueb-log__entry--${ entry.level }` }
						>
							<time dateTime={ entry.time }>
								{ formatTime( entry.time ) }
							</time>
							<span className="oueb-log__level">
								{ ( LEVELS[ entry.level ] || LEVELS.info )() }
							</span>
							<span className="oueb-log__message">
								{ entry.message }
							</span>
						</li>
					) ) }
				</ol>
			) }
		</section>
	);
}
