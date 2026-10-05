/**
 * Notice d'import des tâches de BackWPup, puis rapport.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { dismissImport, fetchImport, runImport } from '../api';
import { joinList } from '../content/content';

/**
 * Indique s'il faut proposer l'import.
 *
 * @param {Object|null} summary Résumé de l'API.
 * @return {boolean} Vrai si des tâches attendent et que l'administrateur n'a pas encore répondu.
 */
export function shouldOffer( summary ) {
	return Boolean(
		summary && summary.available && '' === ( summary.status || '' )
	);
}

/**
 * Libellés des réglages que l'import peut reprendre.
 *
 * @return {Object<string, string>} Libellés, par réglage.
 */
function settingLabels() {
	return {
		max_execution_time: __(
			'maximum duration of a step',
			'oueb-wp-backup'
		),
		max_logs: __( 'number of logs kept', 'oueb-wp-backup' ),
		step_retries: __( 'attempts per step', 'oueb-wp-backup' ),
		trigger_key: __( 'key of the trigger link', 'oueb-wp-backup' ),
		cronjob_org_key: __( 'cron-job.org API key', 'oueb-wp-backup' ),
	};
}

/**
 * Affiche le rapport d'un import.
 *
 * @param {Object}   props         Propriétés.
 * @param {Array}    props.report  Rapport : une entrée par tâche.
 * @param {boolean}  props.active  BackWPup est encore actif.
 * @param {Function} props.onClose Appelée pour masquer le rapport.
 * @return {Element} Rapport.
 */
function ImportReport( { report, active, onClose } ) {
	const jobs = report.filter( ( entry ) => entry.job_id );
	return (
		<section
			className="oueb-card oueb-import"
			aria-labelledby="oueb-import-report"
		>
			<h2 id="oueb-import-report" className="oueb-card__title">
				{ sprintf(
					/* translators: %d: number of imported jobs. */
					_n(
						'%d job imported from BackWPup',
						'%d jobs imported from BackWPup',
						jobs.length,
						'oueb-wp-backup'
					),
					jobs.length
				) }
			</h2>
			<ul className="oueb-import__list">
				{ report.map( ( entry, index ) => (
					<li key={ index }>
						{ entry.job_id ? (
							<a href={ `#/jobs/${ entry.job_id }` }>
								{ entry.name }
							</a>
						) : (
							<strong>{ entry.name }</strong>
						) }
						{ ( entry.settings || [] ).length > 0 && (
							<p className="oueb-import__settings">
								{ sprintf(
									/* translators: %s: list of settings. */
									__( 'Imported: %s.', 'oueb-wp-backup' ),
									joinList(
										entry.settings.map(
											( key ) =>
												settingLabels()[ key ] || key
										)
									)
								) }
							</p>
						) }
						{ entry.notes.length > 0 && (
							<ul>
								{ entry.notes.map( ( note, position ) => (
									<li key={ position }>{ note }</li>
								) ) }
							</ul>
						) }
					</li>
				) ) }
			</ul>
			<p>
				{ __(
					'The imported jobs are additional backups: the main backup did not change. The data of BackWPup was neither changed nor deleted.',
					'oueb-wp-backup'
				) }
			</p>
			{ active && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'BackWPup is still active: both extensions would make the same backups. Deactivate BackWPup in the Plugins page.',
						'oueb-wp-backup'
					) }
				</Notice>
			) }
			<p className="oueb-import__links">
				<a href="#/jobs">
					{ __( 'See the additional backups', 'oueb-wp-backup' ) }
				</a>
				<Button variant="link" onClick={ onClose }>
					{ __( 'Hide this report', 'oueb-wp-backup' ) }
				</Button>
			</p>
		</section>
	);
}

/**
 * Propose l'import des tâches de BackWPup, puis montre son rapport.
 *
 * @return {Element|null} Notice, rapport, ou rien.
 */
export default function ImportNotice() {
	const [ summary, setSummary ] = useState( null );
	const [ report, setReport ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		fetchImport()
			.then( setSummary )
			.catch( () => setSummary( null ) );
	}, [] );

	if ( report ) {
		return (
			<ImportReport
				report={ report }
				active={ Boolean( summary && summary.backwpup_active ) }
				onClose={ () => setReport( null ) }
			/>
		);
	}
	if ( ! shouldOffer( summary ) ) {
		return null;
	}

	const count = summary.jobs.length;
	const start = () => {
		setBusy( true );
		setError( null );
		runImport()
			.then( ( next ) => {
				setSummary( next );
				setReport( next.report );
				speak( __( 'Import finished.', 'oueb-wp-backup' ) );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};
	const later = () => {
		dismissImport()
			.then( setSummary )
			.catch( ( e ) => setError( e.message ) );
	};

	return (
		<section
			className="oueb-card oueb-import"
			aria-labelledby="oueb-import-title"
		>
			<h2 id="oueb-import-title" className="oueb-card__title">
				{ summary.backwpup_active
					? __(
							'BackWPup is installed on this site',
							'oueb-wp-backup'
					  )
					: __(
							'BackWPup settings found on this site',
							'oueb-wp-backup'
					  ) }
			</h2>
			<p>
				{ sprintf(
					/* translators: %d: number of BackWPup jobs. */
					_n(
						'You can import your %d job and your settings. Your BackWPup data will be neither changed nor deleted.',
						'You can import your %d jobs and your settings. Your BackWPup data will be neither changed nor deleted.',
						count,
						'oueb-wp-backup'
					),
					count
				) }
			</p>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="oueb-form__actions">
				<Button
					variant="secondary"
					onClick={ start }
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Import my jobs', 'oueb-wp-backup' ) }
				</Button>
				<Button variant="tertiary" onClick={ later } disabled={ busy }>
					{ __( 'Ignore', 'oueb-wp-backup' ) }
				</Button>
			</div>
		</section>
	);
}
