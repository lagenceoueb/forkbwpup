/**
 * Internal dependencies
 */
import { resultLabel, summarizeResults } from '../database';

const rows = ( ...statuses ) =>
	statuses.map( ( status, i ) => ( {
		table: `wp_t${ i }`,
		status,
		message: '',
	} ) );

describe( 'summarizeResults', () => {
	it( 'reports a clean check', () => {
		expect( summarizeResults( 'check', rows( 'ok', 'ok' ) ) ).toEqual( {
			status: 'success',
			message: '2 tables checked: no problem found.',
		} );
	} );

	it( 'puts errors before warnings', () => {
		const summary = summarizeResults(
			'check',
			rows( 'ok', 'warning', 'error' )
		);
		expect( summary.status ).toBe( 'error' );
		expect( summary.message ).toMatch( /^1 table out of 3 has an error/ );
		expect(
			summarizeResults( 'optimize', rows( 'ok', 'warning', 'warning' ) )
				.message
		).toMatch( /^2 tables out of 3 have a warning/ );
	} );

	it( 'explains a repair the engine does not need', () => {
		expect(
			summarizeResults( 'repair', rows( 'unsupported', 'unsupported' ) )
				.message
		).toMatch( /^Nothing to repair/ );
		expect(
			summarizeResults( 'repair', rows( 'ok', 'unsupported' ) ).message
		).toBe( '1 table repaired.' );
	} );

	it( 'names each result', () => {
		expect( resultLabel( 'unsupported' ) ).toBe( 'Not needed' );
		expect( resultLabel( 'error' ) ).toBe( 'Error' );
	} );
} );
