<?php
/**
 * UPOS API Client
 *
 * Handles communication with the UPOS payment API
 * Based on UPOS cryptocurrency payment system (TRON/USDT)
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Api class
 *
 * API Endpoints:
 * - GET  /v1/merchants/supported-currencies
 * - POST /v1/payment-intents
 * - GET  /v1/payment-intents/:id/detail
 * - GET  /v1/payment-intents/detail?token=xxx
 * - GET  /v1/merchants/statistics/disbursement
 */
class UPOS_Api {
    /**
     * Default API URL
     */
    const API_HOST = 'https://api.upos.fi';

    /**
     * Public Key (pk_test_xxx or pk_live_xxx)
     *
     * @var string
     */
    private $public_key;

    /**
     * Secret Key (sk_test_xxx or sk_live_xxx)
     *
     * @var string
     */
    private $secret_key;

    /**
     * Constructor
     *
     * @param string $public_key Public key (pk_test_xxx or pk_live_xxx).
     * @param string $secret_key Secret key (sk_test_xxx or sk_live_xxx).
     */
  public function __construct( $public_key, $secret_key ) {
      $this->public_key       = $public_key;
      $this->secret_key       = $secret_key;
  } 

  /**
   * Check if using test mode based on key prefix
   *
   * @return bool
   */
  public function is_testmode() {
    return strpos( $this->public_key, 'pk_test_' ) === 0;
  }

  /**
   * Get supported currencies
   *
   * GET /v1/merchants/supported-currencies
   *
   * @return array An array with a 'currencies' key. The value is an array of currency objects, each containing:
   *               - 'id': (string)
   *               - 'name': (string)
   *               - 'networks': (array) An array of network objects, each with 'id' and 'name'.
   * @throws Exception If API request fails.
   */
  public function get_supported_currencies() {
    return $this->request( 'GET', '/v1/merchants/supported-currencies', null, $this->public_key );
  }

  /**
   * Create a payment intent
   *
   * POST /v1/payment-intents
   *
   * @param array $data Payment intent data.
   *          - orderId: (string) Merchant's order ID
   *          - amount: (string) Payment amount
   *          - paymentMethod: (array) { type: 'crypto_tron', currency: 'usdt' }
   *          - returnUrl: (string) URL to redirect after payment
   *          - webhookUrl: (string) URL for webhook notifications (optional)
   * @return array An array containing:
   *               - 'token': (string) One-time token for payment page (pit_xxx).
   *               - 'paymentUrl': (string) Full URL to UPOS payment page.
   *               - 'intent': (array) An array with payment intent details:
   *                 - 'id': (string) Payment intent ID (pi_xxx).
   *                 - 'orderId': (string).
   *                 - 'orderAmount': (string).
   *                 - 'paymentAmount': (string).
   *                 - 'paymentMethod': (array|null) containing type, currency, network, address.
   *                 - 'status': (string).
   *                 - 'returnUrl': (string|null).
   *                 - 'expiredAt': (int|null) epoch ms.
   *                 - 'createdAt': (int) epoch ms.
   * @throws Exception If API request fails.
   */
  public function create_payment_intent( $data ) {
    $payload = array(
      'orderId'       => (string) $data['orderId'],
      'amount'        => (string) $data['amount'],
      'paymentMethod' => $data['paymentMethod'],
    );

    if ( isset( $data['returnUrl'] ) ) {
      $payload['returnUrl'] = $data['returnUrl'];
    }

    if ( isset( $data['webhookUrl'] ) ) {
      $payload['webhookUrl'] = $data['webhookUrl'];
    }

    return $this->request( 'POST', '/v1/payment-intents', $payload, $this->secret_key );
  }

  /**
   * Get payment intent by ID (authenticated)
   *
   * GET /v1/payment-intents/:id/detail
   *
   * @param string $payment_intent_id Payment intent ID (pi_xxx).
   * @return array An array containing the payment intent details, including keys like:
   *               - id
   *               - orderId
   *               - orderAmount
   *               - paymentAmount
   *               - netAmount
   *               - buyerFee
   *               - sellerFee
   *               - paymentMethod
   *               - receivedAmount
   *               - status
   *               - returnUrl
   *               - events
   *               - statusHistory
   *               - disbursements
   *               - paidAt
   *               - expiredAt
   *               - settledAt
   *               - createdAt
   *               - updatedAt
   * @throws Exception If API request fails.
   */
  public function get_payment_intent( $payment_intent_id ) {
    return $this->request( 'GET', '/v1/payment-intents/' . $payment_intent_id . '/detail', null, $this->secret_key );
  }

  /**
   * Get payment intent by token (public, no auth required)
   *
   * GET /v1/payment-intents/detail?token=xxx
   *
   * @param string $token Payment token (pit_xxx).
   * @return array Payment intent details
   * @throws Exception If API request fails.
   */
  public function get_payment_intent_by_token( $token ) {
    return $this->request( 'GET', '/v1/payment-intents/detail', array( 'token' => $token ), '' );
  }

  /**
   * Get disbursement statistics
   *
   * GET /v1/merchants/statistics/disbursement
   *
   * @param array $params Query parameters.
   *            - timezone: (string) e.g., 'Asia/Taipei'
   *            - week-starts-on: (int) 0=Sunday, 1=Monday
   * @return array Disbursement statistics
   * @throws Exception If API request fails.
   */
  public function get_disbursement_statistics( $params = array() ) {
    return $this->request( 'GET', '/v1/merchants/statistics/disbursement', $params, $this->secret_key );
  }


