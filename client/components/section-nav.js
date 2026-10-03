/**
 * Menu des sections de l'extension.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getSections } from '../sections';

/**
 * Affiche le menu des sections, avec la section active signalée.
 *
 * @param {Object} props         Propriétés.
 * @param {string} props.current Identifiant de la section active.
 * @return {Element} Menu.
 */
export default function SectionNav( { current } ) {
	return (
		<nav aria-label={ __( 'Oueb WP Backup sections', 'oueb-wp-backup' ) }>
			<ul className="oueb-nav">
				{ getSections().map( ( section ) => (
					<li key={ section.id }>
						<a
							className="oueb-nav__link"
							href={ `#/${ section.id }` }
							aria-current={
								section.id === current ? 'page' : undefined
							}
						>
							{ section.label }
						</a>
					</li>
				) ) }
			</ul>
		</nav>
	);
}
