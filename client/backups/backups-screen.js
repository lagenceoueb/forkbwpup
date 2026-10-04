/**
 * Liste paginée des sauvegardes.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { fetchRuns } from '../api';
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

	useEffect( () => {
		setResult( null );
		fetchRuns( PER_PAGE, page )
			.then( setResult )
			.catch( ( e ) => setError( e.message ) );
	}, [ page ] );

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
			<RunsTable
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
