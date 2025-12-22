<?php
/**
 * Plugin Name: UPOS Payments Gateway for WooCommerce
 * Plugin URI: https://github.com/upos-pay/woocommerce-gateway
 * Description: UPOS Payments gateway integration for WooCommerce.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: UPOS
 * Author URI: https://upos.fi
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: upos-woocommerce
 * Domain Path: /languages
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 * Requires Plugins: woocommerce
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'UPOS_VERSION', '1.0.0' );
define( 'UPOS_PLUGIN_FILE', __FILE__ );
define( 'UPOS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'UPOS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UPOS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check PHP version
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
  add_action(
    'admin_notices',
    function () {
      echo '<div class="notice notice-error"><p>';
      printf(
        /* translators: %s: PHP version */
        esc_html__( 'UPOS Payments requires PHP 7.4 or higher. Your current version is %s', 'upos-woocommerce' ),
        esc_html( PHP_VERSION )
      );
      echo '</p></div>';
    }
  );
  return;
}

/**
 * Initialize the plugin
 */
function upos_init() {
  // Check if WooCommerce is active
  if ( ! class_exists( 'WooCommerce' ) ) {
    add_action(
      'admin_notices',
      function () {
        echo '<div class="notice notice-error"><p>';
        esc_html_e( 'UPOS Payments requires WooCommerce to be installed and active.', 'upos-woocommerce' );
        echo '</p></div>';
      }
    );
    return;
  }

  // Load required files in correct order
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-logger.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-constants.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-api.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-order-meta.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-order-fsm.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-order-processor.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-sync.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-webhook.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-exchange.php';
  require_once UPOS_PLUGIN_PATH . 'includes/class-upos-gateway.php';

  // Register AJAX action for connection test (must be done here as gateway might not be instantiated)
  if ( is_admin() ) {
    require_once UPOS_PLUGIN_PATH . 'includes/class-upos-admin-dashboard.php';
    UPOS_Admin_Dashboard::init();

    add_action( 'wp_ajax_upos_test_connection', array( 'UPOS_Gateway', 'ajax_test_connection' ) );
  }
}
add_action( 'plugins_loaded', 'upos_init', 10 );

/**
 * Enqueue frontend scripts and styles
 */
function upos_enqueue_scripts() {
  // Only on checkout page
  if ( ! is_checkout() ) {
    return;
  }

  // Enqueue CSS
  wp_enqueue_style(
    'upos-checkout',
    UPOS_PLUGIN_URL . 'assets/css/upos-checkout.css',
    array(),
    UPOS_VERSION
  );

  // Get gateway settings
  $settings                = get_option( 'woocommerce_upos_settings', array() );
  $public_key              = isset( $settings['public_key'] ) ? $settings['public_key'] : '';

  // Don't load if no public key configured
  if ( empty( $public_key ) ) {
    return;
  }

  wp_enqueue_script(
    'upos-checkout',
    UPOS_PLUGIN_URL . 'assets/js/upos-checkout.js',
    array( 'jquery' ),
    UPOS_VERSION,
    true
  );

  // Pass params to frontend
  // ! Note: do not pass sensitive info like secret key
  wp_localize_script(
    'upos-checkout',
    'upos_params',
    array(
      'ajax_url'   => admin_url( 'admin-ajax.php' ),
      'nonce'      => wp_create_nonce( 'upos_get_currencies' ),
      'plugin_url' => UPOS_PLUGIN_URL,
      'i18n'       => array(
        'loading'         => __( 'Loading payment options...', 'upos-woocommerce' ),
        'select_currency' => __( 'Select Payment Currency', 'upos-woocommerce' ),
        'select_network'  => __( 'Select Payment Network', 'upos-woocommerce' ),
        'error'           => __( 'Unable to load payment options', 'upos-woocommerce' ),
      ),
    )
  );
}
add_action( 'wp_enqueue_scripts', 'upos_enqueue_scripts' );

/**
 * Initialize translations
 */
function upos_load_textdomain() {
  load_plugin_textdomain(
    'upos-woocommerce',
    false,
    dirname( UPOS_PLUGIN_BASENAME ) . '/languages'
  );
}
add_action( 'init', 'upos_load_textdomain' );

/**
 * Initialize scheduled tasks and webhooks
 * Run on 'init' to ensure dependencies (Action Scheduler, Rewrite Rules) are ready.
 */
function upos_init_components() {
  if ( class_exists( 'UPOS_Sync' ) ) {
    UPOS_Sync::init();
  }
  if ( class_exists( 'UPOS_Webhook' ) ) {
    UPOS_Webhook::init();
  }
}
add_action( 'init', 'upos_init_components', 20 );

/**
 * Register payment gateway
 *
 * @param array $gateways Existing gateways.
 * @return array
 */
function upos_add_gateway( $gateways ) {
  $gateways[] = 'UPOS_Gateway';
  return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'upos_add_gateway' );

/**
 * Plugin activation
 */
function upos_activate() {
  // Store version
  update_option( 'upos_version', UPOS_VERSION );

  // Create log directory
  $upload_dir = wp_upload_dir();
  $log_dir    = $upload_dir['basedir'] . '/upos-logs';
  if ( ! file_exists( $log_dir ) ) {
    wp_mkdir_p( $log_dir );
  }

  // Flush rewrite rules
  flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'upos_activate' );

/**
 * Plugin deactivation
 */
function upos_deactivate() {
  // Unschedule sync cron
  if ( class_exists( 'UPOS_Sync' ) ) {
    UPOS_Sync::unschedule();
  }

  // Flush rewrite rules
  flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'upos_deactivate' );

/**
 * Declare HPOS compatibility
 */
add_action(
  'before_woocommerce_init',
  function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'custom_order_tables',
        __FILE__,
        true
      );
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'cart_checkout_blocks',
        __FILE__,
        true
      );
    }
  }
);

/**
 * Add settings link on plugin page
 *
 * @param array $links Existing links.
 * @return array
 */
function upos_plugin_action_links( $links ) {
  $settings_link = sprintf(
    '<a href="%s">%s</a>',
    admin_url( 'admin.php?page=wc-settings&tab=checkout&section=upos' ),
    __( 'Settings', 'upos-woocommerce' )
  );
  array_unshift( $links, $settings_link );
  return $links;
}
add_filter( 'plugin_action_links_' . UPOS_PLUGIN_BASENAME, 'upos_plugin_action_links' );

/**
 * Register WooCommerce Blocks support
 */
add_action( 'woocommerce_blocks_loaded', function () {
  if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
      return;
  }

    // Load Blocks Support class
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-upos-blocks-support.php';

    // Register the payment method
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new UPOS_Blocks_Support() );
        }
    );
} );


