<?php
/**
 * UPOS Order Status FSM (Simplified)
 *
 * Maps UPOS payment intent statuses to WooCommerce order statuses based on
 * business logic:
 * - Abnormal amounts (over/under paid) -> On Hold (Manual resolution)
 * - Expired -> Failed
 * - Paid/Settled -> Processing (Safe to ship)
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Order_FSM class
 */
class UPOS_Order_FSM {

	/**
	 * Determine the target WooCommerce status based on UPOS data.
	 *
	 * Logic Priorities:
	 * 1. Abnormal Amount (Over/Under) -> On Hold
	 * 2. Expired (Time passed) -> Failed
	 * 3. Standard Mapping
	 *
	 * @param string   $raw_status      The status from UPOS API (created, awaiting_payment, etc).
	 * @param float    $payment_amount  Target amount (Payment Amount).
	 * @param float    $received_amount Actual received amount.
	 * @param int|null $expired_at      Expiration timestamp (ms). Null if no expiration.
	 * @return array {
	 *     'wc_status'    => string,
	 *     'logic_status' => string (internal status like abnormal/expired),
	 *     'note'         => string (reason)
	 * }
	 */
	public static function determine_state( $raw_status, $payment_amount, $received_amount, $expired_at ) {
		$payment_amount  = (string) $payment_amount;
		$received_amount = (string) $received_amount;
		$now_ms          = time() * 1000;

		// Check for Abnormal Payment (Partial or Excessive)
		// Only relevant if we have received *some* money but it doesn't match,
		// OR if status is paid/settled but amount doesn't match.
		if ( bccomp( $received_amount, '0', 8 ) > 0 && bccomp( $payment_amount, $received_amount, 8 ) !== 0 ) {
			// If status is technically paid/settled but amount is wrong, it's abnormal.
			// Or if awaiting payment but partial amount received.
			return array(
				'wc_status'    => UPOS_Constants::WC_ON_HOLD,
				'logic_status' => UPOS_Constants::STATUS_ABNORMAL,
				'note'         => sprintf(
					/* translators: 1: Received amount, 2: Expected amount */
					__( 'UPOS: Abnormal payment detected. Received: %1$s, Expected: %2$s. Manual resolution required.', 'upos-woocommerce' ),
					$received_amount,
					$payment_amount
				),
			);
		}

		// Check for Expiration
		// Only if not paid yet.
		if ( ! self::is_paid( $raw_status ) && null !== $expired_at && $now_ms > $expired_at ) {
			return array(
				'wc_status'    => UPOS_Constants::WC_FAILED,
				'logic_status' => UPOS_Constants::STATUS_EXPIRED,
				'note'         => __( 'UPOS: Payment expired.', 'upos-woocommerce' ),
			);
		}

		// Standard Mapping
		switch ( $raw_status ) {
			case UPOS_Constants::STATUS_CREATED:
				return array(
					'wc_status'    => UPOS_Constants::WC_PENDING,
					'logic_status' => UPOS_Constants::STATUS_CREATED,
					'note'         => __( 'UPOS: Payment intent created.', 'upos-woocommerce' ),
				);

			case UPOS_Constants::STATUS_AWAITING_PAYMENT:
				return array(
					'wc_status'    => UPOS_Constants::WC_ON_HOLD,
					'logic_status' => UPOS_Constants::STATUS_AWAITING_PAYMENT,
					'note'         => __( 'UPOS: Awaiting payment.', 'upos-woocommerce' ),
				);

			case UPOS_Constants::STATUS_PAID_CONFIRMED:
				return array(
					'wc_status'    => UPOS_Constants::WC_PROCESSING,
					'logic_status' => UPOS_Constants::STATUS_PAID_CONFIRMED,
					'note'         => __( 'UPOS: Payment confirmed.', 'upos-woocommerce' ),
				);

			case UPOS_Constants::STATUS_SETTLED:
				return array(
					'wc_status'    => UPOS_Constants::WC_PROCESSING, // Stay processing, don't auto-complete.
					'logic_status' => UPOS_Constants::STATUS_SETTLED,
					'note'         => __( 'UPOS: Funds settled (disbursed).', 'upos-woocommerce' ),
				);

			case UPOS_Constants::STATUS_ABNORMAL:
				return array(
					'wc_status'    => UPOS_Constants::WC_ON_HOLD,
					'logic_status' => UPOS_Constants::STATUS_ABNORMAL,
					'note'         => __( 'UPOS: Payment marked as abnormal.', 'upos-woocommerce' ),
				);

			case UPOS_Constants::STATUS_EXPIRED:
				return array(
					'wc_status'    => UPOS_Constants::WC_FAILED,
					'logic_status' => UPOS_Constants::STATUS_EXPIRED,
					'note'         => __( 'UPOS: Payment expired.', 'upos-woocommerce' ),
				);

			default:
				return array(
					'wc_status'    => UPOS_Constants::WC_ON_HOLD,
					'logic_status' => $raw_status,
					'note'         => sprintf( __( 'UPOS: Unknown status %s.', 'upos-woocommerce' ), $raw_status ),
				);
		}
	}


