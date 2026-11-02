<?php
/**
 * DEV3 AI drafts: charts (from product data, pasted text or a photo), guides, return insights,
 * assignment suggestions and return-reason classification. Nothing here saves a chart.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Ai;

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Measures;
use Dev3\AiSizeGuide\Core3\Api\Api_Error;
use Dev3\AiSizeGuide\Core3\Api\Prompts;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;
use Dev3\AiSizeGuide\Learning\Insights;
use Dev3\AiSizeGuide\Learning\Returns;

defined( 'ABSPATH' ) || exit;

/**
 * Drafts.
 */
class Drafts {

	const CLASSIFY_JOB = 'dev3sg_classify';
	const MAX_IMAGE    = 4194304;

	/**
	 * Product facts for the chart prompt.
	 *
	 * @param int $product_id Product.
	 * @return array|\WP_Error
	 */
	public static function product_facts( $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product ) {
			return new \WP_Error( 'dev3sg_product_missing', __( 'Choose a product first.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$attr = array();
		foreach ( $product->get_attributes() as $attribute ) {
			$name  = wc_attribute_label( $attribute->get_name() );
			$terms = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
			if ( $terms ) {
				$attr[ $name ] = array_slice( array_map( 'strval', (array) $terms ), 0, 30 );
			}
		}
		return array(
			'name'              => $product->get_name(),
			'categories'        => is_wp_error( $cats ) ? array() : $cats,
			'short_description' => mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 400 ),
			'description'       => mb_substr( wp_strip_all_tags( $product->get_description() ), 0, 800 ),
			'attributes'        => $attr,
		);
	}

	/**
	 * Draft from product data.
	 *
	 * @param int    $product_id Product.
	 * @param string $profile    Profile or ''.
	 * @param string $unit       cm|in.
	 * @param string $notes      Admin notes.
	 * @return array|\WP_Error { draft, notes, model, tokens, cached }
	 */
	public static function chart_from_product( $product_id, $profile = '', $unit = 'cm', $notes = '' ) {
		$facts = self::product_facts( $product_id );
		if ( is_wp_error( $facts ) ) {
			return $facts;
		}
		$result = Prompts::run(
			'chart_from_product',
			array(
				'product' => $facts,
				'profile' => '' !== $profile ? $profile : 'decide from the product',
				'unit'    => 'in' === $unit ? 'in' : 'cm',
				'notes'   => '' !== $notes ? mb_substr( $notes, 0, 500 ) : 'none',
			)
		);
		return self::finish_chart( $result, $facts['name'] );
	}

	/**
	 * Draft from pasted text (any format).
	 *
	 * @param string $text Text.
	 * @param string $unit auto|cm|in.
	 * @return array|\WP_Error
	 */
	public static function chart_from_text( $text, $unit = 'auto' ) {
		$text = trim( mb_substr( (string) $text, 0, 12000 ) );
		if ( mb_strlen( $text ) < 10 ) {
			return new \WP_Error( 'dev3sg_text_short', __( 'Paste the size chart text first.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$result = Prompts::run(
			'chart_from_text',
			array(
				'text' => $text,
				'unit' => in_array( $unit, array( 'cm', 'in' ), true ) ? $unit : 'detect from the text',
			)
		);
		return self::finish_chart( $result, '' );
	}

	/**
	 * Draft from a photo or screenshot (attachment id or base64 upload).
	 *
	 * @param int    $attachment_id Media id (0 = use $data).
	 * @param string $data          Base64 (optionally a data: URL).
	 * @param string $mime          Mime of $data.
	 * @param string $unit          auto|cm|in.
	 * @return array|\WP_Error
	 */
	public static function chart_from_image( $attachment_id = 0, $data = '', $mime = '', $unit = 'auto' ) {
		$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		if ( $attachment_id ) {
			$file = get_attached_file( (int) $attachment_id );
			$mime = (string) get_post_mime_type( (int) $attachment_id );
			if ( ! $file || ! is_readable( $file ) || ! in_array( $mime, $allowed, true ) ) {
				return new \WP_Error( 'dev3sg_image_invalid', __( 'Choose a JPG, PNG or WebP image.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
			}
			if ( filesize( $file ) > self::MAX_IMAGE ) {
				return new \WP_Error( 'dev3sg_image_large', __( 'The image is larger than 4 MB.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
			}
			$data = base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- local media file sent to Gemini as inline data.
		} else {
			if ( preg_match( '#^data:(image/[a-z+]+);base64,#i', (string) $data, $m ) ) {
				$mime = strtolower( $m[1] );
				$data = substr( (string) $data, strlen( $m[0] ) );
			}
			$mime = strtolower( (string) $mime );
			$bin  = base64_decode( (string) $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- validating an uploaded image.
			if ( false === $bin || '' === $bin || ! in_array( $mime, $allowed, true ) ) {
				return new \WP_Error( 'dev3sg_image_invalid', __( 'Choose a JPG, PNG or WebP image.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
			}
			if ( strlen( $bin ) > self::MAX_IMAGE ) {
				return new \WP_Error( 'dev3sg_image_large', __( 'The image is larger than 4 MB.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
			}
			$info = function_exists( 'getimagesizefromstring' ) ? getimagesizefromstring( $bin ) : false;
			if ( ! $info ) {
				return new \WP_Error( 'dev3sg_image_invalid', __( 'Choose a JPG, PNG or WebP image.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}
		$result = Prompts::run(
			'chart_from_image',
			array( 'unit' => in_array( $unit, array( 'cm', 'in' ), true ) ? $unit : 'detect from the image' ),
			'admin',
			array(
				'images' => array(
					array(
						'mime' => $mime,
						'data' => $data,
					),
				),
			)
		);
		return self::finish_chart( $result, '' );
	}

	/**
	 * Turns structured AI output into a validated chart draft (values converted to cm).
	 *
	 * @param array|\WP_Error $result Ai::run result.
	 * @param string          $name   Fallback name.
	 * @return array|\WP_Error
	 */
	public static function finish_chart( $result, $name ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$draft = self::normalize_chart( isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array(), $name );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		return array(
			'draft'  => $draft,
			'notes'  => mb_substr( sanitize_textarea_field( (string) ( $result['data']['notes'] ?? '' ) ), 0, 300 ),
			'model'  => (string) ( $result['model'] ?? '' ),
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Validates AI chart data.
	 *
	 * @param array  $data AI data.
	 * @param string $name Fallback name.
	 * @return array|\WP_Error Chart (without id).
	 */
	public static function normalize_chart( array $data, $name = '' ) {
		$unit   = in_array( $data['unit'] ?? '', array( 'cm', 'in', 'mm' ), true ) ? $data['unit'] : 'cm';
		$tables = array();
		foreach ( array_slice( (array) ( $data['tables'] ?? array() ), 0, Charts::MAX_TABLES ) as $table ) {
			if ( ! is_array( $table ) ) {
				continue;
			}
			$cols = array();
			foreach ( (array) ( $table['columns'] ?? array() ) as $col ) {
				if ( ! is_array( $col ) ) {
					continue;
				}
				$kind    = in_array( $col['kind'] ?? '', array( 'size', 'measure', 'text' ), true ) ? $col['kind'] : 'text';
				$measure = 'measure' === $kind ? ( Measures::exists( $col['measure'] ?? '' ) ? $col['measure'] : Measures::guess( (string) ( $col['label'] ?? '' ) ) ) : '';
				$cols[]  = array(
					'label'   => preg_replace( '/\s*\((cm|in|inch|inches|mm)\)\s*$/i', '', (string) ( $col['label'] ?? '' ) ),
					'kind'    => $kind,
					'measure' => $measure,
				);
			}
			$tables[] = array(
				'title'   => (string) ( $table['title'] ?? '' ),
				'type'    => (string) ( $table['type'] ?? 'body' ),
				'columns' => $cols,
				'rows'    => (array) ( $table['rows'] ?? array() ),
				'unit'    => $unit,
			);
		}
		$chart  = Charts::sanitize(
			array(
				'profile'  => (string) ( $data['profile'] ?? 'unisex' ),
				'tables'   => $tables,
				'guide'    => array( 'text' => (string) ( $data['guide'] ?? '' ) ),
				'fit_note' => (string) ( $data['fit_note'] ?? '' ),
			)
		);
		$useful = false;
		foreach ( $chart['tables'] as $table ) {
			if ( count( $table['rows'] ) >= 2 && count( $table['columns'] ) >= 2 ) {
				$useful = true;
			}
		}
		if ( ! $tables || ! $useful ) {
			return Api_Error::make( 'dev3sg_ai_bad_chart', __( 'DEV3 AI did not return a usable size chart. Try again or add more detail.', 'dev3-ai-size-guide-for-woocommerce' ), __( 'For pasted text or photos, make sure the size table is fully visible.', 'dev3-ai-size-guide-for-woocommerce' ), 502, true );
		}
		$chart['name']   = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 120 );
		$chart['name']   = '' !== $chart['name'] ? $chart['name'] : ( '' !== $name ? $name : __( 'New size chart', 'dev3-ai-size-guide-for-woocommerce' ) );
		$chart['status'] = 'publish';
		return $chart;
	}

	/**
	 * How-to-measure guide draft.
	 *
	 * @param array $chart Chart (name, profile, tables).
	 * @return array|\WP_Error { text, model, tokens, cached }
	 */
	public static function guide( array $chart ) {
		$chart    = array_merge( Charts::sanitize( $chart ), array( 'name' => (string) ( $chart['name'] ?? '' ) ) );
		$measures = array();
		foreach ( $chart['tables'] as $table ) {
			foreach ( $table['columns'] as $col ) {
				if ( 'measure' === $col['kind'] ) {
					$measures[ $col['label'] ] = true;
				}
			}
		}
		if ( ! $measures ) {
			return new \WP_Error( 'dev3sg_guide_empty', __( 'Add at least one measurement column first.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$result = Prompts::run(
			'write_guide',
			array(
				'name'     => $chart['name'],
				'profile'  => $chart['profile'],
				'measures' => implode( ', ', array_keys( $measures ) ),
				'language' => self::language(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'text'   => mb_substr( sanitize_textarea_field( (string) ( $result['data']['text'] ?? '' ) ), 0, 3000 ),
			'model'  => (string) $result['model'],
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Site language name for prompts.
	 *
	 * @return string
	 */
	private static function language() {
		$locale = get_locale();
		return 'en_US' === $locale ? 'English' : 'the language of locale ' . $locale;
	}

	/**
	 * AI summary of a chart's returns (draft).
	 *
	 * @param int $chart_id Chart.
	 * @return array|\WP_Error { summary, suggestions[], direction, bias, fit_note, model, tokens }
	 */
	public static function insights( $chart_id ) {
		$chart = Charts::get( $chart_id );
		if ( ! $chart ) {
			return new \WP_Error( 'dev3sg_chart_missing', __( 'Size chart not found.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$insight = null;
		foreach ( Insights::all() as $row ) {
			if ( (int) $row['chart_id'] === (int) $chart_id ) {
				$insight = $row;
			}
		}
		if ( ! $insight || 0 === ( $insight['totals']['too_small'] + $insight['totals']['too_large'] + $insight['totals']['other'] + $insight['totals']['fit_ok'] + $insight['totals']['unknown'] ) ) {
			return new \WP_Error( 'dev3sg_insights_empty', __( 'There are no return records for this chart yet.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$notes = array();
		foreach ( Returns::since( gmdate( 'Y-m-d H:i:s', time() - Insights::config()['days'] * DAY_IN_SECONDS ), $chart_id ) as $rec ) {
			if ( '' !== $rec['note'] && count( $notes ) < 40 ) {
				$notes[] = array(
					'size'   => $rec['size'],
					'reason' => $rec['reason'],
					'note'   => mb_substr( $rec['note'], 0, 200 ),
				);
			}
		}
		$stats  = array_map(
			static fn( $s ) => array_intersect_key( $s, array_flip( array( 'size', 'sold', 'too_small', 'too_large', 'fit_ok', 'other' ) ) ),
			$insight['sizes']
		);
		$result = Prompts::run(
			'return_insights',
			array(
				'chart' => array(
					'name'         => $chart['name'],
					'profile'      => $chart['profile'],
					'current_bias' => $chart['bias'],
					'fit_note'     => $chart['fit_note'],
				),
				'stats' => $stats,
				'notes' => $notes,
			),
			'admin',
			array( 'mask_pii' => true )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d         = (array) ( $result['data'] ?? array() );
		$direction = in_array( $d['direction'] ?? '', array( 'runs_small', 'runs_large', 'true_to_size' ), true ) ? $d['direction'] : 'true_to_size';
		$bias      = (int) $chart['bias'];
		if ( 'runs_small' === $direction ) {
			$bias = min( 2, $bias + 1 );
		} elseif ( 'runs_large' === $direction ) {
			$bias = max( -2, $bias - 1 );
		}
		$suggestions = array();
		foreach ( array_slice( (array) ( $d['suggestions'] ?? array() ), 0, 12 ) as $s ) {
			if ( is_array( $s ) ) {
				$suggestions[] = array(
					'size'      => mb_substr( sanitize_text_field( (string) ( $s['size'] ?? '' ) ), 0, 24 ),
					'direction' => in_array( $s['direction'] ?? '', array( 'runs_small', 'runs_large', 'true_to_size' ), true ) ? $s['direction'] : 'true_to_size',
					'advice'    => mb_substr( sanitize_text_field( (string) ( $s['advice'] ?? '' ) ), 0, 200 ),
				);
			}
		}
		return array(
			'summary'     => mb_substr( sanitize_textarea_field( (string) ( $d['summary'] ?? '' ) ), 0, 800 ),
			'suggestions' => $suggestions,
			'direction'   => $direction,
			'bias'        => $bias,
			'fit_note'    => mb_substr( sanitize_text_field( (string) ( $d['fit_note'] ?? '' ) ), 0, 120 ),
			'model'       => (string) $result['model'],
			'tokens'      => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'      => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Category → chart mapping suggestions (draft).
	 *
	 * @return array|\WP_Error { items[{category_id, category, chart_id, chart, reason}], model, tokens }
	 */
	public static function assign() {
		$charts = Charts::all( array( 'status' => 'publish' ) );
		if ( ! $charts ) {
			return new \WP_Error( 'dev3sg_no_charts', __( 'Create a size chart first.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 80,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return new \WP_Error( 'dev3sg_no_categories', __( 'There are no product categories with products yet.', 'dev3-ai-size-guide-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$chart_list = array();
		$chart_map  = array();
		foreach ( $charts as $c ) {
			$chart_list[]          = array(
				'id'      => $c['id'],
				'name'    => $c['name'],
				'profile' => $c['profile'],
				'sizes'   => array_slice( Charts::sizes( $c ), 0, 10 ),
			);
			$chart_map[ $c['id'] ] = $c['name'];
		}
		$cat_list = array();
		$cat_map  = array();
		foreach ( $terms as $t ) {
			$samples                = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 3,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- three sample titles per category for the prompt.
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'terms'    => $t->term_id,
						),
					),
				)
			);
			$parent                 = $t->parent ? get_term( $t->parent, 'product_cat' ) : null;
			$cat_list[]             = array(
				'id'       => $t->term_id,
				'name'     => $t->name,
				'parent'   => $parent && ! is_wp_error( $parent ) ? $parent->name : '',
				'products' => array_map( 'get_the_title', $samples ),
			);
			$cat_map[ $t->term_id ] = $t->name;
		}
		$result = Prompts::run(
			'suggest_assign',
			array(
				'charts'     => $chart_list,
				'categories' => $cat_list,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = array();
		foreach ( (array) ( $result['data']['items'] ?? array() ) as $row ) {
			$cat   = (int) ( $row['category_id'] ?? 0 );
			$chart = (int) ( $row['chart_id'] ?? 0 );
			if ( isset( $cat_map[ $cat ], $chart_map[ $chart ] ) ) {
				$items[ $cat ] = array(
					'category_id' => $cat,
					'category'    => $cat_map[ $cat ],
					'chart_id'    => $chart,
					'chart'       => $chart_map[ $chart ],
					'reason'      => mb_substr( sanitize_text_field( (string) ( $row['reason'] ?? '' ) ), 0, 160 ),
				);
			}
		}
		return array(
			'items'  => array_values( $items ),
			'model'  => (string) $result['model'],
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Job handler: classifies a chunk of return notes; suggestions are stored for acceptance.
	 *
	 * @param array $items Record ids.
	 * @param array $job   Job.
	 * @return array id => true|WP_Error
	 */
	public static function run_classify_chunk( array $items, array $job ) {
		unset( $job );
		$notes = array();
		foreach ( $items as $id ) {
			$rec = Returns::get( (int) $id );
			if ( $rec && '' !== $rec['note'] ) {
				$notes[ (int) $id ] = mb_substr( $rec['note'], 0, 300 );
			}
		}
		$out = array();
		if ( ! $notes ) {
			foreach ( $items as $id ) {
				$out[ $id ] = true;
			}
			return $out;
		}
		$result = Prompts::run( 'classify_returns', array( 'notes' => $notes ), 'admin', array( 'mask_pii' => true ) );
		if ( is_wp_error( $result ) ) {
			foreach ( $items as $id ) {
				$out[ $id ] = $result;
			}
			return $out;
		}
		$found = array();
		foreach ( (array) ( $result['data']['items'] ?? array() ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( isset( $notes[ $id ] ) ) {
				Returns::set_ai_reason( $id, (string) ( $row['reason'] ?? '' ) );
				$found[ $id ] = true;
			}
		}
		foreach ( $items as $id ) {
			$out[ $id ] = isset( $found[ (int) $id ] ) || ! isset( $notes[ (int) $id ] ) ? true : __( 'No classification returned.', 'dev3-ai-size-guide-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * Whether AI is usable now (for the UI).
	 *
	 * @return bool
	 */
	public static function available() {
		return (bool) Settings_Store::get( 'ai_enabled', true ) && '' !== (string) Settings_Store::api_key();
	}
}
