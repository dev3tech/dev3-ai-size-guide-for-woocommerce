<?php
/**
 * "Size guide" box on the product edit screen: automatic, none, or a specific chart.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Admin;

use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Product meta box.
 */
class Product_Meta {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes_product', array( $this, 'add' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Adds the box.
	 */
	public function add() {
		add_meta_box( 'dev3sg-product-chart', __( 'Size guide', 'dev3-ai-size-guide-for-woocommerce' ), array( $this, 'render' ), 'product', 'side', 'default' );
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render( $post ) {
		$current = (string) get_post_meta( $post->ID, Resolver::META_OVERRIDE, true );
		$auto    = Resolver::explain( $post->ID );
		wp_nonce_field( 'dev3sg_product_chart', 'dev3sg_product_nonce' );
		echo '<p><label for="dev3sg-product-chart-select">' . esc_html__( 'Size chart for this product', 'dev3-ai-size-guide-for-woocommerce' ) . '</label></p>';
		echo '<select id="dev3sg-product-chart-select" name="dev3sg_product_chart" style="width:100%">';
		echo '<option value="">' . esc_html__( 'Automatic (by category, tag or product rules)', 'dev3-ai-size-guide-for-woocommerce' ) . '</option>';
		echo '<option value="none"' . selected( $current, 'none', false ) . '>' . esc_html__( 'No size guide', 'dev3-ai-size-guide-for-woocommerce' ) . '</option>';
		foreach ( Charts::all() as $chart ) {
			$label = $chart['name'] . ( 'draft' === $chart['status'] ? ' (' . __( 'draft', 'dev3-ai-size-guide-for-woocommerce' ) . ')' : '' );
			echo '<option value="' . esc_attr( (string) $chart['id'] ) . '"' . selected( $current, (string) $chart['id'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( '' === $current ) {
			$chart = $auto['chart_id'] ? Charts::get( $auto['chart_id'] ) : null;
			echo '<p class="description">' . ( $chart
				/* translators: %s: chart name. */
				? esc_html( sprintf( __( 'Currently: %s', 'dev3-ai-size-guide-for-woocommerce' ), $chart['name'] ) )
				: esc_html__( 'Currently: no chart matches this product.', 'dev3-ai-size-guide-for-woocommerce' ) ) . '</p>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SG_SLUG . '#/charts' ) ) . '">' . esc_html__( 'Manage size charts', 'dev3-ai-size-guide-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * Saves the override.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		unset( $post );
		if ( ! isset( $_POST['dev3sg_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dev3sg_product_nonce'] ) ), 'dev3sg_product_chart' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$value = isset( $_POST['dev3sg_product_chart'] ) ? sanitize_key( wp_unslash( $_POST['dev3sg_product_chart'] ) ) : '';
		self::set_override( $post_id, $value );
	}

	/**
	 * Sets or clears a product override.
	 *
	 * @param int    $product_id Product.
	 * @param string $value      '' | none | chart id.
	 */
	public static function set_override( $product_id, $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			delete_post_meta( $product_id, Resolver::META_OVERRIDE );
		} elseif ( 'none' === $value || ( ctype_digit( $value ) && Charts::get( (int) $value ) ) ) {
			update_post_meta( $product_id, Resolver::META_OVERRIDE, $value );
		}
		Resolver::flush();
	}
}
