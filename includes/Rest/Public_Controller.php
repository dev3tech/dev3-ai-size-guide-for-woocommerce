<?php
/**
 * Public storefront routes: fit quiz maths, AI explanation (with fallback) and analytics events.
 * Same-origin + per-IP rate limits, no nonce (page caches), and only published charts.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Rest;

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;
use Dev3\AiSizeGuide\Core3\Rest\Base_Controller;
use Dev3\AiSizeGuide\Data\Events;
use Dev3\AiSizeGuide\Fit\Engine;
use Dev3\AiSizeGuide\Fit\Explainer;

defined( 'ABSPATH' ) || exit;

/**
 * Public routes.
 */
class Public_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/fit/recommend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recommend' ),
				'permission_callback' => fn( $r ) => $this->public_permission( $r, 'fit', 30 ),
			)
		);
		register_rest_route(
			self::NS,
			'/fit/explain',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'explain' ),
				'permission_callback' => fn( $r ) => $this->public_permission( $r, 'explain', 10 ),
			)
		);
		register_rest_route(
			self::NS,
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'track' ),
				'permission_callback' => fn( $r ) => $this->public_permission( $r, 'track', 60 ),
			)
		);
	}

	/**
	 * Published chart the visitor may use: the product's chart, or an explicitly embedded published chart.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function chart( \WP_REST_Request $request ) {
		$chart_id   = (int) $request['chart_id'];
		$product_id = (int) $request['product_id'];
		if ( ! $chart_id && $product_id ) {
			$chart_id = Resolver::chart_id_for_product( $product_id );
		}
		$chart = $chart_id ? Charts::get( $chart_id ) : null;
		if ( ! $chart || 'publish' !== $chart['status'] ) {
			return new \WP_Error( 'dev3sg_chart_missing', __( 'This size guide is not available.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) );
		}
		return $chart;
	}

	/**
	 * Shopper input from the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function input( \WP_REST_Request $request ) {
		$measurements = array();
		foreach ( array_slice( (array) $request['measurements'], 0, 20, true ) as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$measurements[ sanitize_key( (string) $key ) ] = (float) str_replace( ',', '.', (string) $value );
			}
		}
		$usual = array();
		foreach ( array_slice( (array) $request['usual'], 0, 5, true ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$usual[ sanitize_key( (string) $key ) ] = mb_substr( sanitize_text_field( (string) $value ), 0, 24 );
			}
		}
		return array(
			'measurements' => $measurements,
			'usual'        => $usual,
			'unit'         => 'imperial' === $request['unit'] ? 'imperial' : 'metric',
			'fit'          => in_array( $request['fit'], array( 'snug', 'regular', 'relaxed' ), true ) ? (string) $request['fit'] : 'regular',
			'table'        => sanitize_key( (string) $request['table'] ),
		);
	}

	/**
	 * POST fit/recommend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function recommend( \WP_REST_Request $request ) {
		$chart = $this->chart( $request );
		if ( is_wp_error( $chart ) ) {
			return $this->respond( $chart );
		}
		$result = Engine::recommend( $chart, $this->input( $request ), array( 'estimates' => (bool) Settings_Store::get( 'estimates', true ) ) );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$product_id = (int) $request['product_id'];
		Events::record( 'quiz', $product_id, $chart['id'] );
		Events::record( 'recommend', $product_id, $chart['id'], $result['size'] );
		return $this->respond( $result );
	}

	/**
	 * POST fit/explain: recomputes the result server-side, then asks AI (cached) or uses the template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function explain( \WP_REST_Request $request ) {
		$chart = $this->chart( $request );
		if ( is_wp_error( $chart ) ) {
			return $this->respond( $chart );
		}
		$result = Engine::recommend( $chart, $this->input( $request ), array( 'estimates' => (bool) Settings_Store::get( 'estimates', true ) ) );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$product = (int) $request['product_id'] ? get_the_title( (int) $request['product_id'] ) : '';
		$locale  = preg_match( '/^[a-z]{2}(_[A-Z]{2})?$/', (string) $request['locale'] ) ? (string) $request['locale'] : '';
		return $this->respond( array_merge( Explainer::explain( $result, $product, $locale ), array( 'size' => $result['size'] ) ) );
	}

	/**
	 * POST track { type: open|select, chart_id, product_id, size }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function track( \WP_REST_Request $request ) {
		$type = (string) $request['type'];
		if ( ! in_array( $type, array( 'open', 'select' ), true ) ) {
			return $this->respond( new \WP_Error( 'dev3sg_bad_event', __( 'Unknown event.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$chart = $this->chart( $request );
		if ( is_wp_error( $chart ) ) {
			return $this->respond( $chart );
		}
		Events::record( $type, (int) $request['product_id'], $chart['id'], (string) $request['size'] );
		return $this->respond( array( 'ok' => true ) );
	}
}
