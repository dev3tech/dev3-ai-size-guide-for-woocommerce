<?php
/**
 * "What size am I?" maths and the explanation (template + mocked AI with fallback).
 *
 * @package Dev3\AiSizeGuide
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Templates;
use Dev3\AiSizeGuide\Core3\Core\Cache;
use Dev3\AiSizeGuide\Core3\Core\Settings_Store;
use Dev3\AiSizeGuide\Fit\Engine;
use Dev3\AiSizeGuide\Fit\Explainer;

class FitEngineTest extends Dev3sg_Base_Case {

	/**
	 * Template chart as stored data.
	 *
	 * @param string $id    Template.
	 * @param array  $extra Overrides.
	 * @return array
	 */
	private function tpl( $id, array $extra = array() ) {
		return array_merge( Charts::sanitize( Templates::chart( $id ) ), array( 'id' => 0 ), $extra );
	}

	public function test_in_range_measurements_give_high_confidence() {
		$r = Engine::recommend(
			$this->tpl( 'men_tops' ),
			array(
				'measurements' => array(
					'chest' => 98,
					'waist' => 83,
				),
			)
		);
		$this->assertSame( 'M', $r['size'] );
		$this->assertSame( 'high', $r['confidence'] );
		$this->assertSame( '', $r['alternative'] );
		$this->assertSame( array( 'within', 'within' ), array_column( $r['measures'], 'status' ) );
		$this->assertStringContainsString( 'Size M is your best match', $r['explanation'] );
		$this->assertFalse( $r['estimated'] );
	}

	public function test_between_sizes_suggests_an_alternative() {
		$r = Engine::recommend(
			$this->tpl( 'men_tops' ),
			array(
				'measurements' => array(
					'chest' => 101,
					'waist' => 89,
				),
			)
		);
		$this->assertContains( $r['size'], array( 'M', 'L' ) );
		$this->assertNotSame( '', $r['alternative'] );
		$this->assertNotSame( $r['size'], $r['alternative'] );
		$this->assertStringContainsString( 'between', $r['explanation'] );
	}

	public function test_fit_preference_shifts_the_result() {
		$chart  = $this->tpl( 'men_tops' );
		$input  = array( 'measurements' => array( 'chest' => 101 ) );
		$snug   = Engine::recommend( $chart, $input + array( 'fit' => 'snug' ) );
		$relax  = Engine::recommend( $chart, $input + array( 'fit' => 'relaxed' ) );
		$this->assertSame( 'M', $snug['size'] );
		$this->assertSame( 'L', $relax['size'] );
	}

	public function test_imperial_input_is_converted() {
		$r = Engine::recommend(
			$this->tpl( 'men_tops' ),
			array(
				'unit'         => 'imperial',
				'measurements' => array( 'chest' => 38.5 ),
			)
		);
		$this->assertSame( 'M', $r['size'] );
		$this->assertEqualsWithDelta( 97.8, $r['measures'][0]['value'], 0.1 );
		$this->assertStringContainsString( 'in)', $r['explanation'] );
	}

	public function test_estimates_from_height_and_weight() {
		$est = Engine::estimate( 178, 78, 'men' );
		$this->assertEqualsWithDelta( 100.3, $est['chest'], 0.5 );
		$this->assertGreaterThan( Engine::estimate( 178, 70, 'men' )['waist'], Engine::estimate( 178, 90, 'men' )['waist'] );

		$r = Engine::recommend(
			$this->tpl( 'men_tops' ),
			array(
				'measurements' => array(
					'height' => 178,
					'weight' => 78,
				),
			)
		);
		$this->assertTrue( $r['estimated'] );
		$this->assertContains( $r['size'], array( 'M', 'L' ) );
		$this->assertNotSame( 'high', $r['confidence'] );
		$this->assertStringContainsString( 'estimated', $r['explanation'] );

		$off = Engine::recommend( $this->tpl( 'men_tops' ), array( 'measurements' => array( 'height' => 178, 'weight' => 78 ) ), array( 'estimates' => false ) );
		$this->assertWPError( $off, 'Without estimates height/weight alone do not fit a chest chart' );
	}

	public function test_learned_bias_moves_one_size_up() {
		$r = Engine::recommend( $this->tpl( 'men_tops', array( 'bias' => 1, 'fit_note' => 'Runs small.' ) ), array( 'measurements' => array( 'chest' => 98 ) ) );
		$this->assertSame( 'L', $r['size'] );
		$this->assertSame( 'M', $r['alternative'] );
		$this->assertTrue( $r['bias_applied'] );
		$this->assertStringContainsString( 'runs small', $r['explanation'] );
		$this->assertStringContainsString( 'Runs small.', $r['explanation'] );

		$top = Engine::recommend( $this->tpl( 'men_tops', array( 'bias' => 1 ) ), array( 'measurements' => array( 'chest' => 130 ) ) );
		$this->assertSame( '3XL', $top['size'], 'Bias is clamped at the largest size' );
		$this->assertFalse( $top['bias_applied'] );
	}

	public function test_usual_size_and_shoes() {
		$usual = Engine::recommend( $this->tpl( 'women_tops' ), array( 'usual' => array( 'uk' => '12' ) ) );
		$this->assertSame( 'M', $usual['size'], 'UK 12-14 maps to M' );
		$this->assertTrue( $usual['usual_match'] );

		$shoe = Engine::recommend( $this->tpl( 'men_shoes' ), array( 'measurements' => array( 'foot_length' => 26.8 ) ) );
		$this->assertSame( '43', $shoe['size'] );

		$ring = Engine::recommend( $this->tpl( 'rings' ), array( 'measurements' => array( 'ring' => 5.45 ) ) );
		$this->assertSame( '7', $ring['size'], 'Point values match within half a step' );
	}

	public function test_out_of_range_and_errors() {
		$big = Engine::recommend( $this->tpl( 'men_tops' ), array( 'measurements' => array( 'chest' => 150 ) ) );
		$this->assertSame( '3XL', $big['size'] );
		$this->assertSame( 'above', $big['out_of_range'] );
		$this->assertSame( 'low', $big['confidence'] );
		$this->assertStringContainsString( 'above our largest size', $big['explanation'] );

		$this->assertWPError( Engine::recommend( $this->tpl( 'men_tops' ), array( 'measurements' => array() ) ) );
		$this->assertWPError( Engine::recommend( $this->tpl( 'men_tops' ), array( 'measurements' => array( 'bogus' => 5 ) ) ) );
		$this->assertWPError( Engine::recommend( $this->tpl( 'men_tops' ), array( 'measurements' => array( 'chest' => -5 ) ) ) );
	}

	public function test_chest_and_bust_are_interchangeable() {
		$r = Engine::recommend( $this->tpl( 'women_tops' ), array( 'measurements' => array( 'chest' => 88 ) ) );
		$this->assertSame( 'M', $r['size'] );
	}

	public function test_explainer_uses_ai_and_falls_back() {
		$result = Engine::recommend( $this->tpl( 'men_tops' ), array( 'measurements' => array( 'chest' => 98 ) ) );

		// No key: template.
		$out = Explainer::explain( $result, 'Oxford shirt' );
		$this->assertSame( 'template', $out['source'] );
		$this->assertSame( $result['explanation'], $out['text'] );
		$this->assertCount( 0, $this->requests );

		$this->with_key();
		$this->responses = array( $this->gemini( array( 'text' => 'M will feel just right across your chest.' ) ) );
		$ai              = Explainer::explain( $result, 'Oxford shirt' );
		$this->assertSame( 'ai', $ai['source'] );
		$this->assertSame( 'M will feel just right across your chest.', $ai['text'] );
		$sent = wp_json_encode( $this->requests[0]['body'] );
		$this->assertStringContainsString( 'recommended_size', $sent );
		$this->assertStringNotContainsString( '@', $sent, 'No personal data in the prompt' );

		Cache::reset_memo();
		$again = Explainer::explain( $result, 'Oxford shirt' );
		$this->assertTrue( $again['cached'] );
		$this->assertCount( 1, $this->requests, 'Identical results are served from the cache' );

		// Storefront AI switch off → template, no request.
		Settings_Store::update( array( 'public_ai' => false ) );
		Settings_Store::flush();
		$other = Engine::recommend( $this->tpl( 'men_tops' ), array( 'measurements' => array( 'chest' => 106 ) ) );
		$this->assertSame( 'template', Explainer::explain( $other )['source'] );
		$this->assertCount( 1, $this->requests );

		// Gemini failing → template.
		Settings_Store::update( array( 'public_ai' => true ) );
		Settings_Store::flush();
		$this->responses = array( array( 500, array( 'error' => array( 'message' => 'down' ) ) ) );
		$this->assertSame( 'template', Explainer::explain( $other )['source'] );

		// Setting off → template.
		Settings_Store::update( array( 'ai_explain' => false ) );
		Settings_Store::flush();
		$this->assertSame( 'template', Explainer::explain( $result )['source'] );
	}
}
