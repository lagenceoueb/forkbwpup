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
 * @param {string} kind    Nature : backup, restore, ou vide pour toutes.
 * @return {Promise<{runs: Array, total: number}>} Exécutions et total.
 */
export async function fetchRuns( perPage = 20, page = 1, kind = '' ) {
	const response = await apiFetch( {
		path: `${ BASE }/runs?per_page=${ perPage }&page=${ page }&kind=${ kind }`,
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

/**
 * Lance une restauration.
 *
 * @param {Object}  payload          Réglages.
 * @param {Object}  payload.source   Source : { type: 'run', run_id }, { type: 'storage', storage_id, name } ou { type: 'upload', upload_id }.
 * @param {boolean} payload.database Restaurer la base.
 * @param {boolean} payload.files    Restaurer les fichiers.
 * @param {boolean} payload.safety   Sauvegarder le site actuel avant.
 * @return {Promise<Object>} Exécution créée.
 */
export function startRestore( payload ) {
	return apiFetch( {
		path: `${ BASE }/restore`,
		method: 'POST',
		data: payload,
	} );
}

/**
 * Commence l'envoi d'une archive.
 *
 * @param {string} name Nom du fichier.
 * @param {number} size Taille en octets.
 * @return {Promise<Object>} Envoi : id, received, chunk_size.
 */
export function createUpload( name, size ) {
	return apiFetch( {
		path: `${ BASE }/restore/uploads`,
		method: 'POST',
		data: { name, size },
	} );
}

/**
 * Renvoie l'état d'un envoi.
 *
 * @param {string} id Envoi.
 * @return {Promise<Object>} Envoi.
 */
export function fetchUpload( id ) {
	return apiFetch( { path: `${ BASE }/restore/uploads/${ id }` } );
}

/**
 * Envoie un morceau d'archive.
 *
 * @param {string} id     Envoi.
 * @param {number} offset Position du morceau.
 * @param {Blob}   chunk  Octets.
 * @return {Promise<Object>} Envoi mis à jour.
 */
export function sendUploadChunk( id, offset, chunk ) {
	return apiFetch( {
		path: `${ BASE }/restore/uploads/${ id }?offset=${ offset }`,
		method: 'PUT',
		body: chunk,
		headers: { 'Content-Type': 'application/octet-stream' },
	} );
}

/**
 * Abandonne un envoi.
 *
 * @param {string} id Envoi.
 * @return {Promise<Object>} Accusé.
 */
export function deleteUpload( id ) {
	return apiFetch( {
		path: `${ BASE }/restore/uploads/${ id }`,
		method: 'DELETE',
	} );
}
