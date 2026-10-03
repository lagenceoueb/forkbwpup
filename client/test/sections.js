/**
 * Internal dependencies
 */
import { getSections, paramFromHash, sectionFromHash } from '../sections';

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

	it( 'lists the six sections of the mock-ups', () => {
		expect( getSections().map( ( section ) => section.id ) ).toEqual( [
			'dashboard',
			'backups',
			'storage',
			'schedule',
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
