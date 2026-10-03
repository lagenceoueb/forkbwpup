/**
 * Écran des réglages généraux.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { generateKey, toPayload, validateSettings } from './validation';

const SETTINGS_PATH = '/oueb-wp-backup/v1/settings';

/**
 * Charge, modifie et enregistre les réglages généraux.
 *
 * @return {Element} Formulaire des réglages.
 */
export default function SettingsScreen() {
	const [ values, setValues ] = useState( null );
	const [ errors, setErrors ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		apiFetch( { path: SETTINGS_PATH } )
			.then( ( settings ) =>
				setValues( { ...settings, cronjob_org_key: '' } )
			)
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	if ( null === values ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading settings…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const update = ( name ) => ( value ) =>
		setValues( { ...values, [ name ]: value } );

	const save = ( body ) => {
		setSaving( true );
		setNotice( null );
		return apiFetch( { path: SETTINGS_PATH, method: 'POST', data: body } )
			.then( ( settings ) => {
				setValues( { ...settings, cronjob_org_key: '' } );
				const message = __( 'Settings saved.', 'oueb-wp-backup' );
				setNotice( { status: 'success', message } );
				speak( message );
			} )
			.catch( ( error ) => {
				setNotice( { status: 'error', message: error.message } );
				speak( error.message, 'assertive' );
			} )
			.finally( () => setSaving( false ) );
	};

	const onSubmit = ( event ) => {
		event.preventDefault();
		const found = validateSettings( values );
		setErrors( found );
		if ( Object.keys( found ).length > 0 ) {
			const message = __(
				'Some settings are not valid. Check the highlighted fields.',
				'oueb-wp-backup'
			);
			setNotice( { status: 'error', message } );
			speak( message, 'assertive' );
			return;
		}
		save( toPayload( values ) );
	};

	const field = ( name, label, help ) => (
		<TextControl
			__nextHasNoMarginBottom
			type="number"
			label={ label }
			help={ errors[ name ] || help }
			value={ String( values[ name ] ) }
			onChange={ update( name ) }
			aria-invalid={ errors[ name ] ? true : undefined }
			className={ errors[ name ] ? 'oueb-field--invalid' : undefined }
		/>
	);

	return (
		<form className="oueb-form" onSubmit={ onSubmit } noValidate>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<fieldset className="oueb-card">
				<legend className="oueb-card__title">
					{ __( 'Backup jobs', 'oueb-wp-backup' ) }
				</legend>
				{ field(
					'max_execution_time',
					__( 'Maximum step duration, in seconds', 'oueb-wp-backup' ),
					__(
						'The job restarts before this limit. Keep it below the PHP time limit of your host.',
						'oueb-wp-backup'
					)
				) }
				{ field(
					'step_retries',
					__( 'Attempts per step', 'oueb-wp-backup' ),
					__(
						'A step that fails is tried again up to this number of times.',
						'oueb-wp-backup'
					)
				) }
				{ field(
					'max_logs',
					__( 'Logs to keep', 'oueb-wp-backup' ),
					__(
						'The oldest logs are deleted beyond this number.',
						'oueb-wp-backup'
					)
				) }
			</fieldset>

			<fieldset className="oueb-card">
				<legend className="oueb-card__title">
					{ __( 'Starting backups from outside', 'oueb-wp-backup' ) }
				</legend>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Trigger link key', 'oueb-wp-backup' ) }
					help={
						errors.trigger_key ||
						__(
							'Anyone who knows this key can start a backup. If you change it, update the services that call the trigger link.',
							'oueb-wp-backup'
						)
					}
					value={ values.trigger_key }
					onChange={ update( 'trigger_key' ) }
					aria-invalid={ errors.trigger_key ? true : undefined }
					className="oueb-field--code"
				/>
				<Button
					variant="secondary"
					onClick={ () => update( 'trigger_key' )( generateKey() ) }
				>
					{ __( 'Generate a new key', 'oueb-wp-backup' ) }
				</Button>

				<TextControl
					__nextHasNoMarginBottom
					type="password"
					autoComplete="new-password"
					label={ __( 'cron-job.org API key', 'oueb-wp-backup' ) }
					help={
						values.cronjob_org_key_set
							? __(
									'A key is saved. Leave empty to keep it.',
									'oueb-wp-backup'
							  )
							: __(
									'Create a free account on cron-job.org, then generate a key in Settings, API.',
									'oueb-wp-backup'
							  )
					}
					value={ values.cronjob_org_key }
					onChange={ update( 'cronjob_org_key' ) }
				/>
				{ values.cronjob_org_key_set && (
					<Button
						variant="link"
						isDestructive
						disabled={ saving }
						onClick={ () => save( { cronjob_org_key: null } ) }
					>
						{ __(
							'Delete the saved cron-job.org key',
							'oueb-wp-backup'
						) }
					</Button>
				) }
			</fieldset>

			<fieldset className="oueb-card">
				<legend className="oueb-card__title">
					{ __( 'Display', 'oueb-wp-backup' ) }
				</legend>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Show the agency card on the dashboard',
						'oueb-wp-backup'
					) }
					checked={ Boolean( values.show_agency_card ) }
					onChange={ update( 'show_agency_card' ) }
				/>
			</fieldset>

			<div className="oueb-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ saving }
					disabled={ saving }
				>
					{ __( 'Save settings', 'oueb-wp-backup' ) }
				</Button>
			</div>
		</form>
	);
}
