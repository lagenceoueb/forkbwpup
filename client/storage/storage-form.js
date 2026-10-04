/**
 * Formulaire d'ajout ou de modification d'un stockage.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	RadioControl,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { saveStorage, testStorage } from '../api';
import {
	fieldText,
	initialValues,
	toPayload,
	validateStorage,
	visibleFields,
} from './fields';
import ProviderCriteria from './provider-criteria';

/**
 * Renvoie le type HTML du champ de saisie.
 *
 * @param {Object} def Champ.
 * @return {string} Type : password, number ou text.
 */
function inputType( def ) {
	if ( def.secret ) {
		return 'password';
	}
	return 'int' === def.type ? 'number' : 'text';
}

/**
 * Saisit les réglages d'un stockage, l'enregistre puis le teste.
 *
 * @param {Object}      props          Propriétés.
 * @param {Object}      props.meta     Types, fournisseurs et date de vérification.
 * @param {Object|null} props.storage  Stockage à modifier, ou null pour en créer un.
 * @param {Function}    props.onSaved  Appelée avec le stockage enregistré.
 * @param {Function}    props.onCancel Appelée quand l'administrateur abandonne.
 * @return {Element} Formulaire.
 */
export default function StorageForm( { meta, storage, onSaved, onCancel } ) {
	const creatable = meta.types.filter(
		( type ) => 'folder' !== type.id || storage
	);
	const [ type, setType ] = useState( storage ? storage.type : 's3' );
	const typeDef = meta.types.find( ( item ) => item.id === type );
	const [ name, setName ] = useState( storage ? storage.name : '' );
	const [ values, setValues ] = useState( () =>
		initialValues( typeDef, storage )
	);
	const [ errors, setErrors ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const changeType = ( next ) => {
		const def = meta.types.find( ( item ) => item.id === next );
		setType( next );
		setValues( initialValues( def ) );
		setErrors( {} );
	};

	const update = ( field ) => ( value ) => {
		const next = { ...values, [ field ]: value };
		if ( 'provider' === field ) {
			next.region = '';
		}
		setValues( next );
	};

	const provider = meta.providers.find(
		( item ) => item.id === values.provider
	);

	const submit = ( event ) => {
		event.preventDefault();
		const found = validateStorage( typeDef, name, values, storage );
		setErrors( found );
		if ( Object.keys( found ).length > 0 ) {
			const message = __(
				'Some fields are not valid. Check the highlighted fields.',
				'oueb-wp-backup'
			);
			setNotice( { status: 'error', message } );
			speak( message, 'assertive' );
			return;
		}

		setBusy( true );
		setNotice( null );
		saveStorage( storage?.id, toPayload( typeDef, name, values ) )
			.then( ( saved ) =>
				testStorage( saved.id )
					.then( ( result ) =>
						onSaved( saved, {
							status: 'success',
							message: result.message,
						} )
					)
					.catch( ( error ) =>
						onSaved( saved, {
							status: 'warning',
							message:
								__(
									'Storage saved, but the connection test failed:',
									'oueb-wp-backup'
								) +
								' ' +
								error.message,
						} )
					)
			)
			.catch( ( error ) => {
				if ( error.data?.field ) {
					setErrors( { [ error.data.field ]: error.message } );
				}
				setNotice( { status: 'error', message: error.message } );
				speak( error.message, 'assertive' );
			} )
			.finally( () => setBusy( false ) );
	};

	const control = ( field ) => {
		const def = typeDef.fields[ field ];
		const text = fieldText( type, field );
		const common = {
			__nextHasNoMarginBottom: true,
			label: text.label,
			help: errors[ field ] || text.help,
			className: errors[ field ] ? 'oueb-field--invalid' : undefined,
		};

		if ( 's3' === type && 'provider' === field ) {
			return (
				<SelectControl
					{ ...common }
					__next40pxDefaultSize
					value={ values.provider }
					options={ [
						...meta.providers.map( ( item ) => ( {
							value: item.id,
							label: `${ item.name } (${ item.country })`,
						} ) ),
						{
							value: 'custom',
							label: __(
								'Other S3-compatible service',
								'oueb-wp-backup'
							),
						},
					] }
					onChange={ update( field ) }
				/>
			);
		}
		if ( 's3' === type && 'region' === field && provider ) {
			return (
				<SelectControl
					{ ...common }
					__next40pxDefaultSize
					value={ values.region }
					options={ [
						{
							value: '',
							label: __( 'Choose a region', 'oueb-wp-backup' ),
						},
						...provider.regions.map( ( region ) => ( {
							value: region.id,
							label: `${ region.name } (${ region.country })`,
						} ) ),
					] }
					onChange={ update( field ) }
				/>
			);
		}
		if ( 'sftp' === type && 'auth' === field ) {
			return (
				<RadioControl
					label={ text.label }
					selected={ values.auth }
					options={ [
						{
							value: 'password',
							label: __( 'Password', 'oueb-wp-backup' ),
						},
						{
							value: 'key',
							label: __( 'Private key', 'oueb-wp-backup' ),
						},
					] }
					onChange={ update( field ) }
				/>
			);
		}
		if ( 'bool' === def.type ) {
			return (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ text.label }
					help={ text.help }
					checked={ Boolean( values[ field ] ) }
					onChange={ update( field ) }
				/>
			);
		}

		const savedHelp =
			def.secret && storage?.secrets_set?.[ field ]
				? __( 'Saved. Leave empty to keep it.', 'oueb-wp-backup' )
				: '';
		if ( 'text' === def.type ) {
			return (
				<TextareaControl
					{ ...common }
					help={ errors[ field ] || savedHelp || text.help }
					value={ values[ field ] }
					rows={ 6 }
					className={ `oueb-field--code ${ common.className || '' }` }
					spellCheck={ false }
					onChange={ update( field ) }
				/>
			);
		}
		return (
			<TextControl
				{ ...common }
				__next40pxDefaultSize
				help={ errors[ field ] || savedHelp || text.help }
				type={ inputType( def ) }
				autoComplete={ def.secret ? 'new-password' : 'off' }
				value={ String( values[ field ] ?? '' ) }
				aria-invalid={ errors[ field ] ? true : undefined }
				onChange={ update( field ) }
			/>
		);
	};

	return (
		<form className="oueb-card oueb-form" onSubmit={ submit } noValidate>
			<h2 className="oueb-card__title">
				{ storage
					? __( 'Edit the storage', 'oueb-wp-backup' )
					: __( 'Add a storage', 'oueb-wp-backup' ) }
			</h2>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ ! storage && (
				<RadioControl
					label={ __( 'Type of storage', 'oueb-wp-backup' ) }
					selected={ type }
					options={ creatable.map( ( item ) => ( {
						value: item.id,
						label: item.label,
					} ) ) }
					onChange={ changeType }
				/>
			) }
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Name', 'oueb-wp-backup' ) }
				help={
					errors.name ||
					__(
						'Shown in the lists, such as “Scaleway Paris”.',
						'oueb-wp-backup'
					)
				}
				className={ errors.name ? 'oueb-field--invalid' : undefined }
				aria-invalid={ errors.name ? true : undefined }
				value={ name }
				onChange={ setName }
			/>
			{ visibleFields( typeDef, values ).map( ( field ) => (
				<div key={ field }>{ control( field ) }</div>
			) ) }
			{ 's3' === type && provider && (
				<ProviderCriteria
					provider={ provider }
					checkedOn={ meta.checked_on }
				/>
			) }
			{ 'sftp' === type && storage?.settings?.fingerprint && (
				<div className="oueb-fingerprint">
					<p>
						{ __( 'Saved server key:', 'oueb-wp-backup' ) }{ ' ' }
						<code>{ storage.settings.fingerprint }</code>
					</p>
					<p>
						{ __(
							'The connection is refused if the server presents another key. If the server was reinstalled, forget the saved key: the next connection will save the new one.',
							'oueb-wp-backup'
						) }
					</p>
					<Button
						variant="secondary"
						isDestructive
						disabled={ busy }
						onClick={ () => {
							setBusy( true );
							saveStorage( storage.id, {
								settings: { fingerprint: '' },
							} )
								.then( ( saved ) =>
									onSaved( saved, {
										status: 'success',
										message: __(
											'Server key forgotten.',
											'oueb-wp-backup'
										),
									} )
								)
								.catch( ( error ) =>
									setNotice( {
										status: 'error',
										message: error.message,
									} )
								)
								.finally( () => setBusy( false ) );
						} }
					>
						{ __( 'Forget the server key', 'oueb-wp-backup' ) }
					</Button>
				</div>
			) }
			<div className="oueb-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Save and test', 'oueb-wp-backup' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ __( 'Cancel', 'oueb-wp-backup' ) }
				</Button>
			</div>
		</form>
	);
}
