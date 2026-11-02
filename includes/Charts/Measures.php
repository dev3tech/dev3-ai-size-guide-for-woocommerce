<?php
/**
 * Measurement dictionary: labels, quantity, whether fit ease applies and quiz weight.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Charts;

defined( 'ABSPATH' ) || exit;

/**
 * Known measures.
 */
class Measures {

	/**
	 * All measures: key => { label, quantity, girth, weight, help }.
	 *
	 * @return array
	 */
	public static function all() {
		$list = array(
			'chest'       => array( __( 'Chest', 'dev3-ai-size-guide-for-woocommerce' ), 'length', true, 1.5, __( 'Measure around the fullest part of your chest, under the arms, keeping the tape level.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'bust'        => array( __( 'Bust', 'dev3-ai-size-guide-for-woocommerce' ), 'length', true, 1.5, __( 'Measure around the fullest part of your bust, keeping the tape level across your back.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'underbust'   => array( __( 'Under-bust', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 1.5, __( 'Measure snugly around your ribcage, directly under the bust.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'waist'       => array( __( 'Waist', 'dev3-ai-size-guide-for-woocommerce' ), 'length', true, 1.2, __( 'Measure around your natural waistline, the narrowest part of your torso.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'hips'        => array( __( 'Hips', 'dev3-ai-size-guide-for-woocommerce' ), 'length', true, 1.2, __( 'Stand with feet together and measure around the fullest part of your hips.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'inseam'      => array( __( 'Inseam', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 1.0, __( 'Measure from the top of your inner thigh down to your ankle.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'height'      => array( __( 'Height', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 1.0, __( 'Stand straight against a wall without shoes.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'weight'      => array( __( 'Weight', 'dev3-ai-size-guide-for-woocommerce' ), 'weight', false, 0.8, '' ),
			'foot_length' => array( __( 'Foot length', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 2.0, __( 'Stand on paper, mark your heel and longest toe, and measure the distance.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'foot_width'  => array( __( 'Foot width', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 0.6, __( 'Measure across the widest part of your foot.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'neck'        => array( __( 'Neck', 'dev3-ai-size-guide-for-woocommerce' ), 'length', true, 1.0, __( 'Measure around the base of your neck, leaving one finger of room.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'sleeve'      => array( __( 'Sleeve', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 0.6, __( 'From the centre back of your neck, over the shoulder, to the wrist.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'shoulder'    => array( __( 'Shoulder', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 0.6, __( 'Across your back from one shoulder point to the other.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'thigh'       => array( __( 'Thigh', 'dev3-ai-size-guide-for-woocommerce' ), 'length', true, 0.8, __( 'Around the fullest part of your thigh.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'head'        => array( __( 'Head', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 2.0, __( 'Around your head, about 1 cm above the ears and eyebrows.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'hand'        => array( __( 'Hand', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 2.0, __( 'Around your knuckles, excluding the thumb.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'ring'        => array( __( 'Finger circumference', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 2.0, __( 'Wrap a strip of paper around the base of your finger and measure where it overlaps.', 'dev3-ai-size-guide-for-woocommerce' ) ),
			'length'      => array( __( 'Length', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 0.0, '' ),
			'width'       => array( __( 'Width', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 0.0, '' ),
			'custom'      => array( __( 'Custom', 'dev3-ai-size-guide-for-woocommerce' ), 'length', false, 0.0, '' ),
		);
		$out  = array();
		foreach ( $list as $key => $row ) {
			$out[ $key ] = array(
				'key'      => $key,
				'label'    => $row[0],
				'quantity' => $row[1],
				'girth'    => $row[2],
				'weight'   => $row[3],
				'help'     => $row[4],
			);
		}
		/**
		 * Filters the measurement dictionary.
		 *
		 * @param array $out Measures.
		 */
		return apply_filters( 'dev3sg_measures', $out );
	}

	/**
	 * One measure (or the custom one).
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $all['custom'];
	}

	/**
	 * Whether a key is known.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function exists( $key ) {
		return array_key_exists( (string) $key, self::all() );
	}

	/**
	 * Guesses a measure key from a column label ("Chest (cm)" → chest).
	 *
	 * @param string $label Label.
	 * @return string
	 */
	public static function guess( $label ) {
		$l   = strtolower( (string) $label );
		$map = array(
			'underbust'   => '/under ?-?bust|band|unterbrust/',
			'bust'        => '/bust|brust(?!umfang)|poitrine/',
			'chest'       => '/chest|brustumfang|pecho/',
			'waist'       => '/waist|taille|cintura|bund/',
			'hips'        => '/hip|h(ü|u)fte|hanche|cadera/',
			'inseam'      => '/inseam|inside leg|inner leg|schrittl/',
			'height'      => '/height|k(ö|o)rpergr|taille du corps|altura|stature/',
			'weight'      => '/weight|gewicht|poids|peso/',
			'foot_length' => '/foot|feet|fu(ß|ss)l|pied|pie\b|insole/',
			'neck'        => '/neck|collar|kragen|cou\b/',
			'sleeve'      => '/sleeve|arm ?length|(ä|a)rmel/',
			'shoulder'    => '/shoulder|schulter|(é|e)paule/',
			'thigh'       => '/thigh|oberschenkel|cuisse/',
			'head'        => '/head|kopf|t(ê|e)te/',
			'hand'        => '/hand|palm/',
			'ring'        => '/circumference|finger|umfang/',
			'length'      => '/length|l(ä|a)nge|longueur/',
			'width'       => '/width|breite|largeur/',
		);
		foreach ( $map as $key => $re ) {
			if ( preg_match( $re . 'u', $l ) ) {
				return $key;
			}
		}
		return 'custom';
	}
}
