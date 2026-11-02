<?php
/**
 * "What size am I?" maths: matches shopper measurements against a chart's size rows. No AI.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Fit;

use Dev3\AiSizeGuide\Charts\Measures;
use Dev3\AiSizeGuide\Charts\Units;

defined( 'ABSPATH' ) || exit;

/**
 * Fit engine.
 */
class Engine {

	const EASE = array(
		'snug'    => -1.5,
		'regular' => 0.0,
		'relaxed' => 2.5,
	);

	/**
	 * Estimates girths (cm) from height (cm) and weight (kg) for a body profile.
	 *
	 * @param float  $height  Height cm.
	 * @param float  $weight  Weight kg.
	 * @param string $profile women|men|unisex|kids.
	 * @return array measure => cm
	 */
	public static function estimate( $height, $weight, $profile ) {
		$h   = (float) $height;
		$w   = (float) $weight;
		$men = array(
			'chest' => 30 + 0.65 * $w + 0.11 * $h,
			'waist' => 3 + 0.9 * $w + 0.08 * $h,
			'hips'  => 3 + 0.55 * $w + 0.3 * $h,
		);
		$wom = array(
			'bust'  => 28 + 0.7 * $w + 0.12 * $h,
			'waist' => 4 + 0.85 * $w + 0.1 * $h,
			'hips'  => 5 + 0.7 * $w + 0.3 * $h,
		);
		switch ( $profile ) {
			case 'men':
				$out = $men + array( 'bust' => $men['chest'] );
				break;
			case 'women':
				$out = $wom + array( 'chest' => $wom['bust'] );
				break;
			case 'kids':
				$out = array(
					'chest' => 12 + 0.3 * $h + 0.6 * $w,
					'bust'  => 12 + 0.3 * $h + 0.6 * $w,
					'waist' => 15 + 0.2 * $h + 0.8 * $w,
					'hips'  => 14 + 0.32 * $h + 0.7 * $w,
				);
				break;
			default:
				$out = array(
					'chest' => ( $men['chest'] + $wom['bust'] ) / 2,
					'bust'  => ( $men['chest'] + $wom['bust'] ) / 2,
					'waist' => ( $men['waist'] + $wom['waist'] ) / 2,
					'hips'  => ( $men['hips'] + $wom['hips'] ) / 2,
				);
		}
		/**
		 * Filters estimated measurements.
		 *
		 * @param array  $out     measure => cm.
		 * @param float  $h       Height cm.
		 * @param float  $w       Weight kg.
		 * @param string $profile Profile.
		 */
		return apply_filters( 'dev3sg_estimates', array_map( static fn( $v ) => round( $v, 1 ), $out ), $h, $w, $profile );
	}

