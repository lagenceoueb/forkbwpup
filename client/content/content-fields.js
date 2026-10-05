/**
 * Champs du contenu d'une tâche : parties du site, exclusions, format.
 */

/**
 * WordPress dependencies
 */
import {
	CheckboxControl,
	RadioControl,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { contentParts, hasContent } from './content';

/**
 * Affiche les champs du contenu, sans les enregistrer.
 *
 * @param {Object}   props          Propriétés.
 * @param {Object}   props.value    Brouillon : include_*, exclusions (texte), archive_format.
 * @param {Function} props.onChange Appelée avec le brouillon modifié.
 * @param {boolean}  props.advanced Vrai pour montrer exclusions et format.
 * @return {Element} Champs.
 */
export default function ContentFields( { value, onChange, advanced = true } ) {
	const update = ( key ) => ( next ) =>
		onChange( { ...value, [ key ]: next } );

	return (
		<>
			<fieldset className="oueb-fieldset">
				<legend className="oueb-fieldset__legend">
					{ __( 'What to back up', 'oueb-wp-backup' ) }
				</legend>
				{ contentParts().map( ( part ) => (
					<CheckboxControl
						key={ part.key }
						__nextHasNoMarginBottom
						label={ part.label }
						help={ part.help }
						checked={ Boolean( value[ part.key ] ) }
						onChange={ update( part.key ) }
					/>
				) ) }
				{ ! hasContent( value ) && (
					<p className="oueb-help" role="alert">
						{ __(
							'Choose the database or at least one folder.',
							'oueb-wp-backup'
						) }
					</p>
				) }
			</fieldset>

			{ advanced && (
				<>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Files and folders to leave out',
							'oueb-wp-backup'
						) }
						help={ __(
							'One rule per line, from the root of the site. * stands for any text: wp-content/uploads/videos, *.log.',
							'oueb-wp-backup'
						) }
						value={ value.exclusions }
						onChange={ update( 'exclusions' ) }
						rows={ 4 }
						className="oueb-field--code"
					/>
					<RadioControl
						label={ __( 'Archive format', 'oueb-wp-backup' ) }
						selected={ value.archive_format }
						options={ [
							{
								value: 'zip',
								label: __(
									'zip: opens on any computer',
									'oueb-wp-backup'
								),
							},
							{
								value: 'tar.gz',
								label: __(
									'tar.gz: smaller, for servers',
									'oueb-wp-backup'
								),
							},
						] }
						onChange={ update( 'archive_format' ) }
					/>
				</>
			) }
		</>
	);
}

/**
 * Crée le brouillon des champs à partir d'une tâche.
 *
 * @param {Object} job Tâche.
 * @return {Object} Brouillon.
 */
export function draftFromJob( job ) {
	const draft = {
		exclusions: ( job.exclude || [] ).join( '\n' ),
		archive_format: job.archive_format,
	};
	contentParts().forEach( ( part ) => {
		draft[ part.key ] = Boolean( job[ part.key ] );
	} );
	return draft;
}
