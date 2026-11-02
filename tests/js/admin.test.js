import apiFetch from '@wordpress/api-fetch';
import { createElement, useState } from '@wordpress/element';
import { ToastProvider } from '../../src/core3/ui';
import TableEditor, { MeasureCell } from '../../src/admin/TableEditor';
import ChartPreview from '../../src/admin/ChartPreview';
import { ChartDraft } from '../../src/admin/AiChartPanel';
import FitTester from '../../src/admin/FitTester';
import { AppContext } from '../../src/admin/util';
import { act, change, click, render } from './render';

const MEASURES = [
	{ key: 'chest', label: 'Chest', quantity: 'length' },
	{ key: 'waist', label: 'Waist', quantity: 'length' },
	{ key: 'height', label: 'Height', quantity: 'length' },
	{ key: 'weight', label: 'Weight', quantity: 'weight' },
];

const TABLE = {
	id: 't1',
	title: 'Body',
	type: 'body',
	columns: [
		{ key: 'size', label: 'Size', kind: 'size', measure: '', quantity: 'none' },
		{ key: 'chest', label: 'Chest', kind: 'measure', measure: 'chest', quantity: 'length' },
	],
	rows: [
		[ 'S', '86-94' ],
		[ 'M', '94-102' ],
	],
};

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

function Harness( { onChange, system = 'metric' } ) {
	const [ table, setTable ] = useState( TABLE );
	return createElement( TableEditor, {
		table,
		system,
		measures: MEASURES,
		onChange: ( t ) => {
			setTable( t );
			onChange( t );
		},
	} );
}

describe( 'table editor', () => {
	it( 'adds rows and columns and changes column types', async () => {
		const onChange = jest.fn();
		const view = await render( createElement( Harness, { onChange } ) );
		await click( view.getByText( 'Add size' ).closest( 'button' ) );
		expect( onChange.mock.calls[ 0 ][ 0 ].rows ).toHaveLength( 3 );
		await click( view.getByText( 'Add column' ).closest( 'button' ) );
		expect( onChange.mock.calls[ 1 ][ 0 ].columns ).toHaveLength( 3 );
		const type = view.$( 'select[aria-label="Column 3 type"]' );
		await change( type, 'm:waist' );
		expect( onChange.mock.calls[ 2 ][ 0 ].columns[ 2 ] ).toMatchObject( { kind: 'measure', measure: 'waist', label: 'Waist' } );
		await click( view.$( 'button[aria-label="Delete row"]' ) );
		expect( onChange.mock.calls[ 3 ][ 0 ].rows ).toHaveLength( 2 );
		view.unmount();
	} );

	it( 'edits measurements in inches and stores centimetres', async () => {
		const onChange = jest.fn();
		const view = await render( createElement( MeasureCell, { value: '86-94', quantity: 'length', system: 'imperial', onChange, label: 'Chest for S' } ) );
		const input = view.$( 'input' );
		expect( input.value ).toBe( '34–37' );
		await act( async () => input.dispatchEvent( new window.FocusEvent( 'focusin', { bubbles: true } ) ) );
		await change( input, '35-37' );
		await act( async () => input.dispatchEvent( new window.FocusEvent( 'focusout', { bubbles: true } ) ) );
		expect( onChange ).toHaveBeenCalledWith( '88.9-94' );
		view.unmount();
	} );
} );

describe( 'preview and drafts', () => {
	it( 'previews the storefront table with a unit switch and highlight', async () => {
		const chart = { tables: [ TABLE ], guide: { text: 'Chest: around the chest', image_url: '' }, fit_note: 'Runs small' };
		const view = await render( createElement( ChartPreview, { chart, highlight: 'M' } ) );
		expect( view.$( '.dev3sg-guide' ).dataset.unit ).toBe( 'metric' );
		expect( view.$( 'tr.is-recommended th' ).textContent ).toContain( 'M' );
		expect( view.queryByText( 'ⓘ Runs small' ) || view.$( '.dev3sg-note' ) ).toBeTruthy();
		await click( view.getByText( 'in' ) );
		expect( view.$( '.dev3sg-guide' ).dataset.unit ).toBe( 'imperial' );
		await click( view.getByText( 'How to measure' ) );
		expect( view.$( '.dev3sg-measure__steps strong' ).textContent ).toBe( 'Chest' );
		await click( view.getByText( 'Phone' ) );
		expect( view.$( '.dev3sg-a-preview__frame.is-phone' ) ).toBeTruthy();
		view.unmount();
	} );

	it( 'reviews a chart draft before it is used', async () => {
		const onAccept = jest.fn();
		const onReject = jest.fn();
		const result = { draft: { tables: [ TABLE ], profile: 'men', fit_note: '' }, notes: 'Assumed US sizes.', model: 'gemini-2.5-flash', tokens: 200, source: 'ai' };
		const view = await render( createElement( ChartDraft, { result, onAccept, onReject, onRegenerate: jest.fn() } ) );
		expect( view.getByText( 'DEV3 AI draft' ) ).toBeTruthy();
		expect( view.$( '.dev3sg-ui-draft__meta' ).textContent ).toContain( 'Not saved yet' );
		await click( view.getByText( 'Use these tables' ).closest( 'button' ) );
		expect( onAccept ).toHaveBeenCalledWith( result.draft, 'replace' );
		await click( view.getByText( 'Add as extra tables' ).closest( 'button' ) );
		expect( onAccept ).toHaveBeenLastCalledWith( result.draft, 'append' );
		await click( view.getByText( 'Reject' ).closest( 'button' ) );
		expect( onReject ).toHaveBeenCalled();
		view.unmount();
	} );

	it( 'runs the quiz simulator against the unsaved chart', async () => {
		apiFetch.mockResolvedValueOnce( { size: 'M', alternative: '', confidence: 'high', explanation: 'Size M is your best match.', estimated: false } );
		const onResult = jest.fn();
		const chart = { name: 'X', status: 'publish', profile: 'men', tables: [ TABLE ], guide: { text: '', image_id: 0 }, fit_note: '', bias: 0, rules: {} };
		const view = await render(
			createElement( AppContext.Provider, { value: { measures: MEASURES, settings: {} } }, createElement( ToastProvider, null, createElement( FitTester, { chart, onResult } ) ) )
		);
		const input = view.$( 'input[type="number"]' );
		await change( input, '98' );
		await click( view.getByText( 'Find size' ).closest( 'button' ) );
		await view.flush();
		const sent = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( sent.url ).toContain( 'fit/test' );
		expect( sent.data.chart.tables[ 0 ].rows ).toHaveLength( 2 );
		expect( sent.data.input.measurements.chest ).toBe( 98 );
		expect( onResult ).toHaveBeenCalled();
		expect( view.getByText( 'Size M is your best match.' ) ).toBeTruthy();
		view.unmount();
	} );
} );
