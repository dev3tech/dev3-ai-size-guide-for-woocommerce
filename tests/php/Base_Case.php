<?php
/**
 * Shared set-up: clean settings and tables, fake HTTP (Gemini), helpers for charts, products and REST calls.
 *
 * @package Dev3\AiSizeGuide
 */

use Dev3\AiSizeGuide\Ai\Prompt_Library;
use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Charts\Templates;
use Dev3\AiSizeGuide\Core3\Api\Ai;
use Dev3\AiSizeGuide\Core3\Core\Cache;
use Dev3\AiSizeGuide\Core3\Core\Schema;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;
use Dev3\AiSizeGuide\Core3\Data\Usage_Repository;
use Dev3\AiSizeGuide\Frontend\Storefront;
use Dev3\AiSizeGuide\Learning\Insights;

abstract class Dev3sg_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Queue of fake responses ([ status, body ] or callable); the last one repeats.
	 *
	 * @var array
	 */
	protected $responses = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3SG_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		Prompt_Library::register();
		Resolver::flush();
		Insights::flush();
		Storefront::reset();
		delete_option( Insights::DISMISSED_OPT );
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}dev3sg_returns" ); // phpcs:ignore
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}dev3sg_events" ); // phpcs:ignore
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}dev3sg_core_cache" ); // phpcs:ignore
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3sg_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3sg_jobs_use_action_scheduler', '__return_false' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3sg_jobs_use_action_scheduler', '__return_false' );
		Resolver::flush();
		wp_cache_flush();
		parent::tear_down();
	}

	/**
	 * Turns AI on with a fake key.
	 */
	protected function with_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	/**
	 * Gemini-shaped structured response.
	 *
	 * @param array $data Output.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 120,
					'candidatesTokenCount' => 80,
					'totalTokenCount'      => 200,
				),
			),
		);
	}

	/**
	 * pre_http_request handler.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'body' => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
		);
		$next = count( $this->responses ) > 1 ? array_shift( $this->responses ) : ( $this->responses ? $this->responses[0] : array( 500, array( 'error' => array( 'message' => 'no fake' ) ) ) );
		if ( is_callable( $next ) ) {
			$next = $next( end( $this->requests ) );
		}
		return array(
			'headers'  => array(),
			'body'     => is_string( $next[1] ) ? $next[1] : wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * A published chart from a template, with rules.
	 *
	 * @param string $template Template id.
	 * @param array  $rules    Rules.
	 * @param array  $extra    Overrides.
	 * @return int
	 */
	protected function chart( $template = 'men_tops', array $rules = array(), array $extra = array() ) {
		$data          = array_merge( Templates::chart( $template ), $extra );
		$data['rules'] = $rules;
		$id            = Charts::save( $data );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Simple product in categories/tags.
	 *
	 * @param array $cats Category ids.
	 * @param array $tags Tag ids.
	 * @return int
	 */
	protected function product( array $cats = array(), array $tags = array() ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Tee ' . wp_rand( 1, 99999 ) );
		$p->set_status( 'publish' );
		$p->set_regular_price( '20' );
		$p->set_category_ids( $cats );
		$p->set_tag_ids( $tags );
		$p->save();
		return $p->get_id();
	}

	/**
	 * Category id.
	 *
	 * @param string $name   Name.
	 * @param int    $parent Parent.
	 * @return int
	 */
	protected function cat( $name, $parent = 0 ) {
		$t = wp_insert_term( $name . wp_rand( 1, 99999 ), 'product_cat', array( 'parent' => $parent ) );
		return (int) $t['term_id'];
	}

	/**
	 * Tag id.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	protected function tag( $name ) {
		$t = wp_insert_term( $name . wp_rand( 1, 99999 ), 'product_tag' );
		return (int) $t['term_id'];
	}

	/**
	 * REST call as the current user with a valid nonce.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below dev3sg/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send a nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3sg/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Logs in an administrator.
	 *
	 * @return int
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}
}
