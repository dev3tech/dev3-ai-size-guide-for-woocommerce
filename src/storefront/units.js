/**
 * Unit helpers shared by the storefront quiz and the admin editor. Stored data is cm / kg.
 */
export const CM_PER_IN = 2.54;
export const LB_PER_KG = 2.20462;

/**
 * Rounds to `digits` decimals and trims trailing zeros.
 *
 * @param {number} n      Number.
 * @param {number} digits Decimals.
 * @return {number} Rounded.
 */
export function round( n, digits = 1 ) {
	const f = Math.pow( 10, digits );
	return Math.round( n * f ) / f;
}

/**
 * Converts a shopper value between systems.
 *
 * @param {number} value    Value.
 * @param {string} quantity length|weight.
 * @param {string} from     metric|imperial.
 * @param {string} to       metric|imperial.
 * @return {number} Converted (1 decimal).
 */
export function convert( value, quantity, from, to ) {
	const v = Number( value );
	if ( ! isFinite( v ) || from === to ) {
		return v;
	}
	if ( quantity === 'weight' ) {
		return to === 'imperial' ? round( v * LB_PER_KG, 1 ) : round( v / LB_PER_KG, 1 );
	}
	return to === 'imperial' ? round( v / CM_PER_IN, 1 ) : round( v * CM_PER_IN, 1 );
}

/**
 * Parses "86", "86-91", "86 – 91", "34,5" into [ min, max ] or null.
 *
 * @param {*} value Cell.
 * @return {Array|null} Range.
 */
export function parseRange( value ) {
	if ( typeof value === 'number' ) {
		return [ value, value ];
	}
	const text = String( value || '' )
		.toLowerCase()
		.replace( /[–—−~/]| to /g, '-' )
		.replace( /(\d),(\d)/g, '$1.$2' );
	const nums = text.match( /\d+(?:\.\d+)?/g );
	if ( ! nums || nums.length > 2 ) {
		return null;
	}
	const n = nums.map( Number );
	return [ Math.min( ...n ), Math.max( ...n ) ];
}

/**
 * Trims a number for display ("86.0" → "86").
 *
 * @param {number} n Number.
 * @return {string} Text.
 */
export function trim( n ) {
	return String( round( n, 2 ) );
}

/**
 * Display text of a stored cell in a system (inches rounded to 0.5, lb to 1).
 *
 * @param {string} cell     Stored cell (cm/kg).
 * @param {string} quantity length|weight.
 * @param {string} system   metric|imperial.
 * @return {string} Text.
 */
export function formatCell( cell, quantity, system ) {
	const r = parseRange( cell );
	if ( ! r ) {
		return String( cell || '' );
	}
	const conv = ( v ) => {
		if ( system !== 'imperial' ) {
			return trim( round( v, 2 ) );
		}
		return quantity === 'weight' ? trim( Math.round( v * LB_PER_KG ) ) : trim( Math.round( ( v / CM_PER_IN ) * 2 ) / 2 );
	};
	const a = conv( r[ 0 ] );
	const b = conv( r[ 1 ] );
	return a === b ? a : `${ a }–${ b }`;
}

/**
 * Converts an edited cell (typed in the editor's unit) to stored cm/kg text.
 *
 * @param {string} text     Typed text.
 * @param {string} quantity length|weight.
 * @param {string} system   metric|imperial.
 * @return {string} Stored cell ('' when not a number).
 */
export function toStored( text, quantity, system ) {
	const r = parseRange( text );
	if ( ! r ) {
		return '';
	}
	const f = system === 'imperial' ? ( quantity === 'weight' ? 1 / LB_PER_KG : CM_PER_IN ) : 1;
	const digits = system === 'imperial' ? 1 : 2;
	const a = trim( round( r[ 0 ] * f, digits ) );
	const b = trim( round( r[ 1 ] * f, digits ) );
	return a === b ? a : `${ a }-${ b }`;
}

/**
 * Unit label.
 *
 * @param {string} quantity length|weight.
 * @param {string} system   metric|imperial.
 * @return {string} cm|in|kg|lb.
 */
export function unitLabel( quantity, system ) {
	if ( quantity === 'weight' ) {
		return system === 'imperial' ? 'lb' : 'kg';
	}
	return system === 'imperial' ? 'in' : 'cm';
}
