/**
 * Champs des stockages : libellés, aides, affichage et validation.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Renvoie le libellé et l'aide d'un champ.
 *
 * @param {string} type Type de stockage.
 * @param {string} name Champ.
 * @return {{label: string, help: string}} Libellé et aide.
 */
export function fieldText( type, name ) {
	const texts = {
		s3: {
			provider: [ __( 'Provider', 'oueb-wp-backup' ), '' ],
			region: [
				__( 'Region', 'oueb-wp-backup' ),
				__(
					'For another service, the region given by your provider, such as eu-west-1.',
					'oueb-wp-backup'
				),
			],
			endpoint: [
				__( 'Address of the S3 service', 'oueb-wp-backup' ),
				__(
					'HTTPS address given by your provider, such as https://s3.example.com.',
					'oueb-wp-backup'
				),
			],
			path_style: [
				__( 'Put the bucket name in the path', 'oueb-wp-backup' ),
				__(
					'Turn on if your provider does not accept the bucket name in the address.',
					'oueb-wp-backup'
				),
			],
			bucket: [
				__( 'Bucket', 'oueb-wp-backup' ),
				__(
					'Create the bucket at your provider first. Lowercase letters, digits, dots and hyphens.',
					'oueb-wp-backup'
				),
			],
			folder: [
				__( 'Folder in the bucket', 'oueb-wp-backup' ),
				__(
					'Optional. Useful when several sites share a bucket.',
					'oueb-wp-backup'
				),
			],
			access_key: [ __( 'Access key', 'oueb-wp-backup' ), '' ],
			secret_key: [ __( 'Secret key', 'oueb-wp-backup' ), '' ],
		},
		sftp: {
			host: [
				__( 'Server', 'oueb-wp-backup' ),
				__( 'Name or IP address of the server.', 'oueb-wp-backup' ),
			],
			port: [ __( 'Port', 'oueb-wp-backup' ), '' ],
			user: [ __( 'Username', 'oueb-wp-backup' ), '' ],
			auth: [ __( 'Sign in with', 'oueb-wp-backup' ), '' ],
			password: [ __( 'Password', 'oueb-wp-backup' ), '' ],
			private_key: [
				__( 'Private key', 'oueb-wp-backup' ),
				__(
					'Paste the whole key, from the BEGIN line to the END line. Create a key used only for backups.',
					'oueb-wp-backup'
				),
			],
			passphrase: [
				__( 'Key passphrase', 'oueb-wp-backup' ),
				__( 'Leave empty if the key has none.', 'oueb-wp-backup' ),
			],
			folder: [
				__( 'Folder on the server', 'oueb-wp-backup' ),
				__(
					'Created if missing. Leave empty for the home folder.',
					'oueb-wp-backup'
				),
			],
		},
		kdrive: {
			drive_id: [
				__( 'kDrive ID', 'oueb-wp-backup' ),
				__(
					'The number in the address of your kDrive: ksuite.infomaniak.com/kdrive/app/drive/NUMBER.',
					'oueb-wp-backup'
				),
			],
			email: [ __( 'Infomaniak email address', 'oueb-wp-backup' ), '' ],
			password: [
				__( 'Application password', 'oueb-wp-backup' ),
				__(
					'Create an application password in your Infomaniak account, under Security. Never use your main password: an application password can be revoked without touching your account.',
					'oueb-wp-backup'
				),
			],
			folder: [
				__( 'Folder in kDrive', 'oueb-wp-backup' ),
				__( 'Created if missing.', 'oueb-wp-backup' ),
			],
		},
		folder: {
			path: [
				__( 'Folder path', 'oueb-wp-backup' ),
				__(
					'Absolute path, preferably outside the website. A copy on the same server does not protect against a disk failure or a hack.',
					'oueb-wp-backup'
				),
			],
		},
	};
	const [ label, help ] = texts[ type ]?.[ name ] || [ name, '' ];
	return { label, help };
}

/**
 * Indique les champs à afficher selon les valeurs saisies.
 *
 * @param {Object} typeDef Type : id et fields.
 * @param {Object} values  Valeurs du formulaire.
 * @return {string[]} Champs affichés, dans l'ordre.
 */
export function visibleFields( typeDef, values ) {
	return Object.keys( typeDef.fields ).filter( ( name ) => {
		if ( 'fingerprint' === name ) {
			return false;
		}
		if ( 's3' === typeDef.id ) {
			const custom = 'custom' === values.provider;
			if ( [ 'endpoint', 'path_style' ].includes( name ) ) {
				return custom;
			}
		}
		if ( 'sftp' === typeDef.id ) {
			const key = 'key' === values.auth;
			if ( 'password' === name ) {
				return ! key;
			}
			if ( [ 'private_key', 'passphrase' ].includes( name ) ) {
				return key;
			}
		}
		return true;
	} );
}

