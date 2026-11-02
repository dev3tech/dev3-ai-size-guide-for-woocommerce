<?php
/**
 * Returns-reason learning: classifier, records from refunds / order feedback / the developer action, insights.
 *
 * @package Dev3\AiSizeGuide
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Fit\Engine;
use Dev3\AiSizeGuide\Learning\Classifier;
use Dev3\AiSizeGuide\Learning\Insights;
use Dev3\AiSizeGuide\Learning\Returns;
use Dev3\AiSizeGuide\Learning\Sources;

class LearningTest extends Dev3sg_Base_Case {

	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = 'size'" ); // phpcs:ignore
		delete_transient( 'wc_attribute_taxonomies' );
		unregister_taxonomy( 'pa_size' );
		parent::tear_down();
	}

	public function test_classifier() {
		$cases = array(
			'Too small across the shoulders'          => 'too_small',
			'I need a bigger size'                     => 'too_small',
			'It runs small, size up'                   => 'too_small',
			'Way too big and baggy'                    => 'too_large',
			'Ordering a smaller one'                   => 'too_large',
			'Leider zu klein'                          => 'too_small',
			'Trop grand pour moi'                      => 'too_large',
			'Fits well but the colour is different'    => 'fit_ok',
			'Arrived damaged'                          => 'other',
			'Changed my mind'                          => 'other',
			'tight in the chest'                       => 'too_small',
			''                                         => 'unknown',
			'meh'                                      => 'unknown',
			'Too big in the waist, too small in chest' => 'unknown',
		);
		foreach ( $cases as $text => $expected ) {
			$this->assertSame( $expected, Classifier::classify( $text ), $text );
		}
	}

	public function test_returns_add_is_idempotent_per_order_item() {
		$chart = $this->chart( 'men_tops' );
		$p     = $this->product();
		$row   = array(
			'product_id' => $p,
			'chart_id'   => $chart,
			'size'       => 'm',
			'note'       => 'too small',
			'source'     => 'refund',
			'order_id'   => 10,
			'item_id'    => 20,
		);
		$a = Returns::add( $row );
		$b = Returns::add( array_merge( $row, array( 'note' => 'too big' ) ) );
		$this->assertSame( $a, $b );
		$rec = Returns::get( $a );
		$this->assertSame( 'too_large', $rec['reason'], 'Repeat updates the record' );
		$this->assertSame( 'M', $rec['size'], "Size spelled like the chart's label" );
		$this->assertSame( 1, Returns::query()['total'] );
		$this->assertWPError( Returns::add( array( 'product_id' => 0 ) ) );
		do_action( 'dev3sg_record_return', $p, 'L', 'way too tight', array( 'order_id' => 11 ) );
		$this->assertSame( 2, Returns::query()['total'] );
		$this->assertSame( 1, Returns::query( array( 'reason' => 'too_small' ) )['total'] );
		$this->assertSame( 1, Returns::query( array( 'search' => 'tight' ) )['total'] );
	}

	/**
	 * Variable product with a pa_size attribute and an order for size M.
	 *
	 * @return array [ order, item, variation id, parent id ]
	 */
	private function sized_order() {
		$attr_id = wc_create_attribute(
			array(
				'name' => 'Size',
				'slug' => 'size',
			)
		);
		$this->assertIsInt( $attr_id );
		register_taxonomy( 'pa_size', 'product' );
		$term = wp_insert_term( 'M', 'pa_size', array( 'slug' => 'm' ) );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Oxford shirt' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $attr_id );
		$attribute->set_name( 'pa_size' );
		$attribute->set_options( array( $term['term_id'] ) );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'pa_size' => 'm' ) );
		$variation->set_regular_price( '40' );
		$variation->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $variation->get_id() ), 1, array( 'variation' => array( 'attribute_pa_size' => 'm' ) ) );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		$items = $order->get_items();
		return array( $order, reset( $items ), $variation->get_id(), $parent->get_id() );
	}

	public function test_refund_reason_creates_a_record() {
		list( $order, $item, , $parent ) = $this->sized_order();
		$chart                           = $this->chart( 'men_tops', array( 'products' => array( $parent ) ) );
		$this->assertSame( 'M', Sources::item_size( $item ) );

		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 40,
				'reason'     => 'Too small on the shoulders',
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => 40,
					),
				),
			)
		);
		$list = Returns::query();
		$this->assertSame( 1, $list['total'] );
		$rec = $list['items'][0];
		$this->assertSame( 'too_small', $rec['reason'] );
		$this->assertSame( 'M', $rec['size'] );
		$this->assertSame( $parent, $rec['product_id'], 'Variations are stored on the parent' );
		$this->assertSame( $chart, $rec['chart_id'] );
		$this->assertSame( 'refund', $rec['source'] );

		$sold = Insights::sold( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$this->assertSame( 1, $sold[ $chart ]['M'] ?? 0, 'Units sold per chart and size' );
	}

	public function test_order_fit_feedback_box() {
		list( $order, $item ) = $this->sized_order();
		$this->assertSame( 1, Sources::save_feedback( $order->get_id(), array( $item->get_id() => array( 'reason' => 'too_large', 'note' => 'Roomy' ) ) ) );
		$this->assertSame( 'too_large', Returns::query()['items'][0]['reason'] );
		$this->assertSame( 'too_large', wc_get_order( $order->get_id() )->get_item( $item->get_id() )->get_meta( Sources::ITEM_META )['reason'] );
		Sources::save_feedback( $order->get_id(), array( $item->get_id() => array( 'reason' => 'too_small' ) ) );
		$this->assertSame( 1, Returns::query()['total'], 'Same line updates, not duplicates' );
		Sources::save_feedback( $order->get_id(), array( $item->get_id() => array( 'reason' => '' ) ) );
		$this->assertSame( 0, Returns::query()['total'], 'Clearing the feedback removes the record' );

		ob_start();
		( new Sources() )->render_meta_box( $order );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'dev3sg_fit[' . $item->get_id() . '][reason]', $html );
		$this->assertStringContainsString( 'Size: M', $html );
	}

	public function test_insights_suggest_and_apply_bias() {
		$chart = $this->chart( 'men_tops' );
		$p     = $this->product();
		for ( $i = 0; $i < 4; $i++ ) {
			Returns::add(
				array(
					'product_id' => $p,
					'chart_id'   => $chart,
					'size'       => 'M',
					'note'       => 'too small',
				)
			);
		}
		$row = Insights::all( true )[0];
		$this->assertNull( $row['suggestion'], 'Below the minimum sample (5)' );

		Returns::add(
			array(
				'product_id' => $p,
				'chart_id'   => $chart,
				'size'       => 'L',
				'note'       => 'too tight',
			)
		);
		Returns::add(
			array(
				'product_id' => $p,
				'chart_id'   => $chart,
				'size'       => 'L',
				'note'       => 'too big',
			)
		);
		$row = Insights::all( true )[0];
		$this->assertSame( 'runs_small', $row['suggestion']['direction'] );
		$this->assertSame( 1, $row['suggestion']['bias'] );
		$this->assertSame( 83, $row['suggestion']['share'] );
		$sizes = array_column( $row['sizes'], null, 'size' );
		$this->assertSame( 4, $sizes['M']['too_small'] );
		$this->assertSame( 'runs_small', $sizes['M']['direction'] );

		Insights::dismiss( $chart, $row['suggestion']['signature'] );
		$this->assertNull( Insights::all()[0]['suggestion'], 'Dismissed until more returns arrive' );
		$this->assertSame( 0, Insights::open_count() );

		$applied = Insights::apply( $chart, 1, 'Runs small, size up.' );
		$this->assertSame( 1, $applied['bias'] );
		$this->assertSame( 'Runs small, size up.', $applied['fit_note'] );
		$r = Engine::recommend( Charts::get( $chart ), array( 'measurements' => array( 'chest' => 98 ) ) );
		$this->assertSame( 'L', $r['size'], 'The quiz moves one size up after accepting' );

		$row = Insights::all( true )[0];
		$this->assertNull( $row['suggestion'], 'Older returns do not count again after the bias changed' );
	}

	public function test_insights_threshold_is_configurable() {
		$chart = $this->chart( 'men_tops' );
		$p     = $this->product();
		foreach ( array( 'too small', 'too small', 'too big' ) as $note ) {
			Returns::add(
				array(
					'product_id' => $p,
					'chart_id'   => $chart,
					'size'       => 'M',
					'note'       => $note,
				)
			);
		}
		\Dev3\AiSizeGuide\Core3\Core\Settings_Store::update(
			array(
				'learn_min_sample' => 3,
				'learn_threshold'  => 70,
			)
		);
		\Dev3\AiSizeGuide\Core3\Core\Settings_Store::flush();
		$this->assertNull( Insights::all( true )[0]['suggestion'], '67% is below a 70% threshold' );
		\Dev3\AiSizeGuide\Core3\Core\Settings_Store::update( array( 'learn_threshold' => 60 ) );
		\Dev3\AiSizeGuide\Core3\Core\Settings_Store::flush();
		$this->assertNotNull( Insights::all( true )[0]['suggestion'] );
	}
}
