/**
 * Internal dependencies
 */
import {
	contentSummary,
	hasContent,
	joinList,
	parseExclusions,
} from '../content';

describe( 'joinList', () => {
	it( 'joins with commas and a final and', () => {
		expect( joinList( [] ) ).toBe( '' );
		expect( joinList( [ 'a' ] ) ).toBe( 'a' );
		expect( joinList( [ 'a', 'b', 'c' ] ) ).toBe( 'a, b and c' );
	} );
} );

describe( 'contentSummary', () => {
	it( 'reads like the mock-up', () => {
		const summary = contentSummary( {
			include_database: true,
			include_uploads: true,
			include_themes: true,
			include_plugins: true,
			include_other_content: false,
			include_core: false,
			exclude: [],
		} );
		expect( summary.saved ).toEqual( [
			'Database',
			'Media, themes and extensions',
		] );
		expect( summary.excluded ).toBe(
			'Excluded: cache and backups of other extensions'
		);
	} );

	it( 'counts the rules of the user', () => {
		const summary = contentSummary( {
			include_database: false,
			include_uploads: true,
			exclude: [ '*.log', 'wp-content/uploads/cache' ],
		} );
		expect( summary.saved ).toEqual( [ 'Media' ] );
		expect( summary.excluded ).toContain( '2 rule' );
	} );

	it( 'accepts a missing job', () => {
		expect( contentSummary( null ).saved ).toEqual( [] );
	} );
} );

describe( 'parseExclusions', () => {
	it( 'keeps one clean pattern per line', () => {
		expect(
			parseExclusions(
				' *.log \n\n/wp-content/cache/\r\n*.log\nwp-content/uploads/big'
			)
		).toEqual( [ '*.log', 'wp-content/cache', 'wp-content/uploads/big' ] );
	} );
} );

describe( 'hasContent', () => {
	it( 'needs the database or a folder', () => {
		expect( hasContent( { include_database: false } ) ).toBe( false );
		expect( hasContent( { include_themes: true } ) ).toBe( true );
	} );
} );
