<?php
/**
 * Plain-language explanation of a fit result: a template (always) and an optional AI rewrite.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Fit;

use Dev3\AiSizeGuide\Charts\Units;
use Dev3\AiSizeGuide\Core3\Api\Prompts;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Explanations.
 */
class Explainer {

	/**
	 * Template explanation (no AI), in the shopper's unit system.
	 *
	 * @param array $result Engine result.
	 * @return string
	 */
	public static function template( array $result ) {
		$system = 'imperial' === ( $result['unit'] ?? 'metric' ) ? 'imperial' : 'metric';
		$size   = (string) $result['size'];
		/* translators: %s: size label. */
		$parts  = array( sprintf( __( 'Size %s is your best match.', 'dev3-ai-size-guide-for-woocommerce' ), $size ) );
		$within = array();
		foreach ( (array) $result['measures'] as $m ) {
			$label = strtolower( (string) $m['label'] );
			$value = Units::format_value( $m['value'], $m['quantity'], $system );
			if ( 'within' === $m['status'] ) {
				$within[] = sprintf( '%1$s (%2$s)', $label, $value );
			} elseif ( 'above' === $m['status'] ) {
				/* translators: 1: measure, 2: value, 3: difference, 4: size. */
				$parts[] = sprintf( __( 'Your %1$s (%2$s) is %3$s above the %4$s range, so it may feel snug there.', 'dev3-ai-size-guide-for-woocommerce' ), $label, $value, Units::format_value( max( $m['delta'], 'imperial' === $system ? 1.27 : 0.5 ), $m['quantity'], $system ), $size );
			} else {
				/* translators: 1: measure, 2: value, 3: difference, 4: size. */
				$parts[] = sprintf( __( 'Your %1$s (%2$s) is %3$s below the %4$s range, so it may feel roomy there.', 'dev3-ai-size-guide-for-woocommerce' ), $label, $value, Units::format_value( max( $m['delta'], 'imperial' === $system ? 1.27 : 0.5 ), $m['quantity'], $system ), $size );
			}
		}
		if ( $within ) {
			/* translators: 1: list of measures, 2: size. */
			array_splice( $parts, 1, 0, array( sprintf( _n( 'Your %1$s falls within size %2$s.', 'Your %1$s fall within size %2$s.', count( $within ), 'dev3-ai-size-guide-for-woocommerce' ), self::join( $within ), $size ) ) );
		}
		if ( ! empty( $result['usual_match'] ) && ! $result['measures'] ) {
			$parts[] = __( 'It matches the size you usually wear.', 'dev3-ai-size-guide-for-woocommerce' );
		}
		if ( ! empty( $result['bias_applied'] ) ) {
			$parts[] = (int) $result['bias'] > 0
				? __( 'Customers tell us this style runs small, so we suggest one size up.', 'dev3-ai-size-guide-for-woocommerce' )
				: __( 'Customers tell us this style runs large, so we suggest one size down.', 'dev3-ai-size-guide-for-woocommerce' );
		} elseif ( '' !== (string) $result['alternative'] ) {
			$alt    = (string) $result['alternative'];
			$larger = 'smaller' !== ( $result['alt_dir'] ?? 'larger' );
			if ( $larger && 'relaxed' === $result['fit'] ) {
				/* translators: 1: size, 2: alternative size. */
				$parts[] = sprintf( __( 'You are between %1$s and %2$s. You prefer a relaxed fit, so %2$s is also a good choice.', 'dev3-ai-size-guide-for-woocommerce' ), $size, $alt );
			} elseif ( $larger ) {
				/* translators: 1: size, 2: alternative size. */
				$parts[] = sprintf( __( 'You are between %1$s and %2$s: choose %2$s if you prefer more room.', 'dev3-ai-size-guide-for-woocommerce' ), $size, $alt );
			} else {
				/* translators: 1: size, 2: alternative size. */
				$parts[] = sprintf( __( 'You are between %1$s and %2$s: choose %2$s if you prefer a closer fit.', 'dev3-ai-size-guide-for-woocommerce' ), $size, $alt );
			}
		}
		if ( 'above' === $result['out_of_range'] ) {
			$parts[] = __( 'Your measurements are above our largest size, so this item may not fit well.', 'dev3-ai-size-guide-for-woocommerce' );
		} elseif ( 'below' === $result['out_of_range'] ) {
			$parts[] = __( 'Your measurements are below our smallest size, so this item may be loose.', 'dev3-ai-size-guide-for-woocommerce' );
		}
		if ( ! empty( $result['estimated'] ) ) {
			$parts[] = __( 'Some measurements were estimated from your height and weight. Measuring yourself gives a more precise result.', 'dev3-ai-size-guide-for-woocommerce' );
		}
		if ( '' !== (string) $result['fit_note'] ) {
			$parts[] = (string) $result['fit_note'];
		}
		return implode( ' ', $parts );
	}

	/**
	 * "a, b and c".
	 *
	 * @param string[] $items Items.
	 * @return string
	 */
	private static function join( array $items ) {
		if ( count( $items ) < 2 ) {
			return (string) reset( $items );
		}
		$last = array_pop( $items );
		/* translators: 1: comma-separated list, 2: last item. */
		return sprintf( __( '%1$s and %2$s', 'dev3-ai-size-guide-for-woocommerce' ), implode( ', ', $items ), $last );
	}

	/**
	 * AI explanation with a template fallback. Only the maths result is sent (no personal data).
	 *
	 * @param array  $result      Engine result.
	 * @param string $product     Product name (for context).
	 * @param string $locale      Shopper locale.
	 * @return array { text, source: ai|template, cached }
	 */
	public static function explain( array $result, $product = '', $locale = '' ) {
		$fallback = array(
			'text'   => self::template( $result ),
			'source' => 'template',
			'cached' => false,
		);
		if ( ! Settings_Store::get( 'ai_explain', true ) ) {
			return $fallback;
		}
		$system   = 'imperial' === ( $result['unit'] ?? 'metric' ) ? 'imperial' : 'metric';
		$measures = array();
		foreach ( (array) $result['measures'] as $m ) {
			$measures[] = array(
				'measure'    => $m['label'],
				'shopper'    => Units::format_value( $m['value'], $m['quantity'], $system ),
				'size_range' => Units::format_cell( Units::to_cell( array( $m['min'], $m['max'] ) ), $m['quantity'], $system ) . ' ' . Units::unit_label( $m['quantity'], $system ),
				'status'     => $m['status'],
				'estimated'  => (bool) $m['estimated'],
			);
		}
		$facts = array(
			'product'          => mb_substr( (string) $product, 0, 120 ),
			'recommended_size' => $result['size'],
			'alternative_size' => $result['alternative'],
			'confidence'       => $result['confidence'],
			'fit_preference'   => $result['fit'],
			'measures'         => $measures,
			'runs'             => $result['bias_applied'] ? ( $result['bias'] > 0 ? 'small (moved one size up)' : 'large (moved one size down)' ) : 'true to size',
			'out_of_range'     => $result['out_of_range'],
			'store_fit_note'   => $result['fit_note'],
		);
		$ai    = Prompts::run(
			'explain_fit',
			array(
				'facts'  => $facts,
				'locale' => '' !== $locale ? $locale : get_locale(),
			),
			'storefront'
		);
		if ( is_wp_error( $ai ) || empty( $ai['data']['text'] ) ) {
			return $fallback;
		}
		return array(
			'text'   => mb_substr( sanitize_textarea_field( (string) $ai['data']['text'] ), 0, 600 ),
			'source' => 'ai',
			'cached' => ! empty( $ai['cached'] ),
		);
	}
}
