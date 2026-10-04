/**
 * Archives présentes dans un stockage.
 */

/**
 * WordPress dependencies
 */
import { Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { fetchStorageFiles } from '../api';
import { formatDate, formatSize } from '../format';

/**
 * Liste les archives d'un stockage, celles des autres sites comprises.
 *
 * @param {Object} props         Propriétés.
 * @param {Object} props.storage Stockage.
 * @return {Element} Tableau.
 */
export default function StorageFiles( { storage } ) {
	const [ files, setFiles ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		fetchStorageFiles( storage.id )
			.then( setFiles )
			.catch( ( e ) => setError( e.message ) );
	}, [ storage.id ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! files ) {
		return (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Reading the storage…', 'oueb-wp-backup' ) }
			</p>
		);
	}
	if ( files.length === 0 ) {
		return (
			<p className="oueb-empty">
				{ __( 'No backup in this storage.', 'oueb-wp-backup' ) }
			</p>
		);
	}

	return (
		<div className="oueb-table-wrap">
			<table className="oueb-table">
				<caption className="screen-reader-text">
					{ __( 'Backups in this storage', 'oueb-wp-backup' ) }
				</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'File', 'oueb-wp-backup' ) }</th>
						<th scope="col">{ __( 'Date', 'oueb-wp-backup' ) }</th>
						<th scope="col">{ __( 'Size', 'oueb-wp-backup' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ files.map( ( file ) => (
						<tr key={ file.name }>
							<th scope="row" className="oueb-table__file">
								{ file.name }
								{ ! file.this_site && (
									<span className="oueb-tag">
										{ __( 'Other site', 'oueb-wp-backup' ) }
									</span>
								) }
							</th>
							<td>
								{ file.time
									? formatDate(
											new Date(
												file.time * 1000
											).toISOString()
									  )
									: '' }
							</td>
							<td>{ formatSize( file.size ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
