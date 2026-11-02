<?php
/**
 * Measurement parsing, formatting and cm/in, kg/lb conversion. Stored data is always cm / kg.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Charts;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers (no WordPress state).
 */
class Units {

	const CM_PER_IN = 2.54;
	const LB_PER_KG = 2.20462;

	/**
	 * Parses "86", "86.5", "86-91", "86 – 91", "86 to 91", "34,5" into [ min, max ] or null.
	 *
	 * @param mixed $value Cell value.
	 * @return array|null
	 */
	public static function parse_range( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return array( (float) $value, (float) $value );
		}
		$text = strtolower( trim( (string) $value ) );
		if ( '' === $text ) {
			return null;
		}
		$text = str_replace( array( "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92", ' to ', '~', '/' ), '-', $text );
		$text = preg_replace( '/(\d),(\d)/', '$1.$2', $text );
		if ( ! preg_match_all( '/\d+(?:\.\d+)?/', $text, $m ) ) {
			return null;
		}
		$nums = array_map( 'floatval', $m[0] );
		if ( count( $nums ) > 2 ) {
			return null;
		}
		$min = min( $nums );
		$max = max( $nums );
		return array( $min, $max );
	}

	/**
	 * Normalised storage string for a range ("86-91" or "86.5").
	 *
	 * @param array $range  [ min, max ].
	 * @param int   $digits Decimals kept (2 for values entered in cm, 1 after a unit conversion).
	 * @return string
	 */
	public static function to_cell( array $range, $digits = 2 ) {
		$a = self::trim_number( round( $range[0], $digits ) );
		$b = self::trim_number( round( $range[1], $digits ) );
		return $a === $b ? $a : $a . '-' . $b;
	}

	/**
	 * Normalises a measurement cell given in a unit into a stored (cm/kg) cell. '' when not a number.
	 *
	 * @param mixed  $value    Raw.
	 * @param string $unit     cm|in|mm|kg|lb.
	 * @return string
	 */
	public static function normalize_cell( $value, $unit = 'cm' ) {
		$range = self::parse_range( $value );
		if ( ! $range ) {
			return '';
		}
		$factor = self::factor_to_base( $unit );
		return self::to_cell( array( $range[0] * $factor, $range[1] * $factor ), 1.0 === $factor ? 2 : 1 );
	}

	/**
	 * Multiplier from a unit to the base unit (cm or kg).
	 *
	 * @param string $unit Unit.
	 * @return float
	 */
	public static function factor_to_base( $unit ) {
		switch ( strtolower( (string) $unit ) ) {
			case 'in':
			case 'inch':
			case 'inches':
				return self::CM_PER_IN;
			case 'mm':
				return 0.1;
			case 'lb':
			case 'lbs':
				return 1 / self::LB_PER_KG;
			default:
				return 1.0;
		}
	}

	/**
	 * Converts a base value (cm or kg) into the display unit.
	 *
	 * @param float  $value    Base value.
	 * @param string $quantity length|weight.
	 * @param string $system   metric|imperial.
	 * @return float
	 */
	public static function from_base( $value, $quantity, $system ) {
		if ( 'imperial' !== $system ) {
			return round( (float) $value, 2 );
		}
		if ( 'weight' === $quantity ) {
			return round( $value * self::LB_PER_KG );
		}
		return round( ( $value / self::CM_PER_IN ) * 2 ) / 2;
	}

	/**
	 * Display unit label.
	 *
	 * @param string $quantity length|weight.
	 * @param string $system   metric|imperial.
	 * @return string
	 */
	public static function unit_label( $quantity, $system ) {
		if ( 'weight' === $quantity ) {
			return 'imperial' === $system ? 'lb' : 'kg';
		}
		return 'imperial' === $system ? 'in' : 'cm';
	}

	/**
	 * Formats a stored cell for display ("86–91" / "34–36").
	 *
	 * @param string $cell     Stored cell.
	 * @param string $quantity length|weight.
	 * @param string $system   metric|imperial.
	 * @return string
	 */
	public static function format_cell( $cell, $quantity, $system ) {
		$range = self::parse_range( $cell );
		if ( ! $range ) {
			return (string) $cell;
		}
		$a = self::trim_number( self::from_base( $range[0], $quantity, $system ) );
		$b = self::trim_number( self::from_base( $range[1], $quantity, $system ) );
		return $a === $b ? $a : $a . "\xE2\x80\x93" . $b;
	}

	/**
	 * Formats a single base value with its unit ("98 cm").
	 *
	 * @param float  $value    Base value.
	 * @param string $quantity length|weight.
	 * @param string $system   metric|imperial.
	 * @return string
	 */
	public static function format_value( $value, $quantity, $system ) {
		$shown = 'imperial' === $system ? self::from_base( $value, $quantity, $system ) : round( (float) $value, 1 );
		return self::trim_number( $shown ) . ' ' . self::unit_label( $quantity, $system );
	}

	/**
	 * "86.0" → "86", "34.50" → "34.5".
	 *
	 * @param float $number Number.
	 * @return string
	 */
	public static function trim_number( $number ) {
		$s = number_format( (float) $number, 2, '.', '' );
		return rtrim( rtrim( $s, '0' ), '.' );
	}

	/**
	 * Default display system for a country code ("auto" setting).
	 *
	 * @param string $country ISO country.
	 * @return string metric|imperial
	 */
	public static function system_for_country( $country ) {
		return in_array( strtoupper( (string) $country ), array( 'US', 'LR', 'MM' ), true ) ? 'imperial' : 'metric';
	}
}
