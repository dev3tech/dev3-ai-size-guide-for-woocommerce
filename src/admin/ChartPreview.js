import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatCell, unitLabel } from '../storefront/units';
import { cx } from '../core3/ui';

/**
 * One table rendered with the storefront markup and CSS (WYSIWYG).
 *
 * @param {Object} props           Props.
 * @param {Object} props.table     Table.
 * @param {string} props.highlight Size to highlight.
 * @return {Element} Table.
 */
export function PreviewTable( { table, highlight = '' } ) {
	const sizeCol = Math.max( 0, table.columns.findIndex( ( c ) => c.kind === 'size' ) );
	const want = String( highlight ).toLowerCase();
	return (
		<div className="dev3sg-table-wrap" role="region" tabIndex={ 0 } aria-label={ table.title || __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' ) }>
			<table className="dev3sg-table">
				<caption className="dev3sg-sr">{ table.title }</caption>
				<thead>
					<tr>
						{ table.columns.map( ( col, i ) => (
							<th key={ i } scope="col">
								{ col.label }
								{ col.kind === 'measure' && (
									<span className="dev3sg-table__unit">
										<span data-u="metric">{ unitLabel( col.quantity, 'metric' ) }</span>
										<span data-u="imperial">{ unitLabel( col.quantity, 'imperial' ) }</span>
									</span>
								) }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ table.rows.map( ( row, r ) => {
						const on = want !== '' && String( row[ sizeCol ] || '' ).toLowerCase() === want;
						return (
							<tr key={ r } className={ cx( on && 'is-recommended' ) }>
								{ table.columns.map( ( col, c ) => {
									const cell = row[ c ] || '';
									if ( col.kind === 'size' ) {
										return (
											<th key={ c } scope="row">
												{ cell }
												{ on && <span className="dev3sg-rec-badge">{ __( 'Recommended', 'dev3-ai-size-guide-for-woocommerce' ) }</span> }
											</th>
										);
									}
									if ( col.kind === 'measure' && cell ) {
										return (
											<td key={ c }>
												<span data-u="metric">{ formatCell( cell, col.quantity, 'metric' ) }</span>
												<span data-u="imperial">{ formatCell( cell, col.quantity, 'imperial' ) }</span>
											</td>
										);
									}
									return <td key={ c }>{ cell || '–' }</td>;
								} ) }
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * Storefront preview of a chart (tables, how to measure, fit note) at desktop or phone width.
 *
 * @param {Object}  props           Props.
 * @param {Object}  props.chart     Chart.
 * @param {string}  props.highlight Highlighted size.
 * @param {boolean} props.frames    Show the desktop / phone switch.
 * @return {Element} Preview.
 */
export default function ChartPreview( { chart, highlight = '', frames = true } ) {
	const [ system, setSystem ] = useState( 'metric' );
	const [ frame, setFrame ] = useState( 'desktop' );
	const [ tab, setTab ] = useState( 0 );
	const tables = ( chart.tables || [] ).filter( ( t ) => t.rows && t.rows.length );
	const guide = chart.guide && chart.guide.text ? chart.guide.text.split( '\n' ).filter( Boolean ) : [];
	const panels = [ ...tables.map( ( t ) => ( { id: 'table', label: t.title || __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' ), table: t } ) ) ];
	if ( guide.length || ( chart.guide && chart.guide.image_url ) ) {
		panels.push( { id: 'measure', label: __( 'How to measure', 'dev3-ai-size-guide-for-woocommerce' ) } );
	}
	const active = panels[ Math.min( tab, panels.length - 1 ) ];
	const hasMeasures = tables.some( ( t ) => t.columns.some( ( c ) => c.kind === 'measure' ) );

	return (
		<div className="dev3sg-a-preview">
			{ frames && (
				<div className="dev3sg-a-preview__bar" role="group" aria-label={ __( 'Preview width', 'dev3-ai-size-guide-for-woocommerce' ) }>
					<button type="button" className="dev3sg-a-seg" aria-pressed={ frame === 'desktop' } onClick={ () => setFrame( 'desktop' ) }>
						{ __( 'Desktop', 'dev3-ai-size-guide-for-woocommerce' ) }
					</button>
					<button type="button" className="dev3sg-a-seg" aria-pressed={ frame === 'phone' } onClick={ () => setFrame( 'phone' ) }>
						{ __( 'Phone', 'dev3-ai-size-guide-for-woocommerce' ) }
					</button>
				</div>
			) }
			<div className={ cx( 'dev3sg-a-preview__frame', `is-${ frame }` ) }>
				{ frame === 'phone' && <div className="dev3sg-a-preview__grab" aria-hidden="true" /> }
				<div className="dev3sg-guide dev3sg-guide--popup" data-unit={ system }>
					<div className="dev3sg-toolbar">
						{ panels.length > 1 && (
							<div className="dev3sg-tabs" role="tablist" aria-label={ __( 'Preview sections', 'dev3-ai-size-guide-for-woocommerce' ) }>
								{ panels.map( ( p, i ) => (
									<button key={ i } type="button" role="tab" className="dev3sg-tab" aria-selected={ p === active } onClick={ () => setTab( i ) }>
										{ p.label }
									</button>
								) ) }
							</div>
						) }
						{ hasMeasures && (
							<div className="dev3sg-units" role="group" aria-label={ __( 'Units', 'dev3-ai-size-guide-for-woocommerce' ) }>
								<button type="button" className="dev3sg-unit" aria-pressed={ system === 'metric' } onClick={ () => setSystem( 'metric' ) }>
									cm
								</button>
								<button type="button" className="dev3sg-unit" aria-pressed={ system === 'imperial' } onClick={ () => setSystem( 'imperial' ) }>
									in
								</button>
							</div>
						) }
					</div>
					{ chart.fit_note && <p className="dev3sg-note">ⓘ { chart.fit_note }</p> }
					{ ! active && <p className="dev3sg-a-muted">{ __( 'Add sizes to see the preview.', 'dev3-ai-size-guide-for-woocommerce' ) }</p> }
					{ active && active.table && <PreviewTable table={ active.table } highlight={ highlight } /> }
					{ active && active.id === 'measure' && (
						<div className="dev3sg-measure">
							{ chart.guide.image_url && <img className="dev3sg-measure__img" src={ chart.guide.image_url } alt="" /> }
							<ol className="dev3sg-measure__steps">
								{ guide.map( ( line, i ) => {
									const parts = line.split( ':' );
									return parts.length > 1 && parts[ 0 ].length <= 30 ? (
										<li key={ i }>
											<strong>{ parts[ 0 ] }</strong> { parts.slice( 1 ).join( ':' ).trim() }
										</li>
									) : (
										<li key={ i }>{ line }</li>
									);
								} ) }
							</ol>
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
