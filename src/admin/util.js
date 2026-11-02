import { createContext, useContext } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const T = 'dev3-ai-size-guide-for-woocommerce';

/** App-wide data: { settings, save, measures, refreshUsage }. */
export const AppContext = createContext( {} );
export const useApp = () => useContext( AppContext );

export const PROFILES = [
	{ value: 'women', label: __( 'Women', 'dev3-ai-size-guide-for-woocommerce' ) },
	{ value: 'men', label: __( 'Men', 'dev3-ai-size-guide-for-woocommerce' ) },
	{ value: 'unisex', label: __( 'Unisex', 'dev3-ai-size-guide-for-woocommerce' ) },
	{ value: 'kids', label: __( 'Kids', 'dev3-ai-size-guide-for-woocommerce' ) },
];

export const REASONS = {
	too_small: { label: __( 'Too small', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'warning' },
	too_large: { label: __( 'Too large', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'info' },
	fit_ok: { label: __( 'Fit OK', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'success' },
	other: { label: __( 'Other reason', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'neutral' },
	unknown: { label: __( 'Unclear', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'neutral' },
};

export const DIRECTIONS = {
	runs_small: { label: __( 'Runs small', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'warning' },
	runs_large: { label: __( 'Runs large', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'info' },
	true_to_size: { label: __( 'True to size', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'success' },
	too_few: { label: __( 'Too few returns', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'neutral' },
	no_data: { label: __( 'No fit returns', 'dev3-ai-size-guide-for-woocommerce' ), tone: 'neutral' },
};

export const SOURCES = {
	refund: __( 'Refund', 'dev3-ai-size-guide-for-woocommerce' ),
	order: __( 'Order screen', 'dev3-ai-size-guide-for-woocommerce' ),
	manual: __( 'Manual', 'dev3-ai-size-guide-for-woocommerce' ),
	api: __( 'Integration', 'dev3-ai-size-guide-for-woocommerce' ),
};

/**
 * Human bias text.
 *
 * @param {number} bias -2…2.
 * @return {string} Text.
 */
export function biasText( bias ) {
	if ( bias > 0 ) {
		return bias > 1 ? __( 'Quiz suggests 2 sizes up', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Quiz suggests 1 size up', 'dev3-ai-size-guide-for-woocommerce' );
	}
	if ( bias < 0 ) {
		return bias < -1 ? __( 'Quiz suggests 2 sizes down', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Quiz suggests 1 size down', 'dev3-ai-size-guide-for-woocommerce' );
	}
	return __( 'True to size', 'dev3-ai-size-guide-for-woocommerce' );
}

/**
 * Column "type" select value ↔ column fields.
 *
 * @param {Object} col Column.
 * @return {string} size | text | m:<measure>.
 */
export const colType = ( col ) => ( col.kind === 'measure' ? `m:${ col.measure || 'custom' }` : col.kind );

/**
 * Applies a type select value to a column.
 *
 * @param {Object} col      Column.
 * @param {string} value    Type value.
 * @param {Array}  measures Dictionary.
 * @return {Object} Column.
 */
export function withType( col, value, measures ) {
	if ( value.indexOf( 'm:' ) === 0 ) {
		const key = value.slice( 2 );
		const def = measures.find( ( m ) => m.key === key ) || { quantity: 'length', label: col.label };
		const keepLabel = col.kind === 'measure' && col.measure && col.measure !== 'custom' && col.label && col.label !== ( measures.find( ( m ) => m.key === col.measure ) || {} ).label;
		return { ...col, kind: 'measure', measure: key, quantity: def.quantity || 'length', label: keepLabel ? col.label : def.label };
	}
	return { ...col, kind: value, measure: '', quantity: 'none' };
}

/**
 * Size labels of a chart.
 *
 * @param {Object} chart Chart.
 * @return {string[]} Sizes.
 */
export function chartSizes( chart ) {
	const table = ( chart.tables || [] )[ 0 ];
	if ( ! table ) {
		return [];
	}
	const c = Math.max( 0, table.columns.findIndex( ( col ) => col.kind === 'size' ) );
	return table.rows.map( ( r ) => r[ c ] ).filter( Boolean );
}

/**
 * Quiz fields for a chart (client mirror of Renderer::quiz_fields).
 *
 * @param {Object} chart    Chart.
 * @param {Array}  measures Dictionary.
 * @return {Array} [ { key, label, quantity } ].
 */
export function quizFields( chart, measures ) {
	const out = {};
	let girths = false;
	( chart.tables || [] ).forEach( ( table ) => {
		if ( table.type === 'garment' ) {
			return;
		}
		table.columns.forEach( ( col, c ) => {
			if ( col.kind !== 'measure' || out[ col.measure ] || [ 'custom', 'length', 'width' ].includes( col.measure ) ) {
				return;
			}
			const values = new Set( table.rows.map( ( r ) => r[ c ] ).filter( Boolean ) );
			if ( values.size < 2 ) {
				return;
			}
			const def = measures.find( ( m ) => m.key === col.measure ) || {};
			out[ col.measure ] = { key: col.measure, label: def.label || col.label, quantity: col.quantity || def.quantity || 'length' };
			girths = girths || [ 'chest', 'bust', 'waist', 'hips' ].includes( col.measure );
		} );
	} );
	if ( girths ) {
		[ 'height', 'weight' ].forEach( ( key ) => {
			if ( ! out[ key ] ) {
				const def = measures.find( ( m ) => m.key === key ) || {};
				out[ key ] = { key, label: def.label || key, quantity: key === 'weight' ? 'weight' : 'length' };
			}
		} );
	}
	return Object.values( out );
}

export const relTime = ( iso ) => {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	return isNaN( d ) ? '' : d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
};

/**
 * Fields the API accepts (drops read-only extras).
 *
 * @param {Object} c Chart.
 * @return {Object} Payload.
 */
export function strip( c ) {
	return {
		name: c.name,
		status: c.status,
		profile: c.profile,
		tables: c.tables,
		guide: { text: c.guide.text, image_id: c.guide.image_id || 0 },
		fit_note: c.fit_note,
		bias: c.bias || 0,
		bias_at: c.bias_at || 0,
		rules: c.rules,
	};
}

