/**
 * Size guide behaviour: tabs, unit switch, fit quiz, result card, AI explanation and "select this size".
 */
import { convert } from './units';

const UNIT_KEY = 'dev3sg_unit';
const MEASURE_KEY = 'dev3sg_measurements';

/**
 * Config printed by the plugin (window.dev3sgFront).
 *
 * @return {Object} Config.
 */
export function cfg() {
	return window.dev3sgFront || { restUrl: '/wp-json/dev3sg/v1/', i18n: {}, sizeAttrs: [ 'pa_size', 'size' ] };
}

/**
 * Typed number ("98,5" → 98.5) or null.
 *
 * @param {string} value Input value.
 * @return {number|null} Number.
 */
export function num( value ) {
	const v = String( value || '' ).replace( ',', '.' ).trim();
	return v !== '' && isFinite( Number( v ) ) && Number( v ) > 0 ? Number( v ) : null;
}

const t = ( key, fallback ) => ( cfg().i18n && cfg().i18n[ key ] ) || fallback;

/**
 * localStorage that never throws.
 */
export const store = {
	get( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	},
	set( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	},
	remove( key ) {
		try {
			window.localStorage.removeItem( key );
		} catch ( e ) {}
	},
};

/**
 * POST to the plugin's public REST API (no nonce: these routes are page-cache safe).
 *
 * @param {string} path Route.
 * @param {Object} body Body.
 * @return {Promise<Object>} JSON.
 */
export function post( path, body ) {
	const base = cfg().restUrl || '/wp-json/dev3sg/v1/';
	let url = base + path;
	if ( base.indexOf( 'rest_route=' ) !== -1 ) {
		url = base.replace( /\/?$/, '' ) + '/' + path;
	}
	return window
		.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( body ),
		} )
		.then( ( res ) =>
			res.json().then( ( json ) => {
				if ( ! res.ok ) {
					throw json;
				}
				return json;
			} )
		);
}

/**
 * Fire-and-forget analytics.
 *
 * @param {string} type    open|select.
 * @param {Object} payload { chart_id, product_id, size }.
 */
export function track( type, payload ) {
	if ( ! cfg().analytics ) {
		return;
	}
	post( 'track', { type, ...payload } ).catch( () => {} );
}

/**
 * Escapes text for innerHTML.
 *
 * @param {*} s Text.
 * @return {string} Escaped.
 */
