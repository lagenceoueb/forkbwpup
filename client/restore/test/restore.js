/**
 * Internal dependencies
 */
import {
	chunksFrom,
	isCommitted,
	isSupportedArchive,
	restorableRuns,
	restoreStepLabel,
} from '../restore';

describe( 'restoreStepLabel', () => {
	it( 'names the restore steps', () => {
		expect( restoreStepLabel( 'restore_database' ) ).toBe(
			'Restoring the database'
		);
		expect( restoreStepLabel( 'restore_fetch' ) ).toBe(
			'Getting the archive'
		);
	} );

	it( 'reads the safety backup steps as one', () => {
		expect( restoreStepLabel( 'archive' ) ).toBe(
			'Backing up the current site'
		);
		expect( restoreStepLabel( 'database' ) ).toBe(
			'Backing up the current site'
		);
	} );

	it( 'falls back for an unknown step', () => {
		expect( restoreStepLabel( '' ) ).toBe( 'Preparing' );
	} );
} );

describe( 'isCommitted', () => {
	it( 'is false before maintenance', () => {
		expect( isCommitted( { step: 'restore_check', restore: {} } ) ).toBe(
			false
		);
		expect( isCommitted( null ) ).toBe( false );
	} );

	it( 'is true once the site changes', () => {
		expect( isCommitted( { step: 'restore_files' } ) ).toBe( true );
		expect(
			isCommitted( { step: '', restore: { committed: true } } )
		).toBe( true );
	} );
} );

describe( 'isSupportedArchive', () => {
	it( 'accepts zip, tar.gz, tgz and tar, encrypted or not', () => {
		[
			'site.zip',
			'site.tar.gz',
			'SITE.TGZ',
			'site.tar',
			'site.zip.enc',
			'site.tar.gz.enc',
		].forEach( ( name ) =>
			expect( isSupportedArchive( name ) ).toBe( true )
		);
	} );

	it( 'refuses other files', () => {
		[ 'site.sql', 'site.gz', 'site.enc', '', null ].forEach( ( name ) =>
			expect( isSupportedArchive( name ) ).toBe( false )
		);
	} );
} );

describe( 'chunksFrom', () => {
	it( 'cuts the whole file', () => {
		expect( chunksFrom( 10, 4 ) ).toEqual( [
			{ start: 0, end: 4 },
			{ start: 4, end: 8 },
			{ start: 8, end: 10 },
		] );
	} );

	it( 'resumes after the bytes already received', () => {
		expect( chunksFrom( 10, 4, 8 ) ).toEqual( [ { start: 8, end: 10 } ] );
		expect( chunksFrom( 10, 4, 10 ) ).toEqual( [] );
	} );
} );

describe( 'restorableRuns', () => {
	it( 'keeps finished backups with an available archive', () => {
		const runs = [
			{
				id: 1,
				status: 'success',
				archive_file: 'a.zip',
				download_url: 'x',
			},
			{
				id: 2,
				status: 'success',
				archive_file: 'b.zip',
				download_url: null,
			},
			{ id: 3, status: 'running', archive_file: '', download_url: null },
			{ id: 4, kind: 'restore', status: 'success', archive_file: '' },
			{
				id: 5,
				status: 'warning',
				archive_file: 'c.zip',
				download_url: 'y',
			},
		];
		expect( restorableRuns( runs ).map( ( run ) => run.id ) ).toEqual( [
			1, 5,
		] );
	} );
} );
