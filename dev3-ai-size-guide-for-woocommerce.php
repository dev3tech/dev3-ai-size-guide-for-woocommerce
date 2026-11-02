<?php
/**
 * Plugin Name:       DEV3 AI Size Guide & Fit Advisor for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-size-guide-for-woocommerce
 * Description:       Size charts, a What size am I? fit quiz and AI chart drafting for WooCommerce, with return-reason learning.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:  10.3
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-size-guide-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiSizeGuide
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3SG_VERSION', '1.0.0' );
define( 'DEV3SG_FILE', __FILE__ );
define( 'DEV3SG_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3SG_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3SG_SLUG', 'dev3-ai-size-guide-for-woocommerce' );
define( 'DEV3SG_NAME', 'DEV3 AI Size Guide & Fit Advisor for WooCommerce' );
define( 'DEV3SG_MIN_PHP', '7.4' );
define( 'DEV3SG_MIN_WP', '6.0' );
define( 'DEV3SG_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3SG_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiSizeGuide\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3SG_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiSizeGuide\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiSizeGuide\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiSizeGuide\Plugin', 'instance' ) );
