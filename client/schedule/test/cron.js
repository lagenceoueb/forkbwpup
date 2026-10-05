/**
 * Internal dependencies
 */
import { cronFromPreset, describeSchedule, presetFromCron } from '../cron';

describe( 'presetFromCron', () => {
	it.each( [
		[ '0 3 * * *', { preset: 'daily', time: '03:00' } ],
		[ '30 2,14 * * *', { preset: 'twice', time: '02:30' } ],
		[ '15 23 * * 0', { preset: 'weekly', time: '23:15', weekday: '0' } ],
		[ '0 4 15 * *', { preset: 'monthly', time: '04:00', monthday: '15' } ],
		[ '0 4 31 * *', { preset: 'custom' } ],
		[ '*/30 * * * *', { preset: 'custom' } ],
		[ '0 3 * * 1-5', { preset: 'custom' } ],
		[ 'nonsense', { preset: 'custom' } ],
	] )( 'reads %p', ( cron, expected ) => {
		expect( presetFromCron( cron ) ).toMatchObject( expected );
	} );
} );

describe( 'cronFromPreset', () => {
	const form = {
		time: '14:05',
		weekday: '3',
		monthday: '2',
		cron: ' 0  1 * * * ',
	};

	it.each( [
		[ 'daily', '5 14 * * *' ],
		[ 'twice', '5 2,14 * * *' ],
		[ 'weekly', '5 14 * * 3' ],
		[ 'monthly', '5 14 2 * *' ],
		[ 'custom', '0 1 * * *' ],
	] )( 'builds %p', ( preset, expected ) => {
		expect( cronFromPreset( { ...form, preset } ) ).toBe( expected );
	} );

	it( 'round-trips every preset', () => {
		[ 'daily', 'twice', 'weekly', 'monthly' ].forEach( ( preset ) => {
			const cron = cronFromPreset( { ...form, preset } );
			expect( presetFromCron( cron ).preset ).toBe( preset );
		} );
	} );
} );

describe( 'describeSchedule', () => {
	it( 'describes manual and planned jobs', () => {
		expect( describeSchedule( { trigger: 'manual' } ) ).toMatch(
			/Back up now/
		);
		expect(
			describeSchedule( { trigger: 'wpcron', schedule: '0 3 * * 1' } )
		).toBe( 'Every Monday at 03:00.' );
	} );
} );
