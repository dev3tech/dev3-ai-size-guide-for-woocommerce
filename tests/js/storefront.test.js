/**
 * Storefront behaviour: tabs, unit switch, quiz → result → select size, popup sheet.
 */
import { closeSheet, initSheets, isOpen } from '../../src/storefront/sheet';
import { findSizeSelect, initGuide, payload, resultHtml, selectSize, setUnit } from '../../src/storefront/guide';

const flush = () => new Promise( ( r ) => setTimeout( r, 0 ) );

const GUIDE = `
<form class="variations_form cart"><select name="attribute_pa_size" id="pa_size"><option value="">Choose</option><option value="s">S</option><option value="m">M</option></select></form>
<input type="hidden" name="dev3sg_rec" data-dev3sg-rec value="">
<button type="button" id="open" data-dev3sg-open="sheet1" data-dev3sg-tab="quiz">What size am I?</button>
<div class="dev3sg-sheet" id="sheet1" data-dev3sg-sheet hidden>
 <div class="dev3sg-sheet__overlay" data-dev3sg-close></div>
 <div class="dev3sg-sheet__panel" role="dialog" aria-modal="true" tabindex="-1">
  <button type="button" class="dev3sg-close" data-dev3sg-close aria-label="Close">x</button>
  <div class="dev3sg-guide" data-dev3sg-guide data-unit="metric" data-chart="7" data-product="3">
   <div class="dev3sg-tabs" role="tablist">
    <button type="button" role="tab" id="t1" aria-controls="p1" aria-selected="true" data-tab="table">Chart</button>
    <button type="button" role="tab" id="t2" aria-controls="p2" aria-selected="false" data-tab="quiz">Find my size</button>
   </div>
   <div class="dev3sg-units"><button type="button" data-set-unit="metric" aria-pressed="true">cm</button><button type="button" data-set-unit="imperial" aria-pressed="false">in</button></div>
   <div role="tabpanel" id="p1" data-panel="table"><div class="dev3sg-table-wrap"><table class="dev3sg-table"><tbody><tr data-size="s"><th>S</th></tr><tr data-size="m"><th>M</th></tr></tbody></table></div></div>
   <div role="tabpanel" id="p2" data-panel="quiz" hidden>
    <form class="dev3sg-quiz" data-dev3sg-quiz data-chart="7" data-product="3">
     <input type="text" inputmode="decimal" name="m[chest]" data-measure="chest" data-quantity="length">
     <input type="text" inputmode="decimal" name="m[weight]" data-measure="weight" data-quantity="weight">
     <select name="usual"><option value="">Not sure</option><option value="uk|12-14">UK 12-14</option></select>
     <label><input type="radio" name="fit" value="snug"></label><label><input type="radio" name="fit" value="regular" checked></label>
     <label><input type="checkbox" name="remember" value="1"></label>
     <p class="dev3sg-quiz__error" hidden></p>
     <button type="submit">Find my size</button>
     <div class="dev3sg-result" hidden></div>
    </form>
   </div>
  </div>
 </div>
</div>`;

const RESULT = { size: 'M', alternative: 'L', confidence: 'high', score: 92, unit: 'metric', fit: 'regular', explanation: 'Size M is your best match.', measures: [ { label: 'Chest', value: 98, quantity: 'length', status: 'within', estimated: false } ] };

beforeEach( () => {
	document.body.innerHTML = GUIDE;
	window.dev3sgFront = { restUrl: '/wp-json/dev3sg/v1/', ai: true, remember: true, analytics: true, sizeAttrs: [ 'pa_size' ], i18n: { select: 'Select size %s', selected: 'Size %s selected', status: { within: 'within range' }, confidence: { high: 'Great match' } } };
	window.localStorage.clear();
	window.requestAnimationFrame = ( cb ) => cb();
	document.querySelectorAll( '[data-dev3sg-guide]' ).forEach( ( g ) => initGuide( g ) );
	initSheets();
} );

afterEach( () => {
	closeSheet();
	delete window.fetch;
} );

