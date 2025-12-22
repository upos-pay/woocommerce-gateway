<?php
/**
 * UPOS Order Processor
 *
 * Handles the logic for processing individual order updates, coordinating
 * between API data, Order Meta, and the FSM state machine.
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Order_Processor class
 */
class UPOS_Order_Processor {

	/**
	 * Create a new payment intent for an order.
	 *
	 * @param WC_Order     $order    Order object.
	 * @param string       $currency Selected cryptocurrency (e.g. 'usdt').
	 * @param string       $network  Selected network (e.g. 'trc20').
	 * @param UPOS_Gateway $gateway  Gateway instance.
	 * @return array {
	 *     @type string $result   'success' or 'failure'.
	 *     @type string $redirect Redirect URL (if success).
	 *     @type string $message  Error message (if failure).
	 * }
	 */
	public static function create_payment( $order, $currency, $network, $gateway ) {
		try {
			if ( ! $order ) {
				throw new Exception( __( 'Order not found', 'upos-woocommerce' ) );
			}

			// Validate inputs
			if ( empty( $currency ) ) {
				throw new Exception( __( 'Please select a payment currency', 'upos-woocommerce' ) );
			}

			if ( empty( $network ) ) {
				throw new Exception( __( 'Please select a payment network', 'upos-woocommerce' ) );
			}

			// --- Exchange Rate Conversion ---
			// Only proceed with crypto payment if the selected currency is USDT (for now)
			if ( 'usdt' !== strtolower( $currency ) ) {
				throw new Exception( __( 'Currently only USDT is supported for crypto payment.', 'upos-woocommerce' ) );
			}

			$order_total_fiat    = (float) $order->get_total();
			$order_currency_fiat = $order->get_currency();

			// Get USDT exchange rate against the order's fiat currency
			$exchange_rate = UPOS_Exchange::get_usdt_rate( $order_currency_fiat );

			if ( false === $exchange_rate ) {
				UPOS_Logger::error( 'Failed to get USDT exchange rate for ' . $order_currency_fiat );
				throw new Exception( __( 'Unable to get exchange rate for payment. Please try again later.', 'upos-woocommerce' ) );
			}

			// Calculate converted USDT amount
			$converted_usdt_amount = bcdiv( (string) $order_total_fiat, (string) $exchange_rate, 6 );

			// Store fiat details and exchange rate in order meta
			UPOS_Order_Meta::set( $order, 'fiat_amount', $order_total_fiat );
			UPOS_Order_Meta::set( $order, 'fiat_currency', $order_currency_fiat );
			UPOS_Order_Meta::set( $order, 'exchange_rate', $exchange_rate );
			// --- End Exchange Rate Conversion ---

			// Build payment method object
			$payment_method_type = 'crypto_' . strtolower( $network );

			// Create payment intent via UPOS API
			$api = $gateway->get_api();
			$response = $api->create_payment_intent(
				array(
					'orderId'       => (string) $order->get_order_number(),
					'amount'        => (string) $converted_usdt_amount,
					'paymentMethod' => array(
						'type'     => $payment_method_type,
						'currency' => strtolower( $currency ),
					),
					'returnUrl'     => $gateway->get_return_url( $order ),
					'webhookUrl'    => UPOS_Webhook::get_webhook_url(),
				)
			);

			// Store payment intent data in order meta
			UPOS_Order_Meta::init_from_payment_intent( $order, $response );

			// Set payment method on order
			$order->set_payment_method( $gateway->id );
			$order->set_payment_method_title( $gateway->title );

			// Get payment intent ID from response
			$payment_intent_id = $response['intent']['id'] ?? '';

			// Add order note
			$order->add_order_note(
				sprintf(
					/* translators: 1: payment intent ID, 2: currency, 3: network */
					__( 'UPOS: Payment intent created (ID: %1$s), %2$s (%3$s), redirecting to UPOS payment page', 'upos-woocommerce' ),
					$payment_intent_id,
					strtoupper( $currency ),
					strtoupper( $network )
				)
			);

			// Process initial status
			$status = $response['intent']['status'] ?? UPOS_Constants::STATUS_CREATED;
			$initial_amount = $response['intent']['paymentAmount'] ?? $converted_usdt_amount;
			UPOS_Order_FSM::process_status_change( $order, $status, $initial_amount, 0, null );

			$order->save();

			// Empty cart
			WC()->cart->empty_cart();

			UPOS_Logger::debug( 'Payment intent created for order #' . $order->get_id() . ': ' . $payment_intent_id );

			// Get payment URL directly from response
			$payment_url = $response['paymentUrl'] ?? '';

			if ( empty( $payment_url ) ) {
				throw new Exception( __( 'Unable to retrieve UPOS payment URL', 'upos-woocommerce' ) );
			}

			return array(
				'result'   => 'success',
				'redirect' => $payment_url,
			);

		} catch ( Exception $e ) {
			UPOS_Logger::error( 'Payment creation error: ' . $e->getMessage() );
			return array(
				'result'  => 'failure',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Sync a single order by fetching data from API.
	 *
	 * @param int $order_id Order ID.
	 * @return array {
	 *     @type bool $success Whether the operation completed without error.
	 *     @type bool $updated Whether the order status was changed.
	 * }
	 */
	public static function sync_order( $order_id ) {
		$flow_id = sprintf( 'sync_ord_%d_%s', $order_id, substr( wp_generate_password( 6, false ), 0, 6 ) );
		UPOS_Logger::set_flow_id( $flow_id );

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return array( 'success' => false, 'updated' => false );
			}

			$payment_intent_id = UPOS_Order_Meta::get_payment_intent_id( $order_id );
			if ( empty( $payment_intent_id ) ) {
				return array( 'success' => false, 'updated' => false );
			}

			try {
				$gateway = self::get_gateway();
				if ( ! $gateway ) {
					UPOS_Logger::error( 'Failed to get UPOS gateway for sync' );
					return array( 'success' => false, 'updated' => false );
				}

				$api = $gateway->get_api();
				if ( ! $api ) {
					UPOS_Logger::error( 'Failed to get UPOS API for sync' );
					return array( 'success' => false, 'updated' => false );
				}

				$intent = $api->get_payment_intent( $payment_intent_id );

				return self::update_order_from_intent( $order, $intent );

			} catch ( Exception $e ) {
				UPOS_Logger::error(
					sprintf(
						'Sync failed for order #%d: %s',
						$order_id,
						$e->getMessage()
					)
				);
				return array( 'success' => false, 'updated' => false );
			}
		} finally {
			UPOS_Logger::clear_flow_id();
		}
	}

	/**
	 * Force sync an order (manual trigger)
	 *
	 * @param int $order_id Order ID.
	 * @return array Result with success status and message.
	 */
	public static function force_sync( $order_id ) {
		$result = self::sync_order( $order_id );

		if ( $result['success'] ) {
			return array(
				'success' => true,
				'message' => __( 'Order synced successfully', 'upos-woocommerce' ),
			);
		} else {
			return array(
				'success' => false,
				'message' => __( 'Order sync failed', 'upos-woocommerce' ),
			);
		}
	}

	/**
	 * Check expiration for a single order using local data.
	 *
	 * This method relies entirely on stored metadata (Raw Status, Payment Amount, etc.)
	 * to determine if the order should be expired.
	 *
	 * @param int $order_id Order ID.
	 * @return bool Success.
	 */
	public static function check_expiration( $order_id ) {
		$flow_id = sprintf( 'check_exp_%d_%s', $order_id, substr( wp_generate_password( 6, false ), 0, 6 ) );
		UPOS_Logger::set_flow_id( $flow_id );

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return false;
			}

			$raw_status = UPOS_Order_Meta::get_raw_status( $order ) ?? UPOS_Constants::STATUS_CREATED;;
			$payment_amount = UPOS_Order_Meta::get( $order, 'payment_amount' );
			$received_amount = UPOS_Order_Meta::get( $order, 'received_amount' );

			if ( '' === $received_amount || null === $received_amount ) {
				$received_amount = '0';
			}
			$expired_at = UPOS_Order_Meta::get_expired_at( $order );

			return UPOS_Order_FSM::process_status_change( $order, $raw_status, $payment_amount, $received_amount, $expired_at );

		} finally {
			UPOS_Logger::clear_flow_id();
		}
	}

	/**
	 * Update order status using fresh API data.
	 *
	 * @param WC_Order $order  WC Order object.
	 * @param array    $intent API data (Required).
	 * @return array {
	 *     @type bool $success Whether the operation completed without error.
	 *     @type bool $updated Whether the order status was changed.
	 * }
	 */
	public static function update_order_from_intent( $order, $intent ) {
		if ( empty( $intent ) ) {
			return array( 'success' => false, 'updated' => false );
		}

		// Validate required fields
		$required_fields = array( 'status', 'paymentAmount', 'receivedAmount' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $intent[ $field ] ) ) {
				throw new Exception( sprintf( __( 'Missing required field in payment intent: %s', 'upos-woocommerce' ), $field ) );
			}
		}

		// Sync Meta
		UPOS_Order_Meta::update_metas( $order, $intent );

		// Prepare Data for FSM
		$status          = $intent['status'];
		$payment_amount  = $intent['paymentAmount'];
		$received_amount = $intent['receivedAmount'];

		$expired_at = isset( $intent['expiredAt'] ) && $intent['expiredAt'] > 0 ? intval( $intent['expiredAt'] ) : null;

		$is_updated = UPOS_Order_FSM::process_status_change( $order, $status, $payment_amount, $received_amount, $expired_at );

		return array(
			'success' => true,
			'updated' => $is_updated,
		);
	}

	/**
	 * Get UPOS Payments instance
	 *
	 * @return UPOS_Gateway|null
	 */
	private static function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['upos'] ) ? $gateways['upos'] : null;
	}
}