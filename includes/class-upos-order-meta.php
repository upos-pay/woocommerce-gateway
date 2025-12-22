<?php
/**
 * UPOS Order Meta Handler
 *
 * Manages UPOS-specific order metadata using WooCommerce's order meta system.
 * Compatible with both legacy post meta and HPOS (High-Performance Order Storage).
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Order_Meta class
 *
 * Meta keys stored:
 * - _upos_payment_intent_id   : UPOS Payment Intent ID (pi_xxx)
 * - _upos_payment_token       : One-time token for payment page (pit_xxx)
 * - _upos_payment_method_type : Payment method type (e.g., 'crypto_tron')
 * - _upos_payment_currency    : Currency (e.g., 'usdt')
 * - _upos_payment_network     : Network (e.g., 'TRON')
 * - _upos_payment_address     : Wallet address for payment
 * - _upos_raw_status          : Raw status from UPOS API (created, paid_confirmed, etc.)
 * - _upos_logic_status        : UPOS logic status (Superset of API status + Abnormal/Expired)
 * - _upos_fiat_amount         : The original order total in fiat currency as set by the merchant (e.g., TWD, USD).
 * - _upos_fiat_currency       : The fiat currency for the original order total (e.g., 'TWD', 'USD').
 * - _upos_exchange_rate       : The exchange rate used for the fiat-to-USDT conversion (1 USDT = X of fiat_currency).
 * - _upos_order_amount        : The target order amount in USDT, converted from the fiat amount. This is the order_amount sent to UPOS to be collected.
 * - _upos_payment_amount      : Amount to be paid by buyer.
 * - _upos_net_amount          : Net amount settled to merchant.
 * - _upos_buyer_fee           : Fee paid by buyer.
 * - _upos_seller_fee          : Fee paid by merchant.
 * - _upos_received_amount     : The actual amount of cryptocurrency received from the customer.
 * - _upos_expired_at          : Payment expiration timestamp (epoch ms)
 * - _upos_paid_at             : Payment timestamp (epoch ms)
 * - _upos_settled_at          : Settlement timestamp (epoch ms)
 * - _upos_disbursed_at        : Disbursement timestamp (epoch ms)
 * - _upos_disbursed_amount    : Total disbursed amount
 * - _upos_checked_at          : Last sync check timestamp (epoch ms)
 */
class UPOS_Order_Meta {

  /**
   * Meta key prefix
   */
  const PREFIX = '_upos_';

  /**
   * All meta keys
   */
  const KEYS = array(
    'payment_intent_id',
    'payment_method_type',
    'payment_currency',
    'payment_network',
    'payment_address',
    'raw_status',
    'logic_status',
    'order_amount',
    'payment_amount',
    'net_amount',
    'buyer_fee',
    'seller_fee',
    'fiat_amount',
    'fiat_currency',
    'exchange_rate',
    'received_amount',
    'expired_at',
    'paid_at',
    'settled_at',
    'disbursed_at',
    'disbursed_amount',
    'checked_at',
    'payment_events'
  );

  /**
   * Get full meta key with prefix
   *
   * @param string $key Short key name.
   * @return string
   */
  public static function get_meta_key( $key ) {
    return self::PREFIX . $key;
  }

  /**
   * Get order instance
   *
   * @param int|WC_Order $order Order ID or object.
   * @return WC_Order|false
   */
  private static function get_order( $order ) {
    if ( is_numeric( $order ) ) {
      return wc_get_order( $order );
    }
    return $order instanceof WC_Order ? $order : false;
  }

  /**
   * Get a single meta value
   *
   * @param int|WC_Order $order Order ID or object.
   * @param string       $key   Meta key (without prefix).
   * @return mixed
   */
  public static function get( $order, $key ) {
    $order = self::get_order( $order );
    if ( ! $order ) {
      return null;
    }
    return $order->get_meta( self::get_meta_key( $key ), true );
  }

  /**
   * Set a single meta value
   *
   * @param int|WC_Order $order Order ID or object.
   * @param string       $key   Meta key (without prefix).
   * @param mixed        $value Meta value.
   * @return bool
   */
  public static function set( $order, $key, $value ) {
    $order = self::get_order( $order );
    if ( ! $order ) {
      return false;
    }
    $order->update_meta_data( self::get_meta_key( $key ), $value );
    $order->save();
    return true;
  }

  /**
   * Get all UPOS meta for an order
   *
   * @param int|WC_Order $order Order ID or object.
   * @return array
   */
  public static function get_all( $order ) {
    $order = self::get_order( $order );
    if ( ! $order ) {
      return array();
    }

    $meta = array();
    foreach ( self::KEYS as $key ) {
      $value = $order->get_meta( self::get_meta_key( $key ), true );
      if ( '' !== $value ) {
        $meta[ $key ] = $value;
      }
    }
    return $meta;
  }

