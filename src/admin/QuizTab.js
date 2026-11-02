import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Columns, PageHeader, Select, SkeletonCard, Stack, Toggle, useToast } from '../core3/ui';
import FitTester from './FitTester';
import { useApp } from './util';

const KEYS = [ 'show_quiz', 'quiz_fit_pref', 'quiz_usual', 'estimates', 'save_measurements', 'ai_explain' ];

/**
 * Fit quiz settings + simulator (#/quiz).
 *
 * @return {Element} Screen.
 */
export default function QuizTab() {
	const { settings, save } = useApp();
	const toast = useToast();
	const [ form, setForm ] = useState( () => Object.fromEntries( KEYS.map( ( k ) => [ k, !! settings[ k ] ] ) ) );
	const [ busy, setBusy ] = useState( false );
	const [ charts, setCharts ] = useState( null );
	const [ chartId, setChartId ] = useState( '' );
	const [ chart, setChart ] = useState( null );

	useEffect( () => {
		request( 'charts' )
			.then( ( list ) => {
				setCharts( list );
				if ( list[ 0 ] ) {
					setChartId( String( list[ 0 ].id ) );
				}
			} )
			.catch( () => setCharts( [] ) );
	}, [] );
	useEffect( () => {
		if ( chartId ) {
			request( `charts/${ chartId }` ).then( setChart ).catch( () => setChart( null ) );
		}
	}, [ chartId ] );

	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const submit = () => {
		setBusy( true );
		save( form )
			.then( () => toast.success( __( 'Quiz settings saved.', 'dev3-ai-size-guide-for-woocommerce' ) ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<PageHeader title={ __( 'Fit quiz', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( '“What size am I?” matches the shopper’s measurements to your chart with plain maths. DEV3 AI only explains the result in friendly words.', 'dev3-ai-size-guide-for-woocommerce' ) } />
			<Columns min={ 360 }>
				<Card
					title={ __( 'Questions', 'dev3-ai-size-guide-for-woocommerce' ) }
					footer={
						<Button variant="primary" loading={ busy } onClick={ submit }>
							{ __( 'Save', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					}
				>
					<Stack gap={ 16 }>
						<Toggle label={ __( 'Show the “What size am I?” quiz', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ form.show_quiz } onChange={ set( 'show_quiz' ) } />
						<Toggle label={ __( 'Ask for fit preference (snug, regular, relaxed)', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ form.quiz_fit_pref } onChange={ set( 'quiz_fit_pref' ) } />
						<Toggle label={ __( 'Ask for the size they usually wear (US, UK, EU…)', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ form.quiz_usual } onChange={ set( 'quiz_usual' ) } help={ __( 'Uses the text columns of your chart, such as US or EU sizes.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<Toggle label={ __( 'Estimate chest, waist and hips from height and weight', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ form.estimates } onChange={ set( 'estimates' ) } help={ __( 'For shoppers without a tape measure. Results are marked as estimated.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<Toggle label={ __( 'Offer to remember measurements on the shopper’s device', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ form.save_measurements } onChange={ set( 'save_measurements' ) } help={ __( 'Stored only in the browser (localStorage), never on your server.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<Toggle label={ __( 'Explain results with DEV3 AI', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ form.ai_explain } onChange={ set( 'ai_explain' ) } help={ __( 'Sends only the size result (no names or emails). Cached, rate-limited and falls back to a written explanation.', 'dev3-ai-size-guide-for-woocommerce' ) } />
					</Stack>
				</Card>
				<Stack gap={ 16 }>
					{ ! charts && <SkeletonCard lines={ 4 } /> }
					{ charts && charts.length === 0 && (
						<Card title={ __( 'Try the quiz', 'dev3-ai-size-guide-for-woocommerce' ) }>
							<p className="dev3sg-a-muted">{ __( 'Create a size chart first.', 'dev3-ai-size-guide-for-woocommerce' ) }</p>
						</Card>
					) }
					{ charts && charts.length > 0 && (
						<Card title={ __( 'Chart to test', 'dev3-ai-size-guide-for-woocommerce' ) }>
							<Select label={ __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' ) } value={ chartId } onChange={ setChartId } options={ charts.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) } />
						</Card>
					) }
					{ chart && <FitTester key={ chart.id } chart={ chart } title={ __( 'Try the quiz', 'dev3-ai-size-guide-for-woocommerce' ) } /> }
				</Stack>
			</Columns>
		</>
	);
}
