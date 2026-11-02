<?php
/**
 * Storefront markup: size guide (tables, how to measure, fit quiz), trigger buttons and the popup / bottom sheet.
 * Server-rendered so it works before JavaScript loads and causes no layout shift.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Frontend;

use Dev3\AiSizeGuide\Charts\Measures;
use Dev3\AiSizeGuide\Charts\Units;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Renderer.
 */
class Renderer {

	/**
	 * Id counter.
	 *
	 * @var int
	 */
	private static $uid = 0;

	/**
	 * Unique id.
	 *
	 * @param string $base Base.
	 * @return string
	 */
	private static function id( $base ) {
		++self::$uid;
		return 'dev3sg-' . $base . '-' . self::$uid;
	}

	/**
	 * Default unit system for this visitor.
	 *
	 * @return string metric|imperial
	 */
	public static function default_system() {
		$unit = (string) Settings_Store::get( 'default_unit', 'auto' );
		if ( 'in' === $unit ) {
			return 'imperial';
		}
		if ( 'cm' === $unit ) {
			return 'metric';
		}
		$country = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_country() : '';
		if ( '' === $country && function_exists( 'wc_get_base_location' ) ) {
			$base    = wc_get_base_location();
			$country = (string) ( $base['country'] ?? '' );
		}
		return Units::system_for_country( $country );
	}

	/**
	 * Measures the quiz asks for (from body tables), plus height/weight when estimates can help.
	 *
	 * @param array $chart Chart.
	 * @return array[] { key, label, quantity, help, group: measure|estimate }
	 */
	public static function quiz_fields( array $chart ) {
		$fields = array();
		$girths = false;
		foreach ( $chart['tables'] as $table ) {
			if ( 'garment' === $table['type'] ) {
				continue;
			}
			foreach ( $table['columns'] as $c => $col ) {
				if ( 'measure' !== $col['kind'] || isset( $fields[ $col['measure'] ] ) || 'custom' === $col['measure'] || in_array( $col['measure'], array( 'length', 'width' ), true ) ) {
					continue;
				}
				// Skip columns that are the same for every size (they cannot change the result).
				$values = array_unique( array_filter( array_map( static fn( $row ) => (string) ( $row[ $c ] ?? '' ), $table['rows'] ), 'strlen' ) );
				if ( count( $values ) < 2 ) {
					continue;
				}
				$def                       = Measures::get( $col['measure'] );
				$fields[ $col['measure'] ] = array(
					'key'      => $col['measure'],
					'label'    => $def['label'],
					'quantity' => $def['quantity'],
					'help'     => $def['help'],
					'group'    => in_array( $col['measure'], array( 'height', 'weight' ), true ) ? 'estimate' : 'measure',
				);
				if ( in_array( $col['measure'], array( 'chest', 'bust', 'waist', 'hips' ), true ) ) {
					$girths = true;
				}
			}
		}
		if ( $girths && Settings_Store::get( 'estimates', true ) ) {
			foreach ( array( 'height', 'weight' ) as $key ) {
				if ( ! isset( $fields[ $key ] ) ) {
					$def            = Measures::get( $key );
					$fields[ $key ] = array(
						'key'      => $key,
						'label'    => $def['label'],
						'quantity' => $def['quantity'],
						'help'     => $def['help'],
						'group'    => 'estimate',
					);
				}
			}
		}
		return array_values( $fields );
	}

