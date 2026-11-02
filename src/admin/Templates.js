import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, PageHeader, SkeletonCard, useToast } from '../core3/ui';
import { PROFILES } from './util';

/**
 * Template library (#/templates).
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @return {Element} Screen.
 */
export default function Templates( { navigate } ) {
	const toast = useToast();
	const [ items, setItems ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	const load = () => {
		setError( null );
		request( 'templates' ).then( setItems ).catch( setError );
	};
	useEffect( load, [] );

	const use = ( tpl ) => {
		setBusy( tpl.id );
		request( 'charts', { method: 'POST', data: { template: tpl.id } } )
			.then( ( c ) => {
				toast.success( __( 'Chart created from the template.', 'dev3-ai-size-guide-for-woocommerce' ) );
				navigate( `charts/${ c.id }` );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<>
			<PageHeader title={ __( 'Templates', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Ready-made charts with standard measurements in cm. Use one, then adjust it to your brand.', 'dev3-ai-size-guide-for-woocommerce' ) } />
			<ErrorNotice error={ error } onRetry={ load } />
			{ ! items && ! error && <SkeletonCard lines={ 6 } /> }
			{ items && (
				<div className="dev3sg-a-tpls">
					{ items.map( ( tpl ) => {
						const t = tpl.chart.tables[ 0 ];
						const sizeCol = Math.max( 0, t.columns.findIndex( ( c ) => c.kind === 'size' ) );
						return (
							<Card
								key={ tpl.id }
								title={ tpl.name }
								description={ tpl.description }
								footer={
									<Button variant="primary" size="sm" loading={ busy === tpl.id } onClick={ () => use( tpl ) } aria-label={ sprintf( /* translators: %s: template. */ __( 'Use template %s', 'dev3-ai-size-guide-for-woocommerce' ), tpl.name ) }>
										{ __( 'Use template', 'dev3-ai-size-guide-for-woocommerce' ) }
									</Button>
								}
							>
								<div className="dev3sg-a-tpl">
									<Badge tone="brand">{ ( PROFILES.find( ( p ) => p.value === tpl.profile ) || {} ).label }</Badge>
									<p className="dev3sg-a-muted">
										{ t.columns
											.filter( ( c ) => c.kind !== 'size' )
											.map( ( c ) => c.label )
											.join( ' · ' ) }
									</p>
									<div className="dev3sg-a-sizes">
										{ t.rows.slice( 0, 8 ).map( ( r, i ) => (
											<span key={ i }>{ r[ sizeCol ] }</span>
										) ) }
									</div>
								</div>
							</Card>
						);
					} ) }
				</div>
			) }
		</>
	);
}