export function esc( s ) {
	return String( s === undefined || s === null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
}

/**
 * Activates a tab inside a guide.
 *
 * @param {Element} guide Guide root.
 * @param {string}  id    data-tab id (table|measure|quiz) or index.
 * @param {boolean} focus Move focus to the tab.
 */
export function selectTab( guide, id, focus = false ) {
	const tabs = Array.from( guide.querySelectorAll( '[role="tab"]' ) );
	if ( ! tabs.length ) {
		return;
	}
	const target = typeof id === 'number' ? tabs[ id ] : tabs.find( ( tab ) => tab.dataset.tab === id );
	if ( ! target ) {
		return;
	}
	tabs.forEach( ( tab ) => {
		const on = tab === target;
		tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		tab.tabIndex = on ? 0 : -1;
		const panel = document.getElementById( tab.getAttribute( 'aria-controls' ) );
		if ( panel ) {
			panel.hidden = ! on;
		}
	} );
	if ( focus ) {
		target.focus();
	}
	if ( target.scrollIntoView && target.parentNode.scrollWidth > target.parentNode.clientWidth ) {
		target.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
	}
}

/**
 * Sets the unit system on every guide on the page and converts typed quiz values.
 *
 * @param {string}  system   metric|imperial.
 * @param {boolean} remember Save the choice.
 */
export function setUnit( system, remember = true ) {
	document.querySelectorAll( '[data-dev3sg-guide]' ).forEach( ( guide ) => {
		const from = guide.dataset.unit;
		if ( from === system ) {
			return;
		}
		guide.dataset.unit = system;
		guide.querySelectorAll( '[data-set-unit]' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', b.dataset.setUnit === system ? 'true' : 'false' ) );
		guide.querySelectorAll( 'input[data-measure]' ).forEach( ( input ) => {
			const v = num( input.value );
			if ( v !== null ) {
				input.value = String( convert( v, input.dataset.quantity, from, system ) );
			}
		} );
	} );
	if ( remember ) {
		store.set( UNIT_KEY, system );
	}
}

/**
 * Finds the variation select for size attributes on the page.
 *
 * @param {Element} scope Product scope (document by default).
 * @return {HTMLSelectElement|null} Select.
 */
export function findSizeSelect( scope = document ) {
	const names = ( cfg().sizeAttrs || [ 'pa_size', 'size' ] ).map( ( n ) => 'attribute_' + String( n ).toLowerCase() );
	const selects = Array.from( scope.querySelectorAll( 'form.variations_form select, form.cart select' ) );
	return selects.find( ( s ) => names.indexOf( String( s.name ).toLowerCase() ) !== -1 ) || selects.find( ( s ) => /size|gr(o|ö)(ss|ß)e|taille|talla|maat/i.test( s.name + ' ' + ( s.id || '' ) ) ) || null;
}

/**
 * Chooses a size in the variation dropdown (fires WooCommerce's change handlers).
 *
 * @param {string} size Size label.
 * @return {HTMLSelectElement|null} Select that was changed.
 */
export function selectSize( size ) {
	const select = findSizeSelect();
	if ( ! select ) {
		return null;
	}
	const want = String( size ).trim().toLowerCase();
	const option = Array.from( select.options ).find( ( o ) => o.value && ( o.value.toLowerCase() === want || o.text.trim().toLowerCase() === want ) );
	if ( ! option ) {
		return null;
	}
	select.value = option.value;
	select.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
	if ( window.jQuery ) {
		window.jQuery( select ).trigger( 'change' );
	}
	return select;
}

/**
 * Collects the quiz payload.
 *
 * @param {HTMLFormElement} form  Quiz form.
 * @param {Element}         guide Guide root.
 * @return {Object} Payload.
 */
export function payload( form, guide ) {
	const measurements = {};
	form.querySelectorAll( 'input[data-measure]' ).forEach( ( input ) => {
		const v = num( input.value );
		if ( v !== null ) {
			measurements[ input.dataset.measure ] = v;
		}
	} );
	const usual = {};
	const usualSelect = form.querySelector( 'select[name="usual"]' );
	if ( usualSelect && usualSelect.value ) {
		const [ key, ...rest ] = usualSelect.value.split( '|' );
		usual[ key ] = rest.join( '|' );
	}
	const fit = form.querySelector( 'input[name="fit"]:checked' );
	return {
		chart_id: Number( form.dataset.chart || guide.dataset.chart || 0 ),
		product_id: Number( form.dataset.product || guide.dataset.product || 0 ),
		measurements,
		usual,
		unit: guide.dataset.unit === 'imperial' ? 'imperial' : 'metric',
		fit: fit ? fit.value : 'regular',
	};
}

/**
 * Saves / restores measurements (always stored in metric).
 *
 * @param {HTMLFormElement} form  Form.
 * @param {Element}         guide Guide.
 */
function remember( form, guide ) {
	const box = form.querySelector( 'input[name="remember"]' );
	if ( ! box ) {
		return;
	}
	if ( ! box.checked ) {
		store.remove( MEASURE_KEY );
		return;
	}
	const data = { fit: '', values: {} };
	form.querySelectorAll( 'input[data-measure]' ).forEach( ( input ) => {
		if ( num( input.value ) !== null ) {
			data.values[ input.dataset.measure ] = convert( num( input.value ), input.dataset.quantity, guide.dataset.unit, 'metric' );
		}
	} );
	const fit = form.querySelector( 'input[name="fit"]:checked' );
	data.fit = fit ? fit.value : '';
	store.set( MEASURE_KEY, JSON.stringify( data ) );
}

/**
 * Prefills a quiz from saved measurements.
 *
 * @param {HTMLFormElement} form  Form.
 * @param {Element}         guide Guide.
 */
export function prefill( form, guide ) {
	if ( ! cfg().remember ) {
		return;
	}
	let data = null;
	try {
		data = JSON.parse( store.get( MEASURE_KEY ) || 'null' );
	} catch ( e ) {
		data = null;
	}
	if ( ! data || ! data.values ) {
		return;
	}
	form.querySelectorAll( 'input[data-measure]' ).forEach( ( input ) => {
		const v = data.values[ input.dataset.measure ];
		if ( v !== undefined && input.value === '' ) {
			input.value = String( convert( Number( v ), input.dataset.quantity, 'metric', guide.dataset.unit ) );
		}
	} );
	if ( data.fit ) {
		const radio = form.querySelector( `input[name="fit"][value="${ data.fit }"]` );
		if ( radio ) {
			radio.checked = true;
		}
	}
	const box = form.querySelector( 'input[name="remember"]' );
	if ( box ) {
		box.checked = true;
	}
}

/**
 * Highlights the recommended row in the guide's tables.
 *
 * @param {Element} guide Guide.
 * @param {string}  size  Size.
 */
export function highlight( guide, size ) {
	const want = String( size ).toLowerCase();
	guide.querySelectorAll( '.dev3sg-table tbody tr' ).forEach( ( tr ) => {
		const on = tr.dataset.size === want;
		tr.classList.toggle( 'is-recommended', on );
		const th = tr.querySelector( 'th' );
		const old = th && th.querySelector( '.dev3sg-rec-badge' );
		if ( old ) {
			old.remove();
		}
		if ( on && th ) {
			const badge = document.createElement( 'span' );
			badge.className = 'dev3sg-rec-badge';
			badge.textContent = t( 'recommended', 'Recommended' );
			th.appendChild( badge );
		}
	} );
}

/**
 * Formats a base value in the shopper's unit for the result list.
 *
 * @param {Object} m      Measure verdict.
 * @param {string} system metric|imperial.
 * @return {string} "98 cm".
 */
function valueText( m, system ) {
	const v = convert( m.value, m.quantity, 'metric', system );
	const unit = m.quantity === 'weight' ? ( system === 'imperial' ? 'lb' : 'kg' ) : system === 'imperial' ? 'in' : 'cm';
	return `${ v } ${ unit }`;
}

/**
 * Result card HTML.
 *
 * @param {Object}  r         Engine result.
 * @param {boolean} canSelect A size dropdown exists.
 * @return {string} HTML.
 */
export function resultHtml( r, canSelect ) {
	const conf = ( cfg().i18n && cfg().i18n.confidence ) || {};
	const status = ( cfg().i18n && cfg().i18n.status ) || {};
	const items = ( r.measures || [] )
		.map(
			( m ) =>
				`<li><span>${ esc( m.label ) }: ${ esc( valueText( m, r.unit ) ) }${ m.estimated ? ` <em>(${ esc( t( 'estimated', 'estimated' ) ) })</em>` : '' }</span><span class="dev3sg-status dev3sg-status--${ esc( m.status ) }">${ esc( status[ m.status ] || m.status ) }</span></li>`
		)
		.join( '' );
	const select = t( 'select', 'Select size %s' ).replace( '%s', r.size );
	return `<div class="dev3sg-result__top"><div class="dev3sg-result__size" aria-hidden="true">${ esc( r.size ) }</div><div>
		<p class="dev3sg-result__label">${ esc( t( 'best', 'Your best size' ) ) }</p>
		<p class="dev3sg-result__title"><span class="dev3sg-sr">${ esc( t( 'best', 'Your best size' ) ) }: </span>${ esc( r.size ) }${ r.alternative ? ` <span class="dev3sg-result__alt">· ${ esc( t( 'alt', 'Also consider' ) ) } ${ esc( r.alternative ) }</span>` : '' }</p>
		<div class="dev3sg-meter dev3sg-meter--${ esc( r.confidence ) }"><span class="dev3sg-meter__bar" aria-hidden="true"><span style="width:${ Math.max( 8, Math.min( 100, Number( r.score ) || 0 ) ) }%"></span></span><span>${ esc( conf[ r.confidence ] || r.confidence ) }</span></div>
	</div></div>
	${ items ? `<ul class="dev3sg-result__measures">${ items }</ul>` : '' }
	<div class="dev3sg-why${ cfg().ai ? ' is-loading' : '' }"><strong>${ esc( t( 'why', 'Why this size' ) ) }</strong><p data-dev3sg-why>${ esc( r.explanation ) }</p></div>
	<div class="dev3sg-result__actions">
		${ canSelect ? `<button type="button" class="dev3sg-btn dev3sg-btn--primary" data-dev3sg-select="${ esc( r.size ) }">${ esc( select ) }</button>` : '' }
		<button type="button" class="dev3sg-btn dev3sg-btn--secondary" data-dev3sg-show>${ esc( t( 'showChart', 'Show in chart' ) ) }</button>
		<button type="button" class="dev3sg-btn dev3sg-btn--link" data-dev3sg-again>${ esc( t( 'again', 'Start again' ) ) }</button>
	</div>`;
}

/**
 * Locale for the AI explanation (en-US → en_US).
 *
 * @return {string} Locale.
 */
function locale() {
	const lang = ( document.documentElement.lang || '' ).replace( '-', '_' );
	return /^[a-z]{2}(_[A-Z]{2})?$/.test( lang ) ? lang : '';
}

/**
 * Wires one quiz form.
 *
 * @param {HTMLFormElement} form    Form.
 * @param {Element}         guide   Guide root.
 * @param {Function}        onPick  Called after "Select size" ( size ).
 */
export function initQuiz( form, guide, onPick ) {
	if ( form.dataset.ready ) {
		return;
	}
	form.dataset.ready = '1';
	prefill( form, guide );
	const result = form.querySelector( '.dev3sg-result' );
	const error = form.querySelector( '.dev3sg-quiz__error' );
	const submit = form.querySelector( 'button[type="submit"]' );
	let last = null;

	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		const body = payload( form, guide );
		error.hidden = true;
		if ( ! Object.keys( body.measurements ).length && ! Object.keys( body.usual ).length ) {
			error.textContent = t( 'needInput', 'Enter at least one measurement.' );
			error.hidden = false;
			const first = form.querySelector( 'input[data-measure]' );
			if ( first ) {
				first.focus();
			}
			return;
		}
		remember( form, guide );
		const label = submit.textContent;
		submit.disabled = true;
		submit.textContent = t( 'working', 'Finding your size…' );
		post( 'fit/recommend', body )
			.then( ( r ) => {
				last = { r, body };
				const canSelect = !! findSizeSelect();
				result.innerHTML = resultHtml( r, canSelect );
				result.hidden = false;
				highlight( guide, r.size );
				document.querySelectorAll( 'input[data-dev3sg-rec]' ).forEach( ( input ) => ( input.value = r.size ) );
				if ( result.scrollIntoView ) {
					result.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
				}
				if ( cfg().ai ) {
					const why = result.querySelector( '[data-dev3sg-why]' );
					post( 'fit/explain', { ...body, locale: locale() } )
						.then( ( x ) => {
							if ( last && last.r === r && x && x.text && why ) {
								why.textContent = x.text;
							}
						} )
						.catch( () => {} )
						.finally( () => why && why.parentNode.classList.remove( 'is-loading' ) );
				}
			} )
			.catch( ( err ) => {
				error.textContent = ( err && err.message ) || t( 'error', 'Something went wrong. Please try again.' );
				error.hidden = false;
			} )
			.finally( () => {
				submit.disabled = false;
				submit.textContent = label;
			} );
	} );

	result.addEventListener( 'click', ( e ) => {
		const pick = e.target.closest( '[data-dev3sg-select]' );
		if ( pick ) {
			const size = pick.getAttribute( 'data-dev3sg-select' );
			const select = selectSize( size );
			track( 'select', { chart_id: Number( guide.dataset.chart ), product_id: Number( guide.dataset.product ), size } );
			pick.textContent = t( 'selected', 'Size %s selected' ).replace( '%s', size );
			if ( onPick ) {
				onPick( size, select );
			}
			return;
		}
		if ( e.target.closest( '[data-dev3sg-show]' ) ) {
			selectTab( guide, 'table', true );
			const row = guide.querySelector( '.dev3sg-table tr.is-recommended' );
			if ( row && row.scrollIntoView ) {
				row.scrollIntoView( { block: 'center', inline: 'nearest' } );
			}
			return;
		}
		if ( e.target.closest( '[data-dev3sg-again]' ) ) {
			result.hidden = true;
			result.innerHTML = '';
			highlight( guide, '' );
			const first = form.querySelector( 'input[data-measure], select' );
			if ( first ) {
				first.focus();
			}
		}
	} );
}