  /**
   * Set multiple meta values at once
   *
   * @param int|WC_Order $order Order ID or object.
   * @param array        $data  Key-value pairs (without prefix).
   * @return bool
   */
  public static function mset( $order, $data ) {
    $order = self::get_order( $order );
    if ( ! $order ) {
      return false;
    }

    foreach ( $data as $key => $value ) {
      if ( in_array( $key, self::KEYS, true ) ) {
        $order->update_meta_data( self::get_meta_key( $key ), $value );
      }
    }
    $order->save();
    return true;
  }

  /**
   * Initialize UPOS meta from create payment intent response
   *
   * Response format:
   * {
   *   token: "pit_xxx",
   *   data: {
   *     id: "pi_xxx",
   *     orderId: "...",
   *     orderAmount: "100.00",
   *     paymentAmount: "102.00",
   *     paymentMethod: { type, currency, network, address } | null,
   *     status: "created",
   *     returnUrl: "...",
   *     createdAt: 1234567890
   *   }
   * }
   *
   * @param int|WC_Order $order    Order ID or object.
   * @param array        $response UPOS API response from create_payment_intent.
   * @return bool
   */
  public static function init_from_payment_intent( $order, $response ) {
    $order = self::get_order( $order );
    if ( ! $order ) {
      return false;
    }

    $intent = $response['intent'] ?? array();

    $payment_amount = $intent['paymentAmount'] ?? '';

    $data = array(
      'payment_intent_id'   => $intent['id'] ?? '',
      'raw_status'          => $intent['status'] ?? UPOS_Constants::STATUS_CREATED,
      'logic_status'        => $intent['status'] ?? UPOS_Constants::STATUS_CREATED,
      'order_amount'        => $intent['orderAmount'] ?? '',
      'payment_amount'      => $payment_amount,
      'net_amount'          => $intent['netAmount'] ?? '',
      'buyer_fee'           => $intent['buyerFee'] ?? '',
      'seller_fee'          => $intent['sellerFee'] ?? '',
      'expired_at'          => $intent['expiredAt'] ?? null,
      'payment_method_type' => $intent['paymentMethod']['type'] ?? '',
      'payment_currency'    => $intent['paymentMethod']['currency'] ?? '',
      'payment_network'     => $intent['paymentMethod']['network'] ?? '',
      'payment_address'     => $intent['paymentMethod']['address'] ?? '',

      // Initialize other fields to defaults
      'received_amount'     => '0',
      'paid_at'             => null,
      'settled_at'          => null,
      'disbursed_at'        => null,
      'disbursed_amount'    => '0',
      'checked_at'          => time() * 1000,
    );

    // Process initial events if present
    $raw_events = $intent['events'] ?? array();
    $data['payment_events'] = self::process_events_for_storage( $raw_events );

    return self::mset( $order, $data );
  }

  /**
   * Update order metas from payment intent data
   *
   * Response format:
   * {
   *   id, orderId, orderAmount, paymentAmount, paymentMethod, receivedAmount, status,
   *   returnUrl, events, statusHistory, disbursements,
   *   expiredAt, paidAt, settledAt, createdAt, updatedAt
   * }
   *
   * Disbursement format:
   * [{ amount, success, date, ... }]
   *
   * @param int|WC_Order $order  Order ID or object.
   * @param array        $intent UPOS API response from get_payment_intent.
   * @return array Changes made (old => new values).
   */
  public static function update_metas( $order, $intent ) {
    $order = self::get_order( $order );
    if ( ! $order ) {
      return array();
    }

    $changes = array();
    $updates = array();

    // 1. Status & Amounts - Always sync directly
    $new_status = $intent['status'] ?? null;
    $old_status = self::get( $order, 'raw_status' );

    if ( $old_status !== $new_status ) {
      $changes['raw_status'] = array(
        'old' => $old_status,
        'new' => $new_status,
      );
    }
    $updates['raw_status'] = $new_status;

    $new_received = $intent['receivedAmount'] ?? null;
    $old_received = self::get( $order, 'received_amount' );

    if ( $old_received !== $new_received ) {
      $changes['received_amount'] = array(
        'old' => $old_received,
        'new' => $new_received,
      );
    }
    $updates['received_amount'] = $new_received;

    // Also sync the target amount, just in case it changed (though unlikely)
    $updates['order_amount']   = $intent['orderAmount'] ?? null;
    $updates['payment_amount'] = $intent['paymentAmount'] ?? null;
    $updates['net_amount']     = $intent['netAmount'] ?? null;
    $updates['buyer_fee']      = $intent['buyerFee'] ?? null;
    $updates['seller_fee']     = $intent['sellerFee'] ?? null;

    // 2. Timestamps - Sync directly (null means not happened yet or cleared)
    $updates['expired_at'] = $intent['expiredAt'] ?? null;
    $updates['paid_at']    = $intent['paidAt'] ?? null;
    $updates['settled_at'] = $intent['settledAt'] ?? null;

    // 3. Disbursements - Always recalculate
    // If disbursements array is missing or empty, these will be 0/null, which is correct.
    $disbursements = $intent['disbursements'] ?? array();
    $disbursement_data = self::calculate_disbursement( $disbursements );

    $updates['disbursed_amount'] = $disbursement_data['amount']; // "0" if none
    $updates['disbursed_at']     = $disbursement_data['date'];   // null if none

    // 4. Payment Method - Sync deeply
    // If paymentMethod is null, all sub-fields should be cleared.
    $pm = $intent['paymentMethod'] ?? null;

    $updates['payment_method_type'] = $pm['type'] ?? '';
    $updates['payment_currency']    = $pm['currency'] ?? '';
    $updates['payment_network']     = $pm['network'] ?? '';
    $updates['payment_address']     = $pm['address'] ?? '';

    // 5. Events - Sync list
    $raw_events = $intent['events'] ?? array();
    $updates['payment_events'] = self::process_events_for_storage( $raw_events );

    // Update checked_at timestamp.
    $updates['checked_at'] = time() * 1000;

    self::mset( $order, $updates );

    return $changes;
  }

