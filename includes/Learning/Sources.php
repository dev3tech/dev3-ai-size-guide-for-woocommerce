<?php
/**
 * Where return reasons come from: WooCommerce refunds, the order "Fit feedback" box and a developer action.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Learning;

use Dev3\AiSizeGuide\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Sources.
 */
class Sources {

	const ITEM_META = '_dev3sg_fit';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 20, 2 );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 20 );
		add_action( 'dev3sg_record_return', array( __CLASS__, 'record' ), 10, 4 );
	}

	/**
	 * Developer action: do_action( 'dev3sg_record_return', $product_id, $size, $reason_text, $args ).
	 *
	 * @param int    $product_id  Product or variation.
	 * @param string $size        Size label.
	 * @param string $reason_text Shopper's reason.
	 * @param array  $args        { order_id, item_id, reason (too_small|too_large|fit_ok|other) }.
	 * @return int|\WP_Error
	 */
	public static function record( $product_id, $size = '', $reason_text = '', $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		return Returns::add(
			array_merge(
				array(
					'product_id' => (int) $product_id,
					'size'       => (string) $size,
					'note'       => (string) $reason_text,
					'source'     => 'api',
				),
				array_intersect_key( $args, array_flip( array( 'order_id', 'item_id', 'reason' ) ) )
			)
		);
	}

	/**
	 * Size of an order line item from its attributes (pa_size / size, configurable).
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return string
	 */
	public static function item_size( $item ) {
		$names = array_filter( array_map( 'trim', explode( ',', strtolower( (string) Settings_Store::get( 'size_attributes', 'pa_size, size' ) ) ) ) );
		foreach ( $item->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = strtolower( (string) $data['key'] );
			$key  = 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key;
			if ( in_array( $key, $names, true ) || in_array( 'pa_' . $key, $names, true ) ) {
				$value = (string) $data['value'];
				if ( taxonomy_exists( $key ) ) {
					$term  = get_term_by( 'slug', $value, $key );
					$value = $term ? $term->name : $value;
				}
				return $value;
			}
		}
		// Simple products that only have one size option.
		$product = $item->get_product();
		if ( $product ) {
			foreach ( $names as $name ) {
				$value = (string) $product->get_attribute( $name );
				if ( '' !== $value && false === strpos( $value, ',' ) ) {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Refund → return records (one per refunded line; idempotent).
	 *
	 * @param int $order_id  Order.
	 * @param int $refund_id Refund.
	 */
	public function on_refund( $order_id, $refund_id ) {
		if ( ! Settings_Store::get( 'capture_refunds', true ) ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		self::record_refund( $order, $refund );
	}

	/**
	 * Records a refund.
	 *
	 * @param \WC_Order        $order  Order.
	 * @param \WC_Order_Refund $refund Refund.
	 * @return int Records written.
	 */
	public static function record_refund( $order, $refund ) {
		$reason = (string) $refund->get_reason();
		$items  = array();
		foreach ( $refund->get_items() as $refund_item ) {
			$original = $order->get_item( (int) $refund_item->get_meta( '_refunded_item_id' ) );
			if ( $original instanceof \WC_Order_Item_Product ) {
				$items[ $original->get_id() ] = $original;
			}
		}
		if ( ! $items ) {
			// Amount-only refund: attribute it when the order has a single sized line.
			$sized = array();
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product && '' !== self::item_size( $item ) ) {
					$sized[ $item->get_id() ] = $item;
				}
			}
			$items = 1 === count( $sized ) ? $sized : array();
		}
		$n = 0;
		foreach ( $items as $item ) {
			$size = self::item_size( $item );
			if ( '' === $size ) {
				continue;
			}
			$id = Returns::add(
				array(
					'product_id' => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					'size'       => $size,
					'note'       => $reason,
					'source'     => 'refund',
					'order_id'   => $order->get_id(),
					'item_id'    => $item->get_id(),
				)
			);
			if ( ! is_wp_error( $id ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * "Fit feedback" box on the legacy and HPOS order screens.
	 */
	public function meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'dev3sg-fit-feedback', __( 'Fit feedback', 'dev3-ai-size-guide-for-woocommerce' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Object.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$options = array(
			''          => __( 'No feedback', 'dev3-ai-size-guide-for-woocommerce' ),
			'too_small' => __( 'Too small', 'dev3-ai-size-guide-for-woocommerce' ),
			'too_large' => __( 'Too large', 'dev3-ai-size-guide-for-woocommerce' ),
			'fit_ok'    => __( 'Fit OK', 'dev3-ai-size-guide-for-woocommerce' ),
			'other'     => __( 'Other reason', 'dev3-ai-size-guide-for-woocommerce' ),
		);
		wp_nonce_field( 'dev3sg_fit_feedback', 'dev3sg_fit_nonce' );
		echo '<p class="description">' . esc_html__( 'Record why a sized item came back. DEV3 AI Size Guide learns "runs small / large" from it.', 'dev3-ai-size-guide-for-woocommerce' ) . '</p>';
		$any = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$any  = true;
			$fit  = (array) $item->get_meta( self::ITEM_META );
			$size = self::item_size( $item );
			$id   = (int) $item->get_id();
			echo '<div style="margin:10px 0;padding-top:8px;border-top:1px solid #dcdcde">';
			echo '<strong>' . esc_html( $item->get_name() ) . '</strong>';
			if ( '' !== $size ) {
				/* translators: %s: size. */
				echo '<br><span class="description">' . esc_html( sprintf( __( 'Size: %s', 'dev3-ai-size-guide-for-woocommerce' ), $size ) ) . '</span>';
			}
			echo '<p><label for="dev3sg-fit-' . esc_attr( (string) $id ) . '" class="screen-reader-text">' . esc_html__( 'Fit feedback', 'dev3-ai-size-guide-for-woocommerce' ) . '</label>';
			echo '<select style="width:100%" id="dev3sg-fit-' . esc_attr( (string) $id ) . '" name="dev3sg_fit[' . esc_attr( (string) $id ) . '][reason]">';
			foreach ( $options as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) ( $fit['reason'] ?? '' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
			echo '<p><label for="dev3sg-note-' . esc_attr( (string) $id ) . '" class="screen-reader-text">' . esc_html__( 'Note', 'dev3-ai-size-guide-for-woocommerce' ) . '</label>';
			echo '<input type="text" style="width:100%" id="dev3sg-note-' . esc_attr( (string) $id ) . '" name="dev3sg_fit[' . esc_attr( (string) $id ) . '][note]" value="' . esc_attr( (string) ( $fit['note'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Customer note (optional)', 'dev3-ai-size-guide-for-woocommerce' ) . '"></p>';
			echo '</div>';
		}
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'No products in this order.', 'dev3-ai-size-guide-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Saves the box (both order screens fire woocommerce_process_shop_order_meta).
	 *
	 * @param int $order_id Order.
	 */
	public function save_meta_box( $order_id ) {
		if ( ! isset( $_POST['dev3sg_fit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dev3sg_fit_nonce'] ) ), 'dev3sg_fit_feedback' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capabilities.
			return;
		}
		$input = isset( $_POST['dev3sg_fit'] ) && is_array( $_POST['dev3sg_fit'] ) ? wp_unslash( $_POST['dev3sg_fit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised in save_feedback().
		self::save_feedback( (int) $order_id, (array) $input );
	}

	/**
	 * Applies feedback for order lines.
	 *
	 * @param int   $order_id Order.
	 * @param array $input    item_id => { reason, note }.
	 * @return int Records written.
	 */
	public static function save_feedback( $order_id, array $input ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}
		$n = 0;
		foreach ( $input as $item_id => $fields ) {
			$item = $order->get_item( (int) $item_id );
			if ( ! $item instanceof \WC_Order_Item_Product || ! is_array( $fields ) ) {
				continue;
			}
			$reason = sanitize_key( (string) ( $fields['reason'] ?? '' ) );
			$note   = mb_substr( sanitize_text_field( (string) ( $fields['note'] ?? '' ) ), 0, 500 );
			if ( '' === $reason || ! in_array( $reason, Classifier::REASONS, true ) ) {
				$item->delete_meta_data( self::ITEM_META );
				$item->save();
				Returns::delete_for_item( 'order', $order_id, $item->get_id() );
				continue;
			}
			$item->update_meta_data(
				self::ITEM_META,
				array(
					'reason' => $reason,
					'note'   => $note,
					'at'     => time(),
				)
			);
			$item->save();
			$id = Returns::add(
				array(
					'product_id' => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					'size'       => self::item_size( $item ),
					'reason'     => $reason,
					'note'       => $note,
					'source'     => 'order',
					'order_id'   => $order_id,
					'item_id'    => $item->get_id(),
				)
			);
			if ( ! is_wp_error( $id ) ) {
				++$n;
			}
		}
		return $n;
	}
}
