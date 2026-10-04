/**
 * Internal dependencies
 */
import {
	initialValues,
	toPayload,
	validateStorage,
	visibleFields,
} from '../fields';

const s3 = {
	id: 's3',
	fields: {
		provider: {
			type: 'enum',
			required: true,
			secret: false,
			default: null,
		},
		region: {
			type: 'string',
			required: false,
			secret: false,
			default: null,
		},
		endpoint: {
			type: 'string',
			required: false,
			secret: false,
			default: null,
		},
		path_style: {
			type: 'bool',
			required: false,
			secret: false,
			default: false,
		},
		bucket: {
			type: 'string',
			required: true,
			secret: false,
			default: null,
		},
		folder: { type: 'string', required: false, secret: false, default: '' },
		access_key: {
			type: 'string',
			required: true,
			secret: false,
			default: null,
		},
		secret_key: {
			type: 'string',
			required: true,
			secret: true,
			default: null,
		},
	},
};

const sftp = {
	id: 'sftp',
	fields: {
		host: { type: 'string', required: true, secret: false, default: null },
		port: { type: 'int', required: false, secret: false, default: 22 },
		user: { type: 'string', required: true, secret: false, default: null },
		auth: {
			type: 'enum',
			required: false,
			secret: false,
			default: 'password',
		},
		password: {
			type: 'string',
			required: false,
			secret: true,
			default: null,
		},
		private_key: {
			type: 'text',
			required: false,
			secret: true,
			default: null,
		},
		passphrase: {
			type: 'string',
			required: false,
			secret: true,
			default: null,
		},
		folder: { type: 'string', required: false, secret: false, default: '' },
		fingerprint: {
			type: 'string',
			required: false,
			secret: false,
			default: '',
		},
	},
};

describe( 'visibleFields', () => {
	it( 'shows the address only for another S3 service', () => {
		expect( visibleFields( s3, { provider: 'scaleway' } ) ).not.toContain(
			'endpoint'
		);
		expect( visibleFields( s3, { provider: 'custom' } ) ).toEqual(
			expect.arrayContaining( [ 'endpoint', 'path_style' ] )
		);
	} );

	it( 'shows the password or the key, never the fingerprint', () => {
		const password = visibleFields( sftp, { auth: 'password' } );
		expect( password ).toContain( 'password' );
		expect( password ).not.toContain( 'private_key' );
		expect( password ).not.toContain( 'fingerprint' );
		expect( visibleFields( sftp, { auth: 'key' } ) ).toEqual(
			expect.arrayContaining( [ 'private_key', 'passphrase' ] )
		);
	} );
} );

describe( 'initialValues', () => {
	it( 'uses defaults, saved values and empty secrets', () => {
		expect( initialValues( sftp ).port ).toBe( 22 );
		const values = initialValues( sftp, {
			settings: { host: 'h', port: 2222 },
			secrets_set: { password: true },
		} );
		expect( values.port ).toBe( 2222 );
		expect( values.password ).toBe( '' );
	} );
} );

describe( 'validateStorage', () => {
	const valid = {
		provider: 'scaleway',
		region: 'fr-par',
		bucket: 'b',
		access_key: 'a',
		secret_key: 's',
		folder: '',
	};

	it( 'accepts a complete S3 form', () => {
		expect( validateStorage( s3, 'Scaleway', valid ) ).toEqual( {} );
	} );

	it( 'flags missing and invalid fields', () => {
		const errors = validateStorage( s3, ' ', {
			...valid,
			region: '',
			secret_key: '',
			folder: '../x',
		} );
		expect( Object.keys( errors ).sort() ).toEqual( [
			'folder',
			'name',
			'region',
			'secret_key',
		] );
	} );

	it( 'accepts an empty secret already saved', () => {
		expect(
			validateStorage(
				s3,
				'S3',
				{ ...valid, secret_key: '' },
				{
					secrets_set: { secret_key: true },
				}
			)
		).toEqual( {} );
	} );

	it( 'requires HTTPS for another S3 service', () => {
		expect(
			validateStorage( s3, 'S3', {
				...valid,
				provider: 'custom',
				endpoint: 'http://s3.example.com',
			} ).endpoint
		).toBeTruthy();
	} );

	it( 'checks the SFTP port and credentials', () => {
		const errors = validateStorage( sftp, 'SFTP', {
			host: 'h',
			user: 'u',
			port: '70000',
			auth: 'key',
			private_key: '',
			folder: '',
		} );
		expect( errors.port ).toBeTruthy();
		expect( errors.private_key ).toBeTruthy();
	} );
} );

describe( 'toPayload', () => {
	it( 'drops empty secrets and the fingerprint, converts numbers', () => {
		expect(
			toPayload( sftp, ' Serveur ', {
				host: ' h ',
				port: '2222',
				user: 'u',
				auth: 'password',
				password: '',
				private_key: '',
				passphrase: '',
				folder: 'f',
				fingerprint: 'SHA256:x',
			} )
		).toEqual( {
			type: 'sftp',
			name: 'Serveur',
			settings: {
				host: 'h',
				port: 2222,
				user: 'u',
				auth: 'password',
				folder: 'f',
			},
		} );
	} );
} );