	/**
	 * Process status change on order
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $raw_status     UPOS payment status.
	 * @param float    $payment_amount Target amount (Payment Amount).
	 * @param float    $received_amt   Received amount.
	 * @param int|null $expired_at     Expiration time (ms). Null if no expiration.
	 * @return bool Whether WC status was updated.
	 */
	public static function process_status_change( $order, $raw_status, $payment_amount, $received_amt, $expired_at ) {
		$state = self::determine_state( $raw_status, $payment_amount, $received_amt, $expired_at );

		// Special Note for Late Payment Recovery
		// If it's paid, but time is past expiration, it's a late payment.
		if ( self::is_paid( $raw_status ) && null !== $expired_at && ( time() * 1000 ) > $expired_at ) {
			$state['note'] = __( 'UPOS: Late payment received and confirmed. Order recovered from expiration.', 'upos-woocommerce' );
		}

		$current_logic_status = UPOS_Order_Meta::get_logic_status( $order );
		$current_wc_status    = $order->get_status();
		$is_logic_status_changed    = ( $current_logic_status !== $state['logic_status'] );

		// Always update internal UPOS status meta to the new logic status.
		if ( $is_logic_status_changed ) {
			UPOS_Order_Meta::set( $order, 'logic_status', $state['logic_status'] );
			$order->add_order_note( $state['note'] );
		}

		// Check safeguards for WC Status Transitions.

		// Guard: Do not revert 'completed' orders to 'processing' or 'on-hold'.
		if ( $current_wc_status === 'completed' && $state['wc_status'] === 'processing' ) {
			return false;
		}

		// Guard: If status matches, don't spam updates.
		if ( $current_wc_status === $state['wc_status'] ) {
			return false;
		}

		// Execute Change.
		$order->update_status( $state['wc_status'] );

		do_action( 'upos_order_status_changed', $order, $current_logic_status, $state['logic_status'] );

		return true;
	}

	/**
	 * Check if UPOS status indicates payment received (Paid or Settled)
	 *
	 * @param string $status UPOS payment status.
	 * @return bool
	 */
	public static function is_paid( $status ) {
		return in_array( $status, array( UPOS_Constants::STATUS_PAID_CONFIRMED, UPOS_Constants::STATUS_SETTLED ), true );
	}

	/**
	 * Get human-readable status label
	 *
	 * @param string $status UPOS payment status.
	 * @return string
	 */
	public static function get_logic_status_label( $status ) {
		$labels = array(
				UPOS_Constants::STATUS_CREATED          => __( 'Created', 'upos-woocommerce' ),
				UPOS_Constants::STATUS_AWAITING_PAYMENT => __( 'Awaiting Payment', 'upos-woocommerce' ),
				UPOS_Constants::STATUS_PAID_CONFIRMED   => __( 'Paid Confirmed', 'upos-woocommerce' ),
				UPOS_Constants::STATUS_SETTLED          => __( 'Settled', 'upos-woocommerce' ),
				UPOS_Constants::STATUS_EXPIRED          => __( 'Expired', 'upos-woocommerce' ),
				UPOS_Constants::STATUS_ABNORMAL         => __( 'Abnormal', 'upos-woocommerce' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Get the calculated logical status for an order.
	 *
	 * This method fetches current meta and uses the FSM to determine the
	 * real-time status (accounting for expiration).
	 *
	 * @param WC_Order $order Order object.
	 * @return string Logic status (e.g. 'created', 'paid', 'expired').
	 */
	public static function get_calculated_status( $order ) {
		$raw_status      = UPOS_Order_Meta::get_raw_status( $order );
		$payment_amount  = UPOS_Order_Meta::get( $order, 'payment_amount' );
		$received_amount = UPOS_Order_Meta::get( $order, 'received_amount' );
		$expired_at      = UPOS_Order_Meta::get_expired_at( $order );

		$state = self::determine_state( $raw_status, $payment_amount, $received_amount, $expired_at );

		return $state['logic_status'] ?? UPOS_Constants::STATUS_CREATED;
	}

	/**
	 * Get user-friendly status message based on status string
	 *
	 * @param string $status UPOS logic status.
	 * @return string|null Message or null if no specific message.
	 */
	public static function get_status_message( $status ) {
		switch ( $status ) {
			case UPOS_Constants::STATUS_CREATED:
				return __( 'Order created. Please setup a payment method to proceed.', 'upos-woocommerce' );

			case UPOS_Constants::STATUS_AWAITING_PAYMENT:
				return __( 'Awaiting cryptocurrency payment confirmation. Order status will update automatically after payment confirmation. This may take a few minutes.', 'upos-woocommerce' );

			case UPOS_Constants::STATUS_PAID_CONFIRMED:
			case UPOS_Constants::STATUS_SETTLED:
				return __( 'Thank you for your payment! Your order is being processed.', 'upos-woocommerce' );

			case UPOS_Constants::STATUS_EXPIRED:
				return __( 'The payment period for this order has expired. If you have already made a payment, please be patient as blockchain synchronization may take some time. If the payment is not confirmed after several hours, please contact customer support for assistance.', 'upos-woocommerce' );

			case UPOS_Constants::STATUS_ABNORMAL:
				return __( 'An issue was detected with your payment (e.g., amount mismatch). Please contact support for assistance.', 'upos-woocommerce' );
		}

		return null;
	}
}
