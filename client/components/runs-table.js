/**
 * Tableau des sauvegardes.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { contentsLabel, formatDate, formatSize, statusLabel } from '../format';

/**
 * Affiche une liste de sauvegardes, avec leurs actions.
 *
 * @param {Object}   props          Propriétés.
 * @param {Array}    props.runs     Exécutions.
 * @param {string}   props.caption  Légende du tableau, lue par les lecteurs d'écran.
 * @param {Function} props.onDelete Appelée avec l'exécution dont l'archive est à supprimer. Sans elle, pas de bouton.
 * @return {Element} Tableau.
 */
export default function RunsTable( { runs, caption, onDelete } ) {
	if ( runs.length === 0 ) {
		return (
			<p className="oueb-empty">
				{ __(
					'No backup yet. Start the first one with the “Back up now” button.',
					'oueb-wp-backup'
				) }
			</p>
		);
	}

	return (
		<div className="oueb-table-wrap">
			<table className="oueb-table">
				<caption className="screen-reader-text">{ caption }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Date', 'oueb-wp-backup' ) }</th>
						<th scope="col">
							{ __( 'Content', 'oueb-wp-backup' ) }
						</th>
						<th scope="col">{ __( 'Size', 'oueb-wp-backup' ) }</th>
						<th scope="col">
							{ __( 'Stored in', 'oueb-wp-backup' ) }
						</th>
						<th scope="col">
							{ __( 'Status', 'oueb-wp-backup' ) }
						</th>
						<th scope="col">
							<span className="screen-reader-text">
								{ __( 'Actions', 'oueb-wp-backup' ) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ runs.map( ( run ) => {
						const date = formatDate( run.started_at );
						return (
							<tr key={ run.id }>
								<th scope="row">{ date }</th>
								<td>{ contentsLabel( run.contents ) }</td>
								<td>
									{ run.archive_size
										? formatSize( run.archive_size )
										: '' }
								</td>
								<td>
									{ run.archive_file
										? ( run.storage_names || [] ).join(
												', '
										  )
										: '' }
								</td>
								<td>
									<span
										className={ `oueb-status oueb-status--${ run.status }` }
									>
										{ statusLabel( run ) }
									</span>
								</td>
								<td className="oueb-table__actions">
									{ run.download_url && (
										<a
											href={ run.download_url }
											aria-label={ sprintf(
												/* translators: %s: backup date. */
												__(
													'Download the backup of %s',
													'oueb-wp-backup'
												),
												date
											) }
										>
											{ __(
												'Download',
												'oueb-wp-backup'
											) }
										</a>
									) }
									<a
										href={ `#/log/${ run.id }` }
										aria-label={ sprintf(
											/* translators: %s: backup date. */
											__(
												'Log of the backup of %s',
												'oueb-wp-backup'
											),
											date
										) }
									>
										{ __( 'Log', 'oueb-wp-backup' ) }
									</a>
									{ run.download_decrypted_url && (
										<a
											href={ run.download_decrypted_url }
											aria-label={ sprintf(
												/* translators: %s: backup date. */
												__(
													'Download the decrypted backup of %s',
													'oueb-wp-backup'
												),
												date
											) }
										>
											{ __(
												'Decrypted',
												'oueb-wp-backup'
											) }
										</a>
									) }
									{ run.download_url && (
										<a
											href={ `#/restore/${ run.id }` }
											aria-label={ sprintf(
												/* translators: %s: backup date. */
												__(
													'Restore the backup of %s',
													'oueb-wp-backup'
												),
												date
											) }
										>
											{ __(
												'Restore',
												'oueb-wp-backup'
											) }
										</a>
									) }
									{ onDelete && run.download_url && (
										<Button
											variant="link"
											isDestructive
											aria-label={ sprintf(
												/* translators: %s: backup date. */
												__(
													'Delete the backup of %s',
													'oueb-wp-backup'
												),
												date
											) }
											onClick={ () => onDelete( run ) }
										>
											{ __( 'Delete', 'oueb-wp-backup' ) }
										</Button>
									) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
