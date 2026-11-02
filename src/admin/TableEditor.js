import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, IconButton, Inline, Select, TextField } from '../core3/ui';
import { formatCell, toStored, unitLabel } from '../storefront/units';
import { colType, withType } from './util';

/**
 * A measurement cell: shows the value in the editing unit, stores cm/kg on blur.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    Stored value.
 * @param {string}   props.quantity length|weight.
 * @param {string}   props.system   metric|imperial.
 * @param {Function} props.onChange ( stored ) => void.
 * @param {string}   props.label    Accessible label.
 * @return {Element} Input.
 */
export function MeasureCell( { value, quantity, system, onChange, label } ) {
	const [ text, setText ] = useState( null );
	const shown = text !== null ? text : formatCell( value, quantity, system );
	return (
		<input
			className="dev3sg-a-cell"
			type="text"
			inputMode="decimal"
			aria-label={ label }
			value={ shown }
			placeholder="–"
			onFocus={ () => setText( formatCell( value, quantity, system ) ) }
			onChange={ ( e ) => setText( e.target.value ) }
			onBlur={ () => {
				if ( text !== null ) {
					const stored = text.trim() === '' ? '' : toStored( text, quantity, system );
					// Keep the original when nothing changed (avoids rounding drift in inches).
					if ( text !== formatCell( value, quantity, system ) ) {
						onChange( stored );
					}
				}
				setText( null );
			} }
		/>
	);
}

/**
 * Grid editor for one table: columns (label + type), rows, add/move/remove.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.table    Table.
 * @param {Function} props.onChange ( table ) => void.
 * @param {string}   props.system   Editing unit system.
 * @param {Array}    props.measures Measure dictionary.
 * @return {Element} Editor.
 */
