/**
 * Internal dependencies
 */
import {
	generateKey,
	toPayload,
	TRIGGER_KEY_MIN_LENGTH,
	validateSettings,
} from '../validation';

const VALID = {
	max_execution_time: '30',
	step_retries: '3',
	max_logs: '30',
	show_agency_card: true,
	trigger_key: 'a'.repeat( 40 ),
	cronjob_org_key: '',
};

describe( 'validateSettings', () => {
	it( 'accepts valid settings', () => {
		expect( validateSettings( VALID ) ).toEqual( {} );
	} );

	it.each( [
		[ 'max_execution_time', '9' ],
		[ 'max_execution_time', '301' ],
		[ 'step_retries', '0' ],
		[ 'max_logs', '1001' ],
		[ 'max_logs', '12.5' ],
		[ 'max_logs', '' ],
	] )( 'rejects %s = %p', ( name, value ) => {
		expect(
			validateSettings( { ...VALID, [ name ]: value } )
		).toHaveProperty( name );
	} );

	it( 'rejects a short or symbolic trigger key', () => {
		expect(
			validateSettings( { ...VALID, trigger_key: 'abc' } )
		).toHaveProperty( 'trigger_key' );
		expect(
			validateSettings( {
				...VALID,
				trigger_key: 'a'.repeat( 39 ) + '!',
			} )
		).toHaveProperty( 'trigger_key' );
	} );
} );

describe( 'toPayload', () => {
	it( 'sends numbers as integers and omits an empty cron-job.org key', () => {
		expect( toPayload( VALID ) ).toEqual( {
			max_execution_time: 30,
			step_retries: 3,
			max_logs: 30,
			show_agency_card: true,
			trigger_key: 'a'.repeat( 40 ),
		} );
	} );

	it( 'sends a typed cron-job.org key', () => {
		expect(
			toPayload( { ...VALID, cronjob_org_key: 'secret' } )
		).toHaveProperty( 'cronjob_org_key', 'secret' );
	} );
} );

describe( 'generateKey', () => {
	it( 'generates a valid key of the requested length', () => {
		const key = generateKey( 48 );
		expect( key ).toHaveLength( 48 );
		expect( key.length ).toBeGreaterThanOrEqual( TRIGGER_KEY_MIN_LENGTH );
		expect( key ).toMatch( /^[A-Za-z0-9]+$/ );
	} );

	it( 'does not repeat itself', () => {
		expect( generateKey() ).not.toEqual( generateKey() );
	} );
} );