	/**
	 * Converts shopper input to base units and drops nonsense values.
	 *
	 * @param array  $measurements measure => value (display unit).
	 * @param string $system       metric|imperial.
	 * @return array measure => base value
	 */
	public static function to_base( array $measurements, $system ) {
		$out = array();
		foreach ( $measurements as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! Measures::exists( $key ) || ! is_numeric( $value ) ) {
				continue;
			}
			$def = Measures::get( $key );
			$v   = (float) $value;
			if ( 'imperial' === $system ) {
				$v = 'weight' === $def['quantity'] ? $v / Units::LB_PER_KG : $v * Units::CM_PER_IN;
			}
			if ( $v > 0 && $v < 400 ) {
				$out[ $key ] = round( $v, 2 );
			}
		}
		return $out;
	}

	/**
	 * Recommends a size.
	 *
	 * @param array $chart Chart (Charts::get shape).
	 * @param array $input { measurements{}, unit metric|imperial, fit, usual{col_key: value}, table }.
	 * @param array $opts  { estimates: bool }.
	 * @return array|\WP_Error
	 */
	public static function recommend( array $chart, array $input, array $opts = array() ) {
		$system = isset( $input['unit'] ) && 'imperial' === $input['unit'] ? 'imperial' : 'metric';
		$fit    = isset( $input['fit'] ) && isset( self::EASE[ $input['fit'] ] ) ? $input['fit'] : 'regular';
		$given  = self::to_base( isset( $input['measurements'] ) && is_array( $input['measurements'] ) ? $input['measurements'] : array(), $system );
		$usual  = isset( $input['usual'] ) && is_array( $input['usual'] ) ? $input['usual'] : array();
		$allow  = ! isset( $opts['estimates'] ) || $opts['estimates'];

		// Chest and bust are the same body measure for matching purposes.
		if ( isset( $given['chest'] ) && ! isset( $given['bust'] ) ) {
			$given['bust'] = $given['chest'];
		} elseif ( isset( $given['bust'] ) && ! isset( $given['chest'] ) ) {
			$given['chest'] = $given['bust'];
		}

		$table = self::pick_table( $chart, $given, $usual, isset( $input['table'] ) ? (string) $input['table'] : '' );
		if ( ! $table ) {
			return new \WP_Error( 'dev3sg_fit_no_input', __( 'Enter at least one measurement from the size chart.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$size_col = self::size_col( $table );

		// Estimates for girths the table needs and the shopper did not give.
		$estimated = array();
		if ( $allow && isset( $given['height'], $given['weight'] ) ) {
			$est = self::estimate( $given['height'], $given['weight'], $chart['profile'] ?? 'unisex' );
			foreach ( $table['columns'] as $col ) {
				$m = $col['measure'];
				if ( 'measure' === $col['kind'] && isset( $est[ $m ] ) && ! isset( $given[ $m ] ) ) {
					$given[ $m ]     = $est[ $m ];
					$estimated[ $m ] = true;
				}
			}
		}

		// Columns we can score.
		$cols = array();
		foreach ( $table['columns'] as $c => $col ) {
			if ( 'measure' !== $col['kind'] || ! isset( $given[ $col['measure'] ] ) ) {
				continue;
			}
			$def    = Measures::get( $col['measure'] );
			$ranges = array();
			foreach ( $table['rows'] as $r => $row ) {
				$ranges[ $r ] = Units::parse_range( $row[ $c ] ?? '' );
			}
			$value = (float) $given[ $col['measure'] ];
			if ( $def['girth'] ) {
				$value += self::EASE[ $fit ];
			}
			$cols[] = array(
				'index'     => $c,
				'col'       => $col,
				'def'       => $def,
				'raw'       => (float) $given[ $col['measure'] ],
				'value'     => $value,
				'ranges'    => $ranges,
				'step'      => self::step( $ranges ),
				'distinct'  => self::discriminates( $ranges ),
				'weight'    => max( 0.2, (float) $def['weight'] ) * ( isset( $estimated[ $col['measure'] ] ) ? 0.6 : 1.0 ),
				'estimated' => isset( $estimated[ $col['measure'] ] ),
			);
		}

		$usual_row = self::usual_row( $chart, $table, $usual );
		if ( ! $cols && null === $usual_row ) {
			return new \WP_Error( 'dev3sg_fit_no_input', __( 'Enter at least one measurement from the size chart.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}

		// Score every row.
		$scores = array();
		foreach ( $table['rows'] as $r => $row ) {
			if ( '' === (string) ( $row[ $size_col ] ?? '' ) ) {
				continue;
			}
			$sum  = 0.0;
			$wsum = 0.0;
			foreach ( $cols as $col ) {
				$range = $col['ranges'][ $r ];
				if ( ! $range || ! $col['distinct'] ) {
					continue;
				}
				$sum  += $col['weight'] * self::distance( $col['value'], $range, $col['step'] );
				$wsum += $col['weight'];
			}
			$score = $wsum > 0 ? $sum / $wsum : 0.0;
			if ( null !== $usual_row ) {
				$score += $r === $usual_row ? ( $wsum > 0 ? -0.5 : -1.0 ) : ( $wsum > 0 ? 0.0 : 1.0 + abs( $r - $usual_row ) * 0.1 );
			}
			$scores[ $r ] = $score;
		}
		if ( ! $scores ) {
			return new \WP_Error( 'dev3sg_fit_empty', __( 'This size chart has no sizes yet.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$order = array_keys( $scores );
		usort(
			$order,
			static function ( $a, $b ) use ( $scores, $fit ) {
				$d = $scores[ $a ] - $scores[ $b ];
				if ( abs( $d ) > 0.001 ) {
					return $d < 0 ? -1 : 1;
				}
				return 'snug' === $fit ? $a - $b : $b - $a;
			}
		);
		$best       = $order[0];
		$best_score = max( 0.0, $scores[ $best ] );
		$rows       = array_keys( $scores );
		$pos        = array_search( $best, $rows, true );

		// Between two sizes?
		$alt      = null;
		$verdicts = self::verdicts( $cols, $best );
		$above    = in_array( 'above', array_column( $verdicts, 'status' ), true );
		$below    = in_array( 'below', array_column( $verdicts, 'status' ), true );
		if ( $above && isset( $rows[ $pos + 1 ] ) && $scores[ $rows[ $pos + 1 ] ] <= $scores[ $best ] + 0.6 ) {
			$alt = $rows[ $pos + 1 ];
		} elseif ( $below && ! $above && $pos > 0 && $scores[ $rows[ $pos - 1 ] ] <= $scores[ $best ] + 0.6 ) {
			$alt = $rows[ $pos - 1 ];
		} elseif ( isset( $order[1] ) && $scores[ $order[1] ] - $scores[ $best ] < 0.25 && 1 === abs( array_search( $order[1], $rows, true ) - $pos ) ) {
			$alt = $order[1];
		}

		// Learned bias from returns (+1 = this style runs small: one size up).
		$bias   = (int) ( $chart['bias'] ?? 0 );
		$chosen = $best;
		if ( 0 !== $bias ) {
			$new_pos = max( 0, min( count( $rows ) - 1, $pos + $bias ) );
			$chosen  = $rows[ $new_pos ];
			if ( $chosen !== $best ) {
				$alt      = $best;
				$verdicts = self::verdicts( $cols, $chosen );
			}
		}

		$real = 0;
		foreach ( $cols as $col ) {
			if ( ! $col['estimated'] && $col['distinct'] ) {
				++$real;
			}
		}
		$scorable = 0;
		foreach ( $table['columns'] as $c => $col ) {
			if ( 'measure' === $col['kind'] && self::discriminates( array_map( static fn( $row ) => Units::parse_range( $row[ $c ] ?? '' ), $table['rows'] ) ) ) {
				++$scorable;
			}
		}
		if ( $best_score <= 0.25 && ( $real >= min( 2, max( 1, $scorable ) ) || ( null !== $usual_row && $best === $usual_row && $real >= 1 ) ) ) {
			$confidence = 'high';
		} elseif ( $best_score <= 0.8 && ( $real >= 1 || null !== $usual_row ) ) {
			$confidence = 'medium';
		} else {
			$confidence = 'low';
		}
		$pct = (int) round( 100 * exp( -$best_score ) * ( $estimated ? 0.85 : 1 ) * ( 'low' === $confidence ? 0.8 : 1 ) );

		// Outside the chart?
		$out_of_range = '';
		foreach ( $cols as $col ) {
			$ranges = array_filter( $col['ranges'] );
			if ( ! $ranges || ! $col['distinct'] ) {
				continue;
			}
			$max = max( array_map( static fn( $r ) => $r[1], $ranges ) );
			$min = min( array_map( static fn( $r ) => $r[0], $ranges ) );
			if ( $col['value'] > $max + $col['step'] ) {
				$out_of_range = 'above';
			} elseif ( $col['value'] < $min - $col['step'] ) {
				$out_of_range = 'below';
			}
		}

		$result                = array(
			'ok'           => true,
			'chart_id'     => (int) ( $chart['id'] ?? 0 ),
			'table_id'     => $table['id'],
			'row'          => $chosen,
			'size'         => (string) $table['rows'][ $chosen ][ $size_col ],
			'alternative'  => null !== $alt ? (string) $table['rows'][ $alt ][ $size_col ] : '',
			'alt_dir'      => null !== $alt ? ( array_search( $alt, $rows, true ) > array_search( $chosen, $rows, true ) ? 'larger' : 'smaller' ) : '',
			'confidence'   => $confidence,
			'score'        => $pct,
			'estimated'    => (bool) $estimated,
			'measures'     => $verdicts,
			'fit'          => $fit,
			'bias'         => $bias,
			'bias_applied' => $chosen !== $best,
			'usual_match'  => null !== $usual_row,
			'out_of_range' => $out_of_range,
			'fit_note'     => (string) ( $chart['fit_note'] ?? '' ),
			'unit'         => $system,
		);
		$result['explanation'] = Explainer::template( $result );
		/**
		 * Filters a fit recommendation.
		 *
		 * @param array $result Result.
		 * @param array $chart  Chart.
		 * @param array $input  Shopper input.
		 */
		return apply_filters( 'dev3sg_fit_result', $result, $chart, $input );
	}

	/**
	 * Body table that best matches the given measures (or a requested one).
	 *
	 * @param array  $chart  Chart.
	 * @param array  $given  Base measurements.
	 * @param array  $usual  Usual-size answers.
	 * @param string $wanted Table id.
	 * @return array|null
	 */
	private static function pick_table( array $chart, array $given, array $usual, $wanted ) {
		$best       = null;
		$best_score = 0;
		$can_est    = isset( $given['height'], $given['weight'] );
		foreach ( (array) ( $chart['tables'] ?? array() ) as $table ) {
			if ( '' !== $wanted && $table['id'] === $wanted ) {
				return $table;
			}
			if ( 'garment' === ( $table['type'] ?? 'body' ) || ! $table['rows'] ) {
				continue;
			}
			$score = 0;
			foreach ( $table['columns'] as $col ) {
				if ( 'measure' === $col['kind'] && ( isset( $given[ $col['measure'] ] ) || ( $can_est && in_array( $col['measure'], array( 'chest', 'bust', 'waist', 'hips' ), true ) ) ) ) {
					$score += isset( $given[ $col['measure'] ] ) ? 2 : 1;
				}
				if ( 'text' === $col['kind'] && isset( $usual[ $col['key'] ] ) && '' !== (string) $usual[ $col['key'] ] ) {
					++$score;
				}
			}
			if ( $score > $best_score ) {
				$best       = $table;
				$best_score = $score;
			}
		}
		if ( ! $best && $usual ) {
			foreach ( (array) ( $chart['tables'] ?? array() ) as $table ) {
				if ( 'garment' !== ( $table['type'] ?? 'body' ) && $table['rows'] ) {
					return $table;
				}
			}
		}
		return $best;
	}

	/**
	 * Index of the size column.
	 *
	 * @param array $table Table.
	 * @return int
	 */
	public static function size_col( array $table ) {
		foreach ( $table['columns'] as $c => $col ) {
			if ( 'size' === $col['kind'] ) {
				return $c;
			}
		}
		return 0;
	}

	/**
	 * Row of the chosen table that matches "the size I usually wear in <system>" (any table, mapped by size label).
	 *
	 * @param array $chart Chart.
	 * @param array $table Chosen table.
	 * @param array $usual col_key => value.
	 * @return int|null
	 */
	private static function usual_row( array $chart, array $table, array $usual ) {
		$usual = array_filter( array_map( static fn( $v ) => strtolower( trim( (string) $v ) ), $usual ), 'strlen' );
		if ( ! $usual ) {
			return null;
		}
		$size_col = self::size_col( $table );
		foreach ( (array) $chart['tables'] as $t ) {
			$t_size = self::size_col( $t );
			foreach ( $t['columns'] as $c => $col ) {
				if ( ! isset( $usual[ $col['key'] ] ) || 'measure' === $col['kind'] ) {
					continue;
				}
				foreach ( $t['rows'] as $row ) {
					if ( self::label_matches( (string) ( $row[ $c ] ?? '' ), $usual[ $col['key'] ] ) ) {
						$label = strtolower( (string) $row[ $t_size ] );
						foreach ( $table['rows'] as $r => $own ) {
							if ( strtolower( (string) $own[ $size_col ] ) === $label ) {
								return $r;
							}
						}
					}
				}
			}
		}
		return null;
	}

	/**
	 * "4-6" matches "4" and "6"; "M" matches "m"; "9.5" matches "9,5".
	 *
	 * @param string $cell  Cell.
	 * @param string $value Shopper value (lowercase).
	 * @return bool
	 */
	public static function label_matches( $cell, $value ) {
		$cell  = strtolower( trim( $cell ) );
		$value = str_replace( ',', '.', $value );
		if ( '' === $cell ) {
			return false;
		}
		if ( str_replace( ',', '.', $cell ) === $value ) {
			return true;
		}
		$parts = preg_split( '/\s*[-–\/]\s*/u', $cell );
		return count( $parts ) > 1 && in_array( $value, array_map( static fn( $p ) => str_replace( ',', '.', $p ), $parts ), true );
	}

	/**
	 * Typical step between sizes for a column (median midpoint difference), min 0.1.
	 *
	 * @param array $ranges Row ranges.
	 * @return float
	 */
	private static function step( array $ranges ) {
		$mids = array();
		foreach ( $ranges as $r ) {
			if ( $r ) {
				$mids[] = ( $r[0] + $r[1] ) / 2;
			}
		}
		$diffs = array();
		$count = count( $mids );
		for ( $i = 1; $i < $count; $i++ ) {
			$d = abs( $mids[ $i ] - $mids[ $i - 1 ] );
			if ( $d > 0 ) {
				$diffs[] = $d;
			}
		}
		if ( ! $diffs ) {
			return 1.0;
		}
		sort( $diffs );
		return max( 0.1, $diffs[ (int) floor( count( $diffs ) / 2 ) ] );
	}

	/**
	 * A column only helps when its values differ between sizes.
	 *
	 * @param array $ranges Ranges.
	 * @return bool
	 */
	private static function discriminates( array $ranges ) {
		$seen = array();
		foreach ( $ranges as $r ) {
			if ( $r ) {
				$seen[ $r[0] . '-' . $r[1] ] = true;
			}
		}
		return count( $seen ) > 1;
	}

	/**
	 * Distance outside a range in steps (0 inside). Single values count as ±half a step.
	 *
	 * @param float $v     Value.
	 * @param array $range [ min, max ].
	 * @param float $step  Step.
	 * @return float
	 */
	private static function distance( $v, array $range, $step ) {
		list( $lo, $hi ) = self::effective( $range, $step );
		if ( $v < $lo ) {
			return ( $lo - $v ) / $step;
		}
		if ( $v > $hi ) {
			return ( $v - $hi ) / $step;
		}
		return 0.0;
	}

	/**
	 * Point values widen to ±half a step.
	 *
	 * @param array $range Range.
	 * @param float $step  Step.
	 * @return array
	 */
	private static function effective( array $range, $step ) {
		if ( abs( $range[1] - $range[0] ) < 0.0001 ) {
			return array( $range[0] - $step / 2, $range[1] + $step / 2 );
		}
		return $range;
	}

	/**
	 * Per-measure verdicts for a row.
	 *
	 * @param array $cols Scored columns.
	 * @param int   $row  Row.
	 * @return array
	 */
	private static function verdicts( array $cols, $row ) {
		$out = array();
		foreach ( $cols as $col ) {
			$range = $col['ranges'][ $row ];
			if ( ! $range ) {
				continue;
			}
			list( $lo, $hi ) = self::effective( $range, $col['step'] );
			$status          = 'within';
			$delta           = 0.0;
			if ( $col['value'] > $hi ) {
				$status = 'above';
				$delta  = $col['value'] - $hi;
			} elseif ( $col['value'] < $lo ) {
				$status = 'below';
				$delta  = $lo - $col['value'];
			}
			$out[] = array(
				'measure'   => $col['col']['measure'],
				'label'     => $col['col']['label'],
				'quantity'  => $col['def']['quantity'],
				'value'     => round( $col['raw'], 1 ),
				'min'       => $range[0],
				'max'       => $range[1],
				'status'    => $status,
				'delta'     => round( $delta, 1 ),
				'estimated' => $col['estimated'],
				'scored'    => $col['distinct'],
			);
		}
		return $out;
	}
}