/**
 * Prépare les valeurs initiales du formulaire.
 *
 * @param {Object}      typeDef Type : id et fields.
 * @param {Object|null} storage Stockage existant, ou null.
 * @return {Object} Valeurs, secrets vides.
 */
export function initialValues( typeDef, storage = null ) {
	const values = {};
	Object.entries( typeDef.fields ).forEach( ( [ name, field ] ) => {
		if ( field.secret ) {
			values[ name ] = '';
		} else if ( storage && name in storage.settings ) {
			values[ name ] = storage.settings[ name ];
		} else if ( null !== field.default && undefined !== field.default ) {
			values[ name ] = field.default;
		} else {
			values[ name ] = 'bool' === field.type ? false : '';
		}
	} );
	return values;
}

/**
 * Vérifie le formulaire avant l'envoi.
 *
 * @param {Object}      typeDef Type : id et fields.
 * @param {string}      name    Nom du stockage.
 * @param {Object}      values  Valeurs.
 * @param {Object|null} storage Stockage existant, pour savoir quels secrets sont enregistrés.
 * @return {Object<string, string>} Erreurs, par champ.
 */
export function validateStorage( typeDef, name, values, storage = null ) {
	const errors = {};
	if ( '' === String( name ).trim() ) {
		errors.name = __( 'Give the storage a name.', 'oueb-wp-backup' );
	}

	const visible = visibleFields( typeDef, values );
	visible.forEach( ( field ) => {
		const def = typeDef.fields[ field ];
		const value = values[ field ];
		const empty = '' === String( value ?? '' ).trim();
		const saved = storage?.secrets_set?.[ field ];
		if ( def.required && empty && ! ( def.secret && saved ) ) {
			errors[ field ] = __( 'This field is required.', 'oueb-wp-backup' );
		}
	} );

	if ( 'folder' in values && String( values.folder ).includes( '..' ) ) {
		errors.folder = __(
			'The folder cannot contain “..”.',
			'oueb-wp-backup'
		);
	}
	if ( 's3' === typeDef.id && 'custom' === values.provider ) {
		if ( ! /^https:\/\/[^\s/]+/.test( String( values.endpoint ) ) ) {
			errors.endpoint = __(
				'Enter the HTTPS address of the S3 service.',
				'oueb-wp-backup'
			);
		}
	}
	if (
		's3' === typeDef.id &&
		'custom' !== values.provider &&
		! values.region
	) {
		errors.region = __( 'Choose a region.', 'oueb-wp-backup' );
	}
	if ( 'sftp' === typeDef.id ) {
		const port = Number( values.port );
		if ( ! Number.isInteger( port ) || port < 1 || port > 65535 ) {
			errors.port = __(
				'Enter a port between 1 and 65535.',
				'oueb-wp-backup'
			);
		}
		if (
			'password' === values.auth &&
			! values.password &&
			! storage?.secrets_set?.password
		) {
			errors.password = __( 'Enter the password.', 'oueb-wp-backup' );
		}
		if (
			'key' === values.auth &&
			! values.private_key &&
			! storage?.secrets_set?.private_key
		) {
			errors.private_key = __(
				'Paste the private key.',
				'oueb-wp-backup'
			);
		}
	}
	if (
		'kdrive' === typeDef.id &&
		values.drive_id &&
		! /^\d{1,12}$/.test( String( values.drive_id ) )
	) {
		errors.drive_id = __(
			'The kDrive ID contains digits only.',
			'oueb-wp-backup'
		);
	}
	if (
		'folder' === typeDef.id &&
		values.path &&
		! String( values.path ).startsWith( '/' ) &&
		! /^[A-Za-z]:[\\/]/.test( String( values.path ) )
	) {
		errors.path = __(
			'Enter an absolute path, such as /home/site/backups.',
			'oueb-wp-backup'
		);
	}

	return errors;
}

/**
 * Construit le corps de la requête : les secrets vides ne sont pas envoyés.
 *
 * @param {Object} typeDef Type : id et fields.
 * @param {string} name    Nom du stockage.
 * @param {Object} values  Valeurs.
 * @return {Object} Corps : type, name, settings.
 */
export function toPayload( typeDef, name, values ) {
	const settings = {};
	Object.entries( typeDef.fields ).forEach( ( [ field, def ] ) => {
		if ( ! ( field in values ) || 'fingerprint' === field ) {
			return;
		}
		let value = values[ field ];
		if ( def.secret && '' === value ) {
			return;
		}
		if ( 'int' === def.type ) {
			value = Number( value );
		}
		if ( 'string' === def.type && ! def.secret ) {
			value = String( value ).trim();
		}
		settings[ field ] = value;
	} );
	return { type: typeDef.id, name: String( name ).trim(), settings };
}
