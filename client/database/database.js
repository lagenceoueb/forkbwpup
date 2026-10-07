/**
 * Résumé des opérations de maintenance de la base.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Libellé du résultat d'une table.
 *
 * @param {string} status ok, unsupported, warning ou error.
 * @return {string} Libellé.
 */
export function resultLabel( status ) {
	switch ( status ) {
		case 'ok':
			return __( 'OK', 'oueb-wp-backup' );
		case 'unsupported':
			return __( 'Not needed', 'oueb-wp-backup' );
		case 'warning':
			return __( 'Warning', 'oueb-wp-backup' );
		default:
			return __( 'Error', 'oueb-wp-backup' );
	}
}

/**
 * Résume le résultat d'une opération en une phrase.
 *
 * @param {string} operation check, repair ou optimize.
 * @param {Array}  results   Résultat par table.
 * @return {{status: string, message: string}} Statut de la notice et phrase.
 */
export function summarizeResults( operation, results ) {
	const count = results.length;
	const errors = results.filter( ( r ) => 'error' === r.status ).length;
	const warnings = results.filter( ( r ) => 'warning' === r.status ).length;
	const unsupported = results.filter(
		( r ) => 'unsupported' === r.status
	).length;

	if ( errors > 0 ) {
		return {
			status: 'error',
			message: sprintf(
				/* translators: 1: number of tables with an error, 2: number of tables. */
				_n(
					'%1$d table out of %2$d has an error. Try to repair the tables, then check them again.',
					'%1$d tables out of %2$d have an error. Try to repair the tables, then check them again.',
					errors,
					'oueb-wp-backup'
				),
				errors,
				count
			),
		};
	}
	if ( warnings > 0 ) {
		return {
			status: 'warning',
			message: sprintf(
				/* translators: 1: number of tables with a warning, 2: number of tables. */
				_n(
					'%1$d table out of %2$d has a warning. Read the message below.',
					'%1$d tables out of %2$d have a warning. Read the messages below.',
					warnings,
					'oueb-wp-backup'
				),
				warnings,
				count
			),
		};
	}
	if ( 'repair' === operation && unsupported === count ) {
		return {
			status: 'success',
			message: __(
				'Nothing to repair: the database engine of these tables repairs them by itself.',
				'oueb-wp-backup'
			),
		};
	}

	if ( 'repair' === operation ) {
		// Une table qu'InnoDB n'a pas besoin de réparer n'est pas comptée réparée.
		const repaired = count - unsupported;
		return {
			status: 'success',
			message: sprintf(
				/* translators: %d: number of tables. */
				_n(
					'%d table repaired.',
					'%d tables repaired.',
					repaired,
					'oueb-wp-backup'
				),
				repaired
			),
		};
	}
	if ( 'optimize' === operation ) {
		return {
			status: 'success',
			message: sprintf(
				/* translators: %d: number of tables. */
				_n(
					'%d table optimized.',
					'%d tables optimized.',
					count,
					'oueb-wp-backup'
				),
				count
			),
		};
	}
	return {
		status: 'success',
		message: sprintf(
			/* translators: %d: number of tables. */
			_n(
				'%d table checked: no problem found.',
				'%d tables checked: no problem found.',
				count,
				'oueb-wp-backup'
			),
			count
		),
	};
}
