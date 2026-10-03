/**
 * Validation des réglages avant envoi, alignée sur le schéma PHP.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Bornes des réglages numériques, identiques à Settings::fields().
 */
export const NUMBER_LIMITS = {
	max_execution_time: { min: 10, max: 300 },
	step_retries: { min: 1, max: 10 },
	max_logs: { min: 1, max: 1000 },
};

/**
 * Longueur minimale de la clé de déclenchement.
 */
export const TRIGGER_KEY_MIN_LENGTH = 32;

/**
 * Vérifie les réglages saisis.
 *
 * @param {Object} values Réglages du formulaire.
 * @return {Object} Messages d'erreur, par nom de réglage. Vide si tout est valide.
 */
export function validateSettings( values ) {
	const errors = {};

	Object.entries( NUMBER_LIMITS ).forEach( ( [ name, { min, max } ] ) => {
		const raw = String( values[ name ] ?? '' ).trim();
		const value = Number( raw );
		if ( ! /^\d+$/.test( raw ) || value < min || value > max ) {
			errors[ name ] = sprintf(
				/* translators: 1: minimum value, 2: maximum value. */
				__(
					'Enter a whole number between %1$d and %2$d.',
					'oueb-wp-backup'
				),
				min,
				max
			);
		}
	} );

	const key = String( values.trigger_key ?? '' );
	if (
		key.length < TRIGGER_KEY_MIN_LENGTH ||
		! /^[A-Za-z0-9]+$/.test( key )
	) {
		errors.trigger_key = sprintf(
			/* translators: %d: minimum number of characters. */
			__(
				'Use at least %d letters and digits, without spaces or symbols.',
				'oueb-wp-backup'
			),
			TRIGGER_KEY_MIN_LENGTH
		);
	}

	return errors;
}

/**
 * Prépare les réglages à envoyer à l'API.
 *
 * Les nombres partent en entiers. La clé cron-job.org ne part que si elle a
 * été saisie, pour ne pas effacer celle qui est enregistrée.
 *
 * @param {Object} values Réglages du formulaire.
 * @return {Object} Corps de la requête.
 */
export function toPayload( values ) {
	const payload = {
		show_agency_card: Boolean( values.show_agency_card ),
		trigger_key: String( values.trigger_key ),
	};

	Object.keys( NUMBER_LIMITS ).forEach( ( name ) => {
		payload[ name ] = Number( values[ name ] );
	} );

	if ( values.cronjob_org_key ) {
		payload.cronjob_org_key = values.cronjob_org_key;
	}

	return payload;
}

/**
 * Génère une clé de déclenchement aléatoire.
 *
 * @param {number} length Nombre de caractères.
 * @return {string} Clé de lettres et chiffres.
 */
export function generateKey( length = 40 ) {
	const alphabet =
		'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	const bytes = new Uint32Array( length );
	window.crypto.getRandomValues( bytes );
	return Array.from(
		bytes,
		( byte ) => alphabet[ byte % alphabet.length ]
	).join( '' );
}
