<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide\Admin;

use Dev3\AiSizeGuide\Core3\Core\Assets;
use Dev3\AiSizeGuide\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3SG_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3SG_NAME, __( 'DEV3 AI Size Guide', 'dev3-ai-size-guide-for-woocommerce' ), Capabilities::manage(), DEV3SG_SLUG, array( $this, 'render' ), 'dashicons-editor-table', 56 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3sg-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3SG_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3sg-admin-root" class="dev3sg-root dev3sg-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			wp_enqueue_media();
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SG_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-size-guide-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
