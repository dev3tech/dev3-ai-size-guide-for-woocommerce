<?php
/**
 * Return records repository.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Learning;

use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Returns repository.
 */
class Returns {

	const SOURCES = array( 'refund', 'manual', 'order', 'api' );

	/**
	 * Adds a record. Records from refunds/orders/api are unique per (source, order, item): a repeat updates it.
	 *
	 * @param array $row { product_id, size, reason?, note, source, order_id, item_id, chart_id?, created_at? }.
	 * @return int|\WP_Error Record id.
	 */
	public static function add( array $row ) {
		global $wpdb;
		$table      = Tables::returns();
		$product_id = max( 0, (int) ( $row['product_id'] ?? 0 ) );
		$parent     = $product_id ? (int) wp_get_post_parent_id( $product_id ) : 0;
		if ( $parent && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = $parent;
		}
		if ( ! $product_id ) {
			return new \WP_Error( 'dev3sg_return_product', __( 'Choose a product.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$note   = mb_substr( sanitize_textarea_field( (string) ( $row['note'] ?? '' ) ), 0, 1000 );
		$reason = isset( $row['reason'] ) && in_array( $row['reason'], Classifier::REASONS, true ) ? $row['reason'] : Classifier::classify( $note );
		$source = isset( $row['source'] ) && in_array( $row['source'], self::SOURCES, true ) ? $row['source'] : 'manual';
		$chart  = isset( $row['chart_id'] ) && (int) $row['chart_id'] > 0 ? (int) $row['chart_id'] : Resolver::chart_id_for_product( $product_id );
		$size   = mb_substr( sanitize_text_field( (string) ( $row['size'] ?? '' ) ), 0, 40 );
		$def    = $chart ? \Dev3\AiSizeGuide\Charts\Charts::get( $chart ) : null;
		if ( $def ) {
			// Use the chart's own spelling of the size ("m" from a slug → "M").
			foreach ( \Dev3\AiSizeGuide\Charts\Charts::sizes( $def ) as $label ) {
				if ( 0 === strcasecmp( $label, $size ) ) {
					$size = $label;
					break;
				}
			}
		}
		$data   = array(
			'product_id' => $product_id,
			'chart_id'   => $chart,
			'order_id'   => max( 0, (int) ( $row['order_id'] ?? 0 ) ),
			'item_id'    => max( 0, (int) ( $row['item_id'] ?? 0 ) ),
			'size'       => $size,
			'reason'     => $reason,
			'source'     => $source,
			'note'       => $note,
			'created_at' => ! empty( $row['created_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $row['created_at'] ) ) : current_time( 'mysql', true ),
		);
		$format = array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'manual' !== $source && $data['order_id'] && $data['item_id'] ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND order_id = %d AND item_id = %d", $source, $data['order_id'], $data['item_id'] ) );
			if ( $existing ) {
				unset( $data['created_at'] );
				array_pop( $format );
				$wpdb->update( $table, $data, array( 'id' => $existing ), $format, array( '%d' ) );
				return $existing;
			}
		}
		$ok = $wpdb->insert( $table, $data, $format );
		// phpcs:enable
		if ( ! $ok ) {
			return new \WP_Error( 'dev3sg_return_db', __( 'Could not save the return record.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 500 ) );
		}
		delete_transient( Insights::CACHE_KEY );
		return (int) $wpdb->insert_id;
	}

	/**
	 * One record.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::returns();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Deletes a record (or a source/order/item record).
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		delete_transient( Insights::CACHE_KEY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( Tables::returns(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Deletes the record for an order item from a source.
	 *
	 * @param string $source  Source.
	 * @param int    $order   Order id.
	 * @param int    $item_id Item id.
	 */
	public static function delete_for_item( $source, $order, $item_id ) {
		global $wpdb;
		delete_transient( Insights::CACHE_KEY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			Tables::returns(),
			array(
				'source'   => $source,
				'order_id' => (int) $order,
				'item_id'  => (int) $item_id,
			),
			array( '%s', '%d', '%d' )
		);
	}

	/**
	 * Paged list.
	 *
	 * @param array $args { page, per_page, reason, chart_id, search, pending_ai }.
	 * @return array { items, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = Tables::returns();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['reason'] ) && in_array( $args['reason'], Classifier::REASONS, true ) ) {
			$where[]  = 'reason = %s';
			$params[] = $args['reason'];
		}
		if ( ! empty( $args['chart_id'] ) ) {
			$where[]  = 'chart_id = %d';
			$params[] = (int) $args['chart_id'];
		}
		if ( ! empty( $args['pending_ai'] ) ) {
			$where[] = "ai_reason <> '' AND ai_reason <> reason";
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(note LIKE %s OR size LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$per   = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sql_w = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$params[] = 1;
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_w} AND 1 = %d", $params ) );
		array_pop( $params );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Records since a date for a chart (or all).
	 *
	 * @param string $since    MySQL datetime (GMT).
	 * @param int    $chart_id Chart (0 = all).
	 * @return array
	 */
	public static function since( $since, $chart_id = 0 ) {
		global $wpdb;
		$table = Tables::returns();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $chart_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND chart_id = %d ORDER BY id DESC LIMIT 5000", $since, (int) $chart_id ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY id DESC LIMIT 5000", $since ), ARRAY_A );
		// phpcs:enable
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * Ids of records the keyword classifier could not place (for the AI job).
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	public static function unclassified_ids( $limit = 500 ) {
		global $wpdb;
		$table = Tables::returns();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE reason IN ('unknown','other') AND ai_reason = '' AND note <> '' ORDER BY id DESC LIMIT %d", (int) $limit ) ) );
	}

	/**
	 * Stores the AI suggestion (not applied until accepted).
	 *
	 * @param int    $id     Id.
	 * @param string $reason Reason.
	 */
	public static function set_ai_reason( $id, $reason ) {
		global $wpdb;
		if ( ! in_array( $reason, Classifier::REASONS, true ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Tables::returns(), array( 'ai_reason' => $reason ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Applies a reason (manual edit or accepted AI suggestion).
	 *
	 * @param int    $id     Id.
	 * @param string $reason Reason.
	 * @return bool
	 */
	public static function set_reason( $id, $reason ) {
		global $wpdb;
		if ( ! in_array( $reason, Classifier::REASONS, true ) ) {
			return false;
		}
		delete_transient( Insights::CACHE_KEY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update(
			Tables::returns(),
			array(
				'reason'    => $reason,
				'ai_reason' => '',
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Clears the AI suggestion (rejected).
	 *
	 * @param int $id Id.
	 */
	public static function reject_ai( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Tables::returns(), array( 'ai_reason' => 'rejected' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Count of fit returns in the last N days.
	 *
	 * @param int $days Days.
	 * @return array { total, fit }
	 */
	public static function counts( $days = 30 ) {
		global $wpdb;
		$table = Tables::returns();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(reason IN ('too_small','too_large')) AS fit FROM {$table} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS ) ), ARRAY_A );
		return array(
			'total' => (int) ( $row['total'] ?? 0 ),
			'fit'   => (int) ( $row['fit'] ?? 0 ),
		);
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		return array(
			'id'           => (int) $row['id'],
			'product_id'   => (int) $row['product_id'],
			'product_name' => (string) get_the_title( (int) $row['product_id'] ),
			'chart_id'     => (int) $row['chart_id'],
			'order_id'     => (int) $row['order_id'],
			'item_id'      => (int) $row['item_id'],
			'size'         => (string) $row['size'],
			'reason'       => (string) $row['reason'],
			'source'       => (string) $row['source'],
			'note'         => (string) $row['note'],
			'ai_reason'    => (string) $row['ai_reason'],
			'created_at'   => mysql_to_rfc3339( (string) $row['created_at'] ),
		);
	}
}
