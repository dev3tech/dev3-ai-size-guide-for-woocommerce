import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, ErrorNotice, FormGrid, IconButton, Inline, Modal, NumberField, PageHeader, Select, SkeletonCard, Stack, Tabs, Textarea, TextField, Toggle, useToast } from '../core3/ui';
import AiChartPanel from './AiChartPanel';
import ChartPreview from './ChartPreview';
import FitTester from './FitTester';
import TableEditor from './TableEditor';
import TermPicker from './TermPicker';
import { biasText, PROFILES, strip, useApp } from './util';

let tableSeq = 100;

/**
 * Chart editor (#/charts/<id>): tables, guide, fit note, assignment, DEV3 AI, preview and quiz test.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Chart id.
 * @param {boolean}  props.openAi   Start with the AI card focused.
 * @param {Function} props.navigate Router.
 * @return {Element} Editor.
 */
export default function ChartEditor( { id, openAi = false, navigate } ) {
	const { measures, settings, refreshUsage } = useApp();
	const toast = useToast();
	const [ chart, setChart ] = useState( null );
	const [ saved, setSaved ] = useState( null );
	const [ lookup, setLookup ] = useState( { categories: [], tags: [], products: [], exclude: [] } );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ tab, setTab ] = useState( 0 );
	const [ system, setSystem ] = useState( 'metric' );
	const [ guideDraft, setGuideDraft ] = useState( null );
	const [ guideBusy, setGuideBusy ] = useState( false );
	const [ guideError, setGuideError ] = useState( null );
	const [ highlight, setHighlight ] = useState( '' );
	const [ confirmDelete, setConfirmDelete ] = useState( false );

	const load = () => {
		setError( null );
		request( `charts/${ id }` )
			.then( ( c ) => {
				setChart( c );
				setSaved( JSON.stringify( strip( c ) ) );
				setLookup( c.lookup || { categories: [], tags: [], products: [], exclude: [] } );
			} )
			.catch( setError );
	};
	useEffect( load, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( openAi && chart ) {
			const el = document.getElementById( 'dev3sg-a-ai' );
			if ( el && el.scrollIntoView ) {
				el.scrollIntoView( { block: 'start' } );
			}
		}
	}, [ openAi, !! chart ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const dirty = useMemo( () => chart && saved !== JSON.stringify( strip( chart ) ), [ chart, saved ] );

	useEffect( () => {
		const warn = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	if ( error && ! chart ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! chart ) {
		return <SkeletonCard lines={ 8 } />;
	}

	const set = ( patch ) => setChart( { ...chart, ...patch } );
	const setRules = ( key, items ) => {
		setLookup( { ...lookup, [ key ]: items } );
		set( { rules: { ...chart.rules, [ key ]: items.map( ( i ) => i.id ) } } );
	};
	const tables = chart.tables || [];
	const current = tables[ Math.min( tab, tables.length - 1 ) ];
	const setTable = ( t ) => set( { tables: tables.map( ( x, i ) => ( i === tab ? t : x ) ) } );
	const addTable = () => {
		tableSeq += 1;
		set( {
			tables: [
				...tables,
				{
					id: `t${ tableSeq }`,
					title: __( 'International sizes', 'dev3-ai-size-guide-for-woocommerce' ),
					type: 'body',
					columns: [
						{ key: 'size', label: __( 'Size', 'dev3-ai-size-guide-for-woocommerce' ), kind: 'size', measure: '', quantity: 'none' },
						{ key: 'us', label: 'US', kind: 'text', measure: '', quantity: 'none' },
					],
					rows: ( tables[ 0 ] ? tables[ 0 ].rows.map( ( r ) => [ r[ Math.max( 0, tables[ 0 ].columns.findIndex( ( c ) => c.kind === 'size' ) ) ] || '', '' ] ) : [ [ 'S', '' ] ] ),
				},
			],
		} );
		setTab( tables.length );
	};
	const removeTable = () => {
		if ( tables.length < 2 ) {
			return;
		}
		set( { tables: tables.filter( ( t, i ) => i !== tab ) } );
		setTab( 0 );
	};

	const save = () => {
		setSaving( true );
		request( `charts/${ id }`, { method: 'POST', data: strip( chart ) } )
			.then( ( c ) => {
				setChart( c );
				setSaved( JSON.stringify( strip( c ) ) );
				setLookup( c.lookup || lookup );
				toast.success( __( 'Size chart saved.', 'dev3-ai-size-guide-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSaving( false ) );
	};

	const remove = () =>
		request( `charts/${ id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Size chart deleted.', 'dev3-ai-size-guide-for-woocommerce' ) );
				navigate( 'charts' );
			} )
			.catch( ( err ) => toast.error( err ) );

	const acceptDraft = ( draft, mode ) => {
		const incoming = draft.tables.map( ( t ) => {
			tableSeq += 1;
			return { ...t, id: `t${ tableSeq }` };
		} );
		set( {
			tables: mode === 'append' ? [ ...tables, ...incoming ] : incoming,
			profile: draft.profile || chart.profile,
			guide: { ...chart.guide, text: chart.guide.text || ( draft.guide && draft.guide.text ) || '' },
			fit_note: chart.fit_note || draft.fit_note || '',
		} );
		setTab( mode === 'append' ? tables.length : 0 );
	};

	const writeGuide = () => {
		setGuideBusy( true );
		setGuideError( null );
		request( 'ai/guide', { method: 'POST', data: { chart: strip( chart ) } } )
			.then( setGuideDraft )
			.catch( setGuideError )
			.finally( () => {
				setGuideBusy( false );
				refreshUsage();
			} );
	};

	const pickImage = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( { title: __( 'How-to-measure image', 'dev3-ai-size-guide-for-woocommerce' ), library: { type: 'image' }, multiple: false } );
		frame.on( 'select', () => {
			const a = frame.state().get( 'selection' ).first().toJSON();
			set( { guide: { ...chart.guide, image_id: a.id, image_url: ( a.sizes && a.sizes.large ? a.sizes.large.url : a.url ) || '' } } );
		} );
		frame.open();
	};

	const aiReady = settings.api_key_set && settings.ai_enabled !== false;

	return (
		<>
			<PageHeader
				back={ { href: '#/charts', label: __( 'All size charts', 'dev3-ai-size-guide-for-woocommerce' ) } }
				title={ chart.name || __( 'Untitled chart', 'dev3-ai-size-guide-for-woocommerce' ) }
				description={ sprintf( /* translators: %d: number of products. */ __( 'Shown on %d products. Values are stored in centimetres and kilograms; shoppers can switch to inches.', 'dev3-ai-size-guide-for-woocommerce' ), chart.product_count || 0 ) }
				actions={
					<Inline gap={ 8 }>
						{ dirty && <Badge tone="warning" dot>{ __( 'Unsaved changes', 'dev3-ai-size-guide-for-woocommerce' ) }</Badge> }
						<Button variant="primary" icon="check" loading={ saving } onClick={ save }>
							{ __( 'Save chart', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					</Inline>
				}
			/>

			<div className="dev3sg-a-editor">
				<div className="dev3sg-a-editor__main">
					<Card title={ __( 'Details', 'dev3-ai-size-guide-for-woocommerce' ) }>
						<FormGrid>
							<TextField label={ __( 'Chart name', 'dev3-ai-size-guide-for-woocommerce' ) } value={ chart.name } onChange={ ( name ) => set( { name } ) } required maxLength={ 120 } />
							<Select label={ __( 'Body profile', 'dev3-ai-size-guide-for-woocommerce' ) } value={ chart.profile } onChange={ ( profile ) => set( { profile } ) } options={ PROFILES } help={ __( 'Used to estimate measurements from height and weight.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						</FormGrid>
						<div className="dev3sg-a-mt">
							<Toggle label={ __( 'Live on the store', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ chart.status === 'publish' } onChange={ ( on ) => set( { status: on ? 'publish' : 'draft' } ) } help={ __( 'Drafts are never shown to shoppers.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						</div>
					</Card>

					<Card
						title={ __( 'Size tables', 'dev3-ai-size-guide-for-woocommerce' ) }
						description={ __( 'One row per size. Ranges like 86-91 are fine. Each table becomes a tab for shoppers.', 'dev3-ai-size-guide-for-woocommerce' ) }
						actions={
							<div className="dev3sg-a-seggroup" role="group" aria-label={ __( 'Editing unit', 'dev3-ai-size-guide-for-woocommerce' ) }>
								<button type="button" className="dev3sg-a-seg" aria-pressed={ system === 'metric' } onClick={ () => setSystem( 'metric' ) }>
									cm / kg
								</button>
								<button type="button" className="dev3sg-a-seg" aria-pressed={ system === 'imperial' } onClick={ () => setSystem( 'imperial' ) }>
									in / lb
								</button>
							</div>
						}
					>
						<Stack gap={ 12 }>
							<div className="dev3sg-a-tabsrow">
								<Tabs variant="line" label={ __( 'Tables', 'dev3-ai-size-guide-for-woocommerce' ) } value={ String( Math.min( tab, tables.length - 1 ) ) } onChange={ ( v ) => setTab( Number( v ) ) } tabs={ tables.map( ( t, i ) => ( { id: String( i ), label: t.title || sprintf( /* translators: %d: table number. */ __( 'Table %d', 'dev3-ai-size-guide-for-woocommerce' ), i + 1 ) } ) ) } />
								<Inline gap={ 4 }>
									<Button size="sm" variant="ghost" icon="plus" onClick={ addTable } disabled={ tables.length >= 6 }>
										{ __( 'Add table', 'dev3-ai-size-guide-for-woocommerce' ) }
									</Button>
									{ tables.length > 1 && <IconButton size="sm" icon="trash" label={ __( 'Delete this table', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ removeTable } /> }
								</Inline>
							</div>
							{ current && <TableEditor table={ current } onChange={ setTable } system={ system } measures={ measures } /> }
						</Stack>
					</Card>

					<div id="dev3sg-a-ai">
						<AiChartPanel chart={ chart } onAccept={ acceptDraft } />
					</div>

					<Card title={ __( 'How to measure & fit note', 'dev3-ai-size-guide-for-woocommerce' ) }>
						<Stack gap={ 16 }>
							<Textarea
								label={ __( 'How-to-measure guide', 'dev3-ai-size-guide-for-woocommerce' ) }
								value={ chart.guide.text }
								onChange={ ( text ) => set( { guide: { ...chart.guide, text } } ) }
								rows={ 5 }
								maxLength={ 3000 }
								help={ __( 'One step per line. "Chest: measure around…" shows the measure name in bold.', 'dev3-ai-size-guide-for-woocommerce' ) }
							/>
							<Inline gap={ 8 }>
								<AiButton loading={ guideBusy } disabled={ ! aiReady } onClick={ writeGuide }>
									{ __( 'Write guide with DEV3 AI', 'dev3-ai-size-guide-for-woocommerce' ) }
								</AiButton>
								{ window.wp && window.wp.media && (
									<Button icon="file" onClick={ pickImage }>
										{ chart.guide.image_id ? __( 'Change image', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Add a measuring image', 'dev3-ai-size-guide-for-woocommerce' ) }
									</Button>
								) }
								{ !! chart.guide.image_id && (
									<Button variant="ghost" icon="x" onClick={ () => set( { guide: { ...chart.guide, image_id: 0, image_url: '' } } ) }>
										{ __( 'Remove image', 'dev3-ai-size-guide-for-woocommerce' ) }
									</Button>
								) }
							</Inline>
							{ chart.guide.image_url && <img className="dev3sg-a-thumb" src={ chart.guide.image_url } alt={ __( 'How-to-measure image', 'dev3-ai-size-guide-for-woocommerce' ) } /> }
							<ErrorNotice error={ guideError } onRetry={ writeGuide } onDismiss={ () => setGuideError( null ) } />
							{ guideDraft && (
								<AiDraftReview
									draft={ guideDraft.text }
									original={ chart.guide.text }
									meta={ { model: guideDraft.model, tokens: guideDraft.tokens, cached: guideDraft.cached } }
									regenerating={ guideBusy }
									onRegenerate={ writeGuide }
									onReject={ () => setGuideDraft( null ) }
									onAccept={ ( text ) => {
										set( { guide: { ...chart.guide, text } } );
										setGuideDraft( null );
									} }
								/>
							) }
							<TextField
								label={ __( 'Fit note for shoppers', 'dev3-ai-size-guide-for-woocommerce' ) }
								value={ chart.fit_note }
								onChange={ ( fit_note ) => set( { fit_note } ) }
								maxLength={ 200 }
								placeholder={ __( 'e.g. This style runs small. We recommend one size up.', 'dev3-ai-size-guide-for-woocommerce' ) }
							/>
							<Select
								label={ __( 'Quiz adjustment (learned from returns)', 'dev3-ai-size-guide-for-woocommerce' ) }
								value={ String( chart.bias || 0 ) }
								onChange={ ( v ) => set( { bias: Number( v ), bias_at: Math.floor( Date.now() / 1000 ) } ) }
								options={ [ -2, -1, 0, 1, 2 ].map( ( b ) => ( { value: String( b ), label: biasText( b ) } ) ) }
								help={ __( 'Moves the quiz result up or down when a style runs small or large. Returns & learning suggests this for you.', 'dev3-ai-size-guide-for-woocommerce' ) }
							/>
						</Stack>
					</Card>

					<Card title={ __( 'Where it shows', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'A product uses its own override first, then charts listing the product, then tags, then categories (sub-categories included). Higher priority wins ties.', 'dev3-ai-size-guide-for-woocommerce' ) }>
						<FormGrid>
							<TermPicker type="product_cat" label={ __( 'Categories', 'dev3-ai-size-guide-for-woocommerce' ) } value={ lookup.categories } onChange={ ( v ) => setRules( 'categories', v ) } />
							<TermPicker type="product_tag" label={ __( 'Tags', 'dev3-ai-size-guide-for-woocommerce' ) } value={ lookup.tags } onChange={ ( v ) => setRules( 'tags', v ) } />
							<TermPicker type="product" label={ __( 'Products', 'dev3-ai-size-guide-for-woocommerce' ) } value={ lookup.products } onChange={ ( v ) => setRules( 'products', v ) } />
							<TermPicker type="product" label={ __( 'Exclude products', 'dev3-ai-size-guide-for-woocommerce' ) } value={ lookup.exclude } onChange={ ( v ) => setRules( 'exclude', v ) } />
							<NumberField label={ __( 'Priority', 'dev3-ai-size-guide-for-woocommerce' ) } value={ chart.rules.priority } min={ -100 } max={ 100 } onChange={ ( v ) => set( { rules: { ...chart.rules, priority: v === '' ? 0 : v } } ) } help={ __( 'Higher wins when two charts match at the same level.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						</FormGrid>
					</Card>

					<Card title={ __( 'Danger zone', 'dev3-ai-size-guide-for-woocommerce' ) }>
						<Button variant="danger" icon="trash" onClick={ () => setConfirmDelete( true ) }>
							{ __( 'Delete this chart', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					</Card>
				</div>

				<aside className="dev3sg-a-editor__side">
					<Card title={ __( 'Storefront preview', 'dev3-ai-size-guide-for-woocommerce' ) } description={ dirty ? __( 'Includes unsaved changes.', 'dev3-ai-size-guide-for-woocommerce' ) : undefined }>
						<ChartPreview chart={ chart } highlight={ highlight } />
					</Card>
					<FitTester chart={ chart } onResult={ ( r ) => setHighlight( r ? r.size : '' ) } />
				</aside>
			</div>

			<Modal
				open={ confirmDelete }
				onClose={ () => setConfirmDelete( false ) }
				title={ __( 'Delete this size chart?', 'dev3-ai-size-guide-for-woocommerce' ) }
				size="sm"
				footer={
					<Inline gap={ 8 } align="end">
						<Button onClick={ () => setConfirmDelete( false ) }>{ __( 'Cancel', 'dev3-ai-size-guide-for-woocommerce' ) }</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					</Inline>
				}
			>
				<p>{ __( 'Products that use it will stop showing a size guide. It moves to the trash.', 'dev3-ai-size-guide-for-woocommerce' ) }</p>
			</Modal>
		</>
	);
}
