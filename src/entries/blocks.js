/* Editor side of the server-rendered dev3sg/size-chart block. */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const charts = ( window.dev3sgBlocks && window.dev3sgBlocks.charts ) || [];

const icon = (
	<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<path fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" d="M3 15.5 15.5 3 21 8.5 8.5 21zM7 12l2 2M10 9l2 2M13 6l2 2" />
	</svg>
);

registerBlockType( 'dev3sg/size-chart', {
	apiVersion: 2,
	title: __( 'Size Chart', 'dev3-ai-size-guide-for-woocommerce' ),
	description: __( 'A size chart with a cm/in switch, how-to-measure guide and a "What size am I?" quiz.', 'dev3-ai-size-guide-for-woocommerce' ),
	category: 'woocommerce',
	icon,
	keywords: [ 'size', 'fit', 'chart', 'guide', 'dev3' ],
	supports: { align: [ 'wide', 'full' ], html: false, spacing: { margin: true } },
	attributes: {
		chartId: { type: 'number', default: 0 },
		productId: { type: 'number', default: 0 },
		view: { type: 'string', default: 'inline' },
	},
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' ) }>
						<SelectControl
							label={ __( 'Chart', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ String( attributes.chartId ) }
							onChange={ ( v ) => setAttributes( { chartId: Number( v ) } ) }
							options={ [ { value: '0', label: __( 'Chart of the current product', 'dev3-ai-size-guide-for-woocommerce' ) }, ...charts.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ] }
						/>
						<SelectControl
							label={ __( 'Show', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ attributes.view }
							onChange={ ( view ) => setAttributes( { view } ) }
							options={ [
								{ value: 'inline', label: __( 'Size chart (inline)', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'button', label: __( 'Buttons that open the popup', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'quiz', label: __( '"What size am I?" quiz only', 'dev3-ai-size-guide-for-woocommerce' ) },
							] }
						/>
						<TextControl
							type="number"
							label={ __( 'Product ID (optional)', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ attributes.productId || '' }
							onChange={ ( v ) => setAttributes( { productId: Number( v ) || 0 } ) }
							help={ __( 'Uses that product’s chart when no chart is chosen.', 'dev3-ai-size-guide-for-woocommerce' ) }
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block="dev3sg/size-chart" attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );
