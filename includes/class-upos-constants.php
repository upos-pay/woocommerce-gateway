<?php
/**
 * UPOS Constants
 *
 * Centralized definition of all constants used throughout the plugin.
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Constants class
 */
class UPOS_Constants {

	/**
	 * UPOS Payment Intent Statuses
	 * (Matching official API constants)
	 */
	const STATUS_CREATED          = 'created';
	const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
	const STATUS_PAID_CONFIRMED   = 'paid_confirmed';
	const STATUS_SETTLED          = 'settled';
	const STATUS_EXPIRED          = 'expired';
	/**
	 * Internal logical statuses
	 */
	const STATUS_ABNORMAL = 'abnormal';

	/**
	 * WooCommerce Order Statuses
	 * (Standard slugs used by WC)
	 */
	const WC_PENDING    = 'pending';    // Order created, payment pending.
	const WC_ON_HOLD    = 'on-hold';    // Stock reduced, awaiting payment confirmation (or manual check).
	const WC_PROCESSING = 'processing'; // Payment received, order processing.
	const WC_COMPLETED  = 'completed';  // Order fulfilled and complete.
	const WC_FAILED     = 'failed';     // Payment failed or was declined.
	const WC_CANCELLED  = 'cancelled';  // Order cancelled by admin or customer.

}
