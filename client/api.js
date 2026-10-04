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

/**
 * Enregistre des modifications d'une tâche.
 *
 * @param {string} id      Tâche.
 * @param {Object} changes Champs modifiés.
 * @return {Promise<Object>} Tâche.
 */
export function saveJob( id, changes ) {
	return apiFetch( {
		path: `${ BASE }/jobs/${ id }`,
		method: 'POST',
		data: changes,
	} );
}

/**
 * Renvoie les stockages.
 *
 * @return {Promise<Array>} Stockages.
 */
export function fetchStorages() {
	return apiFetch( { path: `${ BASE }/storages` } );
}

/**
 * Renvoie les types de stockage et les fournisseurs S3.
 *
 * @return {Promise<Object>} Types, fournisseurs et date de vérification.
 */
export function fetchStorageTypes() {
	return apiFetch( { path: `${ BASE }/storages/types` } );
}

/**
 * Crée ou modifie un stockage.
 *
 * @param {string|null} id      Stockage, null pour en créer un.
 * @param {Object}      payload Type, nom et réglages.
 * @return {Promise<Object>} Stockage.
 */
export function saveStorage( id, payload ) {
	return apiFetch( {
		path: id ? `${ BASE }/storages/${ id }` : `${ BASE }/storages`,
		method: 'POST',
		data: payload,
	} );
}

/**
 * Supprime un stockage.
 *
 * @param {string} id Stockage.
 * @return {Promise<Object>} Accusé.
 */
export function deleteStorage( id ) {
	return apiFetch( { path: `${ BASE }/storages/${ id }`, method: 'DELETE' } );
}

/**
 * Teste un stockage.
 *
 * @param {string} id Stockage.
 * @return {Promise<{message: string}>} Compte rendu.
 */
export function testStorage( id ) {
	return apiFetch( {
		path: `${ BASE }/storages/${ id }/test`,
		method: 'POST',
	} );
}

/**
 * Liste les archives d'un stockage.
 *
 * @param {string} id Stockage.
 * @return {Promise<Array>} Archives.
 */
export function fetchStorageFiles( id ) {
	return apiFetch( { path: `${ BASE }/storages/${ id }/files` } );
}

/**
 * Supprime l'archive d'une exécution de tous ses stockages.
 *
 * @param {number} id Exécution.
 * @return {Promise<Object>} Exécution.
 */
export function deleteArchive( id ) {
	return apiFetch( {
		path: `${ BASE }/runs/${ id }/archive`,
		method: 'DELETE',
	} );
}

/**
 * Liste les clés de chiffrement, sans leur valeur.
 *
 * @return {Promise<Array>} Clés.
 */
export function fetchKeys() {
	return apiFetch( { path: `${ BASE }/encryption/keys` } );
}

/**
 * Crée une clé, ou importe celle donnée.
 *
 * @param {string} key Clé en base64, vide pour en créer une.
 * @return {Promise<{id: string, key: string}>} Clé.
 */
export function createKey( key = '' ) {
	return apiFetch( {
		path: `${ BASE }/encryption/keys`,
		method: 'POST',
		data: key ? { key } : {},
	} );
}

/**
 * Exporte une clé.
 *
 * @param {string} id Identifiant.
 * @return {Promise<{id: string, key: string}>} Clé.
 */
export function exportKey( id ) {
	return apiFetch( { path: `${ BASE }/encryption/keys/${ id }` } );
}

/**
 * Renvoie les réglages.
 *
 * @return {Promise<Object>} Réglages, sans secret.
 */
export function fetchSettings() {
	return apiFetch( { path: `${ BASE }/settings` } );
}
