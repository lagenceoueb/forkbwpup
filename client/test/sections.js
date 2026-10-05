/**
 * Internal dependencies
 */
import {
	findRoute,
	getSections,
	paramFromHash,
	sectionFromHash,
} from '../sections';

describe( 'sectionFromHash', () => {
	it.each( [
		[ '#/settings', 'settings' ],
		[ '#settings', 'settings' ],
		[ '#/log', 'log' ],
		[ '#/log/12', 'log' ],
		[ '', 'dashboard' ],
		[ '#/unknown', 'dashboard' ],
		[ undefined, 'dashboard' ],
	] )( 'maps %p to %p', ( hash, expected ) => {
		expect( sectionFromHash( hash ) ).toBe( expected );
	} );

	it( 'lists the sections of the mock-ups, with the restore', () => {
		expect( getSections().map( ( section ) => section.id ) ).toEqual( [
			'dashboard',
			'backups',
			'storage',
			'schedule',
			'restore',
			'log',
			'settings',
		] );
	} );
} );

describe( 'paramFromHash', () => {
	it.each( [
		[ '#/log/12', '12' ],
		[ '#log/12', '12' ],
		[ '#/log', '' ],
		[ '', '' ],
		[ undefined, '' ],
	] )( 'reads %p as %p', ( hash, expected ) => {
		expect( paramFromHash( hash ) ).toBe( expected );
	} );
} );

describe( 'pages outside the menu', () => {
	it( 'opens the content, the setup and the additional backups', () => {
		expect( sectionFromHash( '#/content' ) ).toBe( 'content' );
		expect( sectionFromHash( '#/setup' ) ).toBe( 'setup' );
		expect( sectionFromHash( '#/jobs/job-abc' ) ).toBe( 'jobs' );
		expect( paramFromHash( '#/jobs/job-abc' ) ).toBe( 'job-abc' );
	} );

	it( 'keeps their parent section active in the menu', () => {
		expect( findRoute( 'jobs' ).parent ).toBe( 'settings' );
		expect( findRoute( 'content' ).parent ).toBe( 'dashboard' );
		expect(
			getSections().some( ( section ) => section.id === 'jobs' )
		).toBe( false );
	} );
} );