export default function TableEditor( { table, onChange, system, measures } ) {
	const typeOptions = [
		{ value: 'size', label: __( 'Size label', 'dev3-ai-size-guide-for-woocommerce' ) },
		{ value: 'text', label: __( 'Text / other size system', 'dev3-ai-size-guide-for-woocommerce' ) },
		{
			label: __( 'Measurement', 'dev3-ai-size-guide-for-woocommerce' ),
			options: measures.map( ( m ) => ( { value: `m:${ m.key }`, label: m.label } ) ),
		},
	];
	const update = ( patch ) => onChange( { ...table, ...patch } );
	const setCol = ( i, col ) => update( { columns: table.columns.map( ( c, j ) => ( j === i ? col : c ) ) } );
	const setCell = ( r, c, v ) => update( { rows: table.rows.map( ( row, j ) => ( j === r ? row.map( ( x, k ) => ( k === c ? v : x ) ) : row ) ) } );
	const addRow = () => update( { rows: [ ...table.rows, table.columns.map( () => '' ) ] } );
	const addCol = () =>
		update( {
			columns: [ ...table.columns, { key: '', label: __( 'Measurement', 'dev3-ai-size-guide-for-woocommerce' ), kind: 'measure', measure: 'custom', quantity: 'length' } ],
			rows: table.rows.map( ( row ) => [ ...row, '' ] ),
		} );
	const removeCol = ( i ) => update( { columns: table.columns.filter( ( c, j ) => j !== i ), rows: table.rows.map( ( row ) => row.filter( ( x, j ) => j !== i ) ) } );
	const moveCol = ( i, d ) => {
		const j = i + d;
		if ( j < 0 || j >= table.columns.length ) {
			return;
		}
		const swap = ( arr ) => {
			const a = [ ...arr ];
			[ a[ i ], a[ j ] ] = [ a[ j ], a[ i ] ];
			return a;
		};
		update( { columns: swap( table.columns ), rows: table.rows.map( swap ) } );
	};
	const removeRow = ( r ) => update( { rows: table.rows.filter( ( row, j ) => j !== r ) } );
	const moveRow = ( r, d ) => {
		const j = r + d;
		if ( j < 0 || j >= table.rows.length ) {
			return;
		}
		const rows = [ ...table.rows ];
		[ rows[ r ], rows[ j ] ] = [ rows[ j ], rows[ r ] ];
		update( { rows } );
	};
	const sizeCol = Math.max( 0, table.columns.findIndex( ( c ) => c.kind === 'size' ) );

	return (
		<div className="dev3sg-a-tedit">
			<div className="dev3sg-a-tedit__meta">
				<TextField label={ __( 'Table title', 'dev3-ai-size-guide-for-woocommerce' ) } value={ table.title } onChange={ ( title ) => update( { title } ) } maxLength={ 60 } />
				<Select
					label={ __( 'Measurements are of', 'dev3-ai-size-guide-for-woocommerce' ) }
					value={ table.type }
					onChange={ ( type ) => update( { type } ) }
					options={ [
						{ value: 'body', label: __( 'The body (used by the quiz)', 'dev3-ai-size-guide-for-woocommerce' ) },
						{ value: 'garment', label: __( 'The garment (shown only)', 'dev3-ai-size-guide-for-woocommerce' ) },
					] }
				/>
			</div>
			<div className="dev3sg-a-grid-wrap" role="region" tabIndex={ 0 } aria-label={ __( 'Size table editor', 'dev3-ai-size-guide-for-woocommerce' ) }>
				<table className="dev3sg-a-grid">
					<thead>
						<tr>
							{ table.columns.map( ( col, i ) => (
								<th key={ i } scope="col">
									<div className="dev3sg-a-colhead">
										<input
											className="dev3sg-a-cell dev3sg-a-cell--head"
											type="text"
											value={ col.label }
											maxLength={ 40 }
											aria-label={ sprintf( /* translators: %d: column number. */ __( 'Column %d name', 'dev3-ai-size-guide-for-woocommerce' ), i + 1 ) }
											onChange={ ( e ) => setCol( i, { ...col, label: e.target.value } ) }
										/>
										<select
											className="dev3sg-a-cell dev3sg-a-cell--type"
											value={ colType( col ) }
											aria-label={ sprintf( /* translators: %d: column number. */ __( 'Column %d type', 'dev3-ai-size-guide-for-woocommerce' ), i + 1 ) }
											onChange={ ( e ) => setCol( i, withType( col, e.target.value, measures ) ) }
										>
											{ typeOptions.map( ( o ) =>
												o.options ? (
													<optgroup key={ o.label } label={ o.label }>
														{ o.options.map( ( x ) => (
															<option key={ x.value } value={ x.value }>
																{ x.label }
															</option>
														) ) }
													</optgroup>
												) : (
													<option key={ o.value } value={ o.value }>
														{ o.label }
													</option>
												)
											) }
										</select>
										<div className="dev3sg-a-colhead__tools">
											{ col.kind === 'measure' && <span className="dev3sg-a-unit">{ unitLabel( col.quantity, system ) }</span> }
											<IconButton size="sm" icon="chevronLeft" label={ __( 'Move column left', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => moveCol( i, -1 ) } disabled={ i === 0 } />
											<IconButton size="sm" icon="chevronRight" label={ __( 'Move column right', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => moveCol( i, 1 ) } disabled={ i === table.columns.length - 1 } />
											<IconButton size="sm" icon="trash" label={ __( 'Delete column', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => removeCol( i ) } disabled={ table.columns.length < 2 } />
										</div>
									</div>
								</th>
							) ) }
							<th scope="col" className="dev3sg-a-grid__tools">
								<span className="dev3sg-ui-sr-only">{ __( 'Row actions', 'dev3-ai-size-guide-for-woocommerce' ) }</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ table.rows.map( ( row, r ) => (
							<tr key={ r }>
								{ table.columns.map( ( col, c ) => {
									const label = sprintf( /* translators: 1: column name, 2: size. */ __( '%1$s for size %2$s', 'dev3-ai-size-guide-for-woocommerce' ), col.label, row[ sizeCol ] || r + 1 );
									return (
										<td key={ c }>
											{ col.kind === 'measure' ? (
												<MeasureCell value={ row[ c ] || '' } quantity={ col.quantity } system={ system } onChange={ ( v ) => setCell( r, c, v ) } label={ label } />
											) : (
												<input className={ `dev3sg-a-cell${ col.kind === 'size' ? ' dev3sg-a-cell--size' : '' }` } type="text" value={ row[ c ] || '' } maxLength={ 40 } aria-label={ label } onChange={ ( e ) => setCell( r, c, e.target.value ) } />
											) }
										</td>
									);
								} ) }
								<td className="dev3sg-a-grid__tools">
									<Inline gap={ 2 }>
										<IconButton size="sm" icon="chevronDown" className="dev3sg-a-flip" label={ __( 'Move row up', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => moveRow( r, -1 ) } disabled={ r === 0 } />
										<IconButton size="sm" icon="chevronDown" label={ __( 'Move row down', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => moveRow( r, 1 ) } disabled={ r === table.rows.length - 1 } />
										<IconButton size="sm" icon="trash" label={ __( 'Delete row', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => removeRow( r ) } />
									</Inline>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<Inline gap={ 8 }>
				<Button size="sm" icon="plus" onClick={ addRow }>
					{ __( 'Add size', 'dev3-ai-size-guide-for-woocommerce' ) }
				</Button>
				<Button size="sm" icon="columns" onClick={ addCol }>
					{ __( 'Add column', 'dev3-ai-size-guide-for-woocommerce' ) }
				</Button>
			</Inline>
		</div>
	);
}
