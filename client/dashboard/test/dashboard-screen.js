/**
 * Internal dependencies
 */
import { protectionSummary } from '../dashboard-screen';

jest.mock(
	'@wordpress/date',
	() => ( {
		dateI18n: ( format, iso ) => iso,
		getSettings: () => ( { formats: { datetime: 'Y-m-d H:i' } } ),
	} ),
	{ virtual: true }
);
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );
jest.mock( '@wordpress/components', () => ( {} ), { virtual: true } );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ), {
	virtual: true,
} );

const run = ( status, startedAt ) => ( {
	status,
	started_at: startedAt,
} );

describe( 'protectionSummary', () => {
	it( 'asks for a first backup when none succeeded', () => {
		expect( protectionSummary( [] ).tone ).toBe( 'warning' );
		expect( protectionSummary( [ run( 'failed', 'a' ) ] ).tone ).toBe(
			'warning'
		);
	} );

	it( 'reports a protected site after a good backup', () => {
		const summary = protectionSummary( [ run( 'success', 'b' ) ] );
		expect( summary.tone ).toBe( 'success' );
		expect( summary.text ).toBe( 'Last backup: b.' );
	} );

	it( 'ignores a backup in progress', () => {
		expect(
			protectionSummary( [
				run( 'running', 'c' ),
				run( 'warning', 'b' ),
			] ).tone
		).toBe( 'success' );
	} );

	it( 'does not count a stopped backup as a failure', () => {
		expect(
			protectionSummary( [
				run( 'aborted', 'c' ),
				run( 'success', 'b' ),
			] ).tone
		).toBe( 'success' );
	} );

	it( 'reports a failure that follows a good backup', () => {
		const summary = protectionSummary( [
			run( 'failed', 'c' ),
			run( 'success', 'b' ),
		] );
		expect( summary.tone ).toBe( 'error' );
		expect( summary.text ).toContain( 'b' );
	} );
} );

describe( 'protectionSummary with storages', () => {
	it( 'names the storages of the last good backup', () => {
		const summary = protectionSummary( [
			{
				status: 'success',
				started_at: 'b',
				storage_names: [ 'Scaleway', 'Ce serveur' ],
			},
		] );
		expect( summary.text ).toBe(
			'Last backup: b, sent to Scaleway and Ce serveur.'
		);
		expect( summary.last.started_at ).toBe( 'b' );
	} );
} );
