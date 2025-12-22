<?php
/**
 * UPOS Order Status Sync
 *
 * Handles periodic synchronization of order payment statuses with UPOS API.
 * Uses WP-Cron for scheduling with dynamic intervals based on order age.
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Sync class
 *
 * Two Jobs (matching backend):
 * 1. SyncOrderStatus - Syncs payment status from UPOS API
 * 2. ExpireOrders - Marks orders as expired when past expiration time
 *
 * Check Intervals (based on order age, matching SyncOrderStatus.ts):
 * - < 7 minutes:     30 seconds
 * - < 1 hour:        10 minutes
 * - < 3 hours:       30 minutes
 * - < 6 hours:       1 hour
 * - < 24 hours:      6 hours
 * - < 3 days:        12 hours
 * - >= 3 days:       24 hours
 *
 * Max Order Age: 7 days
 */
class UPOS_Sync {

  /**
   * Cron hook names
   */
  const CRON_HOOK_SYNC   = 'upos_sync_orders';
  const CRON_HOOK_EXPIRE = 'upos_expire_orders';

  /**
   * Option keys for last run time
   */
  const OPTION_LAST_RUN_SYNC          = 'upos_last_run_sync';
  const OPTION_LAST_RUN_EXPIRE        = 'upos_last_run_expire';
  const OPTION_LAST_MANUAL_RUN_SYNC   = 'upos_last_manual_run_sync';
  const OPTION_LAST_MANUAL_RUN_EXPIRE = 'upos_last_manual_run_expire';

  /**
   * Max order age in seconds (7 days)
   */
  const MAX_ORDER_AGE = 7 * 86400;

  /**
   * Initialize sync scheduler
   */
  public static function init() {
    // Register action hooks
    add_action( self::CRON_HOOK_SYNC, array( __CLASS__, 'run_sync' ) );
    add_action( self::CRON_HOOK_EXPIRE, array( __CLASS__, 'run_expire' ) );

    // Check if Action Scheduler is available
    if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
      UPOS_Logger::error( 'Action Scheduler not available. Sync jobs not scheduled.' );
      return;
    }

    // Schedule sync job if not already scheduled
    if ( ! as_next_scheduled_action( self::CRON_HOOK_SYNC ) ) {
      as_schedule_recurring_action( time(), 30, self::CRON_HOOK_SYNC );
      UPOS_Logger::info( sprintf( "'%s' every 30 secs action scheduled", self::CRON_HOOK_SYNC ) );
    }

