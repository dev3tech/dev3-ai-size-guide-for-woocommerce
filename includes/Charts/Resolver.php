<?php
/**
 * Picks the size chart for a product: override → product → tag → category (incl. parents) → priority.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Charts;

defined( 'ABSPATH' ) || exit;

/**
 * Assignment resolution.
 */
class Resolver {

	const META_OVERRIDE = '_dev3sg_chart';
	const INDEX_KEY     = 'dev3sg_rules_index';

	/**
	 * Per-request memo.
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Per-request product memo.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Clears caches (after a chart or override changes).
	 */
	public static function flush() {
		self::$index = null;
		self::$memo  = array();
		delete_transient( self::INDEX_KEY );
	}

	/**
	 * Clears only the per-request product memo (product terms or overrides changed).
	 */
	public static function flush_memo() {
		self::$memo = array();
	}

	/**
	 * Rules of all published charts: [ id => { rules, date } ].
	 *
	 * @return array
	 */
	public static function index() {
		if ( null !== self::$index ) {
			return self::$index;
		}
		$cached = get_transient( self::INDEX_KEY );
		if ( is_array( $cached ) ) {
			self::$index = $cached;
			return $cached;
		}
		$index = array();
		$ids   = get_posts(
			array(
				'post_type'      => Charts::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- size charts are few; one query keeps the rules index cheap.
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$rules        = json_decode( (string) get_post_meta( $id, Charts::META_RULES, true ), true );
			$index[ $id ] = Charts::sanitize_rules( is_array( $rules ) ? $rules : array() );
		}
		set_transient( self::INDEX_KEY, $index, DAY_IN_SECONDS );
		self::$index = $index;
		return $index;
	}

	/**
	 * Chart id for a product (0 = none).
	 *
	 * @param int $product_id Product or variation id.
	 * @return int
	 */
	public static function chart_id_for_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return 0;
		}
		$parent = (int) wp_get_post_parent_id( $product_id );
		if ( $parent && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = $parent;
		}
		if ( isset( self::$memo[ $product_id ] ) ) {
			return self::$memo[ $product_id ];
		}
		$found = self::resolve( $product_id );
		/**
		 * Filters the chart chosen for a product.
		 *
		 * @param int $found      Chart id (0 = none).
		 * @param int $product_id Product id.
		 */
		$found                     = (int) apply_filters( 'dev3sg_chart_for_product', $found, $product_id );
		self::$memo[ $product_id ] = $found;
		return $found;
	}

	/**
	 * Explains the match for the admin ("product", "tag", "category", "override", "").
	 *
	 * @param int $product_id Product id.
	 * @return array { chart_id, via }
	 */
	public static function explain( $product_id ) {
		$via = '';
		$id  = self::resolve( (int) $product_id, $via );
		return array(
			'chart_id' => $id,
			'via'      => $via,
		);
	}

	/**
	 * Resolution.
	 *
	 * @param int    $product_id Product id.
	 * @param string $via        Set to the matching rule type.
	 * @return int
	 */
	private static function resolve( $product_id, &$via = '' ) {
		$override = (string) get_post_meta( $product_id, self::META_OVERRIDE, true );
		if ( 'none' === $override ) {
			$via = 'none';
			return 0;
		}
		$index = self::index();
		if ( ctype_digit( $override ) && isset( $index[ (int) $override ] ) ) {
			$via = 'override';
			return (int) $override;
		}
		$cats    = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$cats    = is_wp_error( $cats ) ? array() : array_map( 'intval', $cats );
		$parents = array();
		foreach ( $cats as $cat ) {
			foreach ( get_ancestors( $cat, 'product_cat', 'taxonomy' ) as $anc ) {
				$parents[] = (int) $anc;
			}
		}
		$tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) );
		$tags = is_wp_error( $tags ) ? array() : array_map( 'intval', $tags );

		$best = array( 0, -1.0, -1000, 0, '' );
		foreach ( $index as $chart_id => $rules ) {
			if ( in_array( $product_id, $rules['exclude'], true ) ) {
				continue;
			}
			$tier = 0;
			$type = '';
			if ( in_array( $product_id, $rules['products'], true ) ) {
				$tier = 4;
				$type = 'product';
			} elseif ( array_intersect( $tags, $rules['tags'] ) ) {
				$tier = 3;
				$type = 'tag';
			} elseif ( array_intersect( $cats, $rules['categories'] ) ) {
				$tier = 2;
				$type = 'category';
			} elseif ( array_intersect( $parents, $rules['categories'] ) ) {
				$tier = 1;
				$type = 'parent_category';
			}
			if ( ! $tier ) {
				continue;
			}
			$candidate = array( (int) $chart_id, (float) $tier, (int) $rules['priority'], (int) $chart_id, $type );
			if ( $candidate[1] > $best[1] || ( $candidate[1] === $best[1] && ( $candidate[2] > $best[2] || ( $candidate[2] === $best[2] && $candidate[3] > $best[3] ) ) ) ) {
				$best = $candidate;
			}
		}
		$via = $best[4];
		return $best[0];
	}

	/**
	 * Full chart for a product, or null.
	 *
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	public static function chart_for_product( $product_id ) {
		$id = self::chart_id_for_product( $product_id );
		return $id ? Charts::get( $id ) : null;
	}

	/**
	 * Products a chart applies to (ids), capped.
	 *
	 * @param int $chart_id Chart.
	 * @param int $limit    Max.
	 * @return int[]
	 */
	public static function products_for_chart( $chart_id, $limit = 500 ) {
		$out = array();
		foreach ( self::candidate_products( $limit ) as $pid ) {
			if ( self::chart_id_for_product( $pid ) === (int) $chart_id ) {
				$out[] = $pid;
			}
		}
		return $out;
	}

	/**
	 * Published product ids (newest first).
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	public static function candidate_products( $limit = 500 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => (int) $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		);
	}
}
