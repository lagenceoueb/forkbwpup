/**
 * Section Planification : quand partent les sauvegardes.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { fetchJob, fetchRuns, fetchSettings, saveJob } from '../api';
import { formatDate, formatSize } from '../format';
import { cronFromPreset, presetFromCron, weekdays } from './cron';

/**
 * Règle la fréquence et le déclencheur de la sauvegarde principale.
 *
 * @return {Element} Écran.
 */
export default function ScheduleScreen() {
	const [ job, setJob ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ trigger, setTrigger ] = useState( 'wpcron' );
	const [ manual, setManual ] = useState( true );
	const [ lastSize, setLastSize ] = useState( 0 );
	const [ keySet, setKeySet ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ copied, setCopied ] = useState( false );

	const load = ( saved ) => {
		setJob( saved );
		setForm( presetFromCron( saved.schedule ) );
		setManual( 'manual' === saved.trigger );
		setTrigger( 'manual' === saved.trigger ? 'wpcron' : saved.trigger );
	};

	useEffect( () => {
		Promise.all( [
			fetchJob(),
			fetchRuns( 10, 1, 'backup' ),
			fetchSettings(),
		] )
			.then( ( [ main, recent, settings ] ) => {
				load( main );
				const done = recent.runs.find(
					( run ) => run.archive_size > 0
				);
				setLastSize( done ? done.archive_size : 0 );
				setKeySet( Boolean( settings.cronjob_org_key_set ) );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [] );

	if ( ! job || ! form ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<p className="oueb-loading">
				<Spinner />
				{ __( 'Loading…', 'oueb-wp-backup' ) }
			</p>
		);
	}

	const update = ( field ) => ( value ) =>
		setForm( { ...form, [ field ]: value } );

	const save = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setNotice( null );
		saveJob( job.id, {
			trigger: manual ? 'manual' : trigger,
			schedule: cronFromPreset( form ),
		} )
			.then( ( saved ) => {
				load( saved );
				const message = saved.sync_error
					? __(
							'Schedule saved, but cron-job.org was not updated:',
							'oueb-wp-backup'
					  ) +
					  ' ' +
					  saved.sync_error
					: __( 'Schedule saved.', 'oueb-wp-backup' );
				setNotice( {
					status: saved.sync_error ? 'warning' : 'success',
					message,
				} );
				speak( message );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', message: e.message } );
				speak( e.message, 'assertive' );
			} )
			.finally( () => setBusy( false ) );
	};

	const copy = () => {
		window.navigator.clipboard?.writeText( job.trigger_url ).then( () => {
			setCopied( true );
			speak( __( 'Link copied.', 'oueb-wp-backup' ) );
		} );
	};

	const storages = job.storages.length;
	const estimate = lastSize * job.keep;

	return (
		<form className="oueb-form" onSubmit={ save } noValidate>
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
					{ __( 'Frequency', 'oueb-wp-backup' ) }
				</legend>
				<RadioControl
					label={ __( 'Back up the site', 'oueb-wp-backup' ) }
					hideLabelFromVision
					selected={ manual ? 'manual' : form.preset }
					options={ [
						{
							value: 'manual',
							label: __(
								'Only when I click “Back up now”',
								'oueb-wp-backup'
							),
						},
						{
							value: 'twice',
							label: __(
								'Twice a day: a shop or a site with many orders or comments',
								'oueb-wp-backup'
							),
						},
						{
							value: 'daily',
							label: __(
								'Every day: a site updated every day',
								'oueb-wp-backup'
							),
						},
						{
							value: 'weekly',
							label: __(
								'Every week: a site updated now and then',
								'oueb-wp-backup'
							),
						},
						{
							value: 'monthly',
							label: __(
								'Every month: a showcase site that rarely changes',
								'oueb-wp-backup'
							),
						},
						{
							value: 'custom',
							label: __(
								'Custom schedule, as a cron expression',
								'oueb-wp-backup'
							),
						},
					] }
					onChange={ ( value ) => {
						setManual( 'manual' === value );
						if ( 'manual' !== value ) {
							setForm( {
								...form,
								preset: value,
								cron: form.cron || cronFromPreset( form ),
							} );
						}
					} }
				/>

				{ ! manual && 'custom' !== form.preset && (
					<div className="oueb-inline-fields">
						{ 'weekly' === form.preset && (
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Day', 'oueb-wp-backup' ) }
								value={ form.weekday }
								options={ weekdays() }
								onChange={ update( 'weekday' ) }
							/>
						) }
						{ 'monthly' === form.preset && (
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Day of the month',
									'oueb-wp-backup'
								) }
								value={ form.monthday }
								options={ Array.from(
									{ length: 28 },
									( _, index ) => ( {
										value: String( index + 1 ),
										label: String( index + 1 ),
									} )
								) }
								onChange={ update( 'monthday' ) }
							/>
						) }
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="time"
							label={
								'twice' === form.preset
									? __( 'First backup at', 'oueb-wp-backup' )
									: __( 'Time', 'oueb-wp-backup' )
							}
							help={ __(
								'Time of the site. Choose a quiet hour, such as at night.',
								'oueb-wp-backup'
							) }
							value={ form.time }
							onChange={ update( 'time' ) }
						/>
					</div>
				) }

				{ ! manual && 'custom' === form.preset && (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Cron expression', 'oueb-wp-backup' ) }
						help={ __(
							'Five fields: minute, hour, day of the month, month, day of the week. Example: 0 3 * * 1–5 runs at 3 am from Monday to Friday. At most four backups an hour.',
							'oueb-wp-backup'
						) }
						className="oueb-field--code"
						value={ form.cron }
						onChange={ update( 'cron' ) }
					/>
				) }

				{ ! manual && job.next_run && 'manual' !== job.trigger && (
					<p>
						{ sprintf(
							/* translators: %s: date and time. */
							__( 'Next backup: %s.', 'oueb-wp-backup' ),
							formatDate( job.next_run )
						) }
					</p>
				) }
				{ lastSize > 0 && (
					<p className="oueb-help">
						{ sprintf(
							/* translators: 1: size of one backup, 2: number of backups kept, 3: total size, 4: number of storages. */
							__(
								'The last backup weighs %1$s. With %2$d backups kept, plan about %3$s in each of the %4$d storages.',
								'oueb-wp-backup'
							),
							formatSize( lastSize ),
							job.keep,
							formatSize( estimate ),
							storages
						) }
					</p>
				) }
			</fieldset>

			{ ! manual && (
				<fieldset className="oueb-card">
					<legend className="oueb-card__title">
						{ __( 'Who starts the backup', 'oueb-wp-backup' ) }
					</legend>
					<RadioControl
						label={ __(
							'Who starts the backup',
							'oueb-wp-backup'
						) }
						hideLabelFromVision
						selected={ trigger }
						options={ [
							{
								value: 'wpcron',
								label: __(
									'WordPress, with its scheduler (WP-Cron)',
									'oueb-wp-backup'
								),
							},
							{
								value: 'cronjoborg',
								label: __(
									'cron-job.org, a free service hosted in Germany',
									'oueb-wp-backup'
								),
							},
							{
								value: 'link',
								label: __(
									'My own service, with the trigger link',
									'oueb-wp-backup'
								),
							},
						] }
						onChange={ setTrigger }
					/>
					{ 'wpcron' === trigger && (
						<p className="oueb-help">
							{ __(
								'Nothing to set up. WP-Cron only runs when the site gets visits: on a quiet site, the backup can start late.',
								'oueb-wp-backup'
							) }
						</p>
					) }
					{ 'cronjoborg' === trigger && (
						<p className="oueb-help">
							{ __(
								'cron-job.org calls the site at the planned time, even without visits. The site creates and updates the job on cron-job.org with your API key.',
								'oueb-wp-backup'
							) }
						</p>
					) }
					{ 'cronjoborg' === trigger && ! keySet && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Enter your cron-job.org API key in the settings first.',
								'oueb-wp-backup'
							) }{ ' ' }
							<a href="#/settings">
								{ __( 'Open the settings', 'oueb-wp-backup' ) }
							</a>
						</Notice>
					) }
					{ 'link' === trigger && (
						<div className="oueb-trigger-link">
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Trigger link', 'oueb-wp-backup' ) }
								help={ __(
									'Call this link at the planned time, with GET or POST. It contains a key: keep it secret. Changing the key in the settings changes the link.',
									'oueb-wp-backup'
								) }
								className="oueb-field--code"
								value={ job.trigger_url }
								readOnly
								onFocus={ ( event ) => event.target.select() }
							/>
							<Button variant="secondary" onClick={ copy }>
								{ copied
									? __( 'Copied', 'oueb-wp-backup' )
									: __( 'Copy the link', 'oueb-wp-backup' ) }
							</Button>
						</div>
					) }
				</fieldset>
			) }

			<div className="oueb-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Save the schedule', 'oueb-wp-backup' ) }
				</Button>
			</div>
		</form>
	);
}