	/**
	 * "I usually wear" options: text columns of all tables (system => values).
	 *
	 * @param array $chart Chart.
	 * @return array { col_key => { label, values[] } }
	 */
	public static function usual_options( array $chart ) {
		$out = array();
		foreach ( $chart['tables'] as $table ) {
			foreach ( $table['columns'] as $c => $col ) {
				if ( 'text' !== $col['kind'] || isset( $out[ $col['key'] ] ) ) {
					continue;
				}
				$values = array_values( array_unique( array_filter( array_map( static fn( $row ) => (string) ( $row[ $c ] ?? '' ), $table['rows'] ), 'strlen' ) ) );
				if ( count( $values ) >= 2 && count( $values ) <= 40 ) {
					$out[ $col['key'] ] = array(
						'label'  => $col['label'],
						'values' => $values,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Dual-unit value markup.
	 *
	 * @param string $metric   Metric text.
	 * @param string $imperial Imperial text.
	 * @return string
	 */
	private static function dual( $metric, $imperial ) {
		if ( $metric === $imperial ) {
			return esc_html( $metric );
		}
		return '<span data-u="metric">' . esc_html( $metric ) . '</span><span data-u="imperial">' . esc_html( $imperial ) . '</span>';
	}

	/**
	 * One table.
	 *
	 * @param array $table Table.
	 * @return string
	 */
	public static function table( array $table ) {
		$title = '' !== $table['title'] ? $table['title'] : __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' );
		$html  = '<div class="dev3sg-table-wrap" role="region" tabindex="0" aria-label="' . esc_attr( $title ) . '">';
		$html .= '<table class="dev3sg-table"><caption class="dev3sg-sr">' . esc_html( $title ) . '</caption><thead><tr>';
		foreach ( $table['columns'] as $col ) {
			$unit = '';
			if ( 'measure' === $col['kind'] ) {
				$unit = ' <span class="dev3sg-table__unit">' . self::dual( Units::unit_label( $col['quantity'], 'metric' ), Units::unit_label( $col['quantity'], 'imperial' ) ) . '</span>';
			}
			$html .= '<th scope="col">' . esc_html( $col['label'] ) . $unit . '</th>';
		}
		$html    .= '</tr></thead><tbody>';
		$size_col = 0;
		foreach ( $table['columns'] as $c => $col ) {
			if ( 'size' === $col['kind'] ) {
				$size_col = $c;
				break;
			}
		}
		foreach ( $table['rows'] as $row ) {
			$html .= '<tr data-size="' . esc_attr( strtolower( (string) ( $row[ $size_col ] ?? '' ) ) ) . '">';
			foreach ( $table['columns'] as $c => $col ) {
				$cell = (string) ( $row[ $c ] ?? '' );
				if ( 'size' === $col['kind'] ) {
					$html .= '<th scope="row">' . esc_html( $cell ) . '</th>';
				} elseif ( 'measure' === $col['kind'] && '' !== $cell ) {
					$html .= '<td>' . self::dual( Units::format_cell( $cell, $col['quantity'], 'metric' ), Units::format_cell( $cell, $col['quantity'], 'imperial' ) ) . '</td>';
				} else {
					$html .= '<td>' . ( '' !== $cell ? esc_html( $cell ) : '<span aria-label="' . esc_attr__( 'Not available', 'dev3-ai-size-guide-for-woocommerce' ) . '">&ndash;</span>' ) . '</td>';
				}
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		return $html;
	}

	/**
	 * The size guide (tabs: tables, how to measure, find my size).
	 *
	 * @param array $chart      Chart.
	 * @param int   $product_id Product (0 = none).
	 * @param array $args       { tab: first|quiz, context: popup|tab|inline, quiz: bool, only: '' |quiz }.
	 * @return string
	 */
	public static function guide( array $chart, $product_id = 0, array $args = array() ) {
		$context = $args['context'] ?? 'inline';
		$system  = self::default_system();
		$panels  = array();
		foreach ( $chart['tables'] as $table ) {
			if ( ! $table['rows'] ) {
				continue;
			}
			$panels[] = array(
				'id'    => 'table',
				'label' => '' !== $table['title'] ? $table['title'] : __( 'Size chart', 'dev3-ai-size-guide-for-woocommerce' ),
				'html'  => self::table( $table ),
			);
		}
		$guide_text = trim( (string) $chart['guide']['text'] );
		$image      = $chart['guide']['image_id'] ? wp_get_attachment_image(
			$chart['guide']['image_id'],
			'medium_large',
			false,
			array(
				'class'   => 'dev3sg-measure__img',
				'loading' => 'lazy',
			)
		) : '';
		if ( '' !== $guide_text || '' !== $image ) {
			$steps = '';
			foreach ( array_filter( array_map( 'trim', explode( "\n", $guide_text ) ) ) as $line ) {
				$parts  = explode( ':', $line, 2 );
				$steps .= 2 === count( $parts ) && mb_strlen( $parts[0] ) <= 30
					? '<li><strong>' . esc_html( $parts[0] ) . '</strong> ' . esc_html( trim( $parts[1] ) ) . '</li>'
					: '<li>' . esc_html( $line ) . '</li>';
			}
			$panels[] = array(
				'id'    => 'measure',
				'label' => __( 'How to measure', 'dev3-ai-size-guide-for-woocommerce' ),
				'html'  => '<div class="dev3sg-measure">' . $image . ( '' !== $steps ? '<ol class="dev3sg-measure__steps">' . $steps . '</ol>' : '' ) . '</div>',
			);
		}
		$quiz = '';
		if ( ( $args['quiz'] ?? Settings_Store::get( 'show_quiz', true ) ) && ( self::quiz_fields( $chart ) || self::usual_options( $chart ) ) ) {
			$quiz     = self::quiz( $chart, $product_id );
			$panels[] = array(
				'id'    => 'quiz',
				'label' => __( 'Find my size', 'dev3-ai-size-guide-for-woocommerce' ),
				'html'  => $quiz,
			);
		}
		if ( 'quiz' === ( $args['only'] ?? '' ) ) {
			$panels = array_values( array_filter( $panels, static fn( $p ) => 'quiz' === $p['id'] ) );
		}
		if ( ! $panels ) {
			return '';
		}
		$active = 0;
		if ( 'quiz' === ( $args['tab'] ?? '' ) ) {
			foreach ( $panels as $i => $p ) {
				if ( 'quiz' === $p['id'] ) {
					$active = $i;
				}
			}
		}
		$has_measures = false;
		foreach ( $chart['tables'] as $table ) {
			foreach ( $table['columns'] as $col ) {
				$has_measures = $has_measures || 'measure' === $col['kind'];
			}
		}
		$html  = '<div class="dev3sg-guide dev3sg-guide--' . esc_attr( $context ) . '" data-dev3sg-guide data-unit="' . esc_attr( $system ) . '" data-chart="' . esc_attr( (string) $chart['id'] ) . '" data-product="' . esc_attr( (string) (int) $product_id ) . '">';
		$html .= '<div class="dev3sg-toolbar">';
		if ( count( $panels ) > 1 ) {
			$html .= '<div class="dev3sg-tabs" role="tablist" aria-label="' . esc_attr__( 'Size guide sections', 'dev3-ai-size-guide-for-woocommerce' ) . '">';
			foreach ( $panels as $i => $p ) {
				$panels[ $i ]['tab_id']   = self::id( 'tab' );
				$panels[ $i ]['panel_id'] = self::id( 'panel' );
				$html                    .= '<button type="button" role="tab" class="dev3sg-tab" id="' . esc_attr( $panels[ $i ]['tab_id'] ) . '" aria-controls="' . esc_attr( $panels[ $i ]['panel_id'] ) . '" aria-selected="' . ( $i === $active ? 'true' : 'false' ) . '" tabindex="' . ( $i === $active ? '0' : '-1' ) . '" data-tab="' . esc_attr( $p['id'] ) . '">' . esc_html( $p['label'] ) . '</button>';
			}
			$html .= '</div>';
		}
		if ( $has_measures ) {
			$html .= '<div class="dev3sg-units" role="group" aria-label="' . esc_attr__( 'Units', 'dev3-ai-size-guide-for-woocommerce' ) . '">';
			$html .= '<button type="button" class="dev3sg-unit" data-set-unit="metric" aria-pressed="' . ( 'metric' === $system ? 'true' : 'false' ) . '">cm</button>';
			$html .= '<button type="button" class="dev3sg-unit" data-set-unit="imperial" aria-pressed="' . ( 'imperial' === $system ? 'true' : 'false' ) . '">in</button>';
			$html .= '</div>';
		}
		$html .= '</div>';
		if ( '' !== (string) $chart['fit_note'] ) {
			$html .= '<p class="dev3sg-note"><span aria-hidden="true">&#9432;</span> ' . esc_html( $chart['fit_note'] ) . '</p>';
		}
		foreach ( $panels as $i => $p ) {
			if ( count( $panels ) > 1 ) {
				$html .= '<div class="dev3sg-panel" role="tabpanel" id="' . esc_attr( $p['panel_id'] ) . '" aria-labelledby="' . esc_attr( $p['tab_id'] ) . '" data-panel="' . esc_attr( $p['id'] ) . '"' . ( $i === $active ? '' : ' hidden' ) . '>' . $p['html'] . '</div>';
			} else {
				$html .= '<div class="dev3sg-panel" data-panel="' . esc_attr( $p['id'] ) . '">' . $p['html'] . '</div>';
			}
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Fit quiz form (results are rendered by the storefront script).
	 *
	 * @param array $chart      Chart.
	 * @param int   $product_id Product.
	 * @return string
	 */
	public static function quiz( array $chart, $product_id ) {
		$fields = self::quiz_fields( $chart );
		$usual  = Settings_Store::get( 'quiz_usual', true ) ? self::usual_options( $chart ) : array();
		$html   = '<form class="dev3sg-quiz" data-dev3sg-quiz novalidate data-chart="' . esc_attr( (string) $chart['id'] ) . '" data-product="' . esc_attr( (string) (int) $product_id ) . '">';
		$html  .= '<p class="dev3sg-quiz__intro">' . esc_html__( 'Enter what you know. Measurements give the best result; height and weight give a quick estimate.', 'dev3-ai-size-guide-for-woocommerce' ) . '</p>';
		$groups = array(
			'measure'  => __( 'Your measurements', 'dev3-ai-size-guide-for-woocommerce' ),
			'estimate' => __( 'Height and weight', 'dev3-ai-size-guide-for-woocommerce' ),
		);
		foreach ( $groups as $group => $legend ) {
			$list = array_filter( $fields, static fn( $f ) => $f['group'] === $group );
			if ( ! $list ) {
				continue;
			}
			$html .= '<fieldset class="dev3sg-fieldset"><legend>' . esc_html( $legend ) . '</legend><div class="dev3sg-fields">';
			foreach ( $list as $f ) {
				$id    = self::id( 'f-' . $f['key'] );
				$help  = '' !== $f['help'] ? self::id( 'h-' . $f['key'] ) : '';
				$html .= '<div class="dev3sg-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>';
				$html .= '<div class="dev3sg-input"><input type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*" id="' . esc_attr( $id ) . '" name="m[' . esc_attr( $f['key'] ) . ']" data-measure="' . esc_attr( $f['key'] ) . '" data-quantity="' . esc_attr( $f['quantity'] ) . '" autocomplete="off"' . ( $help ? ' aria-describedby="' . esc_attr( $help ) . '"' : '' ) . '>';
				$html .= '<span class="dev3sg-input__suffix" aria-hidden="true">' . self::dual( Units::unit_label( $f['quantity'], 'metric' ), Units::unit_label( $f['quantity'], 'imperial' ) ) . '</span></div>';
				if ( $help ) {
					$html .= '<p class="dev3sg-field__help" id="' . esc_attr( $help ) . '">' . esc_html( $f['help'] ) . '</p>';
				}
				$html .= '</div>';
			}
			$html .= '</div></fieldset>';
		}
		if ( $usual ) {
			$id    = self::id( 'usual' );
			$html .= '<div class="dev3sg-field dev3sg-field--wide"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Size you usually wear (optional)', 'dev3-ai-size-guide-for-woocommerce' ) . '</label><select id="' . esc_attr( $id ) . '" name="usual"><option value="">' . esc_html__( 'Not sure', 'dev3-ai-size-guide-for-woocommerce' ) . '</option>';
			foreach ( $usual as $key => $opt ) {
				$html .= '<optgroup label="' . esc_attr( $opt['label'] ) . '">';
				foreach ( $opt['values'] as $v ) {
					$html .= '<option value="' . esc_attr( $key . '|' . $v ) . '">' . esc_html( $opt['label'] . ' ' . $v ) . '</option>';
				}
				$html .= '</optgroup>';
			}
			$html .= '</select></div>';
		}
		if ( Settings_Store::get( 'quiz_fit_pref', true ) ) {
			$html .= '<fieldset class="dev3sg-fieldset"><legend>' . esc_html__( 'How do you like it to fit?', 'dev3-ai-size-guide-for-woocommerce' ) . '</legend><div class="dev3sg-chips">';
			$prefs = array(
				'snug'    => __( 'Snug', 'dev3-ai-size-guide-for-woocommerce' ),
				'regular' => __( 'Regular', 'dev3-ai-size-guide-for-woocommerce' ),
				'relaxed' => __( 'Relaxed', 'dev3-ai-size-guide-for-woocommerce' ),
			);
			foreach ( $prefs as $value => $label ) {
				$html .= '<label class="dev3sg-chip"><input type="radio" name="fit" value="' . esc_attr( $value ) . '"' . checked( 'regular', $value, false ) . '><span>' . esc_html( $label ) . '</span></label>';
			}
			$html .= '</div></fieldset>';
		}
		if ( Settings_Store::get( 'save_measurements', true ) ) {
			$html .= '<label class="dev3sg-check"><input type="checkbox" name="remember" value="1"> <span>' . esc_html__( 'Remember my measurements on this device', 'dev3-ai-size-guide-for-woocommerce' ) . '</span></label>';
		}
		$html .= '<p class="dev3sg-quiz__error" role="alert" hidden></p>';
		$html .= '<div class="dev3sg-quiz__actions"><button type="submit" class="dev3sg-btn dev3sg-btn--primary">' . esc_html__( 'Find my size', 'dev3-ai-size-guide-for-woocommerce' ) . '</button></div>';
		$html .= '<div class="dev3sg-result" aria-live="polite" hidden></div>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * Trigger buttons for the popup.
	 *
	 * @param string $sheet_id Sheet element id.
	 * @param bool   $quiz     Show the quiz button.
	 * @return string
	 */
	public static function buttons( $sheet_id, $quiz = true ) {
		$ruler = '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 15.5 15.5 3 21 8.5 8.5 21z"/><path d="m7 12 2 2M10 9l2 2M13 6l2 2"/></svg>';
		$spark = '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="3.2"/><path d="M5.5 20.5c.6-3.6 3.3-6 6.5-6s5.9 2.4 6.5 6"/></svg>';
		$html  = '<div class="dev3sg-triggers">';
		$html .= '<button type="button" class="dev3sg-trigger" data-dev3sg-open="' . esc_attr( $sheet_id ) . '" aria-haspopup="dialog">' . $ruler . '<span>' . esc_html( \Dev3\AiSizeGuide\Settings::label( 'button_label' ) ) . '</span></button>';
		if ( $quiz ) {
			$html .= '<button type="button" class="dev3sg-trigger dev3sg-trigger--quiz" data-dev3sg-open="' . esc_attr( $sheet_id ) . '" data-dev3sg-tab="quiz" aria-haspopup="dialog">' . $spark . '<span>' . esc_html( \Dev3\AiSizeGuide\Settings::label( 'quiz_label' ) ) . '</span></button>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Popup / bottom sheet wrapper.
	 *
	 * @param string $sheet_id   Id.
	 * @param array  $chart      Chart.
	 * @param int    $product_id Product.
	 * @return string
	 */
	public static function sheet( $sheet_id, array $chart, $product_id ) {
		$title_id = $sheet_id . '-title';
		$name     = $product_id ? get_the_title( $product_id ) : $chart['name'];
		$html     = '<div class="dev3sg-sheet" id="' . esc_attr( $sheet_id ) . '" data-dev3sg-sheet hidden>';
		$html    .= '<div class="dev3sg-sheet__overlay" data-dev3sg-close></div>';
		$html    .= '<div class="dev3sg-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $title_id ) . '" tabindex="-1">';
		$html    .= '<div class="dev3sg-sheet__grab" data-dev3sg-grab aria-hidden="true"><span></span></div>';
		$html    .= '<div class="dev3sg-sheet__head"><div class="dev3sg-sheet__titles"><h2 class="dev3sg-sheet__title" id="' . esc_attr( $title_id ) . '">' . esc_html( \Dev3\AiSizeGuide\Settings::label( 'button_label' ) ) . '</h2>';
		$html    .= '<p class="dev3sg-sheet__sub">' . esc_html( $name ) . '</p></div>';
		$html    .= '<button type="button" class="dev3sg-close" data-dev3sg-close aria-label="' . esc_attr__( 'Close size guide', 'dev3-ai-size-guide-for-woocommerce' ) . '"><svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>';
		$html    .= '<div class="dev3sg-sheet__body">' . self::guide( $chart, $product_id, array( 'context' => 'popup' ) ) . '</div>';
		$html    .= '</div></div>';
		return $html;
	}

	/**
	 * Inline CSS variables from settings.
	 *
	 * @return string
	 */
	public static function inline_css() {
		$accent = sanitize_hex_color( (string) Settings_Store::get( 'accent_color', '#1d4ed8' ) );
		return $accent ? ':root{--dev3sg-accent-color:' . $accent . ';}' : '';
	}
}
