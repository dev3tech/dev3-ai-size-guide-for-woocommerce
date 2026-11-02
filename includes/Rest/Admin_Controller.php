<?php
/**
 * Admin REST routes (charts, templates, assignment, analytics, returns, insights, AI drafts).
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Rest;

use Dev3\AiSizeGuide\Admin\Product_Meta;
use Dev3\AiSizeGuide\Ai\Drafts;
use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Parser;
use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Charts\Templates;
use Dev3\AiSizeGuide\Charts\Measures;
use Dev3\AiSizeGuide\Core3\Core\Jobs;
use Dev3\AiSizeGuide\Core3\Rest\Base_Controller;
use Dev3\AiSizeGuide\Data\Events;
use Dev3\AiSizeGuide\Fit\Engine;
use Dev3\AiSizeGuide\Learning\Classifier;
use Dev3\AiSizeGuide\Learning\Insights;
use Dev3\AiSizeGuide\Learning\Returns;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3sg/v1 for admins.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Route helper.
	 *
	 * @param string $route    Route.
	 * @param string $methods  Methods.
	 * @param string $callback Method name.
	 * @param string $perm     can_manage|can_generate.
	 */
	private function route( $route, $methods, $callback, $perm = 'can_manage' ) {
		register_rest_route(
			self::NS,
			$route,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $perm ),
			)
		);
	}

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$this->route( '/charts', 'GET', 'list_charts' );
		$this->route( '/charts', 'POST', 'create_chart' );
		$this->route( '/charts/(?P<id>\d+)', 'GET', 'get_chart' );
		$this->route( '/charts/(?P<id>\d+)', 'POST, PUT, PATCH', 'update_chart' );
		$this->route( '/charts/(?P<id>\d+)', 'DELETE', 'delete_chart' );
		$this->route( '/charts/(?P<id>\d+)/duplicate', 'POST', 'duplicate_chart' );
		$this->route( '/templates', 'GET', 'templates' );
		$this->route( '/measures', 'GET', 'measures' );
		$this->route( '/parse', 'POST', 'parse' );
		$this->route( '/lookup', 'GET', 'lookup' );
		$this->route( '/coverage', 'GET', 'coverage' );
		$this->route( '/assign', 'POST', 'assign' );
		$this->route( '/assign/categories', 'POST', 'assign_categories' );
		$this->route( '/stats', 'GET', 'stats' );
		$this->route( '/fit/test', 'POST', 'fit_test' );
		$this->route( '/returns', 'GET', 'list_returns' );
		$this->route( '/returns', 'POST', 'add_return' );
		$this->route( '/returns/(?P<id>\d+)', 'POST, PUT, PATCH', 'update_return' );
		$this->route( '/returns/(?P<id>\d+)', 'DELETE', 'delete_return' );
		$this->route( '/returns/classify', 'POST', 'classify_returns', 'can_manage' );
		$this->route( '/returns/accept', 'POST', 'accept_returns' );
		$this->route( '/insights', 'GET', 'insights' );
		$this->route( '/insights/apply', 'POST', 'apply_insight' );
		$this->route( '/insights/dismiss', 'POST', 'dismiss_insight' );
		$this->route( '/ai/chart', 'POST', 'ai_chart', 'can_generate' );
		$this->route( '/ai/guide', 'POST', 'ai_guide', 'can_generate' );
		$this->route( '/ai/insights', 'POST', 'ai_insights', 'can_manage' );
		$this->route( '/ai/assign', 'POST', 'ai_assign', 'can_manage' );
	}

	/**
	 * Admin list shape of a chart.
	 *
	 * @param array $chart Chart.
	 * @return array
	 */
	private function summary( array $chart ) {
		$sizes = Charts::sizes( $chart );
		return array(
			'id'            => $chart['id'],
			'name'          => $chart['name'],
			'status'        => $chart['status'],
			'profile'       => $chart['profile'],
			'tables'        => count( $chart['tables'] ),
			'sizes'         => $sizes,
			'bias'          => $chart['bias'],
			'rules'         => $chart['rules'],
			'rules_text'    => $this->rules_text( $chart['rules'] ),
			'product_count' => 'publish' === $chart['status'] ? count( Resolver::products_for_chart( $chart['id'] ) ) : 0,
			'updated'       => $chart['updated'],
		);
	}

	/**
	 * "2 categories, 1 tag, 3 products".
	 *
	 * @param array $rules Rules.
	 * @return string
	 */
	private function rules_text( array $rules ) {
		$parts = array();
		$names = array();
		foreach ( array_slice( $rules['categories'], 0, 3 ) as $id ) {
			$t = get_term( $id, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$names[] = $t->name;
			}
		}
		if ( $rules['categories'] ) {
			$parts[] = implode( ', ', $names ) . ( count( $rules['categories'] ) > 3 ? ' +' . ( count( $rules['categories'] ) - 3 ) : '' );
		}
		if ( $rules['tags'] ) {
			/* translators: %d: number of tags. */
			$parts[] = sprintf( _n( '%d tag', '%d tags', count( $rules['tags'] ), 'dev3-ai-size-guide-for-woocommerce' ), count( $rules['tags'] ) );
		}
		if ( $rules['products'] ) {
			/* translators: %d: number of products. */
			$parts[] = sprintf( _n( '%d product', '%d products', count( $rules['products'] ), 'dev3-ai-size-guide-for-woocommerce' ), count( $rules['products'] ) );
		}
		return $parts ? implode( ' · ', $parts ) : __( 'Not assigned', 'dev3-ai-size-guide-for-woocommerce' );
	}

	/**
	 * GET charts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_charts( \WP_REST_Request $request ) {
		$charts = Charts::all( array( 'search' => sanitize_text_field( (string) $request['search'] ) ) );
		return $this->respond( array_map( array( $this, 'summary' ), $charts ) );
	}

	/**
	 * POST charts: { template } or { chart }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_chart( \WP_REST_Request $request ) {
		$template = sanitize_key( (string) $request['template'] );
		if ( '' !== $template ) {
			$data = Templates::chart( $template );
			if ( ! $data ) {
				return $this->respond( new \WP_Error( 'dev3sg_template_missing', __( 'Template not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) ) );
			}
		} else {
			$data = $request['chart'];
			$data = is_array( $data ) ? $data : Charts::blank();
			if ( empty( $data['name'] ) ) {
				$data['name'] = __( 'New size chart', 'dev3-ai-size-guide-for-woocommerce' );
			}
		}
		$id = Charts::save( $data );
		return is_wp_error( $id ) ? $this->respond( $id ) : $this->respond( Charts::get( $id ), 201 );
	}

	/**
	 * GET charts/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_chart( \WP_REST_Request $request ) {
		$chart = Charts::get( (int) $request['id'] );
		if ( ! $chart ) {
			return $this->respond( new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		$chart['product_count'] = count( Resolver::products_for_chart( $chart['id'] ) );
		$chart['lookup']        = $this->names_for_rules( $chart['rules'] );
		return $this->respond( $chart );
	}

	/**
	 * Names for selected ids so pickers can show chips.
	 *
	 * @param array $rules Rules.
	 * @return array
	 */
	private function names_for_rules( array $rules ) {
		$out = array();
		foreach ( array(
			'categories' => 'product_cat',
			'tags'       => 'product_tag',
		) as $key => $tax ) {
			$out[ $key ] = array();
			foreach ( $rules[ $key ] as $id ) {
				$t = get_term( $id, $tax );
				if ( $t && ! is_wp_error( $t ) ) {
					$out[ $key ][] = array(
						'id'   => $id,
						'name' => $t->name,
					);
				}
			}
		}
		foreach ( array( 'products', 'exclude' ) as $key ) {
			$out[ $key ] = array();
			foreach ( $rules[ $key ] as $id ) {
				$out[ $key ][] = array(
					'id'   => $id,
					'name' => (string) get_the_title( $id ),
				);
			}
		}
		return $out;
	}

	/**
	 * POST charts/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_chart( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : $request->get_body_params();
		$id   = Charts::save( (array) $data, (int) $request['id'] );
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		Insights::flush();
		return $this->get_chart( $request );
	}

	/**
	 * DELETE charts/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_chart( \WP_REST_Request $request ) {
		if ( ! Charts::delete( (int) $request['id'] ) ) {
			return $this->respond( new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST charts/<id>/duplicate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function duplicate_chart( \WP_REST_Request $request ) {
		$id = Charts::duplicate( (int) $request['id'] );
		return is_wp_error( $id ) ? $this->respond( $id ) : $this->respond( Charts::get( $id ), 201 );
	}

	/**
	 * GET templates.
	 *
	 * @return \WP_REST_Response
	 */
	public function templates() {
		return $this->respond( array_values( Templates::all() ) );
	}

	/**
	 * GET measures (dictionary for the editor).
	 *
	 * @return \WP_REST_Response
	 */
	public function measures() {
		return $this->respond( array_values( Measures::all() ) );
	}

	/**
	 * POST parse (non-AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function parse( \WP_REST_Request $request ) {
		$unit = in_array( $request['unit'], array( 'auto', 'cm', 'in', 'mm' ), true ) ? $request['unit'] : 'auto';
		return $this->respond( Parser::parse( (string) $request['text'], $unit ) );
	}

	/**
	 * GET lookup?type=product_cat|product_tag|product&search=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function lookup( \WP_REST_Request $request ) {
		$type   = (string) $request['type'];
		$search = sanitize_text_field( (string) $request['search'] );
		$out    = array();
		if ( in_array( $type, array( 'product_cat', 'product_tag' ), true ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $type,
					'hide_empty' => false,
					'number'     => 30,
					'search'     => $search,
				)
			);
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				$out[] = array(
					'id'    => $t->term_id,
					'name'  => $t->name,
					'count' => $t->count,
				);
			}
		} elseif ( 'product' === $type ) {
			$ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 20,
					's'              => $search,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$out[] = array(
					'id'   => $id,
					'name' => get_the_title( $id ),
				);
			}
		}
		return $this->respond( $out );
	}

	/**
	 * GET coverage: products without a chart.
	 *
	 * @return \WP_REST_Response
	 */
	public function coverage() {
		$ids     = Resolver::candidate_products( 1000 );
		$missing = array();
		$covered = 0;
		foreach ( $ids as $id ) {
			$explain = Resolver::explain( $id );
			if ( $explain['chart_id'] ) {
				++$covered;
			} elseif ( 'none' !== $explain['via'] && count( $missing ) < 100 ) {
				$cats      = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
				$missing[] = array(
					'id'         => $id,
					'name'       => get_the_title( $id ),
					'categories' => is_wp_error( $cats ) ? '' : implode( ', ', $cats ),
					'edit'       => get_edit_post_link( $id, 'raw' ),
				);
			}
		}
		return $this->respond(
			array(
				'total'   => count( $ids ),
				'covered' => $covered,
				'percent' => $ids ? (int) round( 100 * $covered / count( $ids ) ) : 0,
				'missing' => $missing,
			)
		);
	}

	/**
	 * POST assign { product_ids[], chart: '' | none | id }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function assign( \WP_REST_Request $request ) {
		$ids   = array_filter( array_map( 'intval', (array) $request['product_ids'] ) );
		$value = sanitize_key( (string) $request['chart'] );
		$n     = 0;
		foreach ( array_slice( $ids, 0, 500 ) as $id ) {
			if ( 'product' === get_post_type( $id ) ) {
				Product_Meta::set_override( $id, $value );
				++$n;
			}
		}
		return $this->respond( array( 'updated' => $n ) );
	}

	/**
	 * POST assign/categories { items: [ { category_id, chart_id } ] } (accepted AI suggestions).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function assign_categories( \WP_REST_Request $request ) {
		$n = 0;
		foreach ( array_slice( (array) $request['items'], 0, 100 ) as $item ) {
			$cat   = (int) ( $item['category_id'] ?? 0 );
			$chart = Charts::get( (int) ( $item['chart_id'] ?? 0 ) );
			$term  = get_term( $cat, 'product_cat' );
			if ( ! $chart || ! $term || is_wp_error( $term ) ) {
				continue;
			}
			if ( ! in_array( $cat, $chart['rules']['categories'], true ) ) {
				$chart['rules']['categories'][] = $cat;
				Charts::save( $chart, $chart['id'] );
				++$n;
			}
		}
		return $this->respond( array( 'updated' => $n ) );
	}

	/**
	 * GET stats.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats( \WP_REST_Request $request ) {
		$days   = in_array( (int) $request['days'], array( 7, 30, 90 ), true ) ? (int) $request['days'] : 30;
		$charts = Charts::all();
		$ids    = Resolver::candidate_products( 1000 );
		$cov    = 0;
		foreach ( $ids as $id ) {
			$cov += Resolver::chart_id_for_product( $id ) ? 1 : 0;
		}
		$events  = Events::stats( $days );
		$returns = Returns::counts( $days );
		return $this->respond(
			array(
				'days'     => $days,
				'charts'   => count( $charts ),
				'live'     => count( array_filter( $charts, static fn( $c ) => 'publish' === $c['status'] ) ),
				'products' => count( $ids ),
				'covered'  => $cov,
				'coverage' => $ids ? (int) round( 100 * $cov / count( $ids ) ) : 0,
				'events'   => $events,
				'returns'  => $returns,
				'insights' => Insights::open_count(),
			)
		);
	}

	/**
	 * POST fit/test { chart_id | chart, input } (admin quiz simulator, works on unsaved charts).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function fit_test( \WP_REST_Request $request ) {
		$chart = is_array( $request['chart'] ) ? array_merge( Charts::sanitize( $request['chart'] ), array( 'id' => 0 ) ) : Charts::get( (int) $request['chart_id'] );
		if ( ! $chart ) {
			return $this->respond( new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		$input = is_array( $request['input'] ) ? $request['input'] : array();
		return $this->respond( Engine::recommend( $chart, $input, array( 'estimates' => (bool) \Dev3\AiSizeGuide\Core3\Core\Settings_Store::get( 'estimates', true ) ) ) );
	}

	/**
	 * GET returns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_returns( \WP_REST_Request $request ) {
		return $this->respond(
			Returns::query(
				array(
					'page'       => (int) $request['page'],
					'per_page'   => (int) $request['per_page'],
					'reason'     => sanitize_key( (string) $request['reason'] ),
					'chart_id'   => (int) $request['chart_id'],
					'search'     => sanitize_text_field( (string) $request['search'] ),
					'pending_ai' => (bool) $request['pending_ai'],
				)
			)
		);
	}

	/**
	 * POST returns (manual record).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function add_return( \WP_REST_Request $request ) {
		$reason = sanitize_key( (string) $request['reason'] );
		$id     = Returns::add(
			array(
				'product_id' => (int) $request['product_id'],
				'size'       => (string) $request['size'],
				'reason'     => in_array( $reason, Classifier::REASONS, true ) ? $reason : null,
				'note'       => (string) $request['note'],
				'order_id'   => (int) $request['order_id'],
				'source'     => 'manual',
			)
		);
		return is_wp_error( $id ) ? $this->respond( $id ) : $this->respond( Returns::get( $id ), 201 );
	}

	/**
	 * POST returns/<id> { reason }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_return( \WP_REST_Request $request ) {
		if ( ! Returns::get( (int) $request['id'] ) ) {
			return $this->respond( new \WP_Error( 'dev3sg_return_missing', __( 'Record not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		if ( ! Returns::set_reason( (int) $request['id'], sanitize_key( (string) $request['reason'] ) ) ) {
			return $this->respond( new \WP_Error( 'dev3sg_reason_invalid', __( 'Choose a valid reason.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( Returns::get( (int) $request['id'] ) );
	}

	/**
	 * DELETE returns/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_return( \WP_REST_Request $request ) {
		if ( ! Returns::delete( (int) $request['id'] ) ) {
			return $this->respond( new \WP_Error( 'dev3sg_return_missing', __( 'Record not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST returns/classify: background AI job over notes the keyword classifier could not place.
	 *
	 * @return \WP_REST_Response
	 */
	public function classify_returns() {
		$guard = \Dev3\AiSizeGuide\Core3\Api\Ai::enabled( 'admin' );
		if ( ! $guard ) {
			return $this->respond( new \WP_Error( 'dev3sg_ai_disabled', __( 'DEV3 AI is turned off or no API key is set.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$active = Jobs::active( Drafts::CLASSIFY_JOB );
		if ( $active ) {
			return $this->respond( $active );
		}
		$ids = Returns::unclassified_ids( 500 );
		if ( ! $ids ) {
			return $this->respond( new \WP_Error( 'dev3sg_nothing_to_classify', __( 'Every return note is already classified.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$id = Jobs::create( Drafts::CLASSIFY_JOB, $ids, array(), 20 );
		return $this->respond( is_wp_error( $id ) ? $id : Jobs::get( $id ), 201 );
	}

	/**
	 * POST returns/accept { ids[], accept: bool }: applies or rejects AI suggestions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function accept_returns( \WP_REST_Request $request ) {
		$accept = (bool) $request['accept'];
		$n      = 0;
		foreach ( array_slice( array_map( 'intval', (array) $request['ids'] ), 0, 500 ) as $id ) {
			$rec = Returns::get( $id );
			if ( ! $rec || '' === $rec['ai_reason'] || 'rejected' === $rec['ai_reason'] ) {
				continue;
			}
			if ( $accept ) {
				Returns::set_reason( $id, $rec['ai_reason'] );
			} else {
				Returns::reject_ai( $id );
			}
			++$n;
		}
		Insights::flush();
		return $this->respond( array( 'updated' => $n ) );
	}

	/**
	 * GET insights.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function insights( \WP_REST_Request $request ) {
		return $this->respond(
			array(
				'items'  => Insights::all( (bool) $request['fresh'] ),
				'config' => Insights::config(),
			)
		);
	}

	/**
	 * POST insights/apply { chart_id, bias, fit_note? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply_insight( \WP_REST_Request $request ) {
		$note = $request->has_param( 'fit_note' ) && null !== $request['fit_note'] ? (string) $request['fit_note'] : null;
		$res  = Insights::apply( (int) $request['chart_id'], (int) $request['bias'], $note );
		return $this->respond( is_wp_error( $res ) ? $res : $this->summary( $res ) );
	}

	/**
	 * POST insights/dismiss { chart_id, signature }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dismiss_insight( \WP_REST_Request $request ) {
		Insights::dismiss( (int) $request['chart_id'], (string) $request['signature'] );
		return $this->respond( array( 'dismissed' => true ) );
	}

	/**
	 * POST ai/chart { source: product|text|image, … }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_chart( \WP_REST_Request $request ) {
		$unit = in_array( $request['unit'], array( 'auto', 'cm', 'in' ), true ) ? (string) $request['unit'] : 'auto';
		switch ( (string) $request['source'] ) {
			case 'text':
				$result = Drafts::chart_from_text( (string) $request['text'], $unit );
				break;
			case 'image':
				$result = Drafts::chart_from_image( (int) $request['attachment_id'], (string) $request['data'], (string) $request['mime'], $unit );
				break;
			default:
				$profile = in_array( $request['profile'], Charts::PROFILES, true ) ? (string) $request['profile'] : '';
				$result  = Drafts::chart_from_product( (int) $request['product_id'], $profile, 'in' === $unit ? 'in' : 'cm', sanitize_textarea_field( (string) $request['notes'] ) );
		}
		return $this->respond( $result );
	}

	/**
	 * POST ai/guide { chart }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_guide( \WP_REST_Request $request ) {
		$chart = is_array( $request['chart'] ) ? $request['chart'] : Charts::get( (int) $request['chart_id'] );
		if ( ! $chart ) {
			return $this->respond( new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( Drafts::guide( $chart ) );
	}

	/**
	 * POST ai/insights { chart_id }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_insights( \WP_REST_Request $request ) {
		return $this->respond( Drafts::insights( (int) $request['chart_id'] ) );
	}

	/**
	 * POST ai/assign.
	 *
	 * @return \WP_REST_Response
	 */
	public function ai_assign() {
		return $this->respond( Drafts::assign() );
	}
}
