<?php
/**
 * Plugin Name:       AI Sales Rep for WooCommerce
 * Description:       נציג מכירות AI לחנויות ווקומרס: פנייה יזומה לפי התנהגות הגולש, שיחת אבחון והמלצה, קופונים לפי כללים, שיוך מכירות ודשבורד רווחיות.
 * Version:           1.5.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Text Domain:       ai-sales-rep
 * License:           GPL-2.0-or-later
 *
 * WC requires at least: 8.0
 * WC tested up to:      10.3
 */

defined( 'ABSPATH' ) || exit;

define( 'AISR_VERSION', '1.5.1' );
define( 'AISR_FILE', __FILE__ );
define( 'AISR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISR_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Sales Rep דורש PHP 8.1 ומעלה.', 'ai-sales-rep' ) . '</p></div>';
		}
	);
	return;
}

require_once AISR_DIR . 'includes/class-aisr-settings.php';
require_once AISR_DIR . 'includes/class-aisr-db.php';
require_once AISR_DIR . 'includes/class-aisr-privacy.php';
require_once AISR_DIR . 'includes/class-aisr-transport.php';
require_once AISR_DIR . 'includes/class-aisr-knowledge.php';
require_once AISR_DIR . 'includes/class-aisr-tools.php';
require_once AISR_DIR . 'includes/class-aisr-claude.php';
require_once AISR_DIR . 'includes/class-aisr-rest.php';
require_once AISR_DIR . 'includes/class-aisr-frontend.php';
require_once AISR_DIR . 'includes/class-aisr-attribution.php';
require_once AISR_DIR . 'includes/class-aisr-dashboard.php';
require_once AISR_DIR . 'includes/class-aisr-cron.php';
require_once AISR_DIR . 'includes/class-aisr-growth.php';

register_activation_hook( __FILE__, array( 'AISR_DB', 'install' ) );
register_activation_hook( __FILE__, array( 'AISR_Cron', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'AISR_Cron', 'unschedule' ) );

// HPOS + cart/checkout blocks compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Sales Rep דורש את WooCommerce.', 'ai-sales-rep' ) . '</p></div>';
				}
			);
			return;
		}
		AISR_DB::maybe_upgrade();
		AISR_Settings::init();
		AISR_Privacy::init();
		AISR_REST::init();
		AISR_Frontend::init();
		AISR_Attribution::init();
		AISR_Dashboard::init();
		AISR_Cron::init();
		AISR_Growth::init();
	}
);
