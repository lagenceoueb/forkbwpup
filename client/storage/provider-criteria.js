/**
 * Fiche d'un fournisseur S3 : critères du cadrage et sources.
 */

/**
 * WordPress dependencies
 */
import { dateI18n, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Renvoie le libellé d'un critère.
 *
 * @param {string} key Critère.
 * @return {string} Libellé.
 */
function criterionLabel( key ) {
	const labels = {
		location: __( 'Location', 'oueb-wp-backup' ),
		parent: __( 'Parent company', 'oueb-wp-backup' ),
		renewable: __( 'Renewable electricity', 'oueb-wp-backup' ),
		pue: __( 'PUE (energy efficiency)', 'oueb-wp-backup' ),
		cooling: __( 'Cooling and heat reuse', 'oueb-wp-backup' ),
		labels: __( 'Environmental labels', 'oueb-wp-backup' ),
		security: __( 'Security certifications', 'oueb-wp-backup' ),
	};
	return labels[ key ] || key;
}

/**
 * Affiche pourquoi un fournisseur a été retenu.
 *
 * @param {Object} props           Propriétés.
 * @param {Object} props.provider  Fournisseur.
 * @param {string} props.checkedOn Date de vérification, AAAA-MM-JJ.
 * @return {Element} Fiche repliable.
 */
export default function ProviderCriteria( { provider, checkedOn } ) {
	return (
		<details className="oueb-criteria">
			<summary>
				{ sprintf(
					/* translators: %s: provider name. */
					__( 'Why %s?', 'oueb-wp-backup' ),
					provider.name
				) }
			</summary>
			<p>
				{ __(
					'Each provider has its headquarters and data centers in the European Union or Switzerland, has no parent company outside Europe, and proves that its electricity is renewable. The other information is given for reference, with its source.',
					'oueb-wp-backup'
				) }{ ' ' }
				{ sprintf(
					/* translators: %s: date of the last check. */
					__( 'Last checked on %s.', 'oueb-wp-backup' ),
					dateI18n( getSettings().formats.date, checkedOn )
				) }
			</p>
			<dl>
				{ Object.entries( provider.criteria ).map(
					( [ key, criterion ] ) => (
						<div key={ key } className="oueb-criteria__item">
							<dt>{ criterionLabel( key ) }</dt>
							<dd>
								{ criterion.text ||
									__( 'Not published.', 'oueb-wp-backup' ) }
								{ criterion.source && (
									<>
										{ ' ' }
										<a
											href={ criterion.source }
											target="_blank"
											rel="noreferrer"
										>
											{ __( 'Source', 'oueb-wp-backup' ) }
											<span className="screen-reader-text">
												{ sprintf(
													/* translators: %s: criterion name. */
													__(
														'for: %s (opens in a new tab)',
														'oueb-wp-backup'
													),
													criterionLabel( key )
												) }
											</span>
										</a>
									</>
								) }
							</dd>
						</div>
					)
				) }
			</dl>
			{ provider.notes && <p>{ provider.notes }</p> }
		</details>
	);
}
