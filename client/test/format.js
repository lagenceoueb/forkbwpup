/**
 * Internal dependencies
 */
import {
	contentsLabel,
	formatSize,
	formatTime,
	statusLabel,
	stepLabel,
} from '../format';

jest.mock(
	'@wordpress/date',
	() => ( {
		dateI18n: ( format, iso ) => `${ format }|${ iso }`,
		getSettings: () => ( { formats: { datetime: 'Y-m-d H:i' } } ),
	} ),
	{ virtual: true }
);

describe( 'formatSize', () => {
	it.each( [
		[ 0, '0 B' ],
		[ 512, '512 B' ],
		[ 1536, '1.5 KB' ],
		[ 412 * 1024 * 1024, '412 MB' ],
		[ 3 * 1024 ** 4, '3,072 GB' ],
		[ 'abc', '0 B' ],
	] )( 'writes %p as %p', ( bytes, expected ) => {
		expect( formatSize( bytes, 'en-US' ) ).toBe( expected );
	} );
} );

describe( 'formatTime', () => {
	it( 'keeps an empty date empty', () => {
		expect( formatTime( '' ) ).toBe( '' );
	} );

	it( 'shows seconds', () => {
		expect( formatTime( '2026-10-03T10:00:00Z' ) ).toBe(
			'H:i:s|2026-10-03T10:00:00Z'
		);
	} );
} );

describe( 'statusLabel', () => {
	it.each( [
		[ { status: 'queued' }, 'Waiting' ],
		[ { status: 'running' }, 'Running' ],
		[ { status: 'success' }, 'Successful' ],
		[ { status: 'warning', warnings: 1 }, '1 warning' ],
		[ { status: 'warning', warnings: 3 }, '3 warnings' ],
		[ { status: 'aborted' }, 'Stopped' ],
		[ { status: 'failed' }, 'Failed' ],
	] )( 'labels %p as %p', ( run, expected ) => {
		expect( statusLabel( run ) ).toBe( expected );
	} );
} );

describe( 'contentsLabel', () => {
	it.each( [
		[
			{
				database: true,
				uploads: true,
				themes: true,
				plugins: true,
				other_content: true,
			},
			'Full site',
		],
		[ { database: true }, 'Database only' ],
		[ { uploads: true }, 'Files only' ],
		[ { database: true, uploads: true }, 'Database and some files' ],
		[ undefined, 'Files only' ],
	] )( 'sums up %p as %p', ( contents, expected ) => {
		expect( contentsLabel( contents ) ).toBe( expected );
	} );
} );

describe( 'stepLabel', () => {
	it( 'falls back on a generic label', () => {
		expect( stepLabel( 'unknown' ) ).toBe( 'Preparing' );
		expect( stepLabel( 'archive' ) ).toBe( 'Creating the archive' );
	} );
} );
