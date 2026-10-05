/**
 * Internal dependencies
 */
import { shouldOffer } from '../import-notice';

jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );
jest.mock( '@wordpress/components', () => ( {} ), { virtual: true } );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ), {
	virtual: true,
} );
jest.mock( '@wordpress/date', () => ( {} ), { virtual: true } );

describe( 'shouldOffer', () => {
	it( 'offers the import once, when BackWPup jobs exist', () => {
		expect( shouldOffer( { available: true, status: '' } ) ).toBe( true );
		expect( shouldOffer( { available: true, status: 'done' } ) ).toBe(
			false
		);
		expect( shouldOffer( { available: true, status: 'dismissed' } ) ).toBe(
			false
		);
		expect( shouldOffer( { available: false, status: '' } ) ).toBe( false );
		expect( shouldOffer( null ) ).toBe( false );
	} );
} );
