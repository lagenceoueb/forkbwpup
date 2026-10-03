/**
 * Contenu provisoire des sections pas encore réécrites.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Indique qu'une section arrive avec un prochain lot de la refonte.
 *
 * @return {Element} Message.
 */
export default function Placeholder() {
	return (
		<div className="oueb-card">
			<p>
				{ __(
					'This section is being rewritten. Use the current backup screens in the meantime.',
					'oueb-wp-backup'
				) }
			</p>
		</div>
	);
}
