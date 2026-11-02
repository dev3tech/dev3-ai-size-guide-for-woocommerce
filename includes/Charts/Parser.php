<?php
/**
 * Non-AI parser for pasted size tables (tab, semicolon, comma, pipe or space separated).
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Charts;

defined( 'ABSPATH' ) || exit;

/**
 * Paste → table.
 */
class Parser {

	/**
	 * Parses text into a chart table.
	 *
	 * @param string $text Pasted text.
	 * @param string $unit auto|cm|in|mm.
	 * @return array|\WP_Error { table, unit, warnings[] }
	 */
	public static function parse( $text, $unit = 'auto' ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );
		if ( count( $lines ) < 2 ) {
			return new \WP_Error( 'dev3sg_parse_short', __( 'Paste a header row and at least one size row.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$lines = array_slice( $lines, 0, Charts::MAX_ROWS + 1 );
		$sep   = self::separator( $lines );
		$grid  = array();
		foreach ( $lines as $line ) {
			$cells  = 'spaces' === $sep ? preg_split( '/\s{2,}|\t/', $line ) : explode( $sep, trim( $line, '|' ) );
			$grid[] = array_map(
				static function ( $c ) {
					return trim( (string) $c, " \t\"'" );
				},
				$cells
			);
		}
		$header = array_shift( $grid );
		$width  = count( $header );
		if ( $width < 2 ) {
			return new \WP_Error( 'dev3sg_parse_columns', __( 'Could not find columns. Separate them with tabs, semicolons, commas or two spaces.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$warnings = array();
		$all_text = strtolower( implode( ' ', $header ) );
		if ( 'auto' === $unit ) {
			if ( preg_match( '/\binch|\bin\b|\(in\)|"/', $all_text ) ) {
				$unit = 'in';
			} elseif ( preg_match( '/\bmm\b/', $all_text ) ) {
				$unit = 'mm';
			} elseif ( preg_match( '/\bcm\b/', $all_text ) ) {
				$unit = 'cm';
			}
		}
		$columns = array();
		foreach ( $header as $c => $label ) {
			$values  = array_column( $grid, $c );
			$numeric = 0;
			foreach ( $values as $v ) {
				if ( Units::parse_range( $v ) && preg_match( '/^[\d\s.,\-–—to~\/]+(cm|in|mm|")?$/iu', trim( (string) $v ) ) ) {
					++$numeric;
				}
			}
			$clean_label = trim( preg_replace( '/\((cm|in|inch|inches|mm|kg|lb|lbs)\)|\b(cm|inches|inch|mm)\b/i', '', $label ) );
			$measure     = Measures::guess( $clean_label );
			$is_measure  = 0 !== $c && $values && $numeric >= max( 1, (int) ceil( count( $values ) * 0.6 ) ) && ( 'custom' !== $measure || preg_match( '/cm|in|mm|kg|lb/i', $label ) );
			$columns[]   = array(
				'label'   => '' !== $clean_label ? $clean_label : $label,
				'kind'    => 0 === $c ? 'size' : ( $is_measure ? 'measure' : 'text' ),
				'measure' => $is_measure ? $measure : '',
			);
		}
		if ( 'auto' === $unit ) {
			$unit       = self::guess_unit( $columns, $grid );
			$warnings[] = 'in' === $unit
				? __( 'No unit in the header: the values look like inches and were converted to cm.', 'dev3-ai-size-guide-for-woocommerce' )
				: __( 'No unit in the header: the values were read as centimetres.', 'dev3-ai-size-guide-for-woocommerce' );
		}
		$rows = array();
		foreach ( $grid as $row ) {
			$rows[] = array_slice( array_pad( $row, $width, '' ), 0, $width );
		}
		$table = Charts::sanitize_table(
			array(
				'title'   => __( 'Body measurements', 'dev3-ai-size-guide-for-woocommerce' ),
				'columns' => $columns,
				'rows'    => $rows,
				'unit'    => $unit,
			),
			0
		);
		return array(
			'table'    => $table,
			'unit'     => $unit,
			'warnings' => $warnings,
		);
	}

	/**
	 * Most consistent separator.
	 *
	 * @param string[] $lines Lines.
	 * @return string
	 */
	private static function separator( array $lines ) {
		foreach ( array( "\t", ';', '|', ',' ) as $sep ) {
			$counts = array_map(
				static function ( $l ) use ( $sep ) {
					return substr_count( $l, $sep );
				},
				$lines
			);
			if ( min( $counts ) >= 1 && max( $counts ) === min( $counts ) ) {
				return $sep;
			}
		}
		return 'spaces';
	}

	/**
	 * Inches when girth values are implausibly small for centimetres.
	 *
	 * @param array $columns Columns.
	 * @param array $grid    Rows.
	 * @return string
	 */
	private static function guess_unit( array $columns, array $grid ) {
		$values = array();
		foreach ( $columns as $c => $col ) {
			if ( 'measure' === $col['kind'] && in_array( $col['measure'], array( 'chest', 'bust', 'waist', 'hips', 'underbust' ), true ) ) {
				foreach ( array_column( $grid, $c ) as $v ) {
					$r = Units::parse_range( $v );
					if ( $r ) {
						$values[] = ( $r[0] + $r[1] ) / 2;
					}
				}
			}
		}
		if ( ! $values ) {
			return 'cm';
		}
		sort( $values );
		return $values[ (int) floor( count( $values ) / 2 ) ] < 55 ? 'in' : 'cm';
	}
}
