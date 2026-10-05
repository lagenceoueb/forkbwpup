/**
 * Carte « Mode avancé » des réglages : tâches supplémentaires et import BackWPup.
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
import { fetchImport, runImport } from '../api';
import { formatDate } from '../format';

/**
 * Mène aux tâches supplémentaires et à l'import de BackWPup.
 *
 * @return {Element} Carte.
 */
export default function AdvancedCard() {
	const [ summary, setSummary ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		fetchImport()
			.then( setSummary )
			.catch( () => setSummary( null ) );
	}, [] );

	const start = () => {
		setBusy( true );
		setNotice( null );
		runImport()
			.then( ( next ) => {
				setSummary( next );
				const count = next.report.filter(
					( entry ) => entry.job_id
				).length;
				const message = sprintf(
					/* translators: %d: number of imported jobs. */
					_n(
						'%d job imported. Check it in the additional backups.',
						'%d jobs imported. Check them in the additional backups.',
						count,
						'oueb-wp-backup'
					),
					count
				);
				setNotice( { status: 'success', message } );
				speak( message );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<section className="oueb-card" aria-labelledby="oueb-advanced">
			<h2 id="oueb-advanced" className="oueb-card__title">
				{ __( 'Advanced mode', 'oueb-wp-backup' ) }
			</h2>
			<p>
				{ __(
					'Add backup jobs beside the main backup, with their own content, storages and schedule.',
					'oueb-wp-backup'
				) }
			</p>
			<p>
				<a href="#/jobs">
					{ __( 'Manage the additional backups', 'oueb-wp-backup' ) }
				</a>
			</p>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ summary && summary.available && (
				<>
					<h3 className="oueb-card__subtitle">
						{ __( 'BackWPup', 'oueb-wp-backup' ) }
					</h3>
					<p>
						{ 'done' === summary.status && summary.imported_at
							? sprintf(
									/* translators: %s: date of the import. */
									__(
										'Jobs imported on %s. Importing again creates them a second time.',
										'oueb-wp-backup'
									),
									formatDate( summary.imported_at )
							  )
							: sprintf(
									/* translators: %d: number of BackWPup jobs. */
									_n(
										'%d BackWPup job can be imported. Your BackWPup data will be neither changed nor deleted.',
										'%d BackWPup jobs can be imported. Your BackWPup data will be neither changed nor deleted.',
										summary.jobs.length,
										'oueb-wp-backup'
									),
									summary.jobs.length
							  ) }
					</p>
					<Button
						variant="secondary"
						onClick={ start }
						isBusy={ busy }
						disabled={ busy }
					>
						{ 'done' === summary.status
							? __( 'Import again', 'oueb-wp-backup' )
							: __(
									'Import the BackWPup jobs',
									'oueb-wp-backup'
							  ) }
					</Button>
				</>
			) }
		</section>
	);
}
