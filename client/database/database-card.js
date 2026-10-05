/**
 * Carte « Maintenance de la base » des réglages.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { fetchTables, maintainTables } from '../api';
import { formatSize } from '../format';
import { resultLabel, summarizeResults } from './database';

/**
 * Vérifie, répare ou optimise les tables du site.
 *
 * @return {Element} Carte.
 */
export default function DatabaseCard() {
	const [ tables, setTables ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ outcome, setOutcome ] = useState( null );

	useEffect( () => {
		fetchTables()
			.then( setTables )
			.catch( () => setTables( null ) );
	}, [] );

	const run = ( operation ) => {
		setBusy( operation );
		setOutcome( null );
		maintainTables( operation )
			.then( ( results ) => {
				const summary = summarizeResults( operation, results );
				setOutcome( {
					...summary,
					rows: results.filter(
						( r ) => 'ok' !== r.status && 'unsupported' !== r.status
					),
				} );
				speak( summary.message );
				if ( 'optimize' === operation ) {
					fetchTables()
						.then( setTables )
						.catch( () => {} );
				}
			} )
			.catch( ( e ) =>
				setOutcome( { status: 'error', message: e.message, rows: [] } )
			)
			.finally( () => setBusy( '' ) );
	};

	const size = tables
		? tables.reduce( ( total, table ) => total + table.size, 0 )
		: 0;

	const actions = [
		{
			operation: 'check',
			label: __( 'Check the tables', 'oueb-wp-backup' ),
			help: __(
				'Reads each table and reports the damaged ones. Changes nothing.',
				'oueb-wp-backup'
			),
		},
		{
			operation: 'repair',
			label: __( 'Repair the tables', 'oueb-wp-backup' ),
			help: __(
				'Repairs the damaged tables. Back up the site first.',
				'oueb-wp-backup'
			),
		},
		{
			operation: 'optimize',
			label: __( 'Optimize the tables', 'oueb-wp-backup' ),
			help: __(
				'Frees the space left by deleted content. The site can slow down for a few seconds.',
				'oueb-wp-backup'
			),
		},
	];

	return (
		<section className="oueb-card" aria-labelledby="oueb-database">
			<h2 id="oueb-database" className="oueb-card__title">
				{ __( 'Database maintenance', 'oueb-wp-backup' ) }
			</h2>
			{ tables && tables.length > 0 && (
				<p>
					{ size > 0
						? sprintf(
								/* translators: 1: number of tables, 2: size, like "12 MB". */
								_n(
									'%1$d table, %2$s.',
									'%1$d tables, %2$s.',
									tables.length,
									'oueb-wp-backup'
								),
								tables.length,
								formatSize( size )
						  )
						: sprintf(
								/* translators: %d: number of tables. */
								_n(
									'%d table.',
									'%d tables.',
									tables.length,
									'oueb-wp-backup'
								),
								tables.length
						  ) }
				</p>
			) }
			<ul className="oueb-maintenance">
				{ actions.map( ( action ) => (
					<li key={ action.operation }>
						<Button
							variant="secondary"
							onClick={ () => run( action.operation ) }
							isBusy={ busy === action.operation }
							disabled={ '' !== busy }
							aria-describedby={ `oueb-database-${ action.operation }` }
						>
							{ action.label }
						</Button>
						<p id={ `oueb-database-${ action.operation }` }>
							{ action.help }
						</p>
					</li>
				) ) }
			</ul>
			{ outcome && (
				<Notice
					status={ outcome.status }
					onRemove={ () => setOutcome( null ) }
				>
					{ outcome.message }
				</Notice>
			) }
			{ outcome && outcome.rows.length > 0 && (
				<div
					className="oueb-table-wrap"
					role="region"
					aria-label={ __(
						'Tables with a problem',
						'oueb-wp-backup'
					) }
					// Rien de focalisable dans ce tableau : la zone le devient pour défiler au clavier.
					tabIndex={ 0 }
				>
					<table className="oueb-table">
						<caption className="screen-reader-text">
							{ __( 'Tables with a problem', 'oueb-wp-backup' ) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Table', 'oueb-wp-backup' ) }
								</th>
								<th scope="col">
									{ __( 'Result', 'oueb-wp-backup' ) }
								</th>
								<th scope="col">
									{ __( 'Message', 'oueb-wp-backup' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ outcome.rows.map( ( row ) => (
								<tr key={ row.table }>
									<th scope="row">{ row.table }</th>
									<td>{ resultLabel( row.status ) }</td>
									<td>{ row.message }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</section>
	);
}
