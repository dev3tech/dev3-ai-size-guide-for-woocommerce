import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, Checkbox, ColorField, FormGrid, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';
import { useApp } from './util';

const KEYS = [ 'display_mode', 'button_position', 'button_label', 'quiz_label', 'tab_label', 'default_unit', 'accent_color', 'require_size_attr', 'size_attributes', 'analytics', 'delete_on_uninstall' ];

/**
 * Display + store settings (#/settings).
 *
 * @return {Element} Screen.
 */
export default function SettingsTab() {
	const { settings, save } = useApp();
	const toast = useToast();
	const [ form, setForm ] = useState( () => Object.fromEntries( KEYS.map( ( k ) => [ k, settings[ k ] ] ) ) );
	const [ busy, setBusy ] = useState( false );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const dirty = KEYS.some( ( k ) => form[ k ] !== settings[ k ] );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		save( form )
			.then( ( next ) => {
				setForm( Object.fromEntries( KEYS.map( ( k ) => [ k, next[ k ] ] ) ) );
				toast.success( __( 'Settings saved.', 'dev3-ai-size-guide-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const footer = (
		<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
			{ __( 'Save settings', 'dev3-ai-size-guide-for-woocommerce' ) }
		</Button>
	);

	return (
		<form onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Where the size guide appears and how it looks. Shopper-facing text stays neutral.', 'dev3-ai-size-guide-for-woocommerce' ) } />
			<Stack gap={ 16 }>
				<Card title={ __( 'Display', 'dev3-ai-size-guide-for-woocommerce' ) } footer={ footer }>
					<FormGrid>
						<Select
							label={ __( 'Show the size guide as', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ form.display_mode }
							onChange={ set( 'display_mode' ) }
							options={ [
								{ value: 'both', label: __( 'Popup button and product tab', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'popup', label: __( 'Popup button only', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'tab', label: __( 'Product tab only', 'dev3-ai-size-guide-for-woocommerce' ) },
							] }
							help={ __( 'On phones the popup opens as a bottom sheet.', 'dev3-ai-size-guide-for-woocommerce' ) }
						/>
						<Select
							label={ __( 'Button position', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ form.button_position }
							onChange={ set( 'button_position' ) }
							options={ [
								{ value: 'after_cart', label: __( 'After the add-to-cart button', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'before_cart', label: __( 'Before the add-to-cart form', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'after_price', label: __( 'After the price', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'after_summary', label: __( 'After the product summary', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'none', label: __( 'Nowhere (use the shortcode or block)', 'dev3-ai-size-guide-for-woocommerce' ) },
							] }
						/>
						<TextField label={ __( 'Size guide button text', 'dev3-ai-size-guide-for-woocommerce' ) } value={ form.button_label } onChange={ set( 'button_label' ) } maxLength={ 40 } placeholder={ __( 'Size guide', 'dev3-ai-size-guide-for-woocommerce' ) } help={ __( 'Leave empty for the translated default.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<TextField label={ __( 'Quiz button text', 'dev3-ai-size-guide-for-woocommerce' ) } value={ form.quiz_label } onChange={ set( 'quiz_label' ) } maxLength={ 40 } placeholder={ __( 'What size am I?', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<TextField label={ __( 'Product tab title', 'dev3-ai-size-guide-for-woocommerce' ) } value={ form.tab_label } onChange={ set( 'tab_label' ) } maxLength={ 40 } placeholder={ __( 'Size guide', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<Select
							label={ __( 'Default unit for shoppers', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ form.default_unit }
							onChange={ set( 'default_unit' ) }
							options={ [
								{ value: 'auto', label: __( 'Automatic (inches in the US)', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'cm', label: __( 'Centimetres', 'dev3-ai-size-guide-for-woocommerce' ) },
								{ value: 'in', label: __( 'Inches', 'dev3-ai-size-guide-for-woocommerce' ) },
							] }
							help={ __( 'Shoppers can always switch; their choice is remembered.', 'dev3-ai-size-guide-for-woocommerce' ) }
						/>
						<ColorField label={ __( 'Accent colour', 'dev3-ai-size-guide-for-woocommerce' ) } value={ form.accent_color } onChange={ set( 'accent_color' ) } help={ __( 'Buttons, the recommended size and highlights.', 'dev3-ai-size-guide-for-woocommerce' ) } />
					</FormGrid>
				</Card>
				<Card title={ __( 'Products & data', 'dev3-ai-size-guide-for-woocommerce' ) } footer={ footer }>
					<Stack gap={ 16 }>
						<TextField
							label={ __( 'Size attribute names', 'dev3-ai-size-guide-for-woocommerce' ) }
							value={ form.size_attributes }
							onChange={ set( 'size_attributes' ) }
							help={ __( 'Comma-separated, e.g. pa_size, size, pa_shoe-size. Used for “Select this size”, sales per size and return records.', 'dev3-ai-size-guide-for-woocommerce' ) }
						/>
						<Toggle label={ __( 'Only show the guide on products that have a size attribute', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ !! form.require_size_attr } onChange={ set( 'require_size_attr' ) } />
						<Toggle label={ __( 'Count guide opens, quizzes and add-to-carts', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ !! form.analytics } onChange={ set( 'analytics' ) } help={ __( 'Anonymous counts only; no personal data or measurements are stored.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<Checkbox label={ __( 'Delete all plugin data when the plugin is uninstalled', 'dev3-ai-size-guide-for-woocommerce' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
					</Stack>
				</Card>
				<Card title={ __( 'Shortcodes & block', 'dev3-ai-size-guide-for-woocommerce' ) }>
					<ul className="dev3sg-a-list">
						<li>
							<code>[dev3sg_size_guide]</code> { __( 'buttons that open the popup (current product, or product_id="…", or id="chart id").', 'dev3-ai-size-guide-for-woocommerce' ) }
						</li>
						<li>
							<code>[dev3sg_size_chart id="…"]</code> { __( 'the chart inline, with the quiz tab (add quiz="no" to hide it).', 'dev3-ai-size-guide-for-woocommerce' ) }
						</li>
						<li>
							<code>[dev3sg_fit_quiz product_id="…"]</code> { __( 'only the “What size am I?” quiz.', 'dev3-ai-size-guide-for-woocommerce' ) }
						</li>
						<li>{ __( 'Block editor: add the “Size Chart” block (WooCommerce category).', 'dev3-ai-size-guide-for-woocommerce' ) }</li>
					</ul>
				</Card>
			</Stack>
		</form>
	);
}
