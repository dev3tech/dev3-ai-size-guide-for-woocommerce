<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3sg_settings_defaults / dev3sg_settings_sanitize.
 */
class Settings {

	const POSITIONS = array( 'after_cart', 'before_cart', 'after_price', 'after_summary', 'none' );

	/**
	 * Shopper-facing label: the admin's text, or the translated default (defaults are resolved late so
	 * reading settings before `init` never loads translations too early).
	 *
	 * @param string $key button_label|quiz_label|tab_label.
	 * @return string
	 */
	public static function label( $key ) {
		$value = (string) \Dev3\AiSizeGuide\Core3\Core\Settings_Store::get( $key, '' );
		if ( '' !== trim( $value ) ) {
			return $value;
		}
		return 'quiz_label' === $key ? __( 'What size am I?', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Size guide', 'dev3-ai-size-guide-for-woocommerce' );
	}

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'display_mode'        => 'both',
				'button_position'     => 'after_cart',
				'button_label'        => '',
				'quiz_label'          => '',
				'tab_label'           => '',
				'default_unit'        => 'auto',
				'accent_color'        => '#1d4ed8',
				'require_size_attr'   => false,
				'show_quiz'           => true,
				'quiz_fit_pref'       => true,
				'quiz_usual'          => true,
				'estimates'           => true,
				'save_measurements'   => true,
				'ai_explain'          => true,
				'size_attributes'     => 'pa_size, size',
				'capture_refunds'     => true,
				'learn_days'          => 180,
				'learn_min_sample'    => 5,
				'learn_threshold'     => 60,
				'analytics'           => true,
				'delete_on_uninstall' => false,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		$choices = array(
			'display_mode'    => array( 'popup', 'tab', 'both' ),
			'button_position' => self::POSITIONS,
			'default_unit'    => array( 'auto', 'cm', 'in' ),
		);
		foreach ( $choices as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		foreach ( array( 'require_size_attr', 'show_quiz', 'quiz_fit_pref', 'quiz_usual', 'estimates', 'save_measurements', 'ai_explain', 'capture_refunds', 'analytics', 'delete_on_uninstall' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		foreach ( array( 'button_label', 'quiz_label', 'tab_label' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( wp_unslash( (string) $input[ $key ] ) ), 0, 40 );
			}
		}
		if ( isset( $input['accent_color'] ) ) {
			$color = sanitize_hex_color( (string) $input['accent_color'] );
			if ( $color ) {
				$out['accent_color'] = $color;
			}
		}
		if ( isset( $input['size_attributes'] ) ) {
			$names                  = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $input['size_attributes'] ) ) ) );
			$out['size_attributes'] = $names ? implode( ', ', array_slice( $names, 0, 10 ) ) : 'pa_size, size';
		}
		$ranges = array(
			'learn_days'       => array( 7, 730 ),
			'learn_min_sample' => array( 1, 500 ),
			'learn_threshold'  => array( 50, 100 ),
		);
		foreach ( $ranges as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}
		return $out;
	}
}
