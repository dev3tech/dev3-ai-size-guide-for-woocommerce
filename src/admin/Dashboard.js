import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Columns, Drawer, EmptyState, ErrorNotice, Inline, Notice, PageHeader, ResponsiveTable, Select, Skeleton, Stat, StatGrid, formatNumber, useToast } from '../core3/ui';
import { useApp } from './util';

/**
 * Daily activity bars (guide opens vs quizzes) with an accessible table fallback.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.days [ { date, open, quiz, cart } ].
 * @return {Element} Chart.
 */
export function Activity( { days } ) {
	const max = Math.max( 1, ...days.map( ( d ) => Math.max( d.open, d.quiz ) ) );
	const total = days.reduce( ( s, d ) => s + d.open + d.quiz, 0 );
	if ( ! total ) {
		return <EmptyState compact icon="chart" title={ __( 'No shopper activity yet', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Guide opens and quizzes appear here once shoppers use them.', 'dev3-ai-size-guide-for-woocommerce' ) } />;
	}
	return (
		<figure className="dev3sg-a-activity">
			<div className="dev3sg-a-activity__plot" aria-hidden="true">
				{ days.map( ( d ) => (
					<div key={ d.date } className="dev3sg-a-activity__day" title={ `${ d.date }: ${ d.open } / ${ d.quiz }` }>
						<span className="is-open" style={ { height: `${ ( d.open / max ) * 100 }%` } } />
						<span className="is-quiz" style={ { height: `${ ( d.quiz / max ) * 100 }%` } } />
					</div>
				) ) }
			</div>
			<figcaption className="dev3sg-a-legend">
				<span className="is-open">{ __( 'Guide opens', 'dev3-ai-size-guide-for-woocommerce' ) }</span>
				<span className="is-quiz">{ __( 'Quizzes', 'dev3-ai-size-guide-for-woocommerce' ) }</span>
			</figcaption>
			<table className="dev3sg-ui-sr-only">
				<caption>{ __( 'Daily guide opens and quizzes', 'dev3-ai-size-guide-for-woocommerce' ) }</caption>
				<tbody>
					{ days.map( ( d ) => (
						<tr key={ d.date }>
							<th scope="row">{ d.date }</th>
							<td>{ d.open }</td>
							<td>{ d.quiz }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</figure>
	);
}

/**
 * Products without a size chart + quick assign + DEV3 AI category mapping.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onChange Reload stats.
 * @return {Element} Card.
 */
export function Coverage( { onChange } ) {
	const { settings, refreshUsage } = useApp();
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ charts, setCharts ] = useState( [] );
	const [ assign, setAssign ] = useState( null );
	const [ target, setTarget ] = useState( '' );
	const [ ai, setAi ] = useState( null );
	const [ aiBusy, setAiBusy ] = useState( false );
	const [ aiError, setAiError ] = useState( null );
	const [ picked, setPicked ] = useState( {} );

	const load = () => {
		request( 'coverage' ).then( setData ).catch( () => setData( { total: 0, covered: 0, missing: [] } ) );
		request( 'charts' ).then( ( c ) => setCharts( c.filter( ( x ) => x.status === 'publish' ) ) ).catch( () => {} );
	};
	useEffect( load, [] );

	const doAssign = () =>
		request( 'assign', { method: 'POST', data: { product_ids: [ assign.id ], chart: target } } )
			.then( () => {
				toast.success( __( 'Size chart assigned.', 'dev3-ai-size-guide-for-woocommerce' ) );
				setAssign( null );
				load();
				onChange();
			} )
			.catch( ( err ) => toast.error( err ) );

	const suggest = () => {
		setAiBusy( true );
		setAiError( null );
		request( 'ai/assign', { method: 'POST' } )
			.then( ( r ) => {
				setAi( r );
				setPicked( Object.fromEntries( r.items.map( ( i ) => [ i.category_id, true ] ) ) );
			} )
			.catch( setAiError )
			.finally( () => {
				setAiBusy( false );
				refreshUsage();
			} );
	};

	const acceptAi = () =>
		request( 'assign/categories', { method: 'POST', data: { items: ai.items.filter( ( i ) => picked[ i.category_id ] ) } } )
			.then( ( r ) => {
				toast.success( sprintf( /* translators: %d: categories. */ __( '%d categories assigned.', 'dev3-ai-size-guide-for-woocommerce' ), r.updated ) );
				setAi( null );
				load();
				onChange();
			} )
			.catch( ( err ) => toast.error( err ) );

	return (
		<Card
			title={ __( 'Products without a size guide', 'dev3-ai-size-guide-for-woocommerce' ) }
			description={ data ? sprintf( /* translators: 1: covered, 2: total. */ __( '%1$d of %2$d products show a size guide.', 'dev3-ai-size-guide-for-woocommerce' ), data.covered, data.total ) : undefined }
			actions={ charts.length > 0 && <AiButton size="sm" loading={ aiBusy } disabled={ ! settings.api_key_set } onClick={ suggest }>{ __( 'Suggest assignments', 'dev3-ai-size-guide-for-woocommerce' ) }</AiButton> }
			flush
		>
			<ErrorNotice error={ aiError } onDismiss={ () => setAiError( null ) } />
			{ ai && (
				<div className="dev3sg-a-pad">
					<div className="dev3sg-ui-draft">
						<div className="dev3sg-ui-draft__head">
							<div>
								<p className="dev3sg-ui-draft__title">{ __( 'DEV3 AI draft: category → chart', 'dev3-ai-size-guide-for-woocommerce' ) }</p>
								<p className="dev3sg-ui-draft__meta">{ __( 'Not saved yet', 'dev3-ai-size-guide-for-woocommerce' ) } · { ai.model }</p>
							</div>
						</div>
						{ ai.items.length === 0 && <p className="dev3sg-a-pad">{ __( 'DEV3 AI found no category that clearly fits your charts.', 'dev3-ai-size-guide-for-woocommerce' ) }</p> }
						<ul className="dev3sg-a-checklist">
							{ ai.items.map( ( i ) => (
								<li key={ i.category_id }>
									<label>
										<input type="checkbox" checked={ !! picked[ i.category_id ] } onChange={ ( e ) => setPicked( { ...picked, [ i.category_id ]: e.target.checked } ) } />
										<span>
											<strong>{ i.category }</strong> → { i.chart }
											{ i.reason && <span className="dev3sg-a-muted"> · { i.reason }</span> }
										</span>
									</label>
								</li>
							) ) }
						</ul>
						<div className="dev3sg-ui-draft__foot">
							<Inline gap={ 8 }>
								<Button variant="primary" icon="check" onClick={ acceptAi } disabled={ ! Object.values( picked ).some( Boolean ) }>
									{ __( 'Assign selected', 'dev3-ai-size-guide-for-woocommerce' ) }
								</Button>
								<Button variant="ghost" icon="x" onClick={ () => setAi( null ) }>
									{ __( 'Reject', 'dev3-ai-size-guide-for-woocommerce' ) }
								</Button>
							</Inline>
						</div>
					</div>
				</div>
			) }
			<ResponsiveTable
				loading={ ! data }
				rows={ data ? data.missing : [] }
				pageSize={ 6 }
				columns={ [
					{ key: 'name', label: __( 'Product', 'dev3-ai-size-guide-for-woocommerce' ), primary: true },
					{ key: 'categories', label: __( 'Categories', 'dev3-ai-size-guide-for-woocommerce' ) },
				] }
				rowActions={ ( r ) => (
					<Button
						size="sm"
						onClick={ ( e ) => {
							e.stopPropagation();
							setTarget( charts[ 0 ] ? String( charts[ 0 ].id ) : '' );
							setAssign( r );
						} }
						disabled={ ! charts.length }
					>
						{ __( 'Assign', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
				) }
				empty={ { icon: 'check', title: __( 'Every product has a size guide', 'dev3-ai-size-guide-for-woocommerce' ), description: __( 'Or the products are set to "No size guide".', 'dev3-ai-size-guide-for-woocommerce' ) } }
			/>
			<Drawer
				open={ !! assign }
				onClose={ () => setAssign( null ) }
				title={ __( 'Assign a size chart', 'dev3-ai-size-guide-for-woocommerce' ) }
				description={ assign ? assign.name : '' }
				footer={
					<Inline gap={ 8 } align="end">
						<Button onClick={ () => setAssign( null ) }>{ __( 'Cancel', 'dev3-ai-size-guide-for-woocommerce' ) }</Button>
						<Button variant="primary" onClick={ doAssign } disabled={ ! target }>
							{ __( 'Assign', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					</Inline>
				}
			>
				<Select
					label={ __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' ) }
					value={ target }
					onChange={ setTarget }
					options={ [ ...charts.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ), { value: 'none', label: __( 'No size guide for this product', 'dev3-ai-size-guide-for-woocommerce' ) } ] }
					help={ __( 'Sets the product override. To cover a whole category, add it in the chart’s “Where it shows”.', 'dev3-ai-size-guide-for-woocommerce' ) }
				/>
			</Drawer>
		</Card>
	);
}

/**
 * Dashboard (#/dashboard).
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @return {Element} Screen.
 */
export default function Dashboard( { navigate } ) {
	const { settings } = useApp();
	const [ stats, setStats ] = useState( null );
	const [ days, setDays ] = useState( '30' );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		request( `stats?days=${ days }` ).then( setStats ).catch( setError );
	};
	useEffect( load, [ days ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const t = stats ? stats.events.totals : {};
	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-size-guide-for-woocommerce' ) }
				description={ __( 'How shoppers use your size guides and what returns teach you.', 'dev3-ai-size-guide-for-woocommerce' ) }
				actions={
					<Inline gap={ 8 }>
						<Select label={ __( 'Period', 'dev3-ai-size-guide-for-woocommerce' ) } hideLabel value={ days } onChange={ setDays } options={ [ { value: '7', label: __( 'Last 7 days', 'dev3-ai-size-guide-for-woocommerce' ) }, { value: '30', label: __( 'Last 30 days', 'dev3-ai-size-guide-for-woocommerce' ) }, { value: '90', label: __( 'Last 90 days', 'dev3-ai-size-guide-for-woocommerce' ) } ] } />
						<Button variant="primary" icon="plus" href="#/charts">
							{ __( 'Size charts', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					</Inline>
				}
			/>
			{ ! settings.api_key_set && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-size-guide-for-woocommerce' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-size-guide-for-woocommerce' ) }</Button> }>
					{ __( 'Add a Google Gemini API key to draft charts from product data or photos and to explain quiz results. Charts, the quiz and return learning work without it.', 'dev3-ai-size-guide-for-woocommerce' ) }
				</Notice>
			) }
			<ErrorNotice error={ error } onRetry={ load } />
			{ stats && stats.charts === 0 && (
				<Card>
					<EmptyState
						icon="columns"
						title={ __( 'Create your first size chart', 'dev3-ai-size-guide-for-woocommerce' ) }
						description={ __( 'Pick a template, paste your supplier’s chart, or let DEV3 AI draft one from a product.', 'dev3-ai-size-guide-for-woocommerce' ) }
						action={
							<Button variant="primary" onClick={ () => navigate( 'templates' ) }>
								{ __( 'Browse templates', 'dev3-ai-size-guide-for-woocommerce' ) }
							</Button>
						}
						secondary={ <Button href="#/charts">{ __( 'Size charts', 'dev3-ai-size-guide-for-woocommerce' ) }</Button> }
					/>
				</Card>
			) }
			<StatGrid min={ 240 }>
				<Stat loading={ ! stats } label={ __( 'Live size charts', 'dev3-ai-size-guide-for-woocommerce' ) } value={ stats ? `${ stats.live } / ${ stats.charts }` : '' } icon="columns" tone="brand" />
				<Stat loading={ ! stats } label={ __( 'Product coverage', 'dev3-ai-size-guide-for-woocommerce' ) } value={ stats ? `${ stats.coverage }%` : '' } hint={ stats ? sprintf( /* translators: 1: covered, 2: total. */ __( '%1$d of %2$d products', 'dev3-ai-size-guide-for-woocommerce' ), stats.covered, stats.products ) : '' } icon="box" tone={ stats && stats.coverage >= 80 ? 'success' : 'warning' } />
				<Stat loading={ ! stats } label={ __( 'Guide opens', 'dev3-ai-size-guide-for-woocommerce' ) } value={ formatNumber( t.open ) } icon="search" />
				<Stat loading={ ! stats } label={ __( 'Quizzes completed', 'dev3-ai-size-guide-for-woocommerce' ) } value={ formatNumber( t.quiz ) } icon="users" />
				<Stat loading={ ! stats } label={ __( 'Quiz → add to cart', 'dev3-ai-size-guide-for-woocommerce' ) } value={ stats ? `${ stats.events.cart_rate }%` : '' } hint={ stats ? sprintf( /* translators: %d: add to carts. */ __( '%d add-to-carts after a recommendation', 'dev3-ai-size-guide-for-woocommerce' ), t.cart ) : '' } icon="check" tone="success" />
				<Stat loading={ ! stats } label={ __( 'Fit returns', 'dev3-ai-size-guide-for-woocommerce' ) } value={ stats ? formatNumber( stats.returns.fit ) : '' } hint={ stats ? sprintf( /* translators: %d: all returns. */ __( 'of %d recorded returns', 'dev3-ai-size-guide-for-woocommerce' ), stats.returns.total ) : '' } icon="refresh" tone="warning" />
			</StatGrid>
			{ stats && stats.insights > 0 && (
				<Notice
					tone="warning"
					title={ sprintf( /* translators: %d: suggestions. */ _n( '%d fit suggestion from returns', '%d fit suggestions from returns', stats.insights, 'dev3-ai-size-guide-for-woocommerce' ), stats.insights ) }
					actions={
						<Button size="sm" href="#/returns">
							{ __( 'Review', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					}
				>
					{ __( 'Some styles are returned as too small or too large more often than expected.', 'dev3-ai-size-guide-for-woocommerce' ) }
				</Notice>
			) }
			<Columns min={ 340 }>
				<Card title={ __( 'Shopper activity', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Daily guide opens and completed quizzes.', 'dev3-ai-size-guide-for-woocommerce' ) }>
					{ stats ? <Activity days={ stats.events.daily } /> : <Skeleton height={ 140 } /> }
				</Card>
				<Card title={ __( 'Top products', 'dev3-ai-size-guide-for-woocommerce' ) } flush>
					<ResponsiveTable
						loading={ ! stats }
						rows={ stats ? stats.events.top_products : [] }
						rowKey="product_id"
						columns={ [
							{ key: 'name', label: __( 'Product', 'dev3-ai-size-guide-for-woocommerce' ), primary: true },
							{ key: 'opens', label: __( 'Opens', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end' },
							{ key: 'quizzes', label: __( 'Quizzes', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end' },
							{ key: 'carts', label: __( 'Carts', 'dev3-ai-size-guide-for-woocommerce' ), align: 'end', render: ( r ) => <Badge tone={ r.carts ? 'success' : 'neutral' }>{ r.carts }</Badge> },
						] }
						empty={ { icon: 'box', title: __( 'No data yet', 'dev3-ai-size-guide-for-woocommerce' ) } }
					/>
				</Card>
			</Columns>
			<Coverage onChange={ load } />
		</>
	);
}
