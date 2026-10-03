/**
 * Accès à l'API REST de l'extension.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const BASE = '/oueb-wp-backup/v1';

/**
 * Renvoie l'historique des exécutions, avec le total.
 *
 * @param {number} perPage Nombre par page.
 * @param {number} page    Page, à partir de 1.
 * @return {Promise<{runs: Array, total: number}>} Exécutions et total.
 */
export async function fetchRuns( perPage = 20, page = 1 ) {
	const response = await apiFetch( {
		path: `${ BASE }/runs?per_page=${ perPage }&page=${ page }`,
		parse: false,
	} );
	const runs = await response.json();
	return {
		runs,
		total: Number( response.headers.get( 'X-WP-Total' ) || runs.length ),
	};
}

/**
 * Renvoie une exécution.
 *
 * @param {number} id Exécution.
 * @return {Promise<Object>} Exécution.
 */
export function fetchRun( id ) {
	return apiFetch( { path: `${ BASE }/runs/${ id }` } );
}

/**
 * Renvoie le journal d'une exécution.
 *
 * @param {number} id Exécution.
 * @return {Promise<Array>} Entrées du journal.
 */
export function fetchLog( id ) {
	return apiFetch( { path: `${ BASE }/runs/${ id }/log` } );
}

/**
 * Lance une tâche.
 *
 * @param {string} jobId Tâche.
 * @return {Promise<Object>} Exécution créée.
 */
export function startRun( jobId = 'main' ) {
	return apiFetch( {
		path: `${ BASE }/runs`,
		method: 'POST',
		data: { job_id: jobId },
	} );
}

/**
 * Demande l'arrêt d'une exécution.
 *
 * @param {number} id Exécution.
 * @return {Promise<Object>} Exécution.
 */
export function abortRun( id ) {
	return apiFetch( { path: `${ BASE }/runs/${ id }/abort`, method: 'POST' } );
}

/**
 * Renvoie une tâche.
 *
 * @param {string} id Tâche.
 * @return {Promise<Object>} Tâche.
 */
export function fetchJob( id = 'main' ) {
	return apiFetch( { path: `${ BASE }/jobs/${ id }` } );
}
