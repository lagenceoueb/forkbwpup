/**
 * Liste paginée des sauvegardes.
 */

/**
 * WordPress dependencies
 */
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { deleteArchive, fetchRuns } from '../api';
import { formatDate } from '../format';
import RunsTable from '../components/runs-table';

const PER_PAGE = 20;

/**
 * Affiche toutes les sauvegardes, page par page.
 *
 * @return {Element} Liste des sauvegardes.
 */
export default function BackupsScreen() {
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ removing, setRemoving ] = useState( null );
	const [ version, setVersion ] = useState( 0 );

	useEffect( () => {
		setResult( null );
		fetchRuns( PER_PAGE, page, 'backup' )
			.then( setResult )
			.catch( ( e ) => setError( e.message ) );
	}, [ page, version ] );

	const remove = () => {
		const run = removing;
		setRemoving( null );
		deleteArchive( run.id )
			.then( () => {
				const message = __(
					'Backup deleted from all its storages.',
					'oueb-wp-backup'
				);
				setNotice( { status: 'success', message } );
				speak( message );
				setVersion( version + 1 );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', message: e.message } );
				speak( e.message, 'assertive' );
			} );
	};

	if ( error ) {
		return <Notice status="error">{ error }</Notice>;
	}
	if ( ! result ) {
		return (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const pages = Math.max( 1, Math.ceil( result.total / PER_PAGE ) );

	return (
		<section className="oueb-card">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ removing && (
				<Modal
					title={ sprintf(
						/* translators: %s: backup date. */
						__( 'Delete the backup of %s?', 'oueb-wp-backup' ),
						formatDate( removing.started_at )
					) }
					onRequestClose={ () => setRemoving( null ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: list of storage names. */
							__(
								'The archive will be deleted from: %s. This cannot be undone.',
								'oueb-wp-backup'
							),
							( removing.storage_names || [] ).join( ', ' )
						) }
					</p>
					<div className="oueb-form__actions">
						<Button
							variant="primary"
							isDestructive
							onClick={ remove }
						>
							{ __( 'Delete the backup', 'oueb-wp-backup' ) }
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
			<RunsTable
				onDelete={ setRemoving }
				runs={ result.runs }
				caption={ __( 'All backups', 'oueb-wp-backup' ) }
			/>
			{ pages > 1 && (
				<nav
					className="oueb-pagination"
					aria-label={ __( 'Pages', 'oueb-wp-backup' ) }
				>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous page', 'oueb-wp-backup' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages. */
							__( 'Page %1$d of %2$d', 'oueb-wp-backup' ),
							page,
							pages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next page', 'oueb-wp-backup' ) }
					</Button>
				</nav>
			) }
		</section>
	);
}
