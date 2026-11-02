<?php
/**
 * Storefront wiring: buttons near add-to-cart (classic hooks + block themes), product tab, popup sheets,
 * assets and add-to-cart tracking of recommended sizes.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Frontend;

use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Core3\Api\Ai;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;
use Dev3\AiSizeGuide\Data\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront.
 */
class Storefront {

	const HANDLE = 'dev3-ai-size-guide-for-woocommerce-storefront';

	/**
	 * Sheets to print in the footer: sheet id => [ chart, product id ].
	 *
	 * @var array
	 */
	private static $sheets = array();

	/**
	 * Products whose buttons were printed.
	 *
	 * @var array
	 */
	private static $printed = array();

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_sheets' ), 5 );
		add_filter( 'woocommerce_product_tabs', array( $this, 'tab' ), 25 );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'hidden_field' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'track_cart' ), 10, 6 );

		$position = (string) Settings_Store::get( 'button_position', 'after_cart' );
		if ( 'none' === $position || 'tab' === Settings_Store::get( 'display_mode', 'both' ) ) {
			return;
		}
		switch ( $position ) {
			case 'before_cart':
				add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'buttons_current' ) );
				break;
			case 'after_price':
				add_action( 'woocommerce_single_product_summary', array( $this, 'buttons_current' ), 15 );
				break;
			case 'after_summary':
				break; // Printed by buttons_no_form at priority 46.
			default:
				add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'buttons_current' ), 20 );
		}
		// Products without an add-to-cart form (out of stock, not purchasable) still get the buttons.
		add_action( 'woocommerce_single_product_summary', array( $this, 'buttons_no_form' ), 46 );
		// Block themes: WooCommerce blocks do not run the summary hooks.
		add_filter( 'render_block', array( $this, 'render_block' ), 10, 2 );
	}

	/**
	 * Chart for the current/given product, honouring "only products with a size attribute".
	 *
	 * @param int $product_id Product.
	 * @return array|null
	 */
	public static function chart_for( $product_id ) {
		$chart = Resolver::chart_for_product( $product_id );
		if ( ! $chart || 'publish' !== $chart['status'] ) {
			return null;
		}
		if ( Settings_Store::get( 'require_size_attr', false ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			$has     = false;
			foreach ( array_map( 'trim', explode( ',', (string) Settings_Store::get( 'size_attributes', 'pa_size, size' ) ) ) as $name ) {
				$has = $has || ( $product && '' !== (string) $product->get_attribute( $name ) );
			}
			if ( ! $has ) {
				return null;
			}
		}
		return $chart;
	}

	/**
	 * Buttons for the product in the loop.
	 */
	public function buttons_current() {
		global $product;
		if ( $product instanceof \WC_Product ) {
			echo self::buttons_for( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Renderer.
		}
	}

	/**
	 * Fallback for products that render no add-to-cart form (WooCommerce's block-theme compatibility layer
	 * also runs this hook, so purchasable products are left to the form hooks).
	 */
	public function buttons_no_form() {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$has_form = $product->is_type( 'external' ) || ( $product->is_purchasable() && $product->is_in_stock() );
		if ( ! $has_form || 'after_summary' === Settings_Store::get( 'button_position', 'after_cart' ) ) {
			echo self::buttons_for( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Renderer.
		}
	}

	/**
	 * Buttons (once per product) and queues the popup.
	 *
	 * @param int  $product_id Product.
	 * @param bool $force      Print even if already printed (shortcode).
	 * @return string
	 */
	public static function buttons_for( $product_id, $force = false ) {
		$product_id = (int) $product_id;
		if ( ! $force && isset( self::$printed[ $product_id ] ) ) {
			return '';
		}
		$chart = self::chart_for( $product_id );
		if ( ! $chart ) {
			return '';
		}
		self::$printed[ $product_id ] = true;
		$sheet                        = 'dev3sg-sheet-' . $product_id . '-' . $chart['id'];
		self::$sheets[ $sheet ]       = array( $chart, $product_id );
		self::enqueue();
		$quiz = Settings_Store::get( 'show_quiz', true ) && ( Renderer::quiz_fields( $chart ) || Renderer::usual_options( $chart ) );
		return Renderer::buttons( $sheet, $quiz );
	}

	/**
	 * Popup for a chart without product (shortcode with id).
	 *
	 * @param array $chart Chart.
	 * @return string
	 */
	public static function buttons_for_chart( array $chart ) {
		$sheet                  = 'dev3sg-sheet-0-' . $chart['id'];
		self::$sheets[ $sheet ] = array( $chart, 0 );
		self::enqueue();
		$quiz = Settings_Store::get( 'show_quiz', true ) && ( Renderer::quiz_fields( $chart ) || Renderer::usual_options( $chart ) );
		return Renderer::buttons( $sheet, $quiz );
	}

	/**
	 * Block themes: add the buttons after the add-to-cart form / price / meta block of the single product.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public function render_block( $content, $block ) {
		$name = $block['blockName'] ?? '';
		if ( ! in_array( $name, array( 'woocommerce/add-to-cart-form', 'woocommerce/product-price', 'woocommerce/product-meta', 'woocommerce/add-to-cart-with-options' ), true ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $content;
		}
		$position   = (string) Settings_Store::get( 'button_position', 'after_cart' );
		$product_id = (int) get_queried_object_id();
		if ( 'woocommerce/product-price' === $name ) {
			// Only the main product price (not related products) and only for "after price".
			if ( 'after_price' !== $position || ! empty( $block['attrs']['isDescendentOfQueryLoop'] ) || ! empty( $block['attrs']['isDescendentOfSingleProductBlock'] ) ) {
				return $content;
			}
			return $content . self::buttons_for( $product_id );
		}
		if ( 'woocommerce/product-meta' === $name ) {
			return 'after_summary' === $position ? $content . self::buttons_for( $product_id ) : $content;
		}
		if ( 'before_cart' === $position ) {
			return self::buttons_for( $product_id ) . $content;
		}
		// The classic form inside the block already printed the buttons through its hooks; this covers forms without them.
		return $content . self::buttons_for( $product_id );
	}

	/**
	 * Hidden field the script fills with the recommended size (add-to-cart analytics).
	 */
	public function hidden_field() {
		global $product;
		if ( $product instanceof \WC_Product && Resolver::chart_id_for_product( $product->get_id() ) ) {
			echo '<input type="hidden" name="dev3sg_rec" value="" data-dev3sg-rec>';
		}
	}

	/**
	 * Counts add-to-cart after a recommendation.
	 *
	 * @param string $key          Cart item key.
	 * @param int    $product_id   Product.
	 * @param int    $quantity     Qty.
	 * @param int    $variation_id Variation.
	 * @param array  $variation    Attributes.
	 * @param array  $data         Cart item data.
	 */
	public function track_cart( $key, $product_id, $quantity, $variation_id, $variation, $data ) {
		unset( $key, $quantity, $variation_id, $variation, $data );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- analytics flag only; WooCommerce's add-to-cart form carries no nonce.
		$rec = isset( $_POST['dev3sg_rec'] ) ? sanitize_text_field( wp_unslash( $_POST['dev3sg_rec'] ) ) : '';
		if ( '' !== $rec ) {
			Events::record( 'cart', (int) $product_id, Resolver::chart_id_for_product( (int) $product_id ), $rec );
		}
	}

	/**
	 * Product tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		global $product;
		if ( 'popup' === Settings_Store::get( 'display_mode', 'both' ) || ! $product instanceof \WC_Product ) {
			return $tabs;
		}
		$chart = self::chart_for( $product->get_id() );
		if ( ! $chart ) {
			return $tabs;
		}
		$tabs['dev3sg_size_guide'] = array(
			'title'    => \Dev3\AiSizeGuide\Settings::label( 'tab_label' ),
			'priority' => 25,
			'callback' => static function () use ( $chart, $product ) {
				self::enqueue();
				echo Renderer::guide( $chart, $product->get_id(), array( 'context' => 'tab' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Renderer.
			},
		);
		return $tabs;
	}

	/**
	 * Prints queued sheets.
	 */
	public static function print_sheets() {
		foreach ( self::$sheets as $id => $pair ) {
			echo Renderer::sheet( $id, $pair[0], $pair[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Renderer.
		}
		self::$sheets = array();
	}

	/**
	 * Registers assets; enqueues CSS early on pages that will show a guide (no flash of unstyled content).
	 */
	public function assets() {
		self::register_assets();
		$early = false;
		if ( function_exists( 'is_product' ) && is_product() ) {
			$early = (bool) self::chart_for( (int) get_queried_object_id() );
		} elseif ( is_singular() ) {
			$post  = get_post();
			$early = $post && ( has_shortcode( $post->post_content, 'dev3sg_size_guide' ) || has_shortcode( $post->post_content, 'dev3sg_size_chart' ) || has_shortcode( $post->post_content, 'dev3sg_fit_quiz' ) || has_block( 'dev3sg/size-chart', $post ) );
		}
		if ( $early ) {
			self::enqueue();
		}
	}

	/**
	 * Registers the storefront script + style once.
	 */
	public static function register_assets() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$file = DEV3SG_PATH . 'build/storefront.asset.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$asset = include $file;
		wp_register_script( self::HANDLE, DEV3SG_URL . 'build/storefront.js', $asset['dependencies'], $asset['version'], true );
		if ( is_readable( DEV3SG_PATH . 'build/storefront.css' ) ) {
			wp_register_style( self::HANDLE, DEV3SG_URL . 'build/storefront.css', array(), $asset['version'] );
			wp_add_inline_style( self::HANDLE, Renderer::inline_css() );
		}
		wp_add_inline_script( self::HANDLE, 'window.dev3sgFront = ' . wp_json_encode( self::front_config() ) . ';', 'before' );
	}

	/**
	 * Public boot data (no nonce, no secrets: safe for page caches).
	 *
	 * @return array
	 */
	public static function front_config() {
		return array(
			'restUrl'   => esc_url_raw( rest_url( 'dev3sg/v1/' ) ),
			'ai'        => (bool) Settings_Store::get( 'ai_explain', true ) && Ai::enabled( 'storefront' ),
			'remember'  => (bool) Settings_Store::get( 'save_measurements', true ),
			'analytics' => (bool) Settings_Store::get( 'analytics', true ),
			'sizeAttrs' => array_values( array_filter( array_map( 'trim', explode( ',', (string) Settings_Store::get( 'size_attributes', 'pa_size, size' ) ) ) ) ),
			'i18n'      => array(
				'best'        => __( 'Your best size', 'dev3-ai-size-guide-for-woocommerce' ),
				'alt'         => __( 'Also consider', 'dev3-ai-size-guide-for-woocommerce' ),
				/* translators: %s: size label. */
				'select'      => __( 'Select size %s', 'dev3-ai-size-guide-for-woocommerce' ),
				/* translators: %s: size label. */
				'selected'    => __( 'Size %s selected', 'dev3-ai-size-guide-for-woocommerce' ),
				'showChart'   => __( 'Show in chart', 'dev3-ai-size-guide-for-woocommerce' ),
				'again'       => __( 'Start again', 'dev3-ai-size-guide-for-woocommerce' ),
				'working'     => __( 'Finding your size…', 'dev3-ai-size-guide-for-woocommerce' ),
				'why'         => __( 'Why this size', 'dev3-ai-size-guide-for-woocommerce' ),
				'needInput'   => __( 'Enter at least one measurement, or your height and weight.', 'dev3-ai-size-guide-for-woocommerce' ),
				'error'       => __( 'Something went wrong. Please try again.', 'dev3-ai-size-guide-for-woocommerce' ),
				'confidence'  => array(
					'high'   => __( 'Great match', 'dev3-ai-size-guide-for-woocommerce' ),
					'medium' => __( 'Good match', 'dev3-ai-size-guide-for-woocommerce' ),
					'low'    => __( 'Rough estimate', 'dev3-ai-size-guide-for-woocommerce' ),
				),
				'status'      => array(
					'within' => __( 'within range', 'dev3-ai-size-guide-for-woocommerce' ),
					'above'  => __( 'may be snug', 'dev3-ai-size-guide-for-woocommerce' ),
					'below'  => __( 'may be roomy', 'dev3-ai-size-guide-for-woocommerce' ),
				),
				'estimated'   => __( 'estimated', 'dev3-ai-size-guide-for-woocommerce' ),
				'recommended' => __( 'Recommended', 'dev3-ai-size-guide-for-woocommerce' ),
			),
		);
	}

	/**
	 * Enqueues assets (safe to call during rendering).
	 */
	public static function enqueue() {
		self::register_assets();
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Test helper: resets static state.
	 */
	public static function reset() {
		self::$sheets  = array();
		self::$printed = array();
	}
}
