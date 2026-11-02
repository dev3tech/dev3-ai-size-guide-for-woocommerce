import { convert, formatCell, parseRange, toStored, unitLabel } from '../../src/storefront/units';
import { chartSizes, colType, quizFields, withType } from '../../src/admin/util';

const MEASURES = [
	{ key: 'chest', label: 'Chest', quantity: 'length' },
	{ key: 'waist', label: 'Waist', quantity: 'length' },
	{ key: 'height', label: 'Height', quantity: 'length' },
	{ key: 'weight', label: 'Weight', quantity: 'weight' },
	{ key: 'inseam', label: 'Inseam', quantity: 'length' },
	{ key: 'custom', label: 'Custom', quantity: 'length' },
];

describe( 'units', () => {
	it( 'parses numbers and ranges', () => {
		expect( parseRange( '86-91' ) ).toEqual( [ 86, 91 ] );
		expect( parseRange( '91 – 86' ) ).toEqual( [ 86, 91 ] );
		expect( parseRange( '34,5' ) ).toEqual( [ 34.5, 34.5 ] );
		expect( parseRange( 'XL' ) ).toBeNull();
		expect( parseRange( 12 ) ).toEqual( [ 12, 12 ] );
	} );

	it( 'converts both ways', () => {
		expect( convert( 100, 'length', 'metric', 'imperial' ) ).toBe( 39.4 );
		expect( convert( 39.4, 'length', 'imperial', 'metric' ) ).toBe( 100.1 );
		expect( convert( 80, 'weight', 'metric', 'imperial' ) ).toBe( 176.4 );
		expect( convert( 5, 'length', 'metric', 'metric' ) ).toBe( 5 );
	} );

	it( 'formats stored cells like the PHP renderer', () => {
		expect( formatCell( '94-102', 'length', 'metric' ) ).toBe( '94–102' );
		expect( formatCell( '94-102', 'length', 'imperial' ) ).toBe( '37–40' );
		expect( formatCell( '68', 'weight', 'imperial' ) ).toBe( '150' );
		expect( formatCell( 'n/a', 'length', 'metric' ) ).toBe( 'n/a' );
		expect( unitLabel( 'weight', 'imperial' ) ).toBe( 'lb' );
	} );

	it( 'stores edited inch values as cm', () => {
		expect( toStored( '34-36', 'length', 'imperial' ) ).toBe( '86.4-91.4' );
		expect( toStored( '86 – 91', 'length', 'metric' ) ).toBe( '86-91' );
		expect( toStored( '150', 'weight', 'imperial' ) ).toBe( '68' );
		expect( toStored( 'abc', 'length', 'metric' ) ).toBe( '' );
	} );
} );

describe( 'admin helpers', () => {
	const chart = {
		tables: [
			{
				type: 'body',
				columns: [
					{ kind: 'size', label: 'Size' },
					{ kind: 'measure', measure: 'chest', label: 'Chest', quantity: 'length' },
					{ kind: 'measure', measure: 'inseam', label: 'Inseam', quantity: 'length' },
					{ kind: 'text', label: 'US' },
				],
				rows: [
					[ 'S', '86-94', '81', '36' ],
					[ 'M', '94-102', '81', '40' ],
				],
			},
			{ type: 'garment', columns: [ { kind: 'size' }, { kind: 'measure', measure: 'waist', quantity: 'length' } ], rows: [ [ 'S', '40' ], [ 'M', '44' ] ] },
		],
	};

	it( 'lists quiz fields like the storefront (only varying body measures, plus height/weight)', () => {
		expect( quizFields( chart, MEASURES ).map( ( f ) => f.key ) ).toEqual( [ 'chest', 'height', 'weight' ] );
		expect( chartSizes( chart ) ).toEqual( [ 'S', 'M' ] );
	} );

	it( 'maps the column type select', () => {
		const col = { kind: 'text', label: 'Col', measure: '', quantity: 'none' };
		expect( colType( col ) ).toBe( 'text' );
		const m = withType( col, 'm:weight', MEASURES );
		expect( m ).toMatchObject( { kind: 'measure', measure: 'weight', quantity: 'weight', label: 'Weight' } );
		expect( colType( m ) ).toBe( 'm:weight' );
		const renamed = withType( { ...m, label: 'Body weight' }, 'm:chest', MEASURES );
		expect( renamed.label ).toBe( 'Body weight' );
		expect( withType( m, 'size', MEASURES ) ).toMatchObject( { kind: 'size', measure: '', quantity: 'none' } );
	} );
} );