  /**
   * Process raw events for storage
   *
   * Sorts, limits, filters, and JSON encodes events.
   *
   * @param array $raw_events List of event objects from API.
   * @return string JSON encoded string.
   */
  private static function process_events_for_storage( $raw_events ) {
    if ( empty( $raw_events ) || ! is_array( $raw_events ) ) {
      return '[]';
    }

    // Sort by timestamp desc to keep latest
    usort( $raw_events, function($a, $b) {
        $t_a = $a['timestamp'] ?? $a['createdAt'] ?? 0;
        $t_b = $b['timestamp'] ?? $b['createdAt'] ?? 0;
        return $t_b - $t_a;
    });

    // Slice to max 50
    $raw_events = array_slice( $raw_events, 0, 50 );

    // Map to specified fields
    $optimized_events = array_map( function( $e ) {
      return array(
        'type'       => $e['type'] ?? '',
        'amount'     => $e['amount'] ?? '',
        'status'     => $e['status'] ?? '',
        'direction'  => $e['direction'] ?? '',
        'timestamp'  => $e['timestamp'] ?? $e['createdAt'] ?? 0,
        'externalId' => $e['externalId'] ?? '',
      );
    }, $raw_events );

    return wp_json_encode( $optimized_events );
  }


  /**
   * Calculate disbursement totals from disbursement records
   *
   * @param array $disbursements Array of disbursement records.
   * @return array { amount: float, date: int|null }
   */
  private static function calculate_disbursement( $disbursements ) {
    $total_amount = '0';
    $max_date     = null;

    foreach ( $disbursements as $d ) {
      // Only count successful disbursements.
      if ( ! empty( $d['success'] ) ) {
        $total_amount = bcadd( $total_amount, (string) ( $d['amount'] ?? 0 ), 6 );
        $date          = isset( $d['date'] ) ? intval( $d['date'] ) : null;
        if ( $date && ( null === $max_date || $date > $max_date ) ) {
          $max_date = $date;
        }
      }
    }

    return array(
      'amount' => $total_amount,
      'date'   => $max_date,
    );
  }

  /**
   * Check if order has UPOS payment
   *
   * @param int|WC_Order $order Order ID or object.
   * @return bool
   */
  public static function has_upos_payment( $order ) {
    $payment_intent_id = self::get( $order, 'payment_intent_id' );
    return ! empty( $payment_intent_id );
  }

  /**
   * Get payment intent ID
   *
   * @param int|WC_Order $order Order ID or object.
   * @return string|null
   */
  public static function get_payment_intent_id( $order ) {
    return self::get( $order, 'payment_intent_id' );
  }

  /**
   * Get raw UPOS status (from API)
   *
   * @param int|WC_Order $order Order ID or object.
   * @return string|null
   */
  public static function get_raw_status( $order ) {
    return self::get( $order, 'raw_status' );
  }

  /**
   * Get current UPOS logic status
   *
   * @param int|WC_Order $order Order ID or object.
   * @return string|null
   */
  public static function get_logic_status( $order ) {
    return self::get( $order, 'logic_status' );
  }

  /**
   * Get last checked timestamp
   *
   * @param int|WC_Order $order Order ID or object.
   * @return int|null Epoch timestamp in milliseconds.
   */
  public static function get_checked_at( $order ) {
    $val = self::get( $order, 'checked_at' );
    return $val ? intval( $val ) : null;
  }

  /**
   * Get expired_at timestamp
   *
   * @param int|WC_Order $order Order ID or object.
   * @return int|null Epoch timestamp in milliseconds.
   */
  public static function get_expired_at( $order ) {
    $value = self::get( $order, 'expired_at' );
    return $value ? intval( $value ) : null;
  }
}
