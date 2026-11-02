<?php
/**
 * Shortcodes and the "Size Chart" block (server-rendered).
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Frontend;

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Core3\Core\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * [dev3sg_size_guide], [dev3sg_size_chart], [dev3sg_fit_quiz] and dev3sg/size-chart.
 */
class Shortcodes {

	/**
	 * Hooks.
	 */
	public function register() {
		add_shortcode( 'dev3sg_size_guide', array( __CLASS__, 'size_guide' ) );
		add_shortcode( 'dev3sg_size_chart', array( __CLASS__, 'size_chart' ) );
		add_shortcode( 'dev3sg_fit_quiz', array( __CLASS__, 'fit_quiz' ) );
		add_action( 'init', array( $this, 'block' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Chart list for the block's inspector (editors may not reach the admin REST routes).
	 */
	public function editor_data() {
		$charts = array();
		foreach ( Charts::all( array( 'status' => 'publish' ) ) as $chart ) {
			$charts[] = array(
				'id'   => $chart['id'],
				'name' => $chart['name'],
			);
		}
		wp_add_inline_script( Assets::handle( 'blocks' ), 'window.dev3sgBlocks = ' . wp_json_encode( array( 'charts' => $charts ) ) . ';', 'before' );
	}

	/**
	 * Registers the block (editor script from build/blocks.js).
	 */
	public function block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		Storefront::register_assets();
		register_block_type(
			'dev3sg/size-chart',
			array(
				'api_version'     => 2,
				'editor_script'   => is_readable( DEV3SG_PATH . 'build/blocks.asset.php' ) ? Assets::handle( 'blocks' ) : null,
				'editor_style'    => Storefront::HANDLE,
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'chartId'   => array(
						'type'    => 'number',
						'default' => 0,
					),
					'productId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'view'      => array(
						'type'    => 'string',
						'default' => 'inline',
					),
				),
				'supports'        => array(
					'align'   => array( 'wide', 'full' ),
					'spacing' => array( 'margin' => true ),
				),
			)
		);
	}

	/**
	 * Block render.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_block( $attrs ) {
		$atts = array(
			'id'         => (int) ( $attrs['chartId'] ?? 0 ),
			'product_id' => (int) ( $attrs['productId'] ?? 0 ),
		);
		$view = (string) ( $attrs['view'] ?? 'inline' );
		$html = 'button' === $view ? self::size_guide( $atts ) : ( 'quiz' === $view ? self::fit_quiz( $atts ) : self::size_chart( $atts ) );
		if ( '' === $html && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return '<p class="dev3sg-empty">' . esc_html__( 'Choose a size chart, or place the block on a product that has one.', 'dev3-ai-size-guide-for-woocommerce' ) . '</p>';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? get_block_wrapper_attributes( array( 'class' => 'dev3sg-block' ) ) : 'class="dev3sg-block"';
		return '' === $html ? '' : '<div ' . $wrapper . '>' . $html . '</div>';
	}

	/**
	 * Resolves the chart + product from attributes.
	 *
	 * @param array $atts { id, product_id }.
	 * @return array [ chart|null, product_id ]
	 */
	public static function resolve( $atts ) {
		$atts       = shortcode_atts(
			array(
				'id'         => 0,
				'product_id' => 0,
			),
			(array) $atts
		);
		$product_id = (int) $atts['product_id'];
		if ( ! $product_id ) {
			global $product;
			$product_id = $product instanceof \WC_Product ? $product->get_id() : ( 'product' === get_post_type() ? (int) get_the_ID() : 0 );
		}
		if ( (int) $atts['id'] ) {
			$chart = Charts::get( (int) $atts['id'] );
			return array( $chart && 'publish' === $chart['status'] ? $chart : null, $product_id );
		}
		return array( $product_id ? Storefront::chart_for( $product_id ) : null, $product_id );
	}

	/**
	 * [dev3sg_size_guide]: buttons + popup.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function size_guide( $atts ) {
		list( $chart, $product_id ) = self::resolve( $atts );
		if ( ! $chart ) {
			return '';
		}
		$atts = (array) $atts;
		if ( ! empty( $atts['id'] ) ) {
			return Storefront::buttons_for_chart( $chart );
		}
		return Storefront::buttons_for( $product_id, true );
	}

	/**
	 * [dev3sg_size_chart]: inline guide.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function size_chart( $atts ) {
		list( $chart, $product_id ) = self::resolve( $atts );
		if ( ! $chart ) {
			return '';
		}
		Storefront::enqueue();
		$quiz = ! isset( $atts['quiz'] ) || ! in_array( strtolower( (string) $atts['quiz'] ), array( 'no', '0', 'false' ), true );
		return Renderer::guide(
			$chart,
			$product_id,
			array(
				'context' => 'inline',
				'quiz'    => $quiz,
			)
		);
	}

	/**
	 * [dev3sg_fit_quiz]: quiz only.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function fit_quiz( $atts ) {
		list( $chart, $product_id ) = self::resolve( $atts );
		if ( ! $chart ) {
			return '';
		}
		Storefront::enqueue();
		return Renderer::guide(
			$chart,
			$product_id,
			array(
				'context' => 'inline',
				'only'    => 'quiz',
				'quiz'    => true,
			)
		);
	}
}
