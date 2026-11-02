import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, IconButton, Inline, Modal, PageHeader, ResponsiveTable, Stack, TextField, useToast } from '../core3/ui';
import { biasText, PROFILES, relTime } from './util';

/**
 * "New chart" chooser: blank, template or DEV3 AI.
 *
 * @param {Object}   props          Props.
 * @param {boolean}  props.open     Open.
 * @param {Function} props.onClose  Close.
 * @param {Function} props.navigate Router.
 * @return {Element} Modal.
 */
export function NewChartModal( { open, onClose, navigate } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( '' );
	const create = ( mode ) => {
		if ( mode === 'template' ) {
			onClose();
			navigate( 'templates' );
			return;
		}
		setBusy( mode );
		request( 'charts', { method: 'POST', data: { chart: { name: __( 'New size chart', 'dev3-ai-size-guide-for-woocommerce' ) } } } )
			.then( ( c ) => {
				onClose();
				navigate( mode === 'ai' ? `charts/${ c.id }/ai` : `charts/${ c.id }` );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};
	const options = [
		{ id: 'ai', icon: 'sparkles', title: __( 'Generate with DEV3 AI', 'dev3-ai-size-guide-for-woocommerce' ), text: __( 'From a product, a pasted supplier chart or a photo.', 'dev3-ai-size-guide-for-woocommerce' ) },
		{ id: 'template', icon: 'columns', title: __( 'Start from a template', 'dev3-ai-size-guide-for-woocommerce' ), text: __( '12 ready-made charts: tops, dresses, jeans, shoes, kids, bras, rings, hats.', 'dev3-ai-size-guide-for-woocommerce' ) },
		{ id: 'blank', icon: 'plus', title: __( 'Blank chart', 'dev3-ai-size-guide-for-woocommerce' ), text: __( 'Type or paste your own sizes.', 'dev3-ai-size-guide-for-woocommerce' ) },
	];
	return (
		<Modal open={ open } onClose={ onClose } title={ __( 'New size chart', 'dev3-ai-size-guide-for-woocommerce' ) } size="md">
			<div className="dev3sg-a-choices">
				{ options.map( ( o ) => (
					<button key={ o.id } type="button" className="dev3sg-a-choice" onClick={ () => create( o.id ) } disabled={ !! busy } data-autofocus={ o.id === 'ai' ? true : undefined }>
						<span className={ `dev3sg-a-choice__icon is-${ o.id }` } aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
								{ o.id === 'ai' && <path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z" /> }
								{ o.id === 'template' && <path d="M12 3v18M3 5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2z" /> }
								{ o.id === 'blank' && <path d="M12 5v14M5 12h14" /> }
							</svg>
						</span>
						<span>
							<strong>{ o.title }</strong>
							<span className="dev3sg-a-muted">{ busy === o.id ? __( 'Creating…', 'dev3-ai-size-guide-for-woocommerce' ) : o.text }</span>
						</span>
					</button>
				) ) }
			</div>
		</Modal>
	);
}

/**
 * Size charts list (#/charts).
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @return {Element} Screen.
 */
export default function ChartsList( { navigate } ) {
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ creating, setCreating ] = useState( false );

	const load = () => {
		setError( null );
		request( 'charts' ).then( setRows ).catch( setError );
	};
	useEffect( load, [] );

	const duplicate = ( row ) =>
		request( `charts/${ row.id }/duplicate`, { method: 'POST' } )
			.then( ( c ) => {
				toast.success( __( 'Chart duplicated as a draft.', 'dev3-ai-size-guide-for-woocommerce' ) );
				navigate( `charts/${ c.id }` );
			} )
			.catch( ( err ) => toast.error( err ) );

	const list = ( rows || [] ).filter( ( r ) => ! search || r.name.toLowerCase().includes( search.toLowerCase() ) );
	const columns = [
		{
			key: 'name',
			label: __( 'Chart', 'dev3-ai-size-guide-for-woocommerce' ),
			primary: true,
			sortable: true,
			render: ( r ) => (
				<span className="dev3sg-a-namecell">
					<a href={ `#/charts/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>
						{ r.name }
					</a>
					<span className="dev3sg-a-muted">{ ( PROFILES.find( ( p ) => p.value === r.profile ) || {} ).label }</span>
				</span>
			),
		},
		{ key: 'sizes', label: __( 'Sizes', 'dev3-ai-size-guide-for-woocommerce' ), render: ( r ) => ( r.sizes.length ? `${ r.sizes.slice( 0, 6 ).join( ', ' ) }${ r.sizes.length > 6 ? '…' : '' }` : '–' ) },
		{ key: 'rules_text', label: __( 'Assigned to', 'dev3-ai-size-guide-for-woocommerce' ), hideOnMobile: false },
		{ key: 'product_count', label: __( 'Products', 'dev3-ai-size-guide-for-woocommerce' ), sortable: true, align: 'end' },
		{
			key: 'status',
			label: __( 'Status', 'dev3-ai-size-guide-for-woocommerce' ),
			render: ( r ) => (
				<Inline gap={ 4 }>
					<Badge tone={ r.status === 'publish' ? 'success' : 'neutral' } dot>
						{ r.status === 'publish' ? __( 'Live', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Draft', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Badge>
					{ r.bias !== 0 && <Badge tone="warning">{ biasText( r.bias ) }</Badge> }
				</Inline>
			),
		},
		{ key: 'updated', label: __( 'Updated', 'dev3-ai-size-guide-for-woocommerce' ), sortable: true, render: ( r ) => relTime( r.updated ), hideOnMobile: true },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Size charts', 'dev3-ai-size-guide-for-woocommerce' ) }
				description={ __( 'Build charts once and assign them to categories, tags or products.', 'dev3-ai-size-guide-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setCreating( true ) }>
						{ __( 'New chart', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
				}
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<Stack gap={ 0 }>
					<div className="dev3sg-a-toolbar">
						<TextField label={ __( 'Search charts', 'dev3-ai-size-guide-for-woocommerce' ) } hideLabel type="search" placeholder={ __( 'Search charts…', 'dev3-ai-size-guide-for-woocommerce' ) } value={ search } onChange={ setSearch } />
						{ rows && <span className="dev3sg-a-muted">{ sprintf( /* translators: %d: charts. */ __( '%d charts', 'dev3-ai-size-guide-for-woocommerce' ), list.length ) }</span> }
					</div>
					<ResponsiveTable
						columns={ columns }
						rows={ list }
						loading={ ! rows && ! error }
						pageSize={ 20 }
						onRowClick={ ( r ) => navigate( `charts/${ r.id }` ) }
						rowActions={ ( r ) => (
							<span onClick={ ( e ) => e.stopPropagation() } onKeyDown={ ( e ) => e.stopPropagation() } role="presentation">
								<IconButton icon="edit" label={ sprintf( /* translators: %s: chart. */ __( 'Edit %s', 'dev3-ai-size-guide-for-woocommerce' ), r.name ) } onClick={ () => navigate( `charts/${ r.id }` ) } />
								<IconButton icon="copy" label={ sprintf( /* translators: %s: chart. */ __( 'Duplicate %s', 'dev3-ai-size-guide-for-woocommerce' ), r.name ) } onClick={ () => duplicate( r ) } />
							</span>
						) }
						empty={ {
							icon: 'columns',
							title: __( 'No size charts yet', 'dev3-ai-size-guide-for-woocommerce' ),
							description: __( 'Start from a template or let DEV3 AI draft one from your product data.', 'dev3-ai-size-guide-for-woocommerce' ),
							action: (
								<Button variant="primary" icon="plus" onClick={ () => setCreating( true ) }>
									{ __( 'New chart', 'dev3-ai-size-guide-for-woocommerce' ) }
								</Button>
							),
						} }
					/>
				</Stack>
			</Card>
			<NewChartModal open={ creating } onClose={ () => setCreating( false ) } navigate={ navigate } />
		</>
	);
}