    // Schedule expire job if not already scheduled
    if ( ! as_next_scheduled_action( self::CRON_HOOK_EXPIRE ) ) {
      as_schedule_recurring_action( time(), 60, self::CRON_HOOK_EXPIRE );
      UPOS_Logger::info( sprintf( "'%s' every 60 secs action scheduled", self::CRON_HOOK_EXPIRE ) );
    }
  }

  /**
   * Unschedule cron jobs (on deactivation)
   */
  public static function unschedule() {
    if ( function_exists( 'as_unschedule_action' ) ) {
      as_unschedule_action( self::CRON_HOOK_SYNC );
      as_unschedule_action( self::CRON_HOOK_EXPIRE );
    }
  }

  /**
   * Run sync for all pending orders
   *
   * @param bool $manual Whether this was triggered manually.
   */
  public static function run_sync( $manual = false ) {
    $now = time();
    update_option( self::OPTION_LAST_RUN_SYNC, $now );
    if ( $manual ) {
      update_option( self::OPTION_LAST_MANUAL_RUN_SYNC, $now );
    }

    $limit  = 50; // Process max 50 orders per cron run.
    $orders = self::get_orders_to_sync( $limit );

    if ( empty( $orders ) ) {
      return;
    }

    UPOS_Logger::info( sprintf( 'Starting sync for %d orders', count( $orders ) ) );

    foreach ( $orders as $order_id ) {
      UPOS_Order_Processor::sync_order( $order_id );
    }
  }

  /**
   * Get orders that need syncing
   *
   * Get orders that need syncing. Excludes 'created' status to focus on active or settled payments.
   *
   * @param int $limit Max number of orders to retrieve.
   * @return array Order IDs.
   */
  private static function get_orders_to_sync( $limit = 50 ) {
    $now = time();

    // Query orders with UPOS payment.
    $args = array(
      'limit'          => $limit,
      // 'return' => 'ids', // Optimization: Fetch objects directly to avoid re-querying in loop.
      'payment_method' => 'upos', // Only UPOS payment orders.
      'meta_query'     => array(
        'relation' => 'AND',
        array(
          'key'     => UPOS_Order_Meta::get_meta_key( 'payment_intent_id' ),
          'compare' => 'EXISTS',
        ),
        array(
          'key'     => UPOS_Order_Meta::get_meta_key( 'logic_status' ),
          'value'   => UPOS_Constants::STATUS_CREATED,
          'compare' => '!=',
        ),
      ),
      'status'         => array(
        UPOS_Constants::WC_PENDING,
        UPOS_Constants::WC_ON_HOLD,
        UPOS_Constants::WC_PROCESSING,
        UPOS_Constants::WC_COMPLETED,
        UPOS_Constants::WC_FAILED,
      ),
      'date_created'   => '>' . ( $now - self::MAX_ORDER_AGE ), // Only orders from last 7 days.
    );

    $orders_to_check = wc_get_orders( $args );
    $orders_to_sync  = array();

    foreach ( $orders_to_check as $order ) {
      if ( self::should_sync_now( $order ) ) {
        $orders_to_sync[] = $order->get_id();
      }
    }

    return $orders_to_sync;
  }

  /**
   * Determine if order should be synced now based on check interval
   *
   * @param WC_Order $order Order object.
   * @return bool
   */
  private static function should_sync_now( $order ) {
    if ( ! $order ) {
      return false;
    }

    $checked_at = UPOS_Order_Meta::get_checked_at( $order );
    $created_at = $order->get_date_created();

    if ( ! $created_at ) {
      return true;
    }

    $now            = time();
    $order_age      = $now - $created_at->getTimestamp();
    $check_interval = self::get_check_interval( $order_age );

    // Never checked yet
    if ( empty( $checked_at ) ) {
      return true;
    }

    // Calculate time since last check (all in ms)
    $now_ms     = $now * 1000;
    $time_since = $now_ms - $checked_at;

    return $time_since >= ( $check_interval * 1000 );
  }

  /**
   * Get check interval based on order age
   *
   * Dynamic intervals (matching SyncOrderStatus.ts):
   * - < 7 min:    30 seconds
   * - < 1 hour:   10 minutes
   * - < 3 hours:  30 minutes
   * - < 6 hours:  1 hour
   * - < 24 hours: 6 hours
   * - < 3 days:   12 hours
   * - >= 3 days:  24 hours
   *
   * @param int $order_age Order age in seconds.
   * @return int Check interval in seconds.
   */
  private static function get_check_interval( $order_age ) {
    $minute_in_seconds = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
    $hour_in_seconds   = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
    $day_in_seconds    = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;

    if ( $order_age < 7 * $minute_in_seconds ) {
      return 30; // 30 seconds.
    } elseif ( $order_age < $hour_in_seconds ) {
      return 10 * $minute_in_seconds; // 10 minutes.
    } elseif ( $order_age < 3 * $hour_in_seconds ) {
      return 30 * $minute_in_seconds; // 30 minutes.
    } elseif ( $order_age < 6 * $hour_in_seconds ) {
      return $hour_in_seconds; // 1 hour.
    } elseif ( $order_age < $day_in_seconds ) {
      return 6 * $hour_in_seconds; // 6 hours.
    } elseif ( $order_age < 3 * $day_in_seconds ) {
      return 12 * $hour_in_seconds; // 12 hours.
    }
    return $day_in_seconds; // 24 hours.
  }

  /**
   * Run expire job for orders past expiration time
   * (matching ExpireOrders.ts)
   *
   * @param bool $manual Whether this was triggered manually.
   */
  public static function run_expire( $manual = false ) {
    $now = time();
    update_option( self::OPTION_LAST_RUN_EXPIRE, $now );
    if ( $manual ) {
      update_option( self::OPTION_LAST_MANUAL_RUN_EXPIRE, $now );
    }

    $limit  = 50;
    $orders = self::get_orders_to_expire( $limit );

    if ( empty( $orders ) ) {
      return;
    }

    $succeeded = 0;
    $failed    = 0;

    foreach ( $orders as $order_id ) {
      $result = UPOS_Order_Processor::check_expiration( $order_id );
      if ( $result ) {
        ++$succeeded;
      } else {
        ++$failed;
      }
    }

    if ( $succeeded > 0 || $failed > 0 ) {
      UPOS_Logger::info(
        sprintf( 'ExpireOrders: %d expired, %d failed', $succeeded, $failed )
      );
    }
  }

  /**
   * Get orders that have passed their expiration time
   *
   * Only orders in created or awaiting_payment status can expire.
   *
   * @param int $limit Max number of orders to retrieve.
   * @return array Order IDs.
   */
  private static function get_orders_to_expire( $limit = 50 ) {
    $now = time() * 1000; // Convert to ms for comparison with expired_at.

    // Query UPOS orders that are created/awaiting_payment with expired_at in the past.
    $args = array(
      'limit'          => $limit,
      'return'         => 'ids',
      'payment_method' => 'upos', // Only UPOS payment orders.
      'meta_query'     => array(
        'relation' => 'AND',
        array(
          'key'     => UPOS_Order_Meta::get_meta_key( 'payment_intent_id' ),
          'compare' => 'EXISTS',
        ),
        array(
          'key'     => UPOS_Order_Meta::get_meta_key( 'logic_status' ),
          'value'   => array(
            UPOS_Constants::STATUS_CREATED,
            UPOS_Constants::STATUS_AWAITING_PAYMENT,
          ),
          'compare' => 'IN',
        ),
        array(
          'key'     => UPOS_Order_Meta::get_meta_key( 'expired_at' ),
          'value'   => $now,
          'compare' => '<',
          'type'    => 'NUMERIC',
        ),
      ),
      'status'         => array(
        UPOS_Constants::WC_PENDING,
        UPOS_Constants::WC_ON_HOLD,
      ),
    );

    return wc_get_orders( $args );
  }
}
