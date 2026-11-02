<?php
/**
 * Storefront analytics events (guide opens, quizzes, recommendations, size selections, add-to-cart).
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Data;

use Dev3\AiSizeGuide\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Events repository.
 */
class Events {

	const TYPES = array( 'open', 'quiz', 'recommend', 'select', 'cart' );

	/**
	 * Records an event (no personal data).
	 *
	 * @param string $type       Type.
	 * @param int    $product_id Product.
	 * @param int    $chart_id   Chart.
	 * @param string $size       Size label.
	 * @return bool
	 */
	public static function record( $type, $product_id = 0, $chart_id = 0, $size = '' ) {
		if ( ! in_array( $type, self::TYPES, true ) || ! Settings_Store::get( 'analytics', true ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->insert(
			Tables::events(),
			array(
				'type'       => $type,
				'product_id' => max( 0, (int) $product_id ),
				'chart_id'   => max( 0, (int) $chart_id ),
				'size'       => mb_substr( sanitize_text_field( (string) $size ), 0, 40 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Dashboard numbers for the last N days.
	 *
	 * @param int $days Days.
	 * @return array { totals{type:n}, daily[{date, open, quiz, cart}], top_products[{product_id, name, opens, quizzes}], cart_rate }
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$table = Tables::events();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_results( $wpdb->prepare( "SELECT type, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY type", $since ), ARRAY_A );
		$daily  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, type, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY d, type ORDER BY d", $since ), ARRAY_A );
		$top    = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, SUM(type = 'open') AS opens, SUM(type = 'quiz') AS quizzes, SUM(type = 'cart') AS carts FROM {$table} WHERE created_at >= %s AND product_id > 0 GROUP BY product_id ORDER BY opens DESC, quizzes DESC LIMIT 5", $since ), ARRAY_A );
		// phpcs:enable
		$out = array_fill_keys( self::TYPES, 0 );
		foreach ( (array) $totals as $row ) {
			$out[ $row['type'] ] = (int) $row['n'];
		}
		$series = array();
		for ( $i = (int) $days - 1; $i >= 0; $i-- ) {
			$d            = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[ $d ] = array(
				'date' => $d,
				'open' => 0,
				'quiz' => 0,
				'cart' => 0,
			);
		}
		foreach ( (array) $daily as $row ) {
			if ( isset( $series[ $row['d'] ] ) && isset( $series[ $row['d'] ][ $row['type'] ] ) ) {
				$series[ $row['d'] ][ $row['type'] ] = (int) $row['n'];
			}
		}
		$products = array();
		foreach ( (array) $top as $row ) {
			$products[] = array(
				'product_id' => (int) $row['product_id'],
				'name'       => (string) get_the_title( (int) $row['product_id'] ),
				'opens'      => (int) $row['opens'],
				'quizzes'    => (int) $row['quizzes'],
				'carts'      => (int) $row['carts'],
			);
		}
		return array(
			'totals'       => $out,
			'daily'        => array_values( $series ),
			'top_products' => $products,
			'cart_rate'    => $out['recommend'] > 0 ? round( 100 * $out['cart'] / $out['recommend'], 1 ) : 0,
		);
	}

	/**
	 * Deletes events older than ~13 months (daily cron).
	 */
	public static function prune() {
		global $wpdb;
		$table = Tables::events();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
	}
}
