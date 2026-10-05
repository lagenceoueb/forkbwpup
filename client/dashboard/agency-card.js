/**
 * Encart de L'agence Oueb, sur le tableau de bord.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Présente l'agence qui édite l'extension, avec un lien vers son site.
 *
 * @param {Object}   props        Propriétés.
 * @param {Function} props.onHide Appelée pour masquer l'encart.
 * @return {Element} Encart.
 */
export default function AgencyCard( { onHide } ) {
	const url =
		( window.ouebWpBackup && window.ouebWpBackup.agencyUrl ) ||
		'https://lagenceoueb.tech';

	return (
		<aside className="oueb-card oueb-agency" aria-labelledby="oueb-agency">
			<div className="oueb-agency__text">
				<h2 id="oueb-agency" className="oueb-card__title">
					{ __( 'Need a hand?', 'oueb-wp-backup' ) }
				</h2>
				<p>
					{ __(
						'Oueb WP Backup is made by L’agence Oueb. For help with your backups or your site, contact the agency.',
						'oueb-wp-backup'
					) }
				</p>
				<a href={ url } className="oueb-agency__link">
					{ __( 'Discover L’agence Oueb', 'oueb-wp-backup' ) }
					<span className="screen-reader-text">
						{ ' ' }
						{ __( '(lagenceoueb.tech)', 'oueb-wp-backup' ) }
					</span>
				</a>
			</div>
			<Button
				variant="link"
				className="oueb-agency__hide"
				onClick={ onHide }
			>
				{ __( 'Hide this card', 'oueb-wp-backup' ) }
			</Button>
		</aside>
	);
}
