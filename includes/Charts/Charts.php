<?php
/**
 * Size chart storage: the dev3sg_chart post type, validation and read/write helpers.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Charts;

defined( 'ABSPATH' ) || exit;

/**
 * Charts repository.
 */
class Charts {

	const POST_TYPE  = 'dev3sg_chart';
	const META_DATA  = '_dev3sg_data';
	const META_RULES = '_dev3sg_rules';
	const PROFILES   = array( 'women', 'men', 'unisex', 'kids' );
	const MAX_TABLES = 6;
	const MAX_COLS   = 16;
	const MAX_ROWS   = 60;

	/**
	 * Registers the post type (no public UI; the React app manages charts).
	 */
	public static function register_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Size charts', 'dev3-ai-size-guide-for-woocommerce' ),
					'singular_name' => __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Empty chart.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'id'       => 0,
			'name'     => '',
			'status'   => 'publish',
			'profile'  => 'unisex',
			'tables'   => array(
				array(
					'id'      => 't1',
					'title'   => __( 'Body measurements', 'dev3-ai-size-guide-for-woocommerce' ),
					'type'    => 'body',
					'columns' => array(
						array(
							'key'      => 'size',
							'label'    => __( 'Size', 'dev3-ai-size-guide-for-woocommerce' ),
							'kind'     => 'size',
							'measure'  => '',
							'quantity' => 'none',
						),
						array(
							'key'      => 'chest',
							'label'    => __( 'Chest', 'dev3-ai-size-guide-for-woocommerce' ),
							'kind'     => 'measure',
							'measure'  => 'chest',
							'quantity' => 'length',
						),
					),
					'rows'    => array( array( 'S', '' ), array( 'M', '' ), array( 'L', '' ) ),
				),
			),
			'guide'    => array(
				'text'     => '',
				'image_id' => 0,
			),
			'fit_note' => '',
			'bias'     => 0,
			'rules'    => self::blank_rules(),
		);
	}

	/**
	 * Empty assignment rules.
	 *
	 * @return array
	 */
	public static function blank_rules() {
		return array(
			'products'   => array(),
			'categories' => array(),
			'tags'       => array(),
			'exclude'    => array(),
			'priority'   => 0,
		);
	}

	/**
	 * A chart as an array, or null.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$data  = json_decode( (string) get_post_meta( $post->ID, self::META_DATA, true ), true );
		$rules = json_decode( (string) get_post_meta( $post->ID, self::META_RULES, true ), true );
		$chart = self::sanitize( is_array( $data ) ? $data : array() );

		$chart['id']      = (int) $post->ID;
		$chart['name']    = $post->post_title;
		$chart['status']  = 'publish' === $post->post_status ? 'publish' : 'draft';
		$chart['rules']   = self::sanitize_rules( is_array( $rules ) ? $rules : array() );
		$chart['updated'] = mysql_to_rfc3339( $post->post_modified_gmt );

		$chart['guide']['image_url'] = $chart['guide']['image_id'] ? (string) wp_get_attachment_image_url( $chart['guide']['image_id'], 'large' ) : '';
		return $chart;
	}

	/**
	 * Creates or updates a chart.
	 *
	 * @param array $input Chart (name, status, tables, guide, fit_note, profile, bias, rules).
	 * @param int   $id    Existing id (0 = new).
	 * @return int|\WP_Error
	 */
	public static function save( array $input, $id = 0 ) {
		$name = isset( $input['name'] ) ? mb_substr( sanitize_text_field( (string) $input['name'] ), 0, 120 ) : '';
		if ( '' === trim( $name ) ) {
			return new \WP_Error( 'dev3sg_chart_name', __( 'Give the size chart a name.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$existing = $id ? self::get( $id ) : null;
		if ( $id && ! $existing ) {
			return new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$data  = self::sanitize( array_merge( $existing ? $existing : array(), $input ) );
		$rules = self::sanitize_rules( isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : ( $existing ? $existing['rules'] : array() ) );

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => $name,
			'post_status' => isset( $input['status'] ) && 'draft' === $input['status'] ? 'draft' : 'publish',
		);
		if ( $id ) {
			$postarr['ID'] = (int) $id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result;
		update_post_meta( $id, self::META_DATA, wp_slash( wp_json_encode( $data ) ) );
		update_post_meta( $id, self::META_RULES, wp_slash( wp_json_encode( $rules ) ) );
		Resolver::flush();
		/**
		 * Fires after a size chart is saved.
		 *
		 * @param int   $id   Chart id.
		 * @param array $data Chart data.
		 */
		do_action( 'dev3sg_chart_saved', $id, $data );
		return $id;
	}

	/**
	 * Deletes a chart (to the trash).
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		if ( ! self::get( $id ) ) {
			return false;
		}
		$ok = (bool) wp_trash_post( (int) $id );
		Resolver::flush();
		return $ok;
	}

	/**
	 * Duplicates a chart (as a draft without assignment).
	 *
	 * @param int $id Id.
	 * @return int|\WP_Error
	 */
	public static function duplicate( $id ) {
		$chart = self::get( $id );
		if ( ! $chart ) {
			return new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) );
		}
		/* translators: %s: chart name. */
		$chart['name']   = sprintf( __( '%s (copy)', 'dev3-ai-size-guide-for-woocommerce' ), $chart['name'] );
		$chart['status'] = 'draft';
		$chart['rules']  = self::blank_rules();
		return self::save( $chart );
	}

	/**
	 * All charts (newest first).
	 *
	 * @param array $args { status: any|publish, search }.
	 * @return array
	 */
	public static function all( array $args = array() ) {
		$query = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => isset( $args['status'] ) && 'publish' === $args['status'] ? 'publish' : array( 'publish', 'draft' ),
			'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- size charts are few; one query keeps the rules index cheap.
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = (string) $args['search'];
		}
		$out = array();
		foreach ( get_posts( $query ) as $id ) {
			$chart = self::get( $id );
			if ( $chart ) {
				$out[] = $chart;
			}
		}
		return $out;
	}

	/**
	 * Validates chart data (everything except name/status/rules).
	 *
	 * @param array $input Raw.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$blank  = self::blank();
		$out    = array(
			'profile'  => isset( $input['profile'] ) && in_array( $input['profile'], self::PROFILES, true ) ? $input['profile'] : 'unisex',
			'tables'   => array(),
			'guide'    => array(
				'text'     => isset( $input['guide']['text'] ) ? mb_substr( sanitize_textarea_field( (string) $input['guide']['text'] ), 0, 3000 ) : '',
				'image_id' => isset( $input['guide']['image_id'] ) ? max( 0, (int) $input['guide']['image_id'] ) : 0,
			),
			'fit_note' => isset( $input['fit_note'] ) ? mb_substr( sanitize_text_field( (string) $input['fit_note'] ), 0, 200 ) : '',
			'bias'     => isset( $input['bias'] ) ? max( -2, min( 2, (int) $input['bias'] ) ) : 0,
			'bias_at'  => isset( $input['bias_at'] ) ? max( 0, (int) $input['bias_at'] ) : 0,
		);
		$tables = isset( $input['tables'] ) && is_array( $input['tables'] ) ? array_values( $input['tables'] ) : $blank['tables'];
		foreach ( array_slice( $tables, 0, self::MAX_TABLES ) as $i => $table ) {
			$clean = self::sanitize_table( is_array( $table ) ? $table : array(), $i );
			if ( $clean ) {
				$out['tables'][] = $clean;
			}
		}
		if ( ! $out['tables'] ) {
			$out['tables'] = $blank['tables'];
		}
		return $out;
	}

	/**
	 * Validates one table. Measure cells are normalised to "min-max" in cm/kg. Exactly one size column.
	 *
	 * @param array $table Raw table.
	 * @param int   $index Position.
	 * @return array|null
	 */
	public static function sanitize_table( array $table, $index ) {
		$columns = array();
		$keys    = array();
		$raw     = isset( $table['columns'] ) && is_array( $table['columns'] ) ? array_values( $table['columns'] ) : array();
		foreach ( array_slice( $raw, 0, self::MAX_COLS ) as $col ) {
			if ( ! is_array( $col ) ) {
				continue;
			}
			$label   = mb_substr( sanitize_text_field( (string) ( $col['label'] ?? '' ) ), 0, 40 );
			$kind    = in_array( $col['kind'] ?? '', array( 'size', 'measure', 'text' ), true ) ? $col['kind'] : 'text';
			$measure = '';
			$qty     = 'none';
			if ( 'measure' === $kind ) {
				$measure = Measures::exists( $col['measure'] ?? '' ) ? (string) $col['measure'] : Measures::guess( $label );
				$def     = Measures::get( $measure );
				$qty     = 'custom' === $measure && in_array( $col['quantity'] ?? '', array( 'length', 'weight' ), true ) ? $col['quantity'] : $def['quantity'];
				if ( '' === $label ) {
					$label = $def['label'];
				}
			}
			$key = sanitize_key( (string) ( $col['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = sanitize_key( '' !== $measure && 'custom' !== $measure ? $measure : $label );
			}
			if ( '' === $key ) {
				$key = 'col';
			}
			$base = $key;
			$n    = 2;
			while ( in_array( $key, $keys, true ) ) {
				$key = $base . '_' . $n;
				++$n;
			}
			$keys[]    = $key;
			$columns[] = array(
				'key'      => $key,
				'label'    => $label,
				'kind'     => $kind,
				'measure'  => $measure,
				'quantity' => $qty,
			);
		}
		if ( ! $columns ) {
			return null;
		}
		// Exactly one size column: keep the first, else promote the first column.
		$size_seen = false;
		foreach ( $columns as $i => $col ) {
			if ( 'size' === $col['kind'] ) {
				if ( $size_seen ) {
					$columns[ $i ]['kind'] = 'text';
				}
				$size_seen = true;
			}
		}
		if ( ! $size_seen ) {
			$columns[0] = array_merge(
				$columns[0],
				array(
					'kind'     => 'size',
					'measure'  => '',
					'quantity' => 'none',
				)
			);
		}
		$unit = isset( $table['unit'] ) ? (string) $table['unit'] : 'cm';
		$rows = array();
		$raw  = isset( $table['rows'] ) && is_array( $table['rows'] ) ? array_values( $table['rows'] ) : array();
		foreach ( array_slice( $raw, 0, self::MAX_ROWS ) as $row ) {
			$row   = is_array( $row ) ? array_values( $row ) : array();
			$clean = array();
			foreach ( $columns as $c => $col ) {
				$cell = isset( $row[ $c ] ) && is_scalar( $row[ $c ] ) ? (string) $row[ $c ] : '';
				if ( 'measure' === $col['kind'] ) {
					$cell_unit = 'weight' === $col['quantity'] ? ( 'in' === $unit ? 'lb' : ( 'lb' === $unit ? 'lb' : 'kg' ) ) : ( in_array( $unit, array( 'in', 'mm' ), true ) ? $unit : 'cm' );
					$clean[]   = Units::normalize_cell( $cell, $cell_unit );
				} else {
					$clean[] = mb_substr( sanitize_text_field( $cell ), 0, 40 );
				}
			}
			if ( implode( '', $clean ) !== '' ) {
				$rows[] = $clean;
			}
		}
		$id = sanitize_key( (string) ( $table['id'] ?? '' ) );
		return array(
			'id'      => '' !== $id ? $id : 't' . ( $index + 1 ),
			'title'   => mb_substr( sanitize_text_field( (string) ( $table['title'] ?? '' ) ), 0, 60 ),
			'type'    => isset( $table['type'] ) && 'garment' === $table['type'] ? 'garment' : 'body',
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * Validates assignment rules.
	 *
	 * @param array $input Raw.
	 * @return array
	 */
	public static function sanitize_rules( array $input ) {
		$ids = static function ( $items ) {
			$items = is_array( $items ) ? $items : array();
			$out   = array();
			foreach ( $items as $v ) {
				$v = is_array( $v ) && isset( $v['id'] ) ? $v['id'] : $v;
				if ( (int) $v > 0 ) {
					$out[] = (int) $v;
				}
			}
			return array_values( array_unique( array_slice( $out, 0, 500 ) ) );
		};
		return array(
			'products'   => $ids( $input['products'] ?? array() ),
			'categories' => $ids( $input['categories'] ?? array() ),
			'tags'       => $ids( $input['tags'] ?? array() ),
			'exclude'    => $ids( $input['exclude'] ?? array() ),
			'priority'   => isset( $input['priority'] ) ? max( -100, min( 100, (int) $input['priority'] ) ) : 0,
		);
	}

	/**
	 * Size labels of a chart's first table (used by learning + variation matching).
	 *
	 * @param array $chart Chart.
	 * @return string[]
	 */
	public static function sizes( array $chart ) {
		$out = array();
		foreach ( $chart['tables'] as $table ) {
			foreach ( $table['columns'] as $c => $col ) {
				if ( 'size' === $col['kind'] ) {
					foreach ( $table['rows'] as $row ) {
						if ( '' !== (string) $row[ $c ] ) {
							$out[] = (string) $row[ $c ];
						}
					}
					return $out;
				}
			}
		}
		return $out;
	}
}
