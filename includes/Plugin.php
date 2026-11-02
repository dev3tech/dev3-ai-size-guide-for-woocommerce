<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiSizeGuide
 */

namespace Dev3\AiSizeGuide;

use Dev3\AiSizeGuide\Ai\Drafts;
use Dev3\AiSizeGuide\Ai\Prompt_Library;
use Dev3\AiSizeGuide\Charts\Charts;
use Dev3\AiSizeGuide\Charts\Resolver;
use Dev3\AiSizeGuide\Core3\Core\Assets;
use Dev3\AiSizeGuide\Core3\Core\Jobs;
use Dev3\AiSizeGuide\Core3\Core\Privacy;
use Dev3\AiSizeGuide\Core3\Core\Schema;
use Dev3\AiSizeGuide\Data\Events;
use Dev3\AiSizeGuide\Data\Tables;
use Dev3\AiSizeGuide\Learning\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3sg_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Assets registered this request.
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3SG_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( has_filter( 'dev3sg_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			return;
		}
		add_filter( 'dev3sg_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3sg_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3sg_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		add_action( 'init', array( Charts::class, 'register_type' ), 5 );
		add_action( 'init', array( Prompt_Library::class, 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3SG_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( self::CRON_HOOK, array( Events::class, 'prune' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'update_option_dev3sg_settings', array( Insights::class, 'flush' ) );
		add_action( 'set_object_terms', array( Resolver::class, 'flush_memo' ) );
		add_action( 'trashed_post', array( $this, 'chart_removed' ) );
		add_action( 'deleted_post', array( $this, 'chart_removed' ) );
		Privacy::init();
		// Background job types must be registered on every request (the runner looks them up).
		Jobs::register( Drafts::CLASSIFY_JOB, array( Drafts::class, 'run_classify_chunk' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'needs_woo' ) );
			return;
		}
		( new Learning\Sources() )->register();
		( new Frontend\Storefront() )->register();
		( new Frontend\Shortcodes() )->register();
		if ( is_admin() ) {
			( new Admin\Product_Meta() )->register();
		}
	}

	/**
	 * Loads translations (on init, as WordPress 6.7+ expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-size-guide-for-woocommerce', false, dirname( plugin_basename( DEV3SG_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the admin + block editor bundles once.
	 */
	public static function register_assets() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		Assets::register( array( 'admin', 'blocks' ) );
	}

	/**
	 * Clears the rules index when a chart is trashed/deleted outside the app.
	 *
	 * @param int $post_id Post.
	 */
	public function chart_removed( $post_id ) {
		if ( Charts::POST_TYPE === get_post_type( $post_id ) ) {
			Resolver::flush();
		}
	}

	/**
	 * HPOS + Cart/Checkout blocks compatibility.
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3SG_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3SG_FILE, true );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function needs_woo() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DEV3 AI Size Guide & Fit Advisor needs WooCommerce to be active.', 'dev3-ai-size-guide-for-woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
	}
}