describe( 'guide', () => {
	it( 'switches tabs with clicks and arrow keys', () => {
		const guide = document.querySelector( '[data-dev3sg-guide]' );
		document.getElementById( 't2' ).click();
		expect( document.getElementById( 'p2' ).hidden ).toBe( false );
		expect( document.getElementById( 'p1' ).hidden ).toBe( true );
		document.getElementById( 't2' ).dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'ArrowRight', bubbles: true } ) );
		expect( document.getElementById( 't1' ).getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect( guide.dataset.unit ).toBe( 'metric' );
	} );

	it( 'switches units, converts typed values and remembers the choice', () => {
		const chest = document.querySelector( 'input[data-measure="chest"]' );
		const weight = document.querySelector( 'input[data-measure="weight"]' );
		chest.value = '100';
		weight.value = '80';
		document.querySelector( '[data-set-unit="imperial"]' ).click();
		expect( document.querySelector( '[data-dev3sg-guide]' ).dataset.unit ).toBe( 'imperial' );
		expect( chest.value ).toBe( '39.4' );
		expect( weight.value ).toBe( '176.4' );
		expect( document.querySelector( '[data-set-unit="imperial"]' ).getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( window.localStorage.getItem( 'dev3sg_unit' ) ).toBe( 'imperial' );
		setUnit( 'metric' );
		expect( chest.value ).toBe( '100.1' );
	} );

	it( 'builds the quiz payload', () => {
		const form = document.querySelector( '[data-dev3sg-quiz]' );
		form.querySelector( 'input[data-measure="chest"]' ).value = '98,5';
		form.querySelector( 'select[name="usual"]' ).value = 'uk|12-14';
		expect( payload( form, document.querySelector( '[data-dev3sg-guide]' ) ) ).toEqual( { chart_id: 7, product_id: 3, measurements: { chest: 98.5 }, usual: { uk: '12-14' }, unit: 'metric', fit: 'regular' } );
	} );

	it( 'asks for input before submitting', () => {
		window.fetch = jest.fn();
		const form = document.querySelector( '[data-dev3sg-quiz]' );
		form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
		expect( window.fetch ).not.toHaveBeenCalled();
		expect( form.querySelector( '.dev3sg-quiz__error' ).hidden ).toBe( false );
	} );

	it( 'shows the result, highlights the row, explains with AI and selects the size', async () => {
		window.fetch = jest.fn( ( url ) =>
			Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( url.indexOf( 'explain' ) !== -1 ? { text: 'AI says M.', source: 'ai' } : RESULT ),
			} )
		);
		const form = document.querySelector( '[data-dev3sg-quiz]' );
		form.querySelector( 'input[data-measure="chest"]' ).value = '98';
		form.querySelector( 'input[name="remember"]' ).checked = true;
		form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
		await flush();
		await flush();
		const result = form.querySelector( '.dev3sg-result' );
		expect( result.hidden ).toBe( false );
		expect( result.querySelector( '.dev3sg-result__size' ).textContent ).toBe( 'M' );
		expect( result.textContent ).toContain( 'Great match' );
		expect( document.querySelector( 'tr[data-size="m"]' ).classList.contains( 'is-recommended' ) ).toBe( true );
		expect( document.querySelector( '[data-dev3sg-rec]' ).value ).toBe( 'M' );
		expect( result.querySelector( '[data-dev3sg-why]' ).textContent ).toBe( 'AI says M.' );
		expect( window.fetch.mock.calls[ 0 ][ 0 ] ).toBe( '/wp-json/dev3sg/v1/fit/recommend' );
		expect( JSON.parse( window.localStorage.getItem( 'dev3sg_measurements' ) ).values.chest ).toBe( 98 );

		result.querySelector( '[data-dev3sg-select]' ).click();
		expect( document.getElementById( 'pa_size' ).value ).toBe( 'm' );
		expect( result.querySelector( '[data-dev3sg-select]' ).textContent ).toBe( 'Size M selected' );
	} );

	it( 'renders a safe result card', () => {
		const html = resultHtml( { ...RESULT, size: '<b>M</b>' }, false );
		expect( html ).toContain( '&lt;b&gt;M&lt;/b&gt;' );
		expect( html ).not.toContain( 'data-dev3sg-select' );
	} );

	it( 'finds the size dropdown', () => {
		expect( findSizeSelect().name ).toBe( 'attribute_pa_size' );
		expect( selectSize( 'XXL' ) ).toBeNull();
		expect( selectSize( 's' ).value ).toBe( 's' );
	} );
} );

describe( 'sheet', () => {
	it( 'opens on the quiz tab, traps focus, closes with Escape and restores focus', () => {
		window.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } ) );
		const trigger = document.getElementById( 'open' );
		trigger.focus();
		const onOpen = jest.fn();
		document.querySelectorAll( '[data-dev3sg-sheet]' ).forEach( ( s ) => delete s.dataset.ready );
		initSheets( onOpen );
		trigger.click();
		const sheet = document.getElementById( 'sheet1' );
		expect( sheet.hidden ).toBe( false );
		expect( sheet.classList.contains( 'is-open' ) ).toBe( true );
		expect( document.documentElement.classList.contains( 'dev3sg-locked' ) ).toBe( true );
		expect( isOpen() ).toBe( true );
		document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		expect( isOpen() ).toBe( false );
		expect( sheet.classList.contains( 'is-open' ) ).toBe( false );
		expect( document.activeElement ).toBe( trigger );
		expect( document.documentElement.classList.contains( 'dev3sg-locked' ) ).toBe( false );
	} );

	it( 'closes from the overlay', () => {
		document.getElementById( 'open' ).click();
		document.querySelector( '.dev3sg-sheet__overlay' ).click();
		expect( isOpen() ).toBe( false );
	} );
} );
