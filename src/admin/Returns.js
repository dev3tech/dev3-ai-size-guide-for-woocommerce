import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, EmptyState, ErrorNotice, FormGrid, IconButton, Inline, JobProgress, Modal, Notice, NumberField, PageHeader, ResponsiveTable, Select, SkeletonCard, Stack, Textarea, TextField, useToast } from '../core3/ui';
import TermPicker from './TermPicker';
import { biasText, DIRECTIONS, REASONS, relTime, SOURCES, useApp } from './util';

/**
 * One chart's learning card: per-size table, suggestion (accept / dismiss) and the AI summary.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.item     Insight.
 * @param {Function} props.onChange Reload.
 * @return {Element} Card.
 */
export function InsightCard( { item, onChange } ) {
	const { settings, refreshUsage } = useApp();
	const toast = useToast();
	const [ withNote, setWithNote ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ ai, setAi ] = useState( null );
	const [ aiBusy, setAiBusy ] = useState( false );
	const [ aiError, setAiError ] = useState( null );
	const s = item.suggestion;

	const apply = ( bias, note ) => {
		setBusy( true );
		request( 'insights/apply', { method: 'POST', data: { chart_id: item.chart_id, bias, fit_note: note } } )
			.then( () => {
				toast.success( __( 'Fit adjustment saved. The quiz uses it right away.', 'dev3-ai-size-guide-for-woocommerce' ) );
				setAi( null );
				onChange();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const dismiss = () =>
		request( 'insights/dismiss', { method: 'POST', data: { chart_id: item.chart_id, signature: s.signature } } )
			.then( onChange )
			.catch( ( err ) => toast.error( err ) );
	const summarise = () => {
		setAiBusy( true );
		setAiError( null );
		request( 'ai/insights', { method: 'POST', data: { chart_id: item.chart_id } } )
			.then( setAi )
			.catch( setAiError )
			.finally( () => {
				setAiBusy( false );
				refreshUsage();
			} );
	};

	return (
		<Card
			title={ item.chart_name }
			description={ sprintf( /* translators: 1: fit returns, 2: bias text. */ __( '%1$d fit returns · %2$s', 'dev3-ai-size-guide-for-woocommerce' ), item.fit_returns, biasText( item.bias ) ) }
			actions={
				<Inline gap={ 8 }>
					<Button size="sm" variant="ghost" href={ `#/charts/${ item.chart_id }` }>
						{ __( 'Open chart', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
					<AiButton size="sm" loading={ aiBusy } disabled={ ! settings.api_key_set } onClick={ summarise }>
						{ __( 'Summarise with DEV3 AI', 'dev3-ai-size-guide-for-woocommerce' ) }
					</AiButton>
				</Inline>
			}
			flush
		>
			{ s && (
				<div className="dev3sg-a-pad">
					<Notice
						tone="warning"
						title={ DIRECTIONS[ s.direction ].label }
						actions={
							<Inline gap={ 8 }>
								<Button size="sm" variant="primary" loading={ busy } onClick={ () => apply( s.bias, withNote ? s.fit_note : null ) }>
									{ s.bias > item.bias ? __( 'Suggest one size up', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Suggest one size down', 'dev3-ai-size-guide-for-woocommerce' ) }
								</Button>
								<Button size="sm" variant="ghost" onClick={ dismiss }>
									{ __( 'Dismiss', 'dev3-ai-size-guide-for-woocommerce' ) }
								</Button>
							</Inline>
						}
					>
						<p className="dev3sg-a-m0">{ s.message }</p>
						{ s.fit_note && <Checkbox label={ sprintf( /* translators: %s: note. */ __( 'Also show shoppers: “%s”', 'dev3-ai-size-guide-for-woocommerce' ), s.fit_note ) } checked={ withNote } onChange={ setWithNote } /> }
					</Notice>
				</div>
			) }
			<ErrorNotice error={ aiError } onDismiss={ () => setAiError( null ) } />
			{ ai && (
				<div className="dev3sg-a-pad">
					<div className="dev3sg-ui-draft">
						<div className="dev3sg-ui-draft__head">
							<div>
								<p className="dev3sg-ui-draft__title">{ __( 'DEV3 AI summary', 'dev3-ai-size-guide-for-woocommerce' ) }</p>
								<p className="dev3sg-ui-draft__meta">
									{ __( 'Not saved yet', 'dev3-ai-size-guide-for-woocommerce' ) } · { ai.model }
								</p>
							</div>
							<Badge tone={ DIRECTIONS[ ai.direction ].tone }>{ DIRECTIONS[ ai.direction ].label }</Badge>
						</div>
						<div className="dev3sg-ui-draft__body">
							<p>{ ai.summary }</p>
							{ ai.suggestions.length > 0 && (
								<ul className="dev3sg-a-list">
									{ ai.suggestions.map( ( x, i ) => (
										<li key={ i }>
											<strong>{ x.size }</strong> <Badge tone={ DIRECTIONS[ x.direction ].tone }>{ DIRECTIONS[ x.direction ].label }</Badge> { x.advice }
										</li>
									) ) }
								</ul>
							) }
							{ ai.fit_note && (
								<p className="dev3sg-a-muted">
									{ __( 'Shopper note:', 'dev3-ai-size-guide-for-woocommerce' ) } “{ ai.fit_note }”
								</p>
							) }
						</div>
						<div className="dev3sg-ui-draft__foot">
							<Inline gap={ 8 }>
								{ ai.bias !== item.bias && (
									<Button variant="primary" icon="check" loading={ busy } onClick={ () => apply( ai.bias, ai.fit_note || null ) }>
										{ sprintf( /* translators: %s: bias text. */ __( 'Accept: %s', 'dev3-ai-size-guide-for-woocommerce' ), biasText( ai.bias ) ) }
									</Button>
								) }
								<Button variant="ghost" icon="x" onClick={ () => setAi( null ) }>
									{ __( 'Close', 'dev3-ai-size-guide-for-woocommerce' ) }
								</Button>
							</Inline>
						</div>
					</div>
				</div>
			) }
			<ResponsiveTable
				rowKey="size"
				rows={ item.sizes }
				columns={ [
					{ key: 'size', label: __( 'Size', 'dev3-ai-size-guide-for-woocommerce' ), primary: true },
					{ key: 'sold', label: __( 'Sold', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end' },
					{ key: 'too_small', label: __( 'Too small', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end' },
					{ key: 'too_large', label: __( 'Too large', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end' },
					{ key: 'fit_ok', label: __( 'Fit OK', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end', hideOnMobile: true },
					{ key: 'rate', label: __( 'Fit-return rate', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end', render: ( r ) => ( r.rate === null ? '–' : `${ r.rate }%` ) },
					{ key: 'direction', label: __( 'Signal', 'dev3-ai-size-guide-for-woocommerce' ), render: ( r ) => <Badge tone={ DIRECTIONS[ r.direction ].tone }>{ DIRECTIONS[ r.direction ].label }</Badge> },
				] }
			/>
		</Card>
	);
}

/**
 * Add-a-return modal.
 *
 * @param {Object}   props         Props.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onSaved Saved.
 * @return {Element} Modal.
 */
function AddReturn( { open, onClose, onSaved } ) {
	const toast = useToast();
	const [ product, setProduct ] = useState( [] );
	const [ size, setSize ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const save = () => {
		setBusy( true );
		request( 'returns', { method: 'POST', data: { product_id: product[ 0 ] ? product[ 0 ].id : 0, size, reason, note } } )
			.then( () => {
				toast.success( __( 'Return recorded.', 'dev3-ai-size-guide-for-woocommerce' ) );
				setProduct( [] );
				setSize( '' );
				setReason( '' );
				setNote( '' );
				onSaved();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title={ __( 'Record a return', 'dev3-ai-size-guide-for-woocommerce' ) }
			description={ __( 'For returns handled outside WooCommerce refunds.', 'dev3-ai-size-guide-for-woocommerce' ) }
			footer={
				<Inline gap={ 8 } align="end">
					<Button onClick={ onClose }>{ __( 'Cancel', 'dev3-ai-size-guide-for-woocommerce' ) }</Button>
					<Button variant="primary" loading={ busy } disabled={ ! product.length } onClick={ save }>
						{ __( 'Save', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
				</Inline>
			}
		>
			<Stack gap={ 12 }>
				<TermPicker single type="product" label={ __( 'Product', 'dev3-ai-size-guide-for-woocommerce' ) } value={ product } onChange={ setProduct } />
				<FormGrid>
					<TextField label={ __( 'Size', 'dev3-ai-size-guide-for-woocommerce' ) } value={ size } onChange={ setSize } maxLength={ 40 } />
					<Select
						label={ __( 'Reason', 'dev3-ai-size-guide-for-woocommerce' ) }
						value={ reason }
						onChange={ setReason }
						options={ [ { value: '', label: __( 'Detect from the note', 'dev3-ai-size-guide-for-woocommerce' ) }, ...Object.keys( REASONS ).filter( ( k ) => k !== 'unknown' ).map( ( k ) => ( { value: k, label: REASONS[ k ].label } ) ) ] }
					/>
				</FormGrid>
				<Textarea label={ __( 'Customer’s reason', 'dev3-ai-size-guide-for-woocommerce' ) } value={ note } onChange={ setNote } rows={ 3 } maxLength={ 1000 } />
			</Stack>
		</Modal>
	);
}

/**
 * Returns & learning (#/returns).
 *
 * @return {Element} Screen.
 */
export default function Returns() {
	const { settings, save } = useApp();
	const toast = useToast();
	const [ insights, setInsights ] = useState( null );
	const [ records, setRecords ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ reason, setReason ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ pending, setPending ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ adding, setAdding ] = useState( false );
	const [ job, setJob ] = useState( null );
	const [ cfg, setCfg ] = useState( { learn_days: settings.learn_days, learn_min_sample: settings.learn_min_sample, learn_threshold: settings.learn_threshold, capture_refunds: settings.capture_refunds } );

	const loadInsights = ( fresh = false ) =>
		request( `insights${ fresh ? '?fresh=1' : '' }` )
			.then( ( r ) => setInsights( r.items ) )
			.catch( setError );
	const loadRecords = () =>
		request( `returns?page=${ page }&per_page=20&reason=${ reason }&search=${ encodeURIComponent( search ) }${ pending ? '&pending_ai=1' : '' }` )
			.then( setRecords )
			.catch( setError );
	useEffect( () => {
		loadInsights( true );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		loadRecords();
	}, [ page, reason, pending ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const reload = () => {
		loadInsights( true );
		loadRecords();
	};
	const classify = () =>
		request( 'returns/classify', { method: 'POST' } )
			.then( setJob )
			.catch( ( err ) => toast.error( err ) );
	const decide = ( ids, accept ) =>
		request( 'returns/accept', { method: 'POST', data: { ids, accept } } )
			.then( ( r ) => {
				toast.success( sprintf( /* translators: %d: records. */ __( '%d records updated.', 'dev3-ai-size-guide-for-woocommerce' ), r.updated ) );
				reload();
			} )
			.catch( ( err ) => toast.error( err ) );
	const remove = ( row ) =>
		request( `returns/${ row.id }`, { method: 'DELETE' } )
			.then( reload )
			.catch( ( err ) => toast.error( err ) );
	const saveCfg = () =>
		save( cfg )
			.then( () => {
				toast.success( __( 'Learning settings saved.', 'dev3-ai-size-guide-for-woocommerce' ) );
				loadInsights( true );
			} )
			.catch( ( err ) => toast.error( err ) );

	const items = records ? records.items : [];
	const pendingIds = items.filter( ( r ) => r.ai_reason && r.ai_reason !== 'rejected' && r.ai_reason !== r.reason ).map( ( r ) => r.id );

	return (
		<>
			<PageHeader
				title={ __( 'Returns & learning', 'dev3-ai-size-guide-for-woocommerce' ) }
				description={ __( 'Refund reasons and fit feedback are sorted into too small / too large. When a style keeps coming back one way, the quiz can adjust.', 'dev3-ai-size-guide-for-woocommerce' ) }
				actions={
					<Button icon="plus" onClick={ () => setAdding( true ) }>
						{ __( 'Record a return', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
				}
			/>
			<ErrorNotice error={ error } onRetry={ reload } onDismiss={ () => setError( null ) } />
			{ ! insights && ! error && <SkeletonCard lines={ 5 } /> }
			{ insights && insights.length === 0 && (
				<Card>
					<EmptyState
						icon="refresh"
						title={ __( 'No return data yet', 'dev3-ai-size-guide-for-woocommerce' ) }
						description={ __( 'Refunds with a reason, the “Fit feedback” box on orders and manual records appear here. Integrations can call do_action( ‘dev3sg_record_return’, … ).', 'dev3-ai-size-guide-for-woocommerce' ) }
					/>
				</Card>
			) }
			{ insights && insights.map( ( item ) => <InsightCard key={ item.chart_id } item={ item } onChange={ () => loadInsights( true ) } /> ) }

			<Card
				title={ __( 'Return records', 'dev3-ai-size-guide-for-woocommerce' ) }
				flush
				actions={
					<AiButton size="sm" disabled={ ! settings.api_key_set || !! ( job && [ 'queued', 'running' ].includes( job.status ) ) } onClick={ classify }>
						{ __( 'Classify unclear notes', 'dev3-ai-size-guide-for-woocommerce' ) }
					</AiButton>
				}
			>
				{ job && (
					<div className="dev3sg-a-pad">
						<JobProgress
							jobId={ job.id }
							job={ job }
							title={ __( 'DEV3 AI is reading return notes', 'dev3-ai-size-guide-for-woocommerce' ) }
							onDone={ () => {
								setPending( true );
								loadRecords();
							} }
						/>
					</div>
				) }
				<div className="dev3sg-a-toolbar">
					<Select
						label={ __( 'Reason', 'dev3-ai-size-guide-for-woocommerce' ) }
						hideLabel
						value={ reason }
						onChange={ ( v ) => {
							setReason( v );
							setPage( 1 );
						} }
						options={ [ { value: '', label: __( 'All reasons', 'dev3-ai-size-guide-for-woocommerce' ) }, ...Object.keys( REASONS ).map( ( k ) => ( { value: k, label: REASONS[ k ].label } ) ) ] }
					/>
					<form
						className="dev3sg-a-search"
						onSubmit={ ( e ) => {
							e.preventDefault();
							setPage( 1 );
							loadRecords();
						} }
					>
						<TextField label={ __( 'Search notes', 'dev3-ai-size-guide-for-woocommerce' ) } hideLabel type="search" placeholder={ __( 'Search notes or sizes…', 'dev3-ai-size-guide-for-woocommerce' ) } value={ search } onChange={ setSearch } />
					</form>
					<Checkbox label={ __( 'Only DEV3 AI suggestions', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ pending } onChange={ setPending } />
				</div>
				{ pendingIds.length > 0 && (
					<div className="dev3sg-a-pad">
						<Notice
							tone="ai"
							title={ sprintf( /* translators: %d: suggestions. */ __( '%d DEV3 AI suggestions on this page', 'dev3-ai-size-guide-for-woocommerce' ), pendingIds.length ) }
							actions={
								<Inline gap={ 8 }>
									<Button size="sm" variant="primary" onClick={ () => decide( pendingIds, true ) }>
										{ __( 'Accept all', 'dev3-ai-size-guide-for-woocommerce' ) }
									</Button>
									<Button size="sm" variant="ghost" onClick={ () => decide( pendingIds, false ) }>
										{ __( 'Reject all', 'dev3-ai-size-guide-for-woocommerce' ) }
									</Button>
								</Inline>
							}
						>
							{ __( 'Nothing changes until you accept.', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Notice>
					</div>
				) }
				<ResponsiveTable
					loading={ ! records }
					rows={ items }
					columns={ [
						{ key: 'product_name', label: __( 'Product', 'dev3-ai-size-guide-for-woocommerce' ), primary: true, render: ( r ) => r.product_name || `#${ r.product_id }` },
						{ key: 'size', label: __( 'Size', 'dev3-ai-size-guide-for-woocommerce' ), render: ( r ) => r.size || '–' },
						{
							key: 'reason',
							label: __( 'Reason', 'dev3-ai-size-guide-for-woocommerce' ),
							render: ( r ) => (
								<Inline gap={ 4 }>
									<Badge tone={ ( REASONS[ r.reason ] || REASONS.unknown ).tone }>{ ( REASONS[ r.reason ] || REASONS.unknown ).label }</Badge>
									{ r.ai_reason && r.ai_reason !== 'rejected' && r.ai_reason !== r.reason && (
										<span className="dev3sg-a-aisuggest" onClick={ ( e ) => e.stopPropagation() } role="presentation">
											<Badge tone="brand">
												{ __( 'AI:', 'dev3-ai-size-guide-for-woocommerce' ) } { ( REASONS[ r.ai_reason ] || REASONS.unknown ).label }
											</Badge>
											<IconButton size="sm" icon="check" label={ __( 'Accept AI reason', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => decide( [ r.id ], true ) } />
											<IconButton size="sm" icon="x" label={ __( 'Reject AI reason', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => decide( [ r.id ], false ) } />
										</span>
									) }
								</Inline>
							),
						},
						{ key: 'note', label: __( 'Note', 'dev3-ai-size-guide-for-woocommerce' ), render: ( r ) => <span className="dev3sg-a-note">{ r.note || '–' }</span> },
						{ key: 'source', label: __( 'Source', 'dev3-ai-size-guide-for-woocommerce' ), render: ( r ) => SOURCES[ r.source ] || r.source, hideOnMobile: true },
						{ key: 'created_at', label: __( 'Date', 'dev3-ai-size-guide-for-woocommerce' ), render: ( r ) => relTime( r.created_at ), hideOnMobile: true },
					] }
					pagination={ records ? { page, perPage: 20, total: records.total, onPageChange: setPage } : undefined }
					rowActions={ ( r ) => (
						<span onClick={ ( e ) => e.stopPropagation() } role="presentation">
							<IconButton icon="trash" label={ __( 'Delete record', 'dev3-ai-size-guide-for-woocommerce' ) } onClick={ () => remove( r ) } />
						</span>
					) }
					empty={ { icon: 'inbox', title: __( 'No return records', 'dev3-ai-size-guide-for-woocommerce' ) } }
				/>
			</Card>

			<Card
				title={ __( 'Learning settings', 'dev3-ai-size-guide-for-woocommerce' ) }
				footer={
					<Button variant="primary" onClick={ saveCfg }>
						{ __( 'Save', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
				}
			>
				<FormGrid>
					<NumberField label={ __( 'Look back (days)', 'dev3-ai-size-guide-for-woocommerce' ) } value={ cfg.learn_days } min={ 7 } max={ 730 } onChange={ ( v ) => setCfg( { ...cfg, learn_days: v } ) } />
					<NumberField label={ __( 'Minimum fit returns', 'dev3-ai-size-guide-for-woocommerce' ) } value={ cfg.learn_min_sample } min={ 1 } max={ 500 } onChange={ ( v ) => setCfg( { ...cfg, learn_min_sample: v } ) } help={ __( 'Before a chart gets a suggestion.', 'dev3-ai-size-guide-for-woocommerce' ) } />
					<NumberField label={ __( 'Same-direction share (%)', 'dev3-ai-size-guide-for-woocommerce' ) } value={ cfg.learn_threshold } min={ 50 } max={ 100 } onChange={ ( v ) => setCfg( { ...cfg, learn_threshold: v } ) } help={ __( 'e.g. 60 = at least 60% say “too small”.', 'dev3-ai-size-guide-for-woocommerce' ) } />
				</FormGrid>
				<div className="dev3sg-a-mt">
					<Checkbox label={ __( 'Record WooCommerce refund reasons automatically', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ !! cfg.capture_refunds } onChange={ ( v ) => setCfg( { ...cfg, capture_refunds: v } ) } />
				</div>
			</Card>
			<AddReturn
				open={ adding }
				onClose={ () => setAdding( false ) }
				onSaved={ () => {
					setAdding( false );
					reload();
				} }
			/>
		</>
	);
}
