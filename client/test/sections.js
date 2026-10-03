/**
 * Internal dependencies
 */
import { getSections, sectionFromHash } from '../sections';

describe( 'sectionFromHash', () => {
	it.each( [
		[ '#/settings', 'settings' ],
		[ '#settings', 'settings' ],
		[ '#/log', 'log' ],
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
