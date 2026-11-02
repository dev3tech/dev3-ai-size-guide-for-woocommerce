<?php
/**
 * Built-in size chart templates (values in cm / kg).
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Charts;

defined( 'ABSPATH' ) || exit;

/**
 * Template library.
 */
class Templates {

	/**
	 * Column helper.
	 *
	 * @param string $kind    size|measure|text.
	 * @param string $label   Label.
	 * @param string $measure Measure key.
	 * @return array
	 */
	private static function col( $kind, $label, $measure = '' ) {
		return array(
			'key'      => sanitize_key( $measure ? $measure : $label ),
			'label'    => $label,
			'kind'     => $kind,
			'measure'  => $measure,
			'quantity' => 'measure' === $kind ? Measures::get( $measure )['quantity'] : 'none',
		);
	}

	/**
	 * Table helper.
	 *
	 * @param string $title   Title.
	 * @param array  $columns Columns.
	 * @param array  $rows    Rows.
	 * @param string $type    body|garment.
	 * @return array
	 */
	private static function table( $title, array $columns, array $rows, $type = 'body' ) {
		static $n = 0;
		++$n;
		return array(
			'id'      => 't' . $n,
			'title'   => $title,
			'type'    => $type,
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * All templates: id => { id, name, description, category, chart }.
	 *
	 * @return array
	 */
	public static function all() {
		$size  = __( 'Size', 'dev3-ai-size-guide-for-woocommerce' );
		$body  = __( 'Body measurements', 'dev3-ai-size-guide-for-woocommerce' );
		$conv  = __( 'International sizes', 'dev3-ai-size-guide-for-woocommerce' );
		$m     = static function ( $key ) {
			return self::col( 'measure', Measures::get( $key )['label'], $key );
		};
		$s     = static function () use ( $size ) {
			return self::col( 'size', $size );
		};
		$t     = static function ( $label ) {
			return self::col( 'text', $label );
		};
		$items = array(
			'women_tops'    => array(
				__( "Women's tops", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Blouses, shirts, knitwear and jackets (XS–XXL).', 'dev3-ai-size-guide-for-woocommerce' ),
				'women',
				array(
					self::table(
						$body,
						array( $s(), $m( 'bust' ), $m( 'waist' ), $m( 'hips' ) ),
						array(
							array( 'XS', '78-82', '60-64', '86-90' ),
							array( 'S', '82-86', '64-68', '90-94' ),
							array( 'M', '86-90', '68-72', '94-98' ),
							array( 'L', '90-96', '72-78', '98-104' ),
							array( 'XL', '96-102', '78-84', '104-110' ),
							array( 'XXL', '102-108', '84-90', '110-116' ),
						)
					),
					self::table(
						$conv,
						array( $s(), $t( 'US' ), $t( 'UK' ), $t( 'EU' ) ),
						array(
							array( 'XS', '0-2', '4-6', '32-34' ),
							array( 'S', '4-6', '8-10', '36-38' ),
							array( 'M', '8-10', '12-14', '40-42' ),
							array( 'L', '12-14', '16-18', '44-46' ),
							array( 'XL', '16-18', '20-22', '48-50' ),
							array( 'XXL', '20', '24', '52' ),
						)
					),
				),
			),
			'women_dresses' => array(
				__( "Women's dresses", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Dresses and jumpsuits in US numeric sizes.', 'dev3-ai-size-guide-for-woocommerce' ),
				'women',
				array(
					self::table(
						$body,
						array( $s(), $m( 'bust' ), $m( 'waist' ), $m( 'hips' ), $t( 'UK' ), $t( 'EU' ) ),
						array(
							array( '2', '80-82', '62-64', '87-89', '6', '34' ),
							array( '4', '82-85', '64-67', '89-92', '8', '36' ),
							array( '6', '85-88', '67-70', '92-95', '10', '38' ),
							array( '8', '88-91', '70-73', '95-98', '12', '40' ),
							array( '10', '91-95', '73-77', '98-102', '14', '42' ),
							array( '12', '95-99', '77-81', '102-106', '16', '44' ),
							array( '14', '99-104', '81-86', '106-111', '18', '46' ),
							array( '16', '104-109', '86-91', '111-116', '20', '48' ),
						)
					),
				),
			),
			'women_bottoms' => array(
				__( "Women's bottoms", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Trousers, jeans, skirts and shorts.', 'dev3-ai-size-guide-for-woocommerce' ),
				'women',
				array(
					self::table(
						$body,
						array( $s(), $m( 'waist' ), $m( 'hips' ), $m( 'inseam' ) ),
						array(
							array( 'XS', '60-64', '86-90', '76-78' ),
							array( 'S', '64-68', '90-94', '77-79' ),
							array( 'M', '68-72', '94-98', '78-80' ),
							array( 'L', '72-78', '98-104', '79-81' ),
							array( 'XL', '78-84', '104-110', '80-82' ),
							array( 'XXL', '84-90', '110-116', '80-82' ),
						)
					),
				),
			),
			'men_tops'      => array(
				__( "Men's tops & shirts", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Shirts, T-shirts, hoodies and jackets (S–3XL).', 'dev3-ai-size-guide-for-woocommerce' ),
				'men',
				array(
					self::table(
						$body,
						array( $s(), $m( 'chest' ), $m( 'waist' ), $m( 'neck' ), $m( 'sleeve' ) ),
						array(
							array( 'S', '86-94', '71-79', '37-38', '81-84' ),
							array( 'M', '94-102', '79-87', '39-40', '84-86' ),
							array( 'L', '102-110', '87-95', '41-42', '86-89' ),
							array( 'XL', '110-118', '95-103', '43-44', '89-91' ),
							array( 'XXL', '118-126', '103-111', '45-46', '91-93' ),
							array( '3XL', '126-134', '111-119', '47-48', '93-95' ),
						)
					),
				),
			),
			'men_bottoms'   => array(
				__( "Men's bottoms & jeans", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Waist sizes 28–40 with inseam.', 'dev3-ai-size-guide-for-woocommerce' ),
				'men',
				array(
					self::table(
						$body,
						array( $s(), $m( 'waist' ), $m( 'hips' ), $m( 'inseam' ), $t( 'EU' ) ),
						array(
							array( '28', '71-73', '88-90', '81', '44' ),
							array( '30', '76-78', '93-95', '81', '46' ),
							array( '32', '81-83', '98-100', '81', '48' ),
							array( '34', '86-88', '103-105', '81', '50' ),
							array( '36', '91-93', '108-110', '81', '52' ),
							array( '38', '96-98', '113-115', '81', '54' ),
							array( '40', '101-103', '118-120', '81', '56' ),
						)
					),
				),
			),
			'unisex_tees'   => array(
				__( 'Unisex T-shirts', 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Body chest plus flat garment measurements.', 'dev3-ai-size-guide-for-woocommerce' ),
				'unisex',
				array(
					self::table(
						$body,
						array( $s(), $m( 'chest' ), $m( 'height' ) ),
						array(
							array( 'XS', '81-86', '155-163' ),
							array( 'S', '86-94', '160-168' ),
							array( 'M', '94-102', '166-174' ),
							array( 'L', '102-110', '172-180' ),
							array( 'XL', '110-118', '178-186' ),
							array( 'XXL', '118-126', '184-192' ),
						)
					),
					self::table(
						__( 'Garment measurements', 'dev3-ai-size-guide-for-woocommerce' ),
						array( $s(), self::col( 'measure', __( 'Chest width (flat)', 'dev3-ai-size-guide-for-woocommerce' ), 'width' ), $m( 'length' ) ),
						array(
							array( 'XS', '44', '66' ),
							array( 'S', '47', '69' ),
							array( 'M', '51', '72' ),
							array( 'L', '55', '74' ),
							array( 'XL', '59', '76' ),
							array( 'XXL', '63', '78' ),
						),
						'garment'
					),
				),
			),
			'women_shoes'   => array(
				__( "Women's shoes", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'EU 36–42 with US, UK and foot length.', 'dev3-ai-size-guide-for-woocommerce' ),
				'women',
				array(
					self::table(
						__( 'Shoe sizes', 'dev3-ai-size-guide-for-woocommerce' ),
						array( self::col( 'size', 'EU' ), $m( 'foot_length' ), $t( 'US' ), $t( 'UK' ) ),
						array(
							array( '36', '22.5-23', '5.5', '3.5' ),
							array( '37', '23-23.5', '6.5', '4' ),
							array( '38', '23.5-24', '7.5', '5' ),
							array( '39', '24-24.5', '8.5', '6' ),
							array( '40', '24.5-25.5', '9', '6.5' ),
							array( '41', '25.5-26', '10', '7.5' ),
							array( '42', '26-26.5', '11', '8' ),
						)
					),
				),
			),
			'men_shoes'     => array(
				__( "Men's shoes", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'EU 40–46 with US, UK and foot length.', 'dev3-ai-size-guide-for-woocommerce' ),
				'men',
				array(
					self::table(
						__( 'Shoe sizes', 'dev3-ai-size-guide-for-woocommerce' ),
						array( self::col( 'size', 'EU' ), $m( 'foot_length' ), $t( 'US' ), $t( 'UK' ) ),
						array(
							array( '40', '25-25.5', '7', '6.5' ),
							array( '41', '25.5-26', '8', '7' ),
							array( '42', '26-26.5', '8.5', '8' ),
							array( '43', '26.5-27.5', '9.5', '9' ),
							array( '44', '27.5-28', '10.5', '9.5' ),
							array( '45', '28-28.5', '11.5', '10.5' ),
							array( '46', '28.5-29.5', '12', '11' ),
						)
					),
				),
			),
			'kids'          => array(
				__( "Kids' clothing", 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Ages 2–12 by height, chest and waist.', 'dev3-ai-size-guide-for-woocommerce' ),
				'kids',
				array(
					self::table(
						$body,
						array( $s(), $m( 'height' ), $m( 'chest' ), $m( 'waist' ) ),
						array(
							array( '2-3Y', '92-98', '52-54', '50-52' ),
							array( '3-4Y', '98-104', '54-56', '51-53' ),
							array( '4-5Y', '104-110', '56-58', '52-54' ),
							array( '5-6Y', '110-116', '58-60', '53-55' ),
							array( '6-7Y', '116-122', '60-62', '54-56' ),
							array( '7-8Y', '122-128', '62-65', '55-57' ),
							array( '9-10Y', '128-140', '65-70', '57-60' ),
							array( '11-12Y', '140-152', '70-76', '60-64' ),
						)
					),
				),
			),
			'bras'          => array(
				__( 'Bras', 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Band and cup from under-bust and bust.', 'dev3-ai-size-guide-for-woocommerce' ),
				'women',
				array(
					self::table(
						__( 'Bra sizes', 'dev3-ai-size-guide-for-woocommerce' ),
						array( $s(), $m( 'underbust' ), $m( 'bust' ), $t( 'EU' ) ),
						array(
							array( '32A', '68-72', '82-84', '70A' ),
							array( '32B', '68-72', '84-86', '70B' ),
							array( '32C', '68-72', '86-88', '70C' ),
							array( '34A', '73-77', '87-89', '75A' ),
							array( '34B', '73-77', '89-91', '75B' ),
							array( '34C', '73-77', '91-93', '75C' ),
							array( '36B', '78-82', '94-96', '80B' ),
							array( '36C', '78-82', '96-98', '80C' ),
							array( '36D', '78-82', '98-100', '80D' ),
							array( '38C', '83-87', '101-103', '85C' ),
							array( '38D', '83-87', '103-105', '85D' ),
						)
					),
				),
			),
			'rings'         => array(
				__( 'Rings', 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'US ring sizes 5–11 by finger circumference.', 'dev3-ai-size-guide-for-woocommerce' ),
				'unisex',
				array(
					self::table(
						__( 'Ring sizes', 'dev3-ai-size-guide-for-woocommerce' ),
						array( self::col( 'size', 'US' ), $m( 'ring' ), $t( 'UK' ), $t( 'EU' ) ),
						array(
							array( '5', '4.94', 'J½', '49' ),
							array( '6', '5.19', 'L½', '52' ),
							array( '7', '5.44', 'O', '54' ),
							array( '8', '5.70', 'Q', '57' ),
							array( '9', '5.95', 'S', '59' ),
							array( '10', '6.20', 'T½', '62' ),
							array( '11', '6.46', 'V½', '64' ),
						)
					),
				),
			),
			'hats'          => array(
				__( 'Hats & caps', 'dev3-ai-size-guide-for-woocommerce' ),
				__( 'Head circumference S–XL.', 'dev3-ai-size-guide-for-woocommerce' ),
				'unisex',
				array(
					self::table(
						$size,
						array( $s(), $m( 'head' ), $t( __( 'Fitted', 'dev3-ai-size-guide-for-woocommerce' ) ) ),
						array(
							array( 'S', '54-55', '6 3/4' ),
							array( 'M', '56-57', '7' ),
							array( 'L', '58-59', '7 1/4' ),
							array( 'XL', '60-61', '7 1/2' ),
						)
					),
				),
			),
		);
		$out   = array();
		foreach ( $items as $id => $item ) {
			$chart      = array(
				'name'     => $item[0],
				'profile'  => $item[2],
				'tables'   => $item[3],
				'guide'    => array(
					'text'     => self::guide_for( $item[3] ),
					'image_id' => 0,
				),
				'fit_note' => '',
				'bias'     => 0,
			);
			$out[ $id ] = array(
				'id'          => $id,
				'name'        => $item[0],
				'description' => $item[1],
				'profile'     => $item[2],
				'chart'       => $chart,
			);
		}
		/**
		 * Filters the template library.
		 *
		 * @param array $out Templates.
		 */
		return apply_filters( 'dev3sg_templates', $out );
	}

	/**
	 * Default how-to-measure text from the measures a chart uses.
	 *
	 * @param array $tables Tables.
	 * @return string
	 */
	public static function guide_for( array $tables ) {
		$lines = array();
		$seen  = array();
		foreach ( $tables as $table ) {
			if ( isset( $table['type'] ) && 'garment' === $table['type'] ) {
				continue;
			}
			foreach ( $table['columns'] as $col ) {
				if ( 'measure' !== $col['kind'] || isset( $seen[ $col['measure'] ] ) ) {
					continue;
				}
				$seen[ $col['measure'] ] = true;
				$def                     = Measures::get( $col['measure'] );
				if ( '' !== $def['help'] ) {
					$lines[] = $def['label'] . ': ' . $def['help'];
				}
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * One template's chart data, or null.
	 *
	 * @param string $id Template id.
	 * @return array|null
	 */
	public static function chart( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ]['chart'] : null;
	}
}
