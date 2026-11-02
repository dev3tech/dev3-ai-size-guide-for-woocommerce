<?php
/**
 * Returns-reason learning: per chart and size fit-return rates and "runs small / large" suggestions.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Learning;

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Insights.
 */
class Insights {

	const CACHE_KEY     = 'dev3sg_insights';
	const SOLD_KEY      = 'dev3sg_sold_units';
	const DISMISSED_OPT = 'dev3sg_dismissed';

	/**
	 * Settings with defaults.
	 *
	 * @return array { days, min_sample, threshold }
	 */
	public static function config() {
		return array(
			'days'       => max( 7, (int) Settings_Store::get( 'learn_days', 180 ) ),
			'min_sample' => max( 1, (int) Settings_Store::get( 'learn_min_sample', 5 ) ),
			'threshold'  => max( 50, min( 100, (int) Settings_Store::get( 'learn_threshold', 60 ) ) ) / 100,
		);
	}

	/**
	 * Clears cached numbers.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::SOLD_KEY );
	}

	/**
	 * Insights for every chart with records (cached 1 hour).
	 *
	 * @param bool $fresh Skip cache.
	 * @return array List of chart insights.
	 */
	public static function all( $fresh = false ) {
		$cached = $fresh ? false : get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return self::mark_dismissed( $cached );
		}
		$cfg     = self::config();
		$since   = gmdate( 'Y-m-d H:i:s', time() - $cfg['days'] * DAY_IN_SECONDS );
		$records = Returns::since( $since );
		$sold    = self::sold( $since );
		$charts  = array();
		foreach ( $records as $rec ) {
			$charts[ $rec['chart_id'] ][] = $rec;
		}
		$out = array();
		foreach ( $charts as $chart_id => $items ) {
			$chart = $chart_id ? Charts::get( $chart_id ) : null;
			if ( ! $chart ) {
				continue;
			}
			$out[] = self::for_chart( $chart, $items, isset( $sold[ $chart_id ] ) ? $sold[ $chart_id ] : array(), $cfg );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$d = (int) ! empty( $b['suggestion'] ) - (int) ! empty( $a['suggestion'] );
				return 0 !== $d ? $d : $b['fit_returns'] - $a['fit_returns'];
			}
		);
		set_transient( self::CACHE_KEY, $out, HOUR_IN_SECONDS );
		return self::mark_dismissed( $out );
	}

	/**
	 * Insight for one chart.
	 *
	 * @param array $chart   Chart.
	 * @param array $records Return records of this chart.
	 * @param array $sold    size => units sold.
	 * @param array $cfg     Config.
	 * @return array
	 */
	public static function for_chart( array $chart, array $records, array $sold, array $cfg ) {
		$bias_at = (int) ( $chart['bias_at'] ?? 0 );
		$sizes   = array();
		foreach ( Charts::sizes( $chart ) as $label ) {
			$sizes[ $label ] = self::blank_size( $label );
		}
		$tot = self::blank_size( '' );
		$cur = array(
			'too_small' => 0,
			'too_large' => 0,
		);
		foreach ( $records as $rec ) {
			$label = '' !== $rec['size'] ? $rec['size'] : '—';
			if ( ! isset( $sizes[ $label ] ) ) {
				$sizes[ $label ] = self::blank_size( $label );
			}
			$reason = in_array( $rec['reason'], array( 'too_small', 'too_large', 'fit_ok', 'other' ), true ) ? $rec['reason'] : 'unknown';
			++$sizes[ $label ][ $reason ];
			++$tot[ $reason ];
			// Only returns after the last bias change count towards a new suggestion.
			if ( isset( $cur[ $reason ] ) && strtotime( $rec['created_at'] ) > $bias_at ) {
				++$cur[ $reason ];
			}
		}
		foreach ( $sold as $label => $qty ) {
			if ( isset( $sizes[ $label ] ) ) {
				$sizes[ $label ]['sold'] = (int) $qty;
			}
			$tot['sold'] += (int) $qty;
		}
		foreach ( $sizes as $label => $row ) {
			$sizes[ $label ] = self::finish( $row, max( 1, (int) ceil( $cfg['min_sample'] / 2 ) ), $cfg['threshold'] );
		}
		$tot = self::finish( $tot, $cfg['min_sample'], $cfg['threshold'] );

		$fit        = $cur['too_small'] + $cur['too_large'];
		$suggestion = null;
		if ( $fit >= $cfg['min_sample'] ) {
			$share = max( $cur['too_small'], $cur['too_large'] ) / $fit;
			if ( $share >= $cfg['threshold'] ) {
				$dir      = $cur['too_small'] > $cur['too_large'] ? 1 : -1;
				$bias     = max( -2, min( 2, (int) $chart['bias'] + $dir ) );
				$fit_note = '';
				if ( $bias > 0 ) {
					$fit_note = __( 'This style runs small. We recommend ordering one size up.', 'dev3-ai-size-guide-for-woocommerce' );
				} elseif ( $bias < 0 ) {
					$fit_note = __( 'This style runs large. We recommend ordering one size down.', 'dev3-ai-size-guide-for-woocommerce' );
				}
				$suggestion = array(
					'direction' => $dir > 0 ? 'runs_small' : 'runs_large',
					'bias'      => $bias,
					'share'     => (int) round( 100 * $share ),
					'count'     => $fit,
					'fit_note'  => $fit_note,
					'signature' => ( $dir > 0 ? 'small' : 'large' ) . ':' . $fit,
					'message'   => $dir > 0
						/* translators: 1: percent, 2: count. */
						? sprintf( __( '%1$d%% of %2$d fit returns say "too small". Suggest one size up in the quiz.', 'dev3-ai-size-guide-for-woocommerce' ), (int) round( 100 * $share ), $fit )
						/* translators: 1: percent, 2: count. */
						: sprintf( __( '%1$d%% of %2$d fit returns say "too large". Suggest one size down in the quiz.', 'dev3-ai-size-guide-for-woocommerce' ), (int) round( 100 * $share ), $fit ),
				);
				if ( $bias === (int) $chart['bias'] ) {
					$suggestion = null; // Already at the limit.
				}
			}
		}
		return array(
			'chart_id'    => (int) $chart['id'],
			'chart_name'  => $chart['name'],
			'bias'        => (int) $chart['bias'],
			'fit_note'    => (string) $chart['fit_note'],
			'totals'      => $tot,
			'fit_returns' => $tot['too_small'] + $tot['too_large'],
			'sizes'       => array_values( $sizes ),
			'suggestion'  => $suggestion,
		);
	}

	/**
	 * Empty size row.
	 *
	 * @param string $label Size.
	 * @return array
	 */
	private static function blank_size( $label ) {
		return array(
			'size'      => (string) $label,
			'sold'      => 0,
			'too_small' => 0,
			'too_large' => 0,
			'fit_ok'    => 0,
			'other'     => 0,
			'unknown'   => 0,
		);
	}

	/**
	 * Adds rate + direction.
	 *
	 * @param array $row       Row.
	 * @param int   $min       Minimum sample.
	 * @param float $threshold Share.
	 * @return array
	 */
	private static function finish( array $row, $min, $threshold ) {
		$fit              = $row['too_small'] + $row['too_large'];
		$row['fit']       = $fit;
		$row['rate']      = $row['sold'] > 0 ? round( 100 * $fit / $row['sold'], 1 ) : null;
		$row['direction'] = 'true_to_size';
		if ( $fit >= $min ) {
			if ( $row['too_small'] / $fit >= $threshold ) {
				$row['direction'] = 'runs_small';
			} elseif ( $row['too_large'] / $fit >= $threshold ) {
				$row['direction'] = 'runs_large';
			}
		} elseif ( 0 === $fit ) {
			$row['direction'] = 'no_data';
		} else {
			$row['direction'] = 'too_few';
		}
		return $row;
	}

	/**
	 * Units sold per chart and size since a date (order line items; cached 6 hours).
	 *
	 * @param string $since MySQL GMT datetime.
	 * @return array chart_id => [ size => qty ]
	 */
	public static function sold( $since ) {
		$cached = get_transient( self::SOLD_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		for ( $page = 1; $page <= 10; $page++ ) {
			$orders = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => array( 'wc-completed', 'wc-processing', 'wc-refunded' ),
					'date_created' => '>=' . strtotime( $since . ' UTC' ),
					'limit'        => 200,
					'page'         => $page,
					'orderby'      => 'date',
					'order'        => 'DESC',
				)
			);
			if ( ! $orders ) {
				break;
			}
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Product ) {
						continue;
					}
					$size  = Sources::item_size( $item );
					$chart = Resolver::chart_id_for_product( $item->get_product_id() );
					if ( '' === $size || ! $chart ) {
						continue;
					}
					$out[ $chart ][ $size ] = ( $out[ $chart ][ $size ] ?? 0 ) + (int) $item->get_quantity();
				}
			}
			if ( count( $orders ) < 200 ) {
				break;
			}
		}
		set_transient( self::SOLD_KEY, $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Accepts a suggestion: sets the chart's fit bias (and optionally the shopper fit note).
	 *
	 * @param int         $chart_id Chart.
	 * @param int         $bias     New bias (-2…2).
	 * @param string|null $fit_note Note (null = keep).
	 * @return array|\WP_Error Updated chart.
	 */
	public static function apply( $chart_id, $bias, $fit_note = null ) {
		$chart = Charts::get( $chart_id );
		if ( ! $chart ) {
			return new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$chart['bias']    = max( -2, min( 2, (int) $bias ) );
		$chart['bias_at'] = time();
		if ( null !== $fit_note ) {
			$chart['fit_note'] = (string) $fit_note;
		}
		$saved = Charts::save( $chart, $chart_id );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::flush();
		$dismissed = (array) get_option( self::DISMISSED_OPT, array() );
		unset( $dismissed[ (int) $chart_id ] );
		update_option( self::DISMISSED_OPT, $dismissed, false );
		return Charts::get( $chart_id );
	}

	/**
	 * Dismisses the current suggestion of a chart (it comes back when more returns arrive).
	 *
	 * @param int    $chart_id  Chart.
	 * @param string $signature Suggestion signature.
	 */
	public static function dismiss( $chart_id, $signature ) {
		$items                    = (array) get_option( self::DISMISSED_OPT, array() );
		$items[ (int) $chart_id ] = sanitize_text_field( (string) $signature );
		update_option( self::DISMISSED_OPT, $items, false );
	}

	/**
	 * Hides dismissed suggestions until the count grows by the minimum sample.
	 *
	 * @param array $items Insights.
	 * @return array
	 */
	private static function mark_dismissed( array $items ) {
		$dismissed = (array) get_option( self::DISMISSED_OPT, array() );
		$min       = self::config()['min_sample'];
		foreach ( $items as $i => $row ) {
			if ( empty( $row['suggestion'] ) || ! isset( $dismissed[ $row['chart_id'] ] ) ) {
				continue;
			}
			list( $dir, $count ) = array_pad( explode( ':', (string) $dismissed[ $row['chart_id'] ] ), 2, 0 );
			if ( strpos( $row['suggestion']['signature'], $dir . ':' ) === 0 && $row['suggestion']['count'] < (int) $count + $min ) {
				$items[ $i ]['dismissed']  = $row['suggestion'];
				$items[ $i ]['suggestion'] = null;
			}
		}
		return $items;
	}

	/**
	 * Number of open suggestions.
	 *
	 * @return int
	 */
	public static function open_count() {
		return count( array_filter( self::all(), static fn( $r ) => ! empty( $r['suggestion'] ) ) );
	}
}
