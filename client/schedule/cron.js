/**
 * Fréquences proposées et conversion en expression cron.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Lit une heure « HH:MM ».
 *
 * @param {string} time Heure.
 * @return {{hour: number, minute: number}} Heure et minute.
 */
function parseTime( time ) {
	const [ hour, minute ] = String( time || '03:00' )
		.split( ':' )
		.map( ( part ) => parseInt( part, 10 ) );
	return {
		hour: Number.isInteger( hour )
			? Math.min( 23, Math.max( 0, hour ) )
			: 3,
		minute: Number.isInteger( minute )
			? Math.min( 59, Math.max( 0, minute ) )
			: 0,
	};
}

/**
 * Écrit une heure « HH:MM ».
 *
 * @param {number} hour   Heure.
 * @param {number} minute Minute.
 * @return {string} Heure.
 */
function formatTime( hour, minute ) {
	return `${ String( hour ).padStart( 2, '0' ) }:${ String( minute ).padStart(
		2,
		'0'
	) }`;
}

/**
 * Reconnaît une fréquence proposée dans une expression cron.
 *
 * @param {string} cron Expression à cinq champs.
 * @return {{preset: string, time: string, weekday: string, monthday: string, cron: string}} Réglages du formulaire.
 */
export function presetFromCron( cron ) {
	const fields = String( cron || '' )
		.trim()
		.split( /\s+/ );
	const base = {
		preset: 'custom',
		time: '03:00',
		weekday: '1',
		monthday: '1',
		cron: fields.join( ' ' ),
	};
	if ( fields.length !== 5 || ! /^\d+$/.test( fields[ 0 ] ) ) {
		return base;
	}
	const [ minute, hour, mday, month, wday ] = fields;
	const twice = /^(\d+),(\d+)$/.exec( hour );
	if ( twice && mday === '*' && month === '*' && wday === '*' ) {
		if ( Number( twice[ 2 ] ) - Number( twice[ 1 ] ) === 12 ) {
			return {
				...base,
				preset: 'twice',
				time: formatTime( Number( twice[ 1 ] ), Number( minute ) ),
			};
		}
		return base;
	}
	if ( ! /^\d+$/.test( hour ) || month !== '*' ) {
		return base;
	}
	const time = formatTime( Number( hour ), Number( minute ) );
	if ( mday === '*' && wday === '*' ) {
		return { ...base, preset: 'daily', time };
	}
	if ( mday === '*' && /^[0-6]$/.test( wday ) ) {
		return { ...base, preset: 'weekly', time, weekday: wday };
	}
	if ( /^\d+$/.test( mday ) && Number( mday ) <= 28 && wday === '*' ) {
		return { ...base, preset: 'monthly', time, monthday: mday };
	}
	return base;
}

/**
 * Construit l'expression cron d'une fréquence proposée.
 *
 * @param {Object} form          Réglages du formulaire.
 * @param {string} form.preset   daily, twice, weekly, monthly ou custom.
 * @param {string} form.time     Heure « HH:MM ».
 * @param {string} form.weekday  Jour de la semaine, 0 pour dimanche.
 * @param {string} form.monthday Jour du mois, de 1 à 28.
 * @param {string} form.cron     Expression saisie, pour custom.
 * @return {string} Expression à cinq champs.
 */
export function cronFromPreset( { preset, time, weekday, monthday, cron } ) {
	const { hour, minute } = parseTime( time );
	switch ( preset ) {
		case 'daily':
			return `${ minute } ${ hour } * * *`;
		case 'twice':
			return `${ minute } ${ hour % 12 },${ ( hour % 12 ) + 12 } * * *`;
		case 'weekly':
			return `${ minute } ${ hour } * * ${ weekday }`;
		case 'monthly':
			return `${ minute } ${ hour } ${ monthday } * *`;
		default:
			return String( cron || '' )
				.trim()
				.replace( /\s+/g, ' ' );
	}
}

/**
 * Renvoie les jours de la semaine, lundi en premier.
 *
 * @return {Array<{value: string, label: string}>} Jours.
 */
export function weekdays() {
	return [
		{ value: '1', label: __( 'Monday', 'oueb-wp-backup' ) },
		{ value: '2', label: __( 'Tuesday', 'oueb-wp-backup' ) },
		{ value: '3', label: __( 'Wednesday', 'oueb-wp-backup' ) },
		{ value: '4', label: __( 'Thursday', 'oueb-wp-backup' ) },
		{ value: '5', label: __( 'Friday', 'oueb-wp-backup' ) },
		{ value: '6', label: __( 'Saturday', 'oueb-wp-backup' ) },
		{ value: '0', label: __( 'Sunday', 'oueb-wp-backup' ) },
	];
}

/**
 * Décrit la planification d'une tâche en une phrase.
 *
 * @param {Object} job Tâche : trigger et schedule.
 * @return {string} Description.
 */
export function describeSchedule( job ) {
	if ( ! job || 'manual' === job.trigger ) {
		return __( 'Only when you click “Back up now”.', 'oueb-wp-backup' );
	}
	const form = presetFromCron( job.schedule );
	switch ( form.preset ) {
		case 'daily':
			return sprintf(
				/* translators: %s: time, such as 03:00. */
				__( 'Every day at %s.', 'oueb-wp-backup' ),
				form.time
			);
		case 'twice':
			return sprintf(
				/* translators: %s: time, such as 03:00. */
				__(
					'Twice a day, at %s and 12 hours later.',
					'oueb-wp-backup'
				),
				form.time
			);
		case 'weekly':
			return sprintf(
				/* translators: 1: day of the week, 2: time. */
				__( 'Every %1$s at %2$s.', 'oueb-wp-backup' ),
				weekdays().find( ( day ) => day.value === form.weekday ).label,
				form.time
			);
		case 'monthly':
			return sprintf(
				/* translators: 1: day of the month, 2: time. */
				__( 'Every month, on day %1$s at %2$s.', 'oueb-wp-backup' ),
				form.monthday,
				form.time
			);
		default:
			return sprintf(
				/* translators: %s: cron expression. */
				__( 'Custom schedule: %s.', 'oueb-wp-backup' ),
				job.schedule
			);
	}
}
