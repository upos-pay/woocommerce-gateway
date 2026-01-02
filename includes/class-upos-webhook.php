<?php
/**
 * UPOS Webhook Handler
 *
 * Handles incoming webhook notifications from UPOS API for real-time order updates.
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Webhook class
 *
 * Webhook Endpoint: /wp-json/upos/v1/webhook
 *
 * Two ways to use webhook:
 * 1. Global webhook: Configure in UPOS merchant settings (for all orders)
 * 2. Per-order webhook: Pass webhookUrl when creating payment intent
 *
 * Event Types (matching WebhookEvent):
 * - payment_intent.paid    : Payment confirmed
 * - payment_intent.settled : Payment disbursed
 * - payment_event.received : New payment events received
 *
 * Webhook Payload (from UPOS - matching CreateWebhookTask):
 * {
 *   "event": "payment_intent.paid",
 *   "timestamp": 1234567890,
 *   "data": {
 *     "id": "pi_xxx",
 *     "orderId": "123",
 *     "requestAmount": "100.00",
 *     "requestCurrency": "USDT",
 *     "exchangeRate": "1.00",
 *     "subtotalAmount": "100.00",
 *     "paymentAmount": "102.00",
 *     "receivedAmount": "100.00",
 *     "status": "paid",
 *     "createdAt": 1234567890
 *   }
 * }
 */
class UPOS_Webhook {

  /**
   * REST API namespace
   */
  const REST_NAMESPACE = 'upos/v1';

  /**
   * Webhook route
   */
  const WEBHOOK_ROUTE = '/webhook';

  /**
   * Initialize webhook handler
   */
  public static function init() {
    add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
  }

  /**
   * Register REST API routes
   */
  public static function register_routes() {
    register_rest_route(
      self::REST_NAMESPACE,
      self::WEBHOOK_ROUTE,
      array(
        'methods'             => 'POST',
        'callback'            => array( __CLASS__, 'handle_webhook' ),
        'permission_callback' => '__return_true', // Public endpoint, validated via signature
      )
    );
  }

  /**
   * Get the webhook URL for this site
   *
   * @return string
   */
  public static function get_webhook_url() {
    return rest_url( self::REST_NAMESPACE . self::WEBHOOK_ROUTE );
  }

  /**
   * Handle incoming webhook request
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response
   */
  public static function handle_webhook( $request ) {
    $payload  = $request->get_json_params();
    $order_id = $payload['data']['orderId'] ?? 'unknown';
    $flow_id  = sprintf( 'hook_ord_%s_%s', $order_id, substr( wp_generate_password( 6, false ), 0, 6 ) );
    UPOS_Logger::set_flow_id( $flow_id );

    try {
      $event = $payload['event'] ?? 'unknown';

      UPOS_Logger::info( sprintf( 'Webhook received: event=`%s`, order_id=`%s`', $event, $order_id ) );
      UPOS_Logger::debug( 'Webhook payload: ' . wp_json_encode( $payload ) );

      // Validate signature (Strict mode: Required)
      $signature = $request->get_header( 'X-UPOS-Signature' );
      
      if ( empty( $signature ) || ! self::verify_signature( $request, $signature ) ) {
        UPOS_Logger::warning( 'Webhook: Invalid or missing signature' );
        return new WP_REST_Response(
          array(
            'success' => false,
            'message' => 'Invalid signature',
          ),
          401
        );
      }

      // Validate payload structure
      if ( empty( $payload['event'] ) || empty( $payload['data'] ) ) {
        UPOS_Logger::warning( 'Webhook: Invalid payload structure' );
        return new WP_REST_Response(
          array(
            'success' => false,
            'message' => 'Invalid payload',
          ),
          400
        );
      }

      $data = $payload['data'];

      switch ( $event ) {
        case 'payment_intent.paid':
        case 'payment_intent.settled':
        case 'payment_intent.expired':
        case 'payment_event.received':
          self::trigger_sync( $data );
            break;

        default:
          UPOS_Logger::info( 'Webhook: Unhandled event type: ' . $event );
            break;
      }

      // Always return 200 OK if we reached here.
      // We accepted the webhook and attempted to process it.
      // Even if sync didn't change anything (sync_order returns false), it is not an HTTP error.
      return new WP_REST_Response(
        array( 'success' => true ),
        200
      );
    } finally {
      UPOS_Logger::clear_flow_id();
    }
  }

  /**
   * Verify webhook signature
   *
   * @param WP_REST_Request $request   The request object.
   * @param string          $signature The signature from header.
   * @return bool
   */
  private static function verify_signature( $request, $signature ) {
    // Get secret key from gateway settings
    $settings   = get_option( 'woocommerce_upos_settings', array() );
    $secret_key = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';

    if ( empty( $secret_key ) ) {
      UPOS_Logger::error( 'Webhook: Secret key not configured, cannot verify signature' );
      return false;
    }

    // Get raw body
    $body = $request->get_body();

    // Calculate expected signature (HMAC-SHA256)
    $expected = hash_hmac( 'sha256', $body, $secret_key );

    // Handle "algo=signature" format (e.g., "sha256=...")
    // We split by the first '=' to separate the algorithm prefix from the signature.
    $parts = explode( '=', $signature, 2 );
    if ( count( $parts ) === 2 ) {
      $signature = $parts[1];
    }

    return hash_equals( $expected, $signature );
  }

  /**
   * Find order from webhook data
   *
   * @param array $data Webhook payload data.
   * @return WC_Order|null
   */
  private static function find_order_from_data( $data ) {
    $payment_intent_id = $data['id'] ?? '';
    $order_id_str      = $data['orderId'] ?? '';

    if ( empty( $payment_intent_id ) || empty( $order_id_str ) ) {
      UPOS_Logger::warning( 'Webhook: Missing payment intent ID or order ID in payload' );
      return null;
    }

    $order = self::find_order_by_payment_intent( $payment_intent_id );

    if ( $order ) {
      $is_id_match     = (string) $order->get_id() === (string) $order_id_str;
      $is_number_match = (string) $order->get_order_number() === (string) $order_id_str;
      $is_upos_method  = $order->get_payment_method() === UPOS_Gateway::GATEWAY_ID;

      if ( ( $is_id_match || $is_number_match ) && $is_upos_method ) {
        return $order;
      }
    }

    UPOS_Logger::warning(
      sprintf(
        'Webhook: Order not found matching payment_intent_id=%s AND order_id=%s',
        $payment_intent_id,
        $order_id_str
      )
    );
    return null;
  }

  /**
   * Trigger sync for an order based on webhook data
   *
   * @param array $data Payment intent data from webhook.
	 * @return array {
	 *     @type bool $success Whether the operation completed without error.
	 *     @type bool $updated Whether the order status was changed.
	 * }
   */
  private static function trigger_sync( $data ) {
    $order = self::find_order_from_data( $data );
    if ( ! $order ) {
      return array( 'success' => false, 'updated' => false );
    }
    return UPOS_Order_Processor::sync_order( $order->get_id() );
  }

  /**
   * Find order by payment intent ID
   *
   * @param string $payment_intent_id Payment intent ID.
   * @return WC_Order|null
   */
  private static function find_order_by_payment_intent( $payment_intent_id ) {
    $orders = wc_get_orders(
      array(
        'limit'      => 1,
        'meta_query' => array(
          array(
            'key'   => UPOS_Order_Meta::get_meta_key( 'payment_intent_id' ),
            'value' => $payment_intent_id,
          ),
        ),
      )
    );
    return ! empty( $orders ) ? $orders[0] : null;
  }
}