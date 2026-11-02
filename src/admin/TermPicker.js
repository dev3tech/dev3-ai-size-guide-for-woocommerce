import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Field, Icon, Spinner, useUid } from '../core3/ui';

/**
 * Search-and-pick chips for categories, tags or products (GET lookup).
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    Label.
 * @param {string}   props.type     product_cat | product_tag | product.
 * @param {Array}    props.value    [ { id, name } ].
 * @param {Function} props.onChange ( items ) => void.
 * @param {string}   props.help     Help.
 * @param {boolean}  props.single   Only one item.
 * @return {Element} Picker.
 */
export default function TermPicker( { label, type, value = [], onChange, help, single = false } ) {
	const id = useUid( 'dev3sg-a-picker' );
	const listId = `${ id }-list`;
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const timer = useRef( null );
	const box = useRef( null );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			setBusy( true );
			request( `lookup?type=${ type }&search=${ encodeURIComponent( search ) }` )
				.then( ( r ) => setResults( Array.isArray( r ) ? r : [] ) )
				.catch( () => setResults( [] ) )
				.finally( () => setBusy( false ) );
		}, 250 );
		return () => clearTimeout( timer.current );
	}, [ search, open, type ] );

	useEffect( () => {
		const close = ( e ) => {
			if ( box.current && ! box.current.contains( e.target ) ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', close );
		return () => document.removeEventListener( 'mousedown', close );
	}, [] );

	const add = ( item ) => {
		if ( ! value.some( ( v ) => v.id === item.id ) ) {
			onChange( single ? [ item ] : [ ...value, item ] );
		}
		setSearch( '' );
		if ( single ) {
			setOpen( false );
		}
	};
	const remove = ( item ) => onChange( value.filter( ( v ) => v.id !== item.id ) );
	const available = results.filter( ( r ) => ! value.some( ( v ) => v.id === r.id ) );

	return (
		<Field id={ id } label={ label } help={ help }>
			{ ( a11y ) => (
				<div className="dev3sg-a-picker" ref={ box }>
					{ value.length > 0 && (
						<ul className="dev3sg-a-chips">
							{ value.map( ( item ) => (
								<li key={ item.id } className="dev3sg-a-chip">
									<span>{ item.name || `#${ item.id }` }</span>
									<button type="button" onClick={ () => remove( item ) } aria-label={ sprintf( /* translators: %s: item name. */ __( 'Remove %s', 'dev3-ai-size-guide-for-woocommerce' ), item.name ) }>
										<Icon name="x" size={ 12 } />
									</button>
								</li>
							) ) }
						</ul>
					) }
					<div className="dev3sg-ui-inputwrap">
						<input
							id={ id }
							className="dev3sg-ui-input"
							type="search"
							role="combobox"
							aria-expanded={ open }
							aria-controls={ listId }
							aria-autocomplete="list"
							autoComplete="off"
							placeholder={ __( 'Search…', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ search }
							onFocus={ () => setOpen( true ) }
							onChange={ ( e ) => {
								setSearch( e.target.value );
								setOpen( true );
							} }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Escape' ) {
									setOpen( false );
								}
								if ( e.key === 'Enter' ) {
									e.preventDefault();
									if ( available[ 0 ] ) {
										add( available[ 0 ] );
									}
								}
							} }
							{ ...a11y }
						/>
						{ busy && (
							<span className="dev3sg-a-picker__spin">
								<Spinner size={ 14 } />
							</span>
						) }
					</div>
					{ open && (
						<ul className="dev3sg-a-picker__list" id={ listId } role="listbox" aria-label={ label }>
							{ available.length === 0 && ! busy && <li className="dev3sg-a-picker__empty">{ __( 'No matches', 'dev3-ai-size-guide-for-woocommerce' ) }</li> }
							{ available.map( ( item ) => (
								<li key={ item.id } role="option" aria-selected="false">
									<button type="button" onClick={ () => add( item ) }>
										{ item.name }
										{ item.count !== undefined && <span className="dev3sg-a-muted"> · { item.count }</span> }
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</Field>
	);
}
