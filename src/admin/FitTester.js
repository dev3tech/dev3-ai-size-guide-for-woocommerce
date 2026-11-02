import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, Inline, Select, Stack, TextField } from '../core3/ui';
import { convert, unitLabel } from '../storefront/units';
import { quizFields, strip, useApp } from './util';

const TONE = { high: 'success', medium: 'warning', low: 'danger' };

/**
 * Admin quiz simulator: runs the real fit maths on the chart being edited (saved or not).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.chart    Chart (unsaved edits included).
 * @param {number}   props.chartId  Or a saved chart id.
 * @param {Function} props.onResult ( result|null ) => void.
 * @param {string}   props.title    Card title.
 * @return {Element} Card.
 */
export default function FitTester( { chart, chartId = 0, onResult, title } ) {
	const { measures } = useApp();
	const [ values, setValues ] = useState( {} );
	const [ system, setSystem ] = useState( 'metric' );
	const [ fit, setFit ] = useState( 'regular' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ result, setResult ] = useState( null );
	const fields = chart ? quizFields( chart, measures ) : [];

	const run = () => {
		setBusy( true );
		setError( null );
		const input = { measurements: values, unit: system, fit };
		request( 'fit/test', { method: 'POST', data: chart ? { chart: strip( chart ), input } : { chart_id: chartId, input } } )
			.then( ( r ) => {
				setResult( r );
				if ( onResult ) {
					onResult( r );
				}
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};

	const switchSystem = ( next ) => {
		const out = {};
		Object.keys( values ).forEach( ( k ) => {
			const f = fields.find( ( x ) => x.key === k );
			out[ k ] = values[ k ] === '' ? '' : convert( Number( values[ k ] ), f ? f.quantity : 'length', system, next );
		} );
		setValues( out );
		setSystem( next );
	};

	return (
		<Card title={ title || __( 'Test the “What size am I?” quiz', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Same maths shoppers get. No AI is used for the size itself.', 'dev3-ai-size-guide-for-woocommerce' ) }>
			<Stack gap={ 12 }>
				{ fields.length === 0 && <p className="dev3sg-a-muted">{ __( 'Add measurement columns with different values per size to enable the quiz.', 'dev3-ai-size-guide-for-woocommerce' ) }</p> }
				{ fields.length > 0 && (
					<>
						<div className="dev3sg-a-seggroup" role="group" aria-label={ __( 'Units', 'dev3-ai-size-guide-for-woocommerce' ) }>
							<button type="button" className="dev3sg-a-seg" aria-pressed={ system === 'metric' } onClick={ () => switchSystem( 'metric' ) }>
								cm / kg
							</button>
							<button type="button" className="dev3sg-a-seg" aria-pressed={ system === 'imperial' } onClick={ () => switchSystem( 'imperial' ) }>
								in / lb
							</button>
						</div>
						<div className="dev3sg-a-testgrid">
							{ fields.map( ( f ) => (
								<TextField
									key={ f.key }
									type="number"
									inputMode="decimal"
									step="any"
									label={ f.label }
									suffix={ unitLabel( f.quantity, system ) }
									value={ values[ f.key ] === undefined ? '' : String( values[ f.key ] ) }
									onChange={ ( v ) => setValues( { ...values, [ f.key ]: v === '' ? '' : Number( v ) } ) }
								/>
							) ) }
						</div>
						<Select
							label={ __( 'Fit preference', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ fit }
							onChange={ setFit }
							options={ [
								{ value: 'snug', label: __( 'Snug', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'regular', label: __( 'Regular', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'relaxed', label: __( 'Relaxed', 'dev3-ai-size-guide-for-woocommerce' ) },
							] }
						/>
						<Inline gap={ 8 }>
							<Button variant="primary" loading={ busy } onClick={ run } disabled={ ! Object.values( values ).some( ( v ) => v !== '' ) }>
								{ __( 'Find size', 'dev3-ai-size-guide-for-woocommerce' ) }
							</Button>
							{ result && (
								<Button
									variant="ghost"
									onClick={ () => {
										setResult( null );
										setValues( {} );
										if ( onResult ) {
											onResult( null );
										}
									} }
								>
									{ __( 'Clear', 'dev3-ai-size-guide-for-woocommerce' ) }
								</Button>
							) }
						</Inline>
					</>
				) }
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
				{ result && (
					<div className="dev3sg-a-testresult" aria-live="polite">
						<div className="dev3sg-a-testresult__size">{ result.size }</div>
						<div>
							<Inline gap={ 6 }>
								<Badge tone={ TONE[ result.confidence ] }>{ result.confidence === 'high' ? __( 'High confidence', 'dev3-ai-size-guide-for-woocommerce' ) : result.confidence === 'medium' ? __( 'Medium confidence', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Low confidence', 'dev3-ai-size-guide-for-woocommerce' ) }</Badge>
								{ result.alternative && <Badge>{ __( 'Alt:', 'dev3-ai-size-guide-for-woocommerce' ) } { result.alternative }</Badge> }
								{ result.estimated && <Badge tone="info">{ __( 'Estimated', 'dev3-ai-size-guide-for-woocommerce' ) }</Badge> }
								{ result.bias_applied && <Badge tone="warning">{ __( 'Return-learning applied', 'dev3-ai-size-guide-for-woocommerce' ) }</Badge> }
							</Inline>
							<p className="dev3sg-a-testresult__why">{ result.explanation }</p>
						</div>
					</div>
				) }
			</Stack>
		</Card>
	);
}
