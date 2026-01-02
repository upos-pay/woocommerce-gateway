<?php
/**
 * UPOS Payments
 *
 * Handles WooCommerce payment gateway integration for UPOS cryptocurrency payments.
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Gateway class
 *
 * Payment Flow:
 * 1. Customer places order and selects currency/network on checkout page
 * 2. Create PaymentIntent via UPOS API
 * 3. Redirect customer to UPOS official payment page
 * 4. Customer completes payment on UPOS page
 * 5. UPOS redirects back to our thank you page
 * 6. Cron sync task polls for payment status updates
 *
 * @extends WC_Payment_Gateway
 */
class UPOS_Gateway extends WC_Payment_Gateway {

  /**
   * Gateway ID
   *
   * @var string
   */
  const GATEWAY_ID = 'upos';

  /**
   * Track if global hooks have been registered
   *
   * @var bool
   */
  private static $global_hooks_registered = false;

  /**
   * Track if admin hooks have been registered
   *
   * @var bool
   */
  private static $admin_hooks_registered = false;

  /**
   * API client instance
   *
   * @var UPOS_Api
   */
  private $api;

  /**
   * Constructor
   */
  public function __construct() {
    // Basic settings
    $this->id                 = self::GATEWAY_ID;
    $this->icon               = UPOS_PLUGIN_URL . 'assets/icon.svg';
    $this->has_fields         = true;
    $this->method_title       = __( 'UPOS Payments', 'upos-woocommerce' );
    $this->method_description = __( 'Accept cryptocurrency payments via UPOS', 'upos-woocommerce' );

    // Supported features
    $this->supports = array(
      'products',
    );

    // Load settings
    $this->init_form_fields();
    $this->init_settings();

    // Set webhook URL dynamically (read-only display field)
    $this->settings['webhook_url'] = UPOS_Webhook::get_webhook_url();

    // Get settings values
    $this->enabled = $this->get_option( 'enabled' );
    $this->title   = __( 'UPOS', 'upos-woocommerce' );

    // Get API credentials
    $public_key       = $this->get_option( 'public_key' );
    $secret_key       = $this->get_option( 'secret_key' );

    // Initialize API client
    $this->api = new UPOS_Api( $public_key, $secret_key );

    // Global Hooks (Register once per request)
    if ( ! self::$global_hooks_registered ) {
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

      // AJAX for getting currencies (public facing)
      add_action( 'wp_ajax_upos_get_currencies', array( __CLASS__, 'ajax_get_currencies' ) );
      add_action( 'wp_ajax_nopriv_upos_get_currencies', array( __CLASS__, 'ajax_get_currencies' ) );

      self::$global_hooks_registered = true;
    }

    // Admin Hooks (Register once per request, only if in admin)
    if ( is_admin() && ! self::$admin_hooks_registered ) {
      // Admin Order Meta Box
      add_action( 'add_meta_boxes', array( $this, 'add_admin_order_meta_box' ) );

      // Handle manual sync action (Classic POST)
      add_action( 'admin_post_upos_force_sync', array( $this, 'handle_manual_order_sync' ) );

      // AJAX Handlers for Settings Page Buttons
      add_action( 'wp_ajax_upos_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
      add_action( 'wp_ajax_upos_manual_sync', array( __CLASS__, 'ajax_manual_sync' ) );
      add_action( 'wp_ajax_upos_manual_expire', array( __CLASS__, 'ajax_manual_expire' ) );

      // Admin notices
      add_action( 'admin_notices', array( $this, 'display_manual_sync_result_notice' ) );

      // Admin Scripts & AJAX
      add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

      self::$admin_hooks_registered = true;
    }

    // Intercept WooCommerce errors
    add_filter( 'woocommerce_add_error', array( $this, 'intercept_wc_errors' ) );

    // Intercept translation strings (for Thank You page template text)
    add_filter( 'gettext', array( $this, 'intercept_gettext' ), 20, 3 );

    // Enforce keys before saving settings
    add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'enforce_keys_for_enabled' ) );
  }

  /**
   * Intercept and modify gettext strings
   *
   * Used to replace hardcoded template strings like the failure message on Thank You page.
   *
   * @param string $translated_text Translated text.
   * @param string $text            Original text.
   * @param string $domain          Text domain.
   * @return string
   */
  public function intercept_gettext( $translated_text, $text, $domain ) {
    if ( 'woocommerce' !== $domain ) {
        return $translated_text;
    }

    // Target the specific failure message in thankyou.php
    // "Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again."
    if ( strpos( $text, 'originating bank/merchant has declined' ) === false ) {
        return $translated_text;
    }

    // Verify we are on the order received page
    global $wp;
    $order_id = 0;

    if ( isset( $wp->query_vars['order-received'] ) ) {
        $order_id = abs( intval( $wp->query_vars['order-received'] ) );
    } elseif ( isset( $_GET['order-received'] ) ) {
        $order_id = abs( intval( $_GET['order-received'] ) );
    }

    if ( ! $order_id ) {
        return $translated_text;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_payment_method() !== self::GATEWAY_ID ) {
        return $translated_text;
    }

    // Return a short prompt instead of the full message, as the full message is displayed in the custom thankyou block.
    return __( 'Please check the payment status details below.', 'upos-woocommerce' );
  }

  /**
   * Intercept and modify WooCommerce error messages
   *
   * @param string $error Error message.
   * @return string
   */
  public function intercept_wc_errors( $error ) {
    // Target specific WooCommerce error message (English)
    // "Your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again."
    if ( strpos( $error, 'originating bank/merchant has declined' ) === false ) {
        return $error;
    }

    // Try to determine the current order context
    $order_id = 0;

    // Check query vars (Pay for Order page)
    global $wp;
    if ( isset( $wp->query_vars['order-pay'] ) ) {
        $order_id = abs( intval( $wp->query_vars['order-pay'] ) );
    } elseif ( isset( $_GET['order_pay'] ) ) {
        $order_id = abs( intval( $_GET['order_pay'] ) );
    } elseif ( isset( $_GET['key'] ) ) {
        // Fallback: try to find order by key if available (less reliable without ID)
        $order_id = wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) );
    }

    if ( ! $order_id ) {
        return $error;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_payment_method() !== self::GATEWAY_ID ) {
        return $error;
    }

    $status  = UPOS_Order_FSM::get_calculated_status( $order );
    $message = UPOS_Order_FSM::get_status_message( $status );
    return $message ? $message : $error;
  }

  /**
   * Enqueue admin scripts
   */
  public function enqueue_admin_scripts() {
    // Only load on WooCommerce settings page for this gateway
    $screen = get_current_screen();
    if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
      return;
    }

    if ( empty( $_GET['section'] ) || 'upos' !== $_GET['section'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
      return;
    }

    wp_enqueue_script(
      'upos-admin',
      UPOS_PLUGIN_URL . 'assets/js/upos-admin.js',
      array( 'jquery' ),
      UPOS_VERSION,
      true
    );

    wp_localize_script(
      'upos-admin',
      'upos_admin_params',
      array(
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'upos_test_connection' ),
        'nonce_sync'   => wp_create_nonce( 'upos_manual_sync' ),
        'nonce_expire' => wp_create_nonce( 'upos_manual_expire' ),
        'i18n'         => array(
          'testing'            => __( 'Testing connection...', 'upos-woocommerce' ),
          'keys_missing'       => __( 'Please enter both Public Key and Secret Key.', 'upos-woocommerce' ),
          'error'              => __( 'Connection failed. Please check your network or API keys.', 'upos-woocommerce' ),
          'save_first'         => __( 'Settings changed. Please save changes before testing.', 'upos-woocommerce' ),
          'pk_format_error'    => __( 'Public Key must start with pk_test_ or pk_live_.', 'upos-woocommerce' ),
          'sk_format_error'    => __( 'Secret Key must start with sk_test_ or sk_live_.', 'upos-woocommerce' ),
          'env_mismatch_error' => __( 'Public Key and Secret Key environments do not match (Test/Live).', 'upos-woocommerce' ),
          'pk_sk_env_mismatch' => __( 'Public Key and Secret Key must belong to the same environment (Test or Live).', 'upos-woocommerce' ),
          'processing'         => __( 'Processing...', 'upos-woocommerce' ),
          'throttled'          => __( 'Please wait a moment before trying again.', 'upos-woocommerce' ),
        ),
      )
    );
  }

  /**
   * Handle AJAX request for supported currencies
   *
   * This is a public endpoint used by both Shortcode and Blocks frontend
   * to fetch currencies server-side, avoiding CORS issues.
   */
  public static function ajax_get_currencies() {
    check_ajax_referer( 'upos_get_currencies', 'nonce' );

    $settings   = get_option( 'woocommerce_upos_settings', array() );
    $public_key = isset( $settings['public_key'] ) ? $settings['public_key'] : '';
    $secret_key = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';

    if ( empty( $public_key ) || empty( $secret_key ) ) {
      wp_send_json_error( array( 'message' => __( 'Payment gateway is not properly configured.', 'upos-woocommerce' ) ) );
    }

    try {
      $api = new UPOS_Api( $public_key, $secret_key );
      $result = $api->get_supported_currencies();

      if ( is_array( $result ) && isset( $result['currencies'] ) ) {
        wp_send_json_success( $result );
      } else {
        wp_send_json_error( array( 'message' => __( 'Invalid response from payment provider.', 'upos-woocommerce' ) ) );
      }
    } catch ( Exception $e ) {
      UPOS_Logger::error( 'Failed to fetch currencies: ' . $e->getMessage() );
      wp_send_json_error( array( 'message' => __( 'Unable to load payment options.', 'upos-woocommerce' ) ) );
    }
  }

  /**
   * Handle AJAX connection test
   */
  public static function ajax_test_connection() {
    UPOS_Logger::info( 'Starting API connection test...' );
    check_ajax_referer( 'upos_test_connection', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied', 'upos-woocommerce' ) ) );
    }

    // Strictly use stored settings for security
    // We do NOT accept keys from frontend AJAX request
    $settings   = get_option( 'woocommerce_upos_settings', array() );
    $public_key = isset( $settings['public_key'] ) ? $settings['public_key'] : '';
    $secret_key = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';

    if ( empty( $public_key ) || empty( $secret_key ) ) {
      UPOS_Logger::error( 'API connection test failed: Missing API keys.' );
      wp_send_json_error( array( 'message' => __( 'Missing API keys. Please save settings first.', 'upos-woocommerce' ) ) );
    }

    // Validate key environment consistency
    if ( ! UPOS_Api::validate_keys_environment( $public_key, $secret_key ) ) {
      UPOS_Logger::error( 'API connection test failed: Key environments do not match.' );
      wp_send_json_error( array( 'message' => __( 'Public Key and Secret Key must belong to the same environment (Test or Live).', 'upos-woocommerce' ) ) );
    }

    try {
      // Initialize API client
      $api = new UPOS_Api( $public_key, $secret_key );

      // Test connection by fetching supported currencies and stats
      $result = $api->test_connection();

      if ( is_array( $result ) && isset( $result['success'] ) && $result['success'] ) {
        UPOS_Logger::info( 'API connection test successful.' );
        wp_send_json_success( array( 'message' => $result['message'] ) );
      } else {
        $error_message = is_array( $result ) && isset( $result['message'] ) ? $result['message'] : __( 'Connection failed due to an unexpected error.', 'upos-woocommerce' );
        UPOS_Logger::error( 'API connection test failed: ' . $error_message );
        wp_send_json_error( array( 'message' => $error_message ) );
      }
    } catch ( Exception $e ) {
      UPOS_Logger::error( 'API connection test exception: ' . $e->getMessage() );
      wp_send_json_error( array( 'message' => __( 'Error: ', 'upos-woocommerce' ) . $e->getMessage() ) );
    }
  }

  /**
   * Helper to check if an action is running
   *
   * @param string $hook Hook name.
   * @return bool
   */
  private static function is_action_running( $hook ) {
      if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
          return false;
      }

      $actions = as_get_scheduled_actions( array(
          'hook'   => $hook,
          'status' => \ActionScheduler_Store::STATUS_RUNNING,
          'per_page' => 1,
      ) );

      return ! empty( $actions );
  }

  /**
   * Handle AJAX Manual Sync
   */
  public static function ajax_manual_sync() {
    check_ajax_referer( 'upos_manual_sync', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied', 'upos-woocommerce' ) ) );
    }

    // Check Throttling
    if ( get_transient( 'upos_manual_sync_lock' ) ) {
        wp_send_json_error( array( 'message' => __( 'Please wait 30 seconds between syncs.', 'upos-woocommerce' ) ) );
    }

    // Check Running Status
    if ( self::is_action_running( UPOS_Sync::CRON_HOOK_SYNC ) ) {
        wp_send_json_error( array( 'message' => __( 'Sync task is currently running. Please wait.', 'upos-woocommerce' ) ) );
    }

    // Run Logic Directly
    try {
        UPOS_Sync::run_sync( true );
        set_transient( 'upos_manual_sync_lock', true, 30 ); // Lock for 30s

        $last_run = get_option( UPOS_Sync::OPTION_LAST_MANUAL_RUN_SYNC );
        $fmt_time = $last_run ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) : '';

        wp_send_json_success( array(
            'message' => __( 'Order sync completed successfully.', 'upos-woocommerce' ),
            'last_run' => $fmt_time
        ) );
    } catch ( Exception $e ) {
        wp_send_json_error( array( 'message' => __( 'Error: ', 'upos-woocommerce' ) . $e->getMessage() ) );
    }
  }

  /**
   * Handle AJAX Manual Expire Check
   */
  public static function ajax_manual_expire() {
    check_ajax_referer( 'upos_manual_expire', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied', 'upos-woocommerce' ) ) );
    }

    // Check Throttling
    if ( get_transient( 'upos_manual_expire_lock' ) ) {
        wp_send_json_error( array( 'message' => __( 'Please wait 1 minute between checks.', 'upos-woocommerce' ) ) );
    }

    // Check Running Status
    if ( self::is_action_running( UPOS_Sync::CRON_HOOK_EXPIRE ) ) {
        wp_send_json_error( array( 'message' => __( 'Expiration check is currently running. Please wait.', 'upos-woocommerce' ) ) );
    }

    // Run Logic Directly
    try {
        UPOS_Sync::run_expire( true );
        set_transient( 'upos_manual_expire_lock', true, 60 ); // Lock for 60s

        $last_run = get_option( UPOS_Sync::OPTION_LAST_MANUAL_RUN_EXPIRE );
        $fmt_time = $last_run ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) : '';

        wp_send_json_success( array(
            'message' => __( 'Expiration check completed successfully.', 'upos-woocommerce' ),
            'last_run' => $fmt_time
        ) );
    } catch ( Exception $e ) {
        wp_send_json_error( array( 'message' => __( 'Error: ', 'upos-woocommerce' ) . $e->getMessage() ) );
    }
  }

  /**
   * Handle manual sync request (Classic POST for order details page)
   */
  public function handle_manual_order_sync() {
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
      wp_die( esc_html__( 'You do not have permission to sync orders.', 'upos-woocommerce' ) );
    }

    $order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;

    // Verify nonce
    check_admin_referer( 'upos_sync_' . $order_id );

    if ( ! $order_id ) {
      wp_safe_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
      exit;
    }

    UPOS_Logger::info( 'Manual sync triggered for order #' . $order_id . ' by user ' . get_current_user_id() );

    // Perform sync
    $result = UPOS_Order_Processor::force_sync( $order_id );

    // Redirect back to order
    $redirect_url = get_edit_post_link( $order_id, 'url' );

    if ( $result['success'] ) {
      $redirect_url = add_query_arg( 'upos_sync_message', 'success', $redirect_url );
    } else {
      $redirect_url = add_query_arg( 'upos_sync_message', 'failed', $redirect_url );
    }

    wp_safe_redirect( $redirect_url );
    exit;
  }

  /**
   * Display sync result notice
   */
  public function display_manual_sync_result_notice() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( empty( $_GET['upos_sync_message'] ) ) {
      return;
    }

    $message = '';
    $class   = '';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( 'success' === $_GET['upos_sync_message'] ) {
      $message = __( 'UPOS Order status synced successfully.', 'upos-woocommerce' );
      $class   = 'notice-success';
    } elseif ( 'failed' === $_GET['upos_sync_message'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
      $message = __( 'UPOS Order status sync failed.', 'upos-woocommerce' );
      $class   = 'notice-error';
    }

    if ( $message ) {
      printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }
  }

  /**
   * Add meta box to admin order page
   */
  public function add_admin_order_meta_box() {
    $is_hpos_enabled = false;

    // Modern way (WC 6.5+)
    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_is_enabled' ) ) {
      $is_hpos_enabled = call_user_func( array( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_is_enabled' ) );
    } elseif ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && method_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController', 'feature_is_enabled' ) ) {
      // Older way
      $is_hpos_enabled = call_user_func( array( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController', 'feature_is_enabled' ) );
    } else {
      // Fallback for maximum compatibility
      $is_hpos_enabled = ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' );
    }

    add_meta_box(
      'upos_payment_details',
      __( 'UPOS Payment Details', 'upos-woocommerce' ),
      array( $this, 'render_admin_order_meta_box' ),
      $is_hpos_enabled ? wc_get_page_screen_id( 'shop_order' ) : 'shop_order',
      'side',
      'default'
    );
  }

  /**
   * Render admin order meta box content
   *
   * @param WP_Post|WC_Order $post_or_order_object Post object or Order object (HPOS).
   */
  public function render_admin_order_meta_box( $post_or_order_object ) {
    $order = ( $post_or_order_object instanceof WC_Order ) ? $post_or_order_object : wc_get_order( $post_or_order_object->ID );

    if ( ! $order || $order->get_payment_method() !== $this->id ) {
      echo '<p>' . esc_html__( 'Not a UPOS order.', 'upos-woocommerce' ) . '</p>';
      return;
    }

    $meta = UPOS_Order_Meta::get_all( $order );

    if ( empty( $meta['payment_intent_id'] ) ) {
      echo '<p>' . esc_html__( 'No payment details available yet.', 'upos-woocommerce' ) . '</p>';
      return;
    }

    // Helper to format timestamp
    $fmt_date = function ( $ts ) {
      return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $ts / 1000 ) ) : '-';
    };

    echo '<div class="upos-admin-details">';
 
    echo '<p><strong>' . esc_html__( 'Status', 'upos-woocommerce' ) . ':</strong> ';
    $status       = $meta['logic_status'] ?? 'unknown';
    $status_label = UPOS_Order_FSM::get_logic_status_label( $status );
    $status_class = 'upos-status-' . esc_attr( $status );
    echo '<span class="upos-status-badge ' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></p>';

    echo '<p><strong>' . esc_html__('User Payable', 'upos-woocommerce') . ':</strong> ';
    echo esc_html($meta['payment_amount'] ?? '0') . '</p>';

    if ( ! empty( $meta['buyer_fee'] ) && floatval( $meta['buyer_fee'] ) > 0 ) {
        echo '<p><strong>' . esc_html__( 'Buyer Fee', 'upos-woocommerce' ) . ':</strong> ';
        echo esc_html( $meta['buyer_fee'] ) . '</p>';
    }

    echo '<p><strong>' . esc_html__('Product Price', 'upos-woocommerce') . ':</strong> ';
    echo esc_html($meta['subtotal_amount'] ?? '0') . '</p>';

    if (!empty($meta['seller_fee']) && floatval($meta['seller_fee']) > 0) {
        echo '<p><strong>' . esc_html__( 'Seller Fee', 'upos-woocommerce' ) . ':</strong> ';
        echo esc_html( $meta['seller_fee'] ) . '</p>';
    }

    if ( ! empty( $meta['net_amount'] ) ) {
      echo '<p><strong>' . esc_html__('Merchant Receivable', 'upos-woocommerce') . ':</strong> ';
        echo esc_html( $meta['net_amount'] ) . '</p>';
    }

    echo '<p><strong>' . esc_html__('Current Received', 'upos-woocommerce') . ':</strong> ';
    echo esc_html($meta['received_amount'] ?? '0') . '</p>';

    echo '<p><strong>' . esc_html__( 'Disbursed Amount', 'upos-woocommerce' ) . ':</strong> ';
    echo esc_html( $meta['disbursed_amount'] ?? '0' ) . '</p>';

    echo '<hr style="margin: 10px 0; border-color: #eee;">';

    echo '<p><strong>' . esc_html__('Payment Type', 'upos-woocommerce') . ':</strong> ';
    if (!empty($meta['payment_currency'])) {
      echo esc_html(strtoupper($meta['payment_currency'])) . ' / ' . esc_html(strtoupper($meta['payment_network'] ?? '-'));
    } else {
      echo '-';
    }
    echo '</p>';

    echo '<p><strong>' . esc_html__('Payment Address', 'upos-woocommerce') . ':</strong><br>';
    if (!empty($meta['payment_address'])) {
      echo '<code style="word-break:break-all;">' . esc_html($meta['payment_address']) . '</code>';
    } else {
      echo '-';
    }
    echo '</p>';

    echo '<hr style="margin: 10px 0; border-color: #eee;">';

    echo '<p><strong>' . esc_html__('Expired At', 'upos-woocommerce') . ':</strong><br>';
    echo esc_html( $fmt_date( $meta['expired_at'] ?? 0 ) ) . '</p>';

    echo '<p><strong>' . esc_html__( 'Paid At', 'upos-woocommerce' ) . ':</strong><br>';
    echo esc_html( $fmt_date( $meta['paid_at'] ?? 0 ) ) . '</p>';

    echo '<p><strong>' . esc_html__('Disbursed At', 'upos-woocommerce') . ':</strong><br>';
    echo esc_html( $fmt_date( $meta['disbursed_at'] ?? 0 ) ) . '</p>';
 
    // Sync Button (Manual Trigger)
    echo '<hr style="margin: 10px 0; border-color: #eee;">';
    $sync_url = wp_nonce_url(
      add_query_arg(
        array(
          'action'   => 'upos_force_sync',
          'order_id' => $order->get_id(),
        ),
        admin_url( 'admin-post.php' )
      ),
      'upos_sync_' . $order->get_id()
    );
    echo '<a href="' . esc_url( $sync_url ) . '" class="button button-small">' . esc_html__( 'Sync Status Now', 'upos-woocommerce' ) . '</a>';

    // Transaction History
    echo '<hr style="margin: 10px 0; border-color: #eee;">';
    echo '<p><strong>' . esc_html__( 'Transaction History', 'upos-woocommerce' ) . ':</strong></p>';

    $events_json = UPOS_Order_Meta::get( $order, 'payment_events' );
    $events = ! empty( $events_json ) ? json_decode( $events_json, true ) : array();

    if ( ! empty( $events ) && is_array( $events ) ) {
      // Scrollable Container
      echo '<div style="border: 1px solid #ccd0d4; background: #fff; max-height: 200px; overflow-y: auto; margin-top: 5px;">';
      echo '<ul style="margin: 0; padding: 0; list-style: none;">';

      // Sort events by date desc
      usort($events, function ($a, $b) {
        $t_a = $a['timestamp'] ?? $a['createdAt'] ?? 0;
        $t_b = $b['timestamp'] ?? $b['createdAt'] ?? 0;
        return $t_b - $t_a;
      });

      foreach ($events as $event) {
        $date = $fmt_date($event['timestamp'] ?? $event['createdAt'] ?? 0);
        $type = $event['type'] ?? '-';

        // Status Text
        $status_text = $event['status'] ?? '-';
        if (!empty($event['direction'])) {
          $status_text .= ' (' . $event['direction'] . ')';
        }

        // Amount
        $amount = isset($event['amount']) ? $event['amount'] . ' ' . strtoupper($event['currency'] ?? '') : '-';

        // TX Details
        $tx_html = '';
        $externalId = $event['externalId'] ?? '';
        if (!empty($externalId)) {
          $tx_html = '<div style="font-size: 10px; color: #666; margin-top: 2px;">' . esc_html__('Tx', 'upos-woocommerce') . ': <span style="font-family: monospace; word-break: break-all;">' . esc_html($externalId) . '</span></div>';
        }

        // Render Item
        echo '<li style="padding: 8px 10px; border-bottom: 1px solid #f0f0f1;">';

        // Row 1: Date & Type
        echo '<div style="display: flex; justify-content: space-between; font-size: 11px; color: #50575e; margin-bottom: 4px;">';
        echo '<span>' . esc_html($date) . '</span>';
        echo '<span style="font-weight: 600; color: #2271b1;">' . esc_html(ucfirst($type)) . '</span>';
        echo '</div>';

        // Row 2: Status & Amount
        echo '<div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 2px;">';
        echo '<span style="color: #1d2327;">' . esc_html($status_text) . '</span>';
        echo '<strong style="color: #1d2327;">' . esc_html($amount) . '</strong>';
        echo '</div>';

        // Row 3: Tx ID
        echo $tx_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo '</li>';
      }
      echo '</ul>';
      echo '</div>';
    } else {
      echo '<p class="description" style="margin-top:5px;">-</p>';
    }

    echo '</div>';
  }

  /**
   * Enforce keys for enabled status
   *
   * Filter callback to ensure that the gateway cannot be enabled
   * if the Public Key or Secret Key are missing.
   *
   * @param array $settings Sanitized settings array.
   * @return array Modified settings.
   */
  public function enforce_keys_for_enabled( $settings ) {
    $enabled = isset( $settings['enabled'] ) ? $settings['enabled'] : 'no';
    $public_key = isset( $settings['public_key'] ) ? $settings['public_key'] : '';
    $secret_key = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';

    // If enabled is 'yes', but keys are missing
    if ( 'yes' === $enabled && ( empty( $public_key ) || empty( $secret_key ) ) ) {
      // Force disable
      $settings['enabled'] = 'no';

      // Add error message
      WC_Admin_Settings::add_error( __( 'Please enter your Public Key and Secret Key before enabling the gateway.', 'upos-woocommerce' ) );
    }

    return $settings;
  }

  /**
   * Generate custom secret key HTML
   *
   * Prevents the actual secret key from being output in the HTML source.
   *
   * @param string $key Field key.
   * @param array  $data Field data.
   * @return string
   */
  public function generate_upos_secret_key_html( $key, $data ) {
    $field_key = $this->get_field_key( $key );
    $defaults  = array(
      'title'             => '',
      'disabled'          => false,
      'class'             => '',
      'css'               => '',
      'placeholder'       => '',
      'type'              => 'password',
      'desc_tip'          => false,
      'description'       => '',
      'custom_attributes' => array(),
    );

    $data  = wp_parse_args( $data, $defaults );
    $value = $this->get_option( $key );

    // If value exists, mask it and don't output in value attribute
    $display_placeholder = $data['placeholder'];
    if ( ! empty( $value ) ) {
      $display_placeholder = '*****'; // Masked display for security
    }

    ob_start();
    ?>
      <tr valign="top">
        <th scope="row" class="titledesc">
          <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
        </th>
        <td class="forminp">
          <fieldset>
            <legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
            <input class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>"
                type="text"
                name="<?php echo esc_attr( $field_key ); ?>"
                id="<?php echo esc_attr( $field_key ); ?>"
                style="<?php echo esc_attr( $data['css'] ); ?>"
                value=""
                placeholder="<?php echo esc_attr( $display_placeholder ); ?>"
            <?php disabled( $data['disabled'], true ); ?>
            <?php echo $this->get_custom_attribute_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
            <?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          </fieldset>
        </td>
      </tr>
    <?php
    return ob_get_clean();
  }

  /**
   * Validate UPOS Secret Key Field
   *
   * Since we empty the value on display, we need to handle saving carefully.
   * If the input is empty, it means the user didn't change it, so we keep the old value.
   *
   * @param string $key Field key.
   * @param string $value Posted value.
   * @return string
   */
  public function validate_upos_secret_key_field( $key, $value ) {
    $value         = is_null( $value ) ? '' : $value;
    $current_value = $this->get_option( $key );

    // If value is empty but we have a stored value, keep the stored value
    // (This handles the masked display logic)
    $final_value = ( empty( $value ) && ! empty( $current_value ) ) ? $current_value : trim( $value );

    // Get the public key to compare against
    // Check $_POST first (if user is updating it)
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $posted_public_key = isset( $_POST['woocommerce_upos_public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_upos_public_key'] ) ) : '';

    // If posted public key is empty, get from DB
    $public_key = ! empty( $posted_public_key ) ? $posted_public_key : $this->get_option( 'public_key' );

    // Validate environment consistency
    if ( ! empty( $final_value ) && ! empty( $public_key ) ) {
      if ( ! UPOS_Api::validate_keys_environment( $public_key, $final_value ) ) {
        $error_msg = __( 'Public Key and Secret Key must belong to the same environment (Test or Live). Settings not saved.', 'upos-woocommerce' );
        WC_Admin_Settings::add_error( $error_msg );

        // Return old value to prevent saving invalid config
        return $current_value;
      }
    }

    return $final_value;
  }

  /**
   * Validate Public Key Field
   *
   * Also checks consistency with Secret Key environment.
   *
   * @param string $key Field key.
   * @param string $value Posted value.
   * @return string
   */
  public function validate_public_key_field( $key, $value ) {
    $value         = is_null( $value ) ? '' : trim( $value );
    $current_value = $this->get_option( $key );

    // If value is empty but we have a stored value, keep the stored value
    // This prevents accidental clearing if the field is not present or accidentally cleared
    // while keeping the gateway enabled.
    $final_value = ( empty( $value ) && ! empty( $current_value ) ) ? $current_value : $value;

    // Get the secret key to compare against
    // Check $_POST first (if user is updating it)
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $posted_secret_key = isset( $_POST['woocommerce_upos_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_upos_secret_key'] ) ) : '';

    // If posted secret key is empty (masked), get from DB
    if ( empty( $posted_secret_key ) ) {
      $secret_key = $this->get_option( 'secret_key' );
    } else {
      $secret_key = $posted_secret_key;
    }

    // Validate environment consistency
    if ( ! empty( $final_value ) && ! empty( $secret_key ) ) {
      if ( ! UPOS_Api::validate_keys_environment( $final_value, $secret_key ) ) {
        $error_msg = __( 'Public Key and Secret Key must belong to the same environment (Test or Live). Settings not saved.', 'upos-woocommerce' );
        WC_Admin_Settings::add_error( $error_msg );

        // Return old value to prevent saving invalid config
        return $current_value;
      }
    }

    return $final_value;
  }

  /**
   * Initialize form fields for settings
   */
  public function init_form_fields() {
    $this->form_fields = array(
      'enabled'     => array(
        'title'   => __( 'Enable/Disable', 'upos-woocommerce' ),
        'type'    => 'checkbox',
        'label'   => __( 'Enable UPOS Payments', 'upos-woocommerce' ),
        'default' => 'no',
      ),
      'logging'     => array(
        'title'       => __( 'Debug Log', 'upos-woocommerce' ),
        'type'        => 'checkbox',
        'label'       => __( 'Enable logging', 'upos-woocommerce' ),
        'default'     => 'yes',
        'description' => sprintf(
          /* translators: %s: log file path */
          __( 'Log UPOS events to WooCommerce logs (%s)', 'upos-woocommerce' ),
          '<code>WooCommerce > Status > Logs</code>'
        ),
      ),
      'public_key'  => array(
        'title'       => __( 'Public Key', 'upos-woocommerce' ),
        'type'        => 'text',
        'description' => __( 'UPOS Public Key (pk_test_xxx or pk_live_xxx)', 'upos-woocommerce' ),
        'placeholder' => 'pk_xxx_xxxxxxxx',
      ),
      'secret_key'  => array(
        'title'       => __( 'Secret Key', 'upos-woocommerce' ),
        'type'        => 'upos_secret_key', // Custom type to prevent outputting value in HTML
        'description' => __( 'UPOS Secret Key (sk_test_xxx or sk_live_xxx)', 'upos-woocommerce' ),
        'placeholder' => 'sk_xxx_xxxxxxxx',
      ),
      'webhook_url' => array(
        'title'             => __( 'Webhook URL', 'upos-woocommerce' ),
        'type'              => 'text',
        'description'       => __( 'Set this URL in UPOS backend to receive real-time payment notifications.', 'upos-woocommerce' ),
        'default'           => '',
        'custom_attributes' => array(
          'readonly' => 'readonly',
        ),
      ),
    );
  }

  /**
   * Display payment fields on checkout
   *
   * Currency and network selection will be handled by frontend
   * which fetches supported options from UPOS API
   */
  public function payment_fields() {
    // Container for dynamic currency/network selection
    echo '<div id="upos-payment-options" class="upos-payment-options upos-shortcode-content">';

    // Test mode notice
    if ( $this->api->is_testmode() ) {
      echo '<div class="upos-testmode-notice">';
      esc_html_e( 'Test Mode Enabled', 'upos-woocommerce' );
      echo '</div>';
    }

    // JS Render Target
    echo '<div class="upos-options-render-target">';
    echo '<p>' . esc_html__( 'Loading payment options...', 'upos-woocommerce' ) . '</p>';
    echo '</div>'; // End render target

    echo '</div>'; // End main container

    // Hidden fields for selected currency and network
    echo '<input type="hidden" name="upos_currency" id="upos_currency" value="" />';
    echo '<input type="hidden" name="upos_network" id="upos_network" value="" />';
  }

  /**
   * Validate payment fields
   *
   * @return bool
   */
  public function validate_fields() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $currency = isset( $_POST['upos_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['upos_currency'] ) ) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $network = isset( $_POST['upos_network'] ) ? sanitize_text_field( wp_unslash( $_POST['upos_network'] ) ) : '';

    if ( empty( $currency ) ) {
      wc_add_notice( __( 'Please select a payment currency', 'upos-woocommerce' ), 'error' );
      return false;
    }

    if ( empty( $network ) ) {
      wc_add_notice( __( 'Please select a payment network', 'upos-woocommerce' ), 'error' );
      return false;
    }

    return true;
  }

  /**
   * Process payment
   *
   * @param int $order_id Order ID.
   * @return array
   */
  public function process_payment( $order_id ) {
    $flow_id = sprintf( 'pay_ord_%d_%s', $order_id, substr( wp_generate_password( 6, false ), 0, 6 ) );
    UPOS_Logger::set_flow_id( $flow_id );

    try {
      UPOS_Logger::info( 'Starting payment process for order #' . $order_id );

      $order = wc_get_order( $order_id );

      if ( ! $order ) {
        UPOS_Logger::error( 'Could not find order #' . $order_id . ' in process_payment.' );
        wc_add_notice( __( 'Order not found', 'upos-woocommerce' ), 'error' );
        return array( 'result' => 'failure' );
      }

      // Unified handling for currency/network from Store API (meta) or classic POST.
      $currency_meta = $order->get_meta( '_upos_currency', true );
      // phpcs:ignore WordPress.Security.NonceVerification.Missing
      $currency      = ! empty( $currency_meta ) ? $currency_meta : sanitize_text_field( $_POST['upos_currency'] ?? '' );

      $network_meta = $order->get_meta( '_upos_network', true );
      // phpcs:ignore WordPress.Security.NonceVerification.Missing
      $network      = ! empty( $network_meta ) ? $network_meta : sanitize_text_field( $_POST['upos_network'] ?? '' );

      // Delegate processing to UPOS_Order_Processor
      $result = UPOS_Order_Processor::create_payment( $order, $currency, $network, $this );

      if ( 'failure' === $result['result'] ) {
         if ( ! empty( $result['message'] ) ) {
             wc_add_notice( __( 'Payment error: ', 'upos-woocommerce' ) . $result['message'], 'error' );
         }
      }

      return $result;

    } catch ( Exception $e ) {
      UPOS_Logger::error( 'Payment error for order #' . $order_id . ': ' . $e->getMessage() );
      wc_add_notice(
        __( 'Payment error: ', 'upos-woocommerce' ) . $e->getMessage(),
        'error'
      );
      return array(
        'result'   => 'failure',
        'messages' => $e->getMessage(),
      );
    } finally {
      // Always clear the flow ID at the end of the process.
      UPOS_Logger::clear_flow_id();
    }
  }

  /**
   * Thank you page - show payment status
   *
   * User is redirected here after completing payment on UPOS page.
   *
   * @param int $order_id Order ID.
   */
  public function thankyou_page( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
      return;
    }

    // Only show for UPOS orders
    if ( $order->get_payment_method() !== $this->id ) {
      return;
    }

    // Proactive Sync: Try to update status immediately upon return
    // This reduces the chance of showing "Pending" when the user has already paid
    // Throttling: Check if we synced recently (within 30 seconds) to prevent abuse
    $should_sync = true;
    $last_checked_at = UPOS_Order_Meta::get_checked_at( $order_id );

    if ( $last_checked_at ) {
      $now_ms = time() * 1000;
      if ( ( $now_ms - $last_checked_at ) < 30000 ) { // 30 seconds
         $should_sync = false;
      }
    }

    if ( $should_sync ) {
      try {
        if ( class_exists( 'UPOS_Order_Processor' ) ) {
          UPOS_Order_Processor::sync_order( $order_id );
          // Reload order to get updated meta/status after sync
          // Note: wc_get_order caches objects, so we might need to clear cache or trust that update_meta updates the object in memory if loaded.
          // However, since sync_order creates a new instance internally or updates DB,
          // reloading specific meta fields later via UPOS_Order_Meta is safer.
          // But for $order object state, we'll just re-fetch to be safe.
          $order = wc_get_order( $order_id );
        }
      } catch ( Exception $e ) {
        // Just log and continue, don't break the thank you page
        UPOS_Logger::error( 'Auto-sync on thank you page failed: ' . $e->getMessage() );
      }
    }

    // Check payment status
    // We calculate the state dynamically to account for expiration time passing
    // even if the database hasn't been updated yet.
    $current_status = UPOS_Order_FSM::get_calculated_status( $order );
    $message        = UPOS_Order_FSM::get_status_message( $current_status );

    // Success (Paid or Settled)
    if ( UPOS_Order_FSM::is_paid( $current_status ) ) {
      ?>
        <div class="upos-payment-success" style="padding: 20px; background: #d4edda; border-radius: 4px; margin: 20px 0;">
          <h2 style="color: #155724; margin-top: 0;"><?php esc_html_e( 'Payment Confirmed', 'upos-woocommerce' ); ?></h2>
          <p><?php echo esc_html( $message ); ?></p>
        </div>
      <?php
      return;
    }

    // Expired
    if ( UPOS_Constants::STATUS_EXPIRED === $current_status ) {
      ?>
        <div class="upos-payment-expired" style="padding: 20px; background: #f8d7da; border-radius: 4px; margin: 20px 0;">
          <h2 style="color: #721c24; margin-top: 0;"><?php esc_html_e( 'Payment Expired', 'upos-woocommerce' ); ?></h2>
          <p><?php echo esc_html( $message ); ?></p>
        </div>
      <?php
      return;
    }

    // Pending / Awaiting Payment (Default)
    ?>
      <div class="upos-payment-pending" style="padding: 20px; background: #fff3cd; border-radius: 4px; margin: 20px 0;">
        <h2 style="color: #856404; margin-top: 0;"><?php esc_html_e( 'Awaiting Payment Confirmation', 'upos-woocommerce' ); ?></h2>
        <p><?php echo esc_html( $message ); ?></p>
      </div>
    <?php
  }

  /**
   * Get API client instance
   *
   * @return UPOS_Api
   */
  public function get_api() {
    return $this->api;
  }

  /**
   * Check if gateway is available
   *
   * @return bool
   */
  public function is_available() {
    if ( 'yes' !== $this->enabled ) {
      return false;
    }

    // Check if API keys are set
    $public_key = $this->get_option( 'public_key' );
    $secret_key = $this->get_option( 'secret_key' );

    if ( empty( $public_key ) || empty( $secret_key ) ) {
      UPOS_Logger::debug( 'Gateway disabled: API keys are not set.' );
      return false;
    }

    // Check if currency is supported
    $currency     = get_woocommerce_currency();
    $is_supported = UPOS_Exchange::is_fiat_supported( $currency );

    if ( ! $is_supported ) {
      UPOS_Logger::debug( 'Gateway disabled: Store currency ' . $currency . ' not supported by exchange API.' );
      return false;
    }

    $parent_available = parent::is_available();

    return $parent_available;
  }

  /**
   * Admin options - add connection test
   */
  public function admin_options() {
    // Show environment based on key
    $public_key = $this->get_option( 'public_key' );
    $secret_key = $this->get_option( 'secret_key' );
    $is_enabled = 'yes' === $this->get_option( 'enabled' );

    // Tasks require enabled plugin and both keys
    $tasks_enabled = $is_enabled && ! empty( $public_key ) && ! empty( $secret_key );
    $task_btn_attr = $tasks_enabled ? '' : 'disabled';
    $task_btn_cls  = $tasks_enabled ? 'button' : 'button disabled';

    // Connection test requires both keys
    $conn_enabled  = ! empty( $public_key ) && ! empty( $secret_key );
    $conn_btn_attr = $conn_enabled ? '' : 'disabled';
    $conn_btn_cls  = $conn_enabled ? 'button' : 'button disabled';

    if ( ! empty( $public_key ) ) {
      $is_test = strpos( $public_key, 'pk_test_' ) === 0;
      echo '<div class="notice notice-info inline"><p>';
      if ( $is_test ) {
        esc_html_e( 'Using Test Environment Keys', 'upos-woocommerce' );
      } else {
        esc_html_e( 'Using Live Environment Keys', 'upos-woocommerce' );
      }
      echo '</p></div>';
    }

    parent::admin_options();

    // Wrapper for scoped selection
    echo '<div class="wc-upos-settings">';

    ?>
      <hr />
      <h3><?php esc_html_e( 'Tools & Diagnostics', 'upos-woocommerce' ); ?></h3>

      <table class="form-table">
        <tr valign="top">
          <th scope="row" class="titledesc">
            <?php esc_html_e( 'API Connection', 'upos-woocommerce' ); ?>
          </th>
          <td class="forminp">
            <div style="display: flex; align-items: center; gap: 10px;">
              <button type="button" class="<?php echo esc_attr( $conn_btn_cls ); ?> upos-test-connection" <?php echo $conn_btn_attr; ?>>
                <?php esc_html_e( 'Test Connection', 'upos-woocommerce' ); ?>
              </button>
              <span class="upos-test-result" style="line-height: 2.3; margin-left: 10px;"></span>
            </div>
            <p class="description"><?php esc_html_e( 'Verify that your API keys are correct and the UPOS server is reachable.', 'upos-woocommerce' ); ?></p>
          </td>
        </tr>

        <?php
          // Get Last Run Times
          $last_sync   = get_option( UPOS_Sync::OPTION_LAST_MANUAL_RUN_SYNC );
          $last_expire = get_option( UPOS_Sync::OPTION_LAST_MANUAL_RUN_EXPIRE );

          $fmt_time = function($t) {
              return $t ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $t ) : __( 'Never', 'upos-woocommerce' );
          };
        ?>

        <tr valign="top">
          <th scope="row" class="titledesc">
            <?php esc_html_e( 'Scheduled Tasks', 'upos-woocommerce' ); ?>
          </th>
          <td class="forminp">
            <!-- Sync Task -->
            <div style="margin-bottom: 15px;">
                <strong><?php esc_html_e( 'Order Sync Task', 'upos-woocommerce' ); ?></strong>
                <br>
                <span class="description"><?php printf( esc_html__( 'Last Manual Run: %s', 'upos-woocommerce' ), '<code class="upos-last-run-sync">' . esc_html( $fmt_time( $last_sync ) ) . '</code>' ); ?></span>
                <div style="margin-top: 5px; display: flex; align-items: center; gap: 10px;">
                     <button type="button" class="<?php echo esc_attr( $task_btn_cls ); ?> upos-manual-sync" <?php echo $task_btn_attr; ?>>
                        <?php esc_html_e( 'Run Sync Now', 'upos-woocommerce' ); ?>
                     </button>
                     <span class="upos-sync-result"></span>
                </div>
            </div>

            <!-- Expire Task -->
            <div>
                <strong><?php esc_html_e( 'Expiration Check Task', 'upos-woocommerce' ); ?></strong>
                <br>
                <span class="description"><?php printf( esc_html__( 'Last Manual Run: %s', 'upos-woocommerce' ), '<code class="upos-last-run-expire">' . esc_html( $fmt_time( $last_expire ) ) . '</code>' ); ?></span>
                <div style="margin-top: 5px; display: flex; align-items: center; gap: 10px;">
                     <button type="button" class="<?php echo esc_attr( $task_btn_cls ); ?> upos-manual-expire" <?php echo $task_btn_attr; ?>>
                        <?php esc_html_e( 'Check Expired Now', 'upos-woocommerce' ); ?>
                     </button>
                     <span class="upos-expire-result"></span>
                </div>
            </div>

            <p class="description" style="margin-top: 10px;">
                <?php esc_html_e( 'Tasks run automatically in the background. Use these buttons only if you need to force an immediate update.', 'upos-woocommerce' ); ?>
            </p>
          </td>
        </tr>
      </table>
    <?php
    echo '</div>'; // End .wc-upos-settings
  }
}