/**
 * Wires a guide (tabs, units, quiz).
 *
 * @param {Element}  guide  Guide root.
 * @param {Function} onPick After "Select size".
 */
export function initGuide( guide, onPick ) {
	if ( guide.dataset.ready ) {
		return;
	}
	guide.dataset.ready = '1';
	const saved = store.get( UNIT_KEY );
	if ( saved === 'metric' || saved === 'imperial' ) {
		setUnit( saved, false );
	}
	guide.addEventListener( 'click', ( e ) => {
		const tab = e.target.closest( '[role="tab"]' );
		if ( tab && guide.contains( tab ) ) {
			selectTab( guide, tab.dataset.tab );
			return;
		}
		const unit = e.target.closest( '[data-set-unit]' );
		if ( unit ) {
			setUnit( unit.dataset.setUnit );
		}
	} );
	guide.addEventListener( 'keydown', ( e ) => {
		const tab = e.target.closest( '[role="tab"]' );
		if ( ! tab ) {
			return;
		}
		const tabs = Array.from( guide.querySelectorAll( '[role="tab"]' ) );
		const i = tabs.indexOf( tab );
		let next = -1;
		if ( e.key === 'ArrowRight' ) {
			next = ( i + 1 ) % tabs.length;
		} else if ( e.key === 'ArrowLeft' ) {
			next = ( i - 1 + tabs.length ) % tabs.length;
		} else if ( e.key === 'Home' ) {
			next = 0;
		} else if ( e.key === 'End' ) {
			next = tabs.length - 1;
		}
		if ( next >= 0 ) {
			e.preventDefault();
			selectTab( guide, next, true );
		}
	} );
	guide.querySelectorAll( '[data-dev3sg-quiz]' ).forEach( ( form ) => initQuiz( form, guide, onPick ) );
}
