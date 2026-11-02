<?php
/**
 * REST permissions, admin flows, public storefront routes and AI drafts (Gemini mocked).
 *
 * @package Dev3\AiSizeGuide
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSizeGuide\Ai\Drafts;
use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Core3\Core\Jobs;
use Dev3\AiSizeGuide\Data\Events;
use Dev3\AiSizeGuide\Learning\Returns;

class RestTest extends Dev3sg_Base_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	/**
	 * AI chart output in inches.
	 *
	 * @return array
	 */
	private function ai_chart() {
		return array(
			'name'     => 'Linen shirt',
			'profile'  => 'men',
			'unit'     => 'in',
			'tables'   => array(
				array(
					'title'   => 'Body measurements',
					'type'    => 'body',
					'columns' => array(
						array(
							'label' => 'Size',
							'kind'  => 'size',
						),
						array(
							'label'   => 'Chest (in)',
							'kind'    => 'measure',
							'measure' => 'chest',
						),
						array(
							'label' => 'US',
							'kind'  => 'text',
						),
					),
					'rows'    => array(
						array( 'S', '34-36', '36' ),
						array( 'M', '38-40', '40' ),
						array( 'L', '42-44', '44' ),
					),
				),
			),
			'guide'    => 'Chest: measure around the fullest part.',
			'fit_note' => '',
			'notes'    => 'Standard US menswear.',
		);
	}

	public function test_admin_routes_need_capability_and_nonce() {
		$routes = array( array( 'GET', 'charts' ), array( 'POST', 'charts' ), array( 'GET', 'stats' ), array( 'GET', 'returns' ), array( 'POST', 'ai/chart' ), array( 'GET', 'insights' ), array( 'POST', 'parse' ) );
		wp_set_current_user( 0 );
		foreach ( $routes as $r ) {
			$this->assertContains( $this->rest( $r[0], $r[1] )->get_status(), array( 401, 403 ), $r[1] );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $routes as $r ) {
			$this->assertSame( 403, $this->rest( $r[0], $r[1] )->get_status(), $r[1] );
		}
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'charts', array(), false )->get_status(), 'Nonce required' );
		$this->assertSame( 200, $this->rest( 'GET', 'charts' )->get_status() );
	}

	public function test_chart_crud_templates_and_assign() {
		$this->admin();
		$this->assertCount( 12, $this->rest( 'GET', 'templates' )->get_data() );
		$res = $this->rest( 'POST', 'charts', array( 'template' => 'women_dresses' ) );
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['id'];

		$cat = $this->cat( 'Dresses' );
		$p   = $this->product( array( $cat ) );
		$upd = $this->rest(
			'POST',
			'charts/' . $id,
			array(
				'name'  => 'Dresses',
				'rules' => array( 'categories' => array( $cat ) ),
			)
		);
		$this->assertSame( 200, $upd->get_status() );
		$this->assertSame( 'Dresses', $upd->get_data()['name'] );
		$this->assertSame( 1, $upd->get_data()['product_count'] );
		$this->assertSame( $cat, $upd->get_data()['lookup']['categories'][0]['id'] );

		$list = $this->rest( 'GET', 'charts' )->get_data();
		$this->assertSame( 1, $list[0]['product_count'] );

		$cov = $this->rest( 'GET', 'coverage' )->get_data();
		$this->assertSame( 1, $cov['covered'] );

		$other = $this->product();
		$this->assertSame( 0, Resolver::chart_id_for_product( $other ) );
		$this->rest(
			'POST',
			'assign',
			array(
				'product_ids' => array( $other ),
				'chart'       => (string) $id,
			)
		);
		$this->assertSame( $id, Resolver::chart_id_for_product( $other ) );

		$this->assertSame( 201, $this->rest( 'POST', 'charts/' . $id . '/duplicate' )->get_status() );
		$this->assertSame( 200, $this->rest( 'DELETE', 'charts/' . $id )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', 'charts/' . $id )->get_status() );
		$this->assertSame( 404, $this->rest( 'POST', 'charts', array( 'template' => 'nope' ) )->get_status() );

		$parsed = $this->rest( 'POST', 'parse', array( 'text' => "Size\tWaist (cm)\nS\t70\nM\t75" ) )->get_data();
		$this->assertSame( 'waist', $parsed['table']['columns'][1]['measure'] );

		$sim = $this->rest(
			'POST',
			'fit/test',
			array(
				'chart' => Charts::sanitize( \Dev3\AiSizeGuide\Charts\Templates::chart( 'men_tops' ) ),
				'input' => array( 'measurements' => array( 'chest' => 98 ) ),
			)
		);
		$this->assertSame( 'M', $sim->get_data()['size'], 'Simulator works on unsaved charts' );
	}

	public function test_public_recommend_and_explain() {
		$cat   = $this->cat( 'Shirts' );
		$p     = $this->product( array( $cat ) );
		$chart = $this->chart( 'men_tops', array( 'categories' => array( $cat ) ) );
		wp_set_current_user( 0 );

		$res = $this->rest(
			'POST',
			'fit/recommend',
			array(
				'product_id'   => $p,
				'measurements' => array( 'chest' => '98' ),
				'unit'         => 'metric',
				'fit'          => 'regular',
			),
			false
		);
		$this->assertSame( 200, $res->get_status(), 'No nonce needed (page caches)' );
		$this->assertSame( 'M', $res->get_data()['size'] );
		$this->assertSame( $chart, $res->get_data()['chart_id'] );
		$stats = Events::stats( 1 );
		$this->assertSame( 1, $stats['totals']['quiz'] );
		$this->assertSame( 1, $stats['totals']['recommend'] );

		$exp = $this->rest(
			'POST',
			'fit/explain',
			array(
				'chart_id'     => $chart,
				'measurements' => array( 'chest' => 98 ),
			),
			false
		);
		$this->assertSame( 'template', $exp->get_data()['source'], 'No key: written explanation' );
		$this->assertSame( 'M', $exp->get_data()['size'] );

		$this->with_key();
		$this->responses = array( $this->gemini( array( 'text' => 'Go with M.' ) ) );
		$exp             = $this->rest(
			'POST',
			'fit/explain',
			array(
				'chart_id'     => $chart,
				'measurements' => array( 'chest' => 98 ),
				'locale'       => 'de_DE',
			),
			false
		);
		$this->assertSame( 'Go with M.', $exp->get_data()['text'] );
		$this->assertStringContainsString( 'de_DE', wp_json_encode( $this->requests[0]['body'] ) );

		$track = $this->rest(
			'POST',
			'track',
			array(
				'type'     => 'open',
				'chart_id' => $chart,
			),
			false
		);
		$this->assertSame( 200, $track->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'track', array( 'type' => 'hack', 'chart_id' => $chart ), false )->get_status() );
	}

	public function test_public_routes_reject_other_origins_drafts_and_floods() {
		$chart = $this->chart( 'men_tops' );
		$draft = $this->chart( 'men_tops', array(), array( 'status' => 'draft' ) );
		wp_set_current_user( 0 );

		$req = new WP_REST_Request( 'POST', '/dev3sg/v1/fit/recommend' );
		$req->set_header( 'Origin', 'https://evil.example' );
		$req->set_param( 'chart_id', $chart );
		$req->set_param( 'measurements', array( 'chest' => 98 ) );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );

		$this->assertSame( 404, $this->rest( 'POST', 'fit/recommend', array( 'chart_id' => $draft, 'measurements' => array( 'chest' => 98 ) ), false )->get_status(), 'Draft charts are private' );
		$this->assertSame( 400, $this->rest( 'POST', 'fit/recommend', array( 'chart_id' => $chart ), false )->get_status() );

		add_filter( 'dev3sg_public_rate_limit', static fn() => 2 );
		$codes = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$codes[] = $this->rest( 'POST', 'track', array( 'type' => 'open', 'chart_id' => $chart ), false )->get_status();
		}
		$this->assertSame( array( 200, 200, 429 ), $codes );
	}

	public function test_ai_chart_from_product_is_a_draft_in_cm() {
		$this->admin();
		$p = $this->product( array( $this->cat( 'Shirts' ) ) );
		$no_key = $this->rest( 'POST', 'ai/chart', array( 'source' => 'product', 'product_id' => $p ) )->get_status();
		$this->assertTrue( $no_key >= 400 && $no_key < 500, 'No key' );
		$this->assertCount( 0, $this->requests );

		$this->with_key();
		$before          = count( Charts::all() );
		$this->responses = array( $this->gemini( $this->ai_chart() ) );
		$res             = $this->rest(
			'POST',
			'ai/chart',
			array(
				'source'     => 'product',
				'product_id' => $p,
				'profile'    => 'men',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$draft = $res->get_data()['draft'];
		$this->assertSame( 'Linen shirt', $draft['name'] );
		$this->assertSame( '86.4-91.4', $draft['tables'][0]['rows'][0][1], 'Inches converted to cm' );
		$this->assertSame( 'Chest', $draft['tables'][0]['columns'][1]['label'] );
		$this->assertSame( 'Standard US menswear.', $res->get_data()['notes'] );
		$this->assertSame( $before, count( Charts::all() ), 'Nothing saved until accepted' );
		$prompt = wp_json_encode( $this->requests[0]['body'] );
		$this->assertStringContainsString( get_the_title( $p ), $prompt );
		$this->assertStringContainsString( 'maxLength', $prompt );
	}

	public function test_ai_chart_from_text_image_and_bad_output() {
		$this->admin();
		$this->with_key();
		$this->responses = array( $this->gemini( $this->ai_chart() ) );
		$res             = $this->rest(
			'POST',
			'ai/chart',
			array(
				'source' => 'text',
				'text'   => 'S 34-36 M 38-40 L 42-44 chest inches',
			)
		);
		$this->assertSame( 200, $res->get_status() );

		$png = base64_encode( (string) file_get_contents( DEV3SG_PATH . 'tests/php/fixtures/chart.png' ) ); // phpcs:ignore
		$res = $this->rest(
			'POST',
			'ai/chart',
			array(
				'source' => 'image',
				'data'   => 'data:image/png;base64,' . $png,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$parts = end( $this->requests )['body']['contents'][0]['parts'];
		$this->assertSame( 'image/png', $parts[1]['inline_data']['mime_type'] ?? $parts[0]['inline_data']['mime_type'] ?? '' );

		$this->assertSame( 400, $this->rest( 'POST', 'ai/chart', array( 'source' => 'image', 'data' => 'not-an-image', 'mime' => 'image/png' ) )->get_status() );

		$this->responses = array( $this->gemini( array( 'name' => 'x', 'unit' => 'cm', 'tables' => array() ) ) );
		$bad             = $this->rest( 'POST', 'ai/chart', array( 'source' => 'text', 'text' => 'garbage text here' ) );
		$this->assertSame( 502, $bad->get_status() );
		$this->assertSame( 'dev3sg_ai_bad_chart', $bad->get_data()['code'] );
	}

	public function test_ai_guide_insights_and_assign() {
		$this->admin();
		$this->with_key();
		$chart = $this->chart( 'men_tops' );
		$cat   = $this->cat( 'Shirts' );
		$this->product( array( $cat ) );

		$this->responses = array( $this->gemini( array( 'text' => "Chest: around the chest.\nWaist: around the waist." ) ) );
		$guide           = $this->rest( 'POST', 'ai/guide', array( 'chart_id' => $chart ) );
		$this->assertStringStartsWith( 'Chest:', $guide->get_data()['text'] );

		$this->responses = array(
			$this->gemini(
				array(
					'items' => array(
						array(
							'category_id' => $cat,
							'chart_id'    => $chart,
							'reason'      => 'Shirts',
						),
						array(
							'category_id' => 999999,
							'chart_id'    => $chart,
						),
					),
				)
			),
		);
		$assign          = $this->rest( 'POST', 'ai/assign' )->get_data();
		$this->assertCount( 1, $assign['items'], 'Unknown ids from AI are dropped' );
		$this->assertSame( 0, count( Charts::get( $chart )['rules']['categories'] ), 'Draft only' );
		$this->rest( 'POST', 'assign/categories', array( 'items' => $assign['items'] ) );
		$this->assertSame( array( $cat ), Charts::get( $chart )['rules']['categories'] );

		$this->assertSame( 400, $this->rest( 'POST', 'ai/insights', array( 'chart_id' => $chart ) )->get_status(), 'No returns yet' );
		$p = $this->product();
		Returns::add(
			array(
				'product_id' => $p,
				'chart_id'   => $chart,
				'size'       => 'M',
				'note'       => 'too small, contact me at jane@example.com',
			)
		);
		$this->responses = array(
			$this->gemini(
				array(
					'summary'     => 'M runs small.',
					'direction'   => 'runs_small',
					'suggestions' => array(
						array(
							'size'      => 'M',
							'direction' => 'runs_small',
							'advice'    => 'Size up.',
						),
					),
					'fit_note'    => 'Runs small.',
				)
			),
		);
		$ins = $this->rest( 'POST', 'ai/insights', array( 'chart_id' => $chart ) )->get_data();
		$this->assertSame( 1, $ins['bias'] );
		$this->assertSame( 0, Charts::get( $chart )['bias'], 'Not applied until accepted' );
		$this->assertStringNotContainsString( 'jane@example.com', wp_json_encode( end( $this->requests )['body'] ), 'PII is masked' );
		$apply = $this->rest(
			'POST',
			'insights/apply',
			array(
				'chart_id' => $chart,
				'bias'     => $ins['bias'],
				'fit_note' => $ins['fit_note'],
			)
		);
		$this->assertSame( 200, $apply->get_status() );
		$this->assertSame( 1, Charts::get( $chart )['bias'] );
		$this->assertSame( 'Runs small.', Charts::get( $chart )['fit_note'] );
	}

	public function test_classify_job_and_accept() {
		$this->admin();
		$chart = $this->chart( 'men_tops' );
		$p     = $this->product();
		$a     = Returns::add(
			array(
				'product_id' => $p,
				'chart_id'   => $chart,
				'size'       => 'M',
				'note'       => 'The shoulders pinch',
			)
		);
		$b     = Returns::add(
			array(
				'product_id' => $p,
				'chart_id'   => $chart,
				'size'       => 'L',
				'note'       => 'Sleeves hang past my hands',
			)
		);
		$this->assertSame( 'unknown', Returns::get( $a )['reason'] );
		$this->assertSame( 400, $this->rest( 'POST', 'returns/classify' )->get_status(), 'AI needs a key' );

		$this->with_key();
		$this->responses = array(
			$this->gemini(
				array(
					'items' => array(
						array(
							'id'     => $a,
							'reason' => 'too_small',
						),
						array(
							'id'     => $b,
							'reason' => 'too_large',
						),
					),
				)
			),
		);
		$job             = $this->rest( 'POST', 'returns/classify' )->get_data();
		$this->assertSame( 2, $job['total'] );
		$done = Jobs::run( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 'unknown', Returns::get( $a )['reason'], 'Suggestion only' );
		$this->assertSame( 'too_small', Returns::get( $a )['ai_reason'] );
		$this->assertSame( 2, $this->rest( 'GET', 'returns', array( 'pending_ai' => 1 ) )->get_data()['total'] );

		$this->rest(
			'POST',
			'returns/accept',
			array(
				'ids'    => array( $a ),
				'accept' => true,
			)
		);
		$this->rest(
			'POST',
			'returns/accept',
			array(
				'ids'    => array( $b ),
				'accept' => false,
			)
		);
		$this->assertSame( 'too_small', Returns::get( $a )['reason'] );
		$this->assertSame( 'unknown', Returns::get( $b )['reason'] );
		$this->assertSame( 'rejected', Returns::get( $b )['ai_reason'] );

		$manual = $this->rest(
			'POST',
			'returns',
			array(
				'product_id' => $p,
				'size'       => 'S',
				'note'       => 'too big',
			)
		);
		$this->assertSame( 201, $manual->get_status() );
		$this->assertSame( 'too_large', $manual->get_data()['reason'] );
		$this->assertSame( 200, $this->rest( 'POST', 'returns/' . $manual->get_data()['id'], array( 'reason' => 'fit_ok' ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'DELETE', 'returns/' . $manual->get_data()['id'] )->get_status() );
		$this->assertSame( 404, $this->rest( 'DELETE', 'returns/' . $manual->get_data()['id'] )->get_status() );
		$this->assertSame( 'dev3sg_classify', Drafts::CLASSIFY_JOB );
	}

	public function test_stats_route() {
		$this->admin();
		$chart = $this->chart( 'men_tops', array( 'categories' => array( $this->cat( 'A' ) ) ) );
		Events::record( 'open', 1, $chart );
		Events::record( 'recommend', 1, $chart, 'M' );
		Events::record( 'cart', 1, $chart, 'M' );
		$stats = $this->rest( 'GET', 'stats', array( 'days' => 7 ) )->get_data();
		$this->assertSame( 1, $stats['charts'] );
		$this->assertSame( 100.0, (float) $stats['events']['cart_rate'] );
		$this->assertCount( 7, $stats['events']['daily'] );
		\Dev3\AiSizeGuide\Core3\Core\Settings_Store::update( array( 'analytics' => false ) );
		\Dev3\AiSizeGuide\Core3\Core\Settings_Store::flush();
		$this->assertFalse( Events::record( 'open', 1, $chart ), 'Analytics can be turned off' );
	}
}