  /**
   * Sensitive keys to filter from logs. Case-insensitive.
   *
   * @var array
   */
  const SENSITIVE_KEYS = array(
      'token',
      'secret',
      'secret_key',
      'public_key',
      'password',
      'Authorization',
  );

  /**
   * Recursively filters sensitive data from an array.
   *
   * @param array|mixed $data The data to filter.
   * @return array|mixed The filtered data.
   */
  private static function filter_sensitive_data( $data ) {
      if ( ! is_array( $data ) ) {
          return $data;
      }

      $filtered_data = array();
      foreach ( $data as $key => $value ) {
          $is_sensitive = false;
          foreach ( self::SENSITIVE_KEYS as $sensitive_key ) {
              if ( strcasecmp( $key, $sensitive_key ) === 0 ) {
                  $is_sensitive = true;
                  break;
              }
          }

          if ( $is_sensitive ) {
              $filtered_data[ $key ] = '[FILTERED]';
          } elseif ( is_array( $value ) ) {
              $filtered_data[ $key ] = self::filter_sensitive_data( $value );
          } else {
              $filtered_data[ $key ] = $value;
          }
      }
      return $filtered_data;
  }

  /**
   * Make an API request
   *
   * @param string     $method     HTTP method.
   * @param string     $endpoint   API endpoint.
   * @param array|null $data     Request data (body for POST, query params for GET).
   * @param string     $token    Token to use for authorization.
   * @return array
   * @throws Exception If API request fails or auth token is missing.
   */
  private function request( $method, $endpoint, $data = null, $token = '' ) {
    $trace_id   = substr( wp_generate_password( 8, false ), 0, 8 );
    $url        = self::API_HOST . $endpoint;
    $start_time = microtime( true );

    // For GET requests, append data as query parameters
    if ( 'GET' === $method && ! empty( $data ) ) {
      $query = http_build_query( $data, '', '&', PHP_QUERY_RFC3986 );
      $url  .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . $query;
    }

    $headers = array(
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
    );

    // Add auth header if token is provided
    if ( ! empty( $token ) ) {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    $args = array(
      'method'  => $method,
      'timeout' => 30,
      'headers' => $headers,
    );

    // For POST/PUT/PATCH, send data as JSON body
    if ( $data && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
      $args['body'] = wp_json_encode( $data );
    }

    $response = wp_remote_request( $url, $args );

    $duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );
    $req_for_log = wp_json_encode( self::filter_sensitive_data( $data ) );

    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        UPOS_Logger::error(
            sprintf(
                'API %s %s %s %dms, req: %s, res: %s',
                '---',
                $method,
                $endpoint,
                $duration_ms,
                $req_for_log,
                wp_json_encode( array( 'error' => $error_message ) )
            ),
            array( 'trace_id' => $trace_id )
        );
        throw new Exception( esc_html( $error_message ) );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body        = wp_remote_retrieve_body( $response );
    $result      = json_decode( $body, true );
    $res_for_log = wp_json_encode( self::filter_sensitive_data( $result ) );
    $log_level   = $status_code >= 400 ? 'error' : 'info';

    UPOS_Logger::$log_level(
        sprintf(
            'API %s %s %s %dms, req: %s, res: %s',
            $status_code,
            $method,
            $endpoint,
            $duration_ms,
            $req_for_log,
            $res_for_log
        ),
        array( 'trace_id' => $trace_id )
    );
    if ( $status_code >= 400 ) {
        $error_message = isset( $result['message'] )
            ? $result['message']
            : __( 'UPOS API request failed', 'upos-woocommerce' );
        // The detailed log is already created above, this exception is for the caller.
        throw new Exception( esc_html( $error_message ), (int) $status_code );
    }

    return $result;
  }

  /**
   * Get API URL (for debugging)
   *
   * @return string
   */
  public function get_API_HOST() {
    return self::API_HOST;
  }

  /**
   * Get public key (for frontend use)
   *
   * @return string
   */
  public function get_public_key() {
    return $this->public_key;
  }

  /**
   * Check API connectivity
   *
   * @return array
   */
  public function test_connection() {
    try {
      $this->get_supported_currencies();
      return array(
        'success' => true,
        'message' => __( 'Connection successful! Both keys are valid.', 'upos-woocommerce' ),
      );
    } catch ( Exception $e ) {
      UPOS_Logger::error( 'API connection test failed: ' . $e->getMessage() );
      return array(
        'success' => false,
        'message' => $e->getMessage(),
      );
    }
  }

  /**
   * Validate if keys are from the same environment
   *
   * @param string $public_key Public Key.
   * @param string $secret_key Secret Key.
   * @return bool True if valid, false otherwise.
   */
  public static function validate_keys_environment( $public_key, $secret_key ) {
    $is_pk_test = strpos( $public_key, 'pk_test_' ) === 0;
    $is_sk_test = strpos( $secret_key, 'sk_test_' ) === 0;

    $is_pk_live = strpos( $public_key, 'pk_live_' ) === 0;
    $is_sk_live = strpos( $secret_key, 'sk_live_' ) === 0;

    // Both test
    if ( $is_pk_test && $is_sk_test ) {
      return true;
    }

    // Both live
    if ( $is_pk_live && $is_sk_live ) {
      return true;
    }

    return false;
  }
}
