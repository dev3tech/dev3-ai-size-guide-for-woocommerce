<?php
/**
 * Units, chart validation/storage, templates, the paste parser and assignment resolution.
 *
 * @package Dev3\AiSizeGuide
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSizeGuide\Admin\Product_Meta;
use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Measures;
use Dev3\AiSizeGuide\Charts\Parser;
use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Charts\Templates;
use Dev3\AiSizeGuide\Charts\Units;

class ChartsTest extends Dev3sg_Base_Case {

	public function test_units_parse_convert_and_format() {
		$this->assertSame( array( 86.0, 91.0 ), Units::parse_range( '86-91' ) );
		$this->assertSame( array( 86.0, 91.0 ), Units::parse_range( "91 \xE2\x80\x93 86" ) );
		$this->assertSame( array( 34.5, 34.5 ), Units::parse_range( '34,5' ) );
		$this->assertSame( array( 30.0, 32.0 ), Units::parse_range( '30 to 32' ) );
		$this->assertNull( Units::parse_range( 'XL' ) );
		$this->assertNull( Units::parse_range( '1-2-3' ) );
		$this->assertSame( '86.4-91.4', Units::normalize_cell( '34-36', 'in' ) );
		$this->assertSame( '25', Units::normalize_cell( '250', 'mm' ) );
		$this->assertSame( '68', Units::normalize_cell( '150', 'lb' ) );
		$this->assertSame( "34\xE2\x80\x9336", Units::format_cell( '86.4-91.4', 'length', 'imperial' ) );
		$this->assertSame( "86\xE2\x80\x9391", Units::format_cell( '86-91', 'length', 'metric' ) );
		$this->assertSame( '150', Units::format_cell( '68', 'weight', 'imperial' ) );
		$this->assertSame( '98 cm', Units::format_value( 98, 'length', 'metric' ) );
		$this->assertSame( '38.5 in', Units::format_value( 98, 'length', 'imperial' ) );
		$this->assertSame( 'imperial', Units::system_for_country( 'us' ) );
		$this->assertSame( 'metric', Units::system_for_country( 'DE' ) );
	}

	public function test_measure_guess_from_labels() {
		$this->assertSame( 'chest', Measures::guess( 'Chest (cm)' ) );
		$this->assertSame( 'bust', Measures::guess( 'Bust' ) );
		$this->assertSame( 'underbust', Measures::guess( 'Under-bust' ) );
		$this->assertSame( 'foot_length', Measures::guess( 'Foot length' ) );
		$this->assertSame( 'hips', Measures::guess( 'Hüfte' ) );
		$this->assertSame( 'sleeve', Measures::guess( 'Arm length' ) );
		$this->assertSame( 'custom', Measures::guess( 'Something' ) );
	}

	public function test_sanitize_promotes_size_column_and_converts_units() {
		$chart = Charts::sanitize(
			array(
				'profile' => 'nope',
				'bias'    => 9,
				'tables'  => array(
					array(
						'title'   => 'Body <b>sizes</b>',
						'unit'    => 'in',
						'columns' => array(
							array(
								'label' => 'Label',
								'kind'  => 'text',
							),
							array(
								'label' => 'Chest',
								'kind'  => 'measure',
							),
							array(
								'label' => 'Also size',
								'kind'  => 'size',
							),
							array(
								'label'   => 'Weight',
								'kind'    => 'measure',
								'measure' => 'weight',
							),
						),
						'rows'    => array(
							array( 'S', '34-36', 'x', '150' ),
							array( '', '', '', '' ),
							array( 'M', 'abc', 'y', '' ),
						),
					),
				),
			)
		);
		$this->assertSame( 'unisex', $chart['profile'] );
		$this->assertSame( 2, $chart['bias'] );
		$table = $chart['tables'][0];
		$this->assertSame( 'Body sizes', $table['title'] );
		$this->assertSame( 'text', $table['columns'][0]['kind'], 'First explicit size column wins' );
		$this->assertSame( 'size', $table['columns'][2]['kind'] );
		$this->assertSame( 'chest', $table['columns'][1]['measure'], 'Measure guessed from the label' );
		$this->assertSame( 'weight', $table['columns'][3]['quantity'] );
		$this->assertCount( 2, $table['rows'], 'Empty rows dropped' );
		$this->assertSame( '86.4-91.4', $table['rows'][0][1], 'Inches stored as cm' );
		$this->assertSame( '68', $table['rows'][0][3], 'Pounds stored as kg' );
		$this->assertSame( '', $table['rows'][1][1], 'Non-numeric measurement cleared' );

		$no_size = Charts::sanitize_table(
			array(
				'columns' => array(
					array(
						'label' => 'A',
						'kind'  => 'text',
					),
				),
				'rows'    => array( array( 'S' ) ),
			),
			0
		);
		$this->assertSame( 'size', $no_size['columns'][0]['kind'], 'Without a size column the first becomes one' );
	}

	public function test_save_get_duplicate_delete() {
		$this->assertWPError( Charts::save( array( 'name' => '  ' ) ) );
		$id = $this->chart( 'women_tops', array( 'categories' => array( '5', 0, 'x', 5 ) ) );
		$c  = Charts::get( $id );
		$this->assertSame( "Women's tops", $c['name'] );
		$this->assertSame( array( 5 ), $c['rules']['categories'] );
		$this->assertSame( array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' ), Charts::sizes( $c ) );
		$this->assertSame( 'publish', $c['status'] );

		$copy = Charts::duplicate( $id );
		$this->assertSame( 'draft', Charts::get( $copy )['status'] );
		$this->assertSame( array(), Charts::get( $copy )['rules']['categories'] );
		$this->assertTrue( Charts::delete( $copy ) );
		$this->assertNull( Charts::get( $copy ) );
		$this->assertWPError( Charts::save( array( 'name' => 'x' ), 999999 ) );
	}

	public function test_templates_are_valid_charts() {
		$all = Templates::all();
		$this->assertCount( 12, $all );
		foreach ( $all as $tpl ) {
			$clean = Charts::sanitize( $tpl['chart'] );
			$this->assertNotEmpty( $clean['tables'][0]['rows'], $tpl['id'] );
			$this->assertSame( 'size', $clean['tables'][0]['columns'][0]['kind'], $tpl['id'] );
			$this->assertSame( $tpl['chart']['tables'][0]['rows'][0][1], $clean['tables'][0]['rows'][0][1], 'Template values survive validation: ' . $tpl['id'] );
		}
		$this->assertStringContainsString( 'Chest:', Templates::chart( 'men_tops' )['guide']['text'] );
	}

	public function test_parser_reads_tables_and_detects_inches() {
		$cm = Parser::parse( "Size\tChest (cm)\tWaist (cm)\tUS\nS\t86-91\t71-76\t36\nM\t91-97\t76-81\t38" );
		$this->assertSame( 'cm', $cm['unit'] );
		$this->assertSame( array( 'size', 'measure', 'measure', 'text' ), array_column( $cm['table']['columns'], 'kind' ) );
		$this->assertSame( 'Chest', $cm['table']['columns'][1]['label'] );
		$this->assertSame( '91-97', $cm['table']['rows'][1][1] );

		$in = Parser::parse( "Size;Chest;Waist\nS;34-36;28-30\nM;38-40;32-34" );
		$this->assertSame( 'in', $in['unit'] );
		$this->assertSame( '86.4-91.4', $in['table']['rows'][0][1] );
		$this->assertNotEmpty( $in['warnings'] );

		$spaces = Parser::parse( "EU    Foot length    US\n40    25-25.5    7\n41    25.5-26    8", 'cm' );
		$this->assertSame( 'foot_length', $spaces['table']['columns'][1]['measure'] );

		$this->assertWPError( Parser::parse( 'just one line' ) );
	}

	public function test_resolver_precedence() {
		$parent = $this->cat( 'Clothing' );
		$child  = $this->cat( 'Shirts', $parent );
		$tag    = $this->tag( 'slim' );
		$p      = $this->product( array( $child ), array( $tag ) );
		$other  = $this->product( array( $parent ) );

		$by_parent = $this->chart( 'unisex_tees', array( 'categories' => array( $parent ) ) );
		$this->assertSame( $by_parent, Resolver::chart_id_for_product( $p ), 'Parent category matches children' );

		$by_cat = $this->chart( 'men_tops', array( 'categories' => array( $child ) ) );
		Resolver::flush();
		$this->assertSame( $by_cat, Resolver::chart_id_for_product( $p ), 'Direct category beats parent' );

		$by_tag = $this->chart( 'women_tops', array( 'tags' => array( $tag ) ) );
		Resolver::flush();
		$this->assertSame( $by_tag, Resolver::chart_id_for_product( $p ), 'Tag beats category' );

		$by_product = $this->chart( 'kids', array( 'products' => array( $p ) ) );
		Resolver::flush();
		$this->assertSame( $by_product, Resolver::chart_id_for_product( $p ), 'Product list beats tag' );

		$this->chart(
			'hats',
			array(
				'categories' => array( $parent ),
				'priority'   => 50,
			)
		);
		Resolver::flush();
		$this->assertNotSame( $by_parent, Resolver::chart_id_for_product( $other ), 'Higher priority wins a tie' );

		Product_Meta::set_override( $p, (string) $by_parent );
		$this->assertSame( $by_parent, Resolver::chart_id_for_product( $p ), 'Override wins' );
		$this->assertSame( 'override', Resolver::explain( $p )['via'] );
		Product_Meta::set_override( $p, 'none' );
		$this->assertSame( 0, Resolver::chart_id_for_product( $p ), 'None hides the guide' );
		Product_Meta::set_override( $p, '' );

		$excl = $this->chart(
			'bras',
			array(
				'products' => array( $other ),
				'exclude'  => array( $other ),
			)
		);
		Resolver::flush();
		$this->assertNotSame( $excl, Resolver::chart_id_for_product( $other ), 'Excluded products are skipped' );

		$draft = $this->chart( 'rings', array( 'products' => array( $other ) ), array( 'status' => 'draft' ) );
		Resolver::flush();
		$this->assertNotSame( $draft, Resolver::chart_id_for_product( $other ), 'Draft charts never match' );
		$this->assertContains( $by_product, array( Resolver::chart_id_for_product( $p ) ) );
	}
}
