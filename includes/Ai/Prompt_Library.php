<?php
/**
 * All DEV3 AI prompts of this plugin (one place, versioned, unit-tested with fixtures).
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Ai;

use Dev3\AiSizeGuide\Charts\Measures;
use Dev3\AiSizeGuide\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt registry.
 */
class Prompt_Library {

	/**
	 * Gemini schema string.
	 *
	 * @param int   $max  Max length.
	 * @param array $choices Optional enum.
	 * @return array
	 */
	private static function str( $max, array $choices = array() ) {
		$s = array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
		if ( $choices ) {
			$s['enum'] = $choices;
		}
		return $s;
	}

	/**
	 * Structured chart schema shared by the three chart prompts.
	 *
	 * @return array
	 */
	public static function chart_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'name'     => self::str( 80 ),
				'profile'  => self::str( 10, array( 'women', 'men', 'unisex', 'kids' ) ),
				'unit'     => self::str( 4, array( 'cm', 'in', 'mm' ) ),
				'tables'   => array(
					'type'     => 'ARRAY',
					'maxItems' => 4,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'title'   => self::str( 60 ),
							'type'    => self::str( 8, array( 'body', 'garment' ) ),
							'columns' => array(
								'type'     => 'ARRAY',
								'maxItems' => 12,
								'items'    => array(
									'type'       => 'OBJECT',
									'properties' => array(
										'label'   => self::str( 40 ),
										'kind'    => self::str( 8, array( 'size', 'measure', 'text' ) ),
										'measure' => self::str( 12, array_keys( Measures::all() ) ),
									),
									'required'   => array( 'label', 'kind' ),
								),
							),
							'rows'    => array(
								'type'     => 'ARRAY',
								'maxItems' => 40,
								'items'    => array(
									'type'     => 'ARRAY',
									'maxItems' => 12,
									'items'    => self::str( 24 ),
								),
							),
						),
						'required'   => array( 'title', 'columns', 'rows' ),
					),
				),
				'guide'    => self::str( 1500 ),
				'fit_note' => self::str( 200 ),
				'notes'    => self::str( 300 ),
			),
			'required'   => array( 'name', 'unit', 'tables' ),
		);
	}

	/**
	 * Registers every prompt (on init).
	 */
	public static function register() {
		$chart_system = 'You are an apparel and footwear sizing expert who builds size charts for online stores. '
			. 'Return one structured chart. Rules: the first column of each table is the size label (kind "size"); body or garment measurements use kind "measure" with the closest measure key; other size systems (US, UK, EU, IT, JP) use kind "text". '
			. 'Measurement cells contain only a number or a range like "86-91" in the declared unit, no unit symbols. One row per size, in ascending order. '
			. 'Use the store\'s own size labels when they are given. Keep every number realistic for the product type and profile; never invent brands. '
			. '"guide" is short how-to-measure steps (one line per measure). "fit_note" is empty unless the product information says the item runs small/large or has a special fit. "notes" lists assumptions for the store owner.';

		Prompts::register(
			'chart_from_product',
			array(
				'version'  => '1',
				'task'     => 'chart_product',
				'system'   => $chart_system,
				'template' => "Draft a size chart for this product.\nProduct: {{product}}\nRequested profile: {{profile}}\nPreferred unit: {{unit}}\nStore notes: {{notes}}",
				'schema'   => self::chart_schema(),
				'profile'  => 'heavy',
				'cache'    => DAY_IN_SECONDS,
			)
		);
		Prompts::register(
			'chart_from_text',
			array(
				'version'  => '1',
				'task'     => 'chart_text',
				'system'   => $chart_system . ' Copy the values exactly from the source; do not invent sizes or measurements that are not in it. Detect the unit from the source (inches when marked in, inch or ").',
				'template' => "Convert this pasted size chart into the structured format.\nUnit hint: {{unit}}\n---\n{{text}}\n---",
				'schema'   => self::chart_schema(),
				'profile'  => 'heavy',
				'cache'    => DAY_IN_SECONDS,
			)
		);
		Prompts::register(
			'chart_from_image',
			array(
				'version'  => '1',
				'task'     => 'chart_image',
				'system'   => $chart_system . ' Read the values exactly from the image; do not invent rows. If several tables are shown (for example body and garment), return each one.',
				'template' => "Read the size chart in the attached image and convert it into the structured format.\nUnit hint: {{unit}}",
				'schema'   => self::chart_schema(),
				'profile'  => 'heavy',
			)
		);
		Prompts::register(
			'write_guide',
			array(
				'version'  => '1',
				'task'     => 'guide',
				'system'   => 'You write clear, friendly how-to-measure guides for size charts in online stores. Plain text, one short step per line, no markdown, no brand names. Write in {{language}}.',
				'template' => 'Write a how-to-measure guide for a {{profile}} size chart named "{{name}}" with these measurements: {{measures}}. Add one closing tip about choosing between two sizes.',
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array( 'text' => self::str( 1500 ) ),
					'required'   => array( 'text' ),
				),
				'profile'  => 'fast',
				'cache'    => WEEK_IN_SECONDS,
			)
		);
		Prompts::register(
			'explain_fit',
			array(
				'version'  => '1',
				'task'     => 'explain',
				'system'   => 'You are a friendly fit advisor in an online store. Explain a size recommendation in 2-3 short sentences for the shopper, in the language of locale {{locale}}. '
					. 'Use only the facts given: never change the recommended size, never invent measurements, never mention AI, scores or algorithms. Mention the alternative size only if one is given, and the fit note only if one is given.',
				'template' => 'Facts: {{facts}}',
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array( 'text' => self::str( 500 ) ),
					'required'   => array( 'text' ),
				),
				'profile'  => 'fast',
				'cache'    => MONTH_IN_SECONDS,
			)
		);
		Prompts::register(
			'classify_returns',
			array(
				'version'  => '1',
				'task'     => 'classify',
				'system'   => 'You classify product return reasons for a clothing and footwear store. For each note pick one reason: '
					. 'too_small (tight, short, narrow, needs a bigger size), too_large (loose, long, wide, needs a smaller size), fit_ok (fit was fine, returned for another reason related to fit expectations being met), other (quality, colour, damage, late, changed mind, wrong item). Notes may be in any language.',
				'template' => 'Notes (JSON id => text): {{notes}}',
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'items' => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'id'     => array( 'type' => 'INTEGER' ),
									'reason' => self::str( 10, array( 'too_small', 'too_large', 'fit_ok', 'other' ) ),
								),
								'required'   => array( 'id', 'reason' ),
							),
						),
					),
					'required'   => array( 'items' ),
				),
				'profile'  => 'fast',
			)
		);
		Prompts::register(
			'return_insights',
			array(
				'version'  => '1',
				'task'     => 'insights',
				'system'   => 'You are a merchandising analyst for an online fashion store. From return statistics and return notes for one size chart, write a short plain-language summary for the store owner and concrete per-size advice. Never invent numbers; base advice only on the data given. A shopper-facing fit note (max 120 characters, neutral tone, no numbers) is optional.',
				'template' => "Chart: {{chart}}\nStatistics per size (sold units and fit returns): {{stats}}\nReturn notes (sample): {{notes}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'summary'     => self::str( 800 ),
						'suggestions' => array(
							'type'     => 'ARRAY',
							'maxItems' => 12,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'size'      => self::str( 24 ),
									'direction' => self::str( 14, array( 'runs_small', 'runs_large', 'true_to_size' ) ),
									'advice'    => self::str( 200 ),
								),
								'required'   => array( 'size', 'direction', 'advice' ),
							),
						),
						'direction'   => self::str( 14, array( 'runs_small', 'runs_large', 'true_to_size' ) ),
						'fit_note'    => self::str( 120 ),
					),
					'required'   => array( 'summary', 'direction' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			'suggest_assign',
			array(
				'version'  => '1',
				'task'     => 'assign',
				'system'   => 'You map WooCommerce product categories to the most fitting size chart. Only map categories whose products are clothing, footwear or accessories that the chart really fits; skip the rest. Use only ids from the input.',
				'template' => "Size charts: {{charts}}\nCategories (id, name, parent, sample products): {{categories}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'items' => array(
							'type'     => 'ARRAY',
							'maxItems' => 60,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'category_id' => array( 'type' => 'INTEGER' ),
									'chart_id'    => array( 'type' => 'INTEGER' ),
									'reason'      => self::str( 160 ),
								),
								'required'   => array( 'category_id', 'chart_id' ),
							),
						),
					),
					'required'   => array( 'items' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
	}
}
