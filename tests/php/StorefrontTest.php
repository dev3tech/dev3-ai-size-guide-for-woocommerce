<?php
/**
 * Storefront markup: guide, sticky tables, quiz form, popup sheet, product tab, shortcodes, block and tracking.
 *
 * @package Dev3\AiSizeGuide
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;
use Dev3\AiSizeGuide\Data\Events;
use Dev3\AiSizeGuide\Frontend\Renderer;
use Dev3\AiSizeGuide\Frontend\Shortcodes;
use Dev3\AiSizeGuide\Frontend\Storefront;

class StorefrontTest extends Dev3sg_Base_Case {

	public function test_guide_markup_has_tables_units_tabs_and_quiz() {
		$id   = $this->chart( 'men_tops', array(), array( 'fit_note' => 'Slim cut.' ) );
		$html = Renderer::guide( Charts::get( $id ), 0, array( 'context' => 'popup' ) );
		$this->assertStringContainsString( 'class="dev3sg-table-wrap" role="region" tabindex="0"', $html );
		$this->assertStringContainsString( '<th scope="col">Chest <span class="dev3sg-table__unit">', $html );
		$this->assertStringContainsString( '<span data-u="metric">94–102</span><span data-u="imperial">37–40</span>', $html );
		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'data-tab="quiz"', $html );
		$this->assertStringContainsString( 'data-tab="measure"', $html );
		$this->assertStringContainsString( 'name="m[chest]"', $html );
		$this->assertStringContainsString( 'name="m[height]"', $html, 'Height/weight offered for estimates' );
		$this->assertStringContainsString( 'name="fit" value="regular" checked', $html );
		$this->assertStringContainsString( 'Slim cut.', $html );
		$this->assertStringContainsString( 'data-set-unit="imperial"', $html );
		$this->assertMatchesRegularExpression( '/<label for="(dev3sg-f-chest-\d+)">Chest<\/label>.*id="\1"/s', $html, 'Labels are bound to inputs' );

		Settings_Store::update(
			array(
				'show_quiz'      => false,
				'default_unit'   => 'in',
			)
		);
		Settings_Store::flush();
		$plain = Renderer::guide( Charts::get( $id ) );
		$this->assertStringNotContainsString( 'data-dev3sg-quiz', $plain );
		$this->assertStringContainsString( 'data-unit="imperial"', $plain );
	}

	public function test_quiz_offers_usual_sizes_from_text_columns() {
		$id   = $this->chart( 'women_tops' );
		$html = Renderer::quiz( Charts::get( $id ), 0 );
		$this->assertStringContainsString( '<optgroup label="UK">', $html );
		$this->assertStringContainsString( 'value="uk|12-14"', $html );
		$this->assertStringContainsString( 'name="remember"', $html );
	}

	public function test_buttons_sheet_and_tab_for_a_product() {
		$cat = $this->cat( 'Tops' );
		$p   = $this->product( array( $cat ) );
		$this->chart( 'men_tops', array( 'categories' => array( $cat ) ) );
		$buttons = Storefront::buttons_for( $p );
		$this->assertStringContainsString( 'data-dev3sg-open="dev3sg-sheet-' . $p, $buttons );
		$this->assertStringContainsString( 'data-dev3sg-tab="quiz"', $buttons );
		$this->assertStringContainsString( 'Size guide', $buttons );
		$this->assertSame( '', Storefront::buttons_for( $p ), 'Printed once per product' );
		$this->assertTrue( wp_script_is( Storefront::HANDLE, 'enqueued' ) );

		ob_start();
		Storefront::print_sheets();
		$sheet = ob_get_clean();
		$this->assertStringContainsString( 'role="dialog" aria-modal="true"', $sheet );
		$this->assertStringContainsString( 'data-dev3sg-sheet hidden', $sheet );
		$this->assertStringContainsString( 'aria-label="Close size guide"', $sheet );
		$this->assertStringContainsString( 'dev3sg-sheet__grab', $sheet );

		$GLOBALS['product'] = wc_get_product( $p );
		$tabs               = ( new Storefront() )->tab( array() );
		$this->assertArrayHasKey( 'dev3sg_size_guide', $tabs );
		ob_start();
		call_user_func( $tabs['dev3sg_size_guide']['callback'] );
		$this->assertStringContainsString( 'dev3sg-guide--tab', ob_get_clean() );

		Settings_Store::update( array( 'display_mode' => 'popup' ) );
		Settings_Store::flush();
		$this->assertArrayNotHasKey( 'dev3sg_size_guide', ( new Storefront() )->tab( array() ) );
		unset( $GLOBALS['product'] );

		$none = $this->product();
		$this->assertSame( '', Storefront::buttons_for( $none ), 'No chart, no buttons' );
	}

	public function test_require_size_attribute_setting() {
		$cat = $this->cat( 'Tops' );
		$p   = $this->product( array( $cat ) );
		$this->chart( 'men_tops', array( 'categories' => array( $cat ) ) );
		Settings_Store::update( array( 'require_size_attr' => true ) );
		Settings_Store::flush();
		$this->assertNull( Storefront::chart_for( $p ) );
		$product   = wc_get_product( $p );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S', 'M' ) );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		$this->assertNotNull( Storefront::chart_for( $p ) );
	}

	public function test_shortcodes_and_block() {
		$id    = $this->chart( 'women_shoes' );
		$inline = do_shortcode( '[dev3sg_size_chart id="' . $id . '"]' );
		$this->assertStringContainsString( 'dev3sg-guide--inline', $inline );
		$this->assertStringContainsString( 'Foot length', $inline );
		$this->assertStringNotContainsString( 'data-dev3sg-quiz', do_shortcode( '[dev3sg_size_chart id="' . $id . '" quiz="no"]' ) );

		$quiz = do_shortcode( '[dev3sg_fit_quiz id="' . $id . '"]' );
		$this->assertStringContainsString( 'name="m[foot_length]"', $quiz );
		$this->assertStringNotContainsString( 'dev3sg-table-wrap', $quiz, 'Quiz only' );

		$this->assertStringContainsString( 'data-dev3sg-open', do_shortcode( '[dev3sg_size_guide id="' . $id . '"]' ) );
		$this->assertSame( '', do_shortcode( '[dev3sg_size_chart id="999999"]' ) );

		$draft = $this->chart( 'hats', array(), array( 'status' => 'draft' ) );
		$this->assertSame( '', do_shortcode( '[dev3sg_size_chart id="' . $draft . '"]' ), 'Drafts stay private' );

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'dev3sg/size-chart' ) );
		$block = Shortcodes::render_block(
			array(
				'chartId' => $id,
				'view'    => 'inline',
			)
		);
		$this->assertStringContainsString( 'dev3sg-block', $block );
		$this->assertStringContainsString( 'dev3sg-guide', $block );
	}

	public function test_cart_tracking_and_front_config() {
		$cat = $this->cat( 'Tops' );
		$p   = $this->product( array( $cat ) );
		$c   = $this->chart( 'men_tops', array( 'categories' => array( $cat ) ) );
		$_POST['dev3sg_rec'] = 'M';
		( new Storefront() )->track_cart( 'k', $p, 1, 0, array(), array() );
		unset( $_POST['dev3sg_rec'] );
		$stats = Events::stats( 1 );
		$this->assertSame( 1, $stats['totals']['cart'] );

		$cfg = Storefront::front_config();
		$this->assertArrayNotHasKey( 'nonce', $cfg, 'Page-cache safe: no nonce' );
		$this->assertFalse( $cfg['ai'], 'No key: no AI explanation' );
		$this->assertSame( array( 'pa_size', 'size' ), $cfg['sizeAttrs'] );
		$this->assertSame( ':root{--dev3sg-accent-color:#1d4ed8;}', Renderer::inline_css() );
		$this->assertGreaterThan( 0, $c );
	}
}
