<?php
/**
 * UPOS Logger
 *
 * Handles logging for the UPOS plugin
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Logger class
 */
class UPOS_Logger {

  /**
   * Log source identifier
   *
   * @var string
   */
  private static $source = 'upos';

  /**
   * WooCommerce logger instance
   *
   * @var WC_Logger|null
   */
  private static $logger = null;

  /**
   * Whether logging is enabled
   *
   * @var bool
   */
  private static $enabled = null;

  /**
   * Flow ID for tracking a whole process
   *
   * @var string|null
   */
  private static $flow_id = null;

  /**
   * Set a flow ID for the duration of a process
   *
   * @param string $flow_id The unique ID for the flow.
   */
  public static function set_flow_id( $flow_id ) {
    self::$flow_id = $flow_id;
  }

  /**
   * Clear the flow ID
   */
  public static function clear_flow_id() {
    self::$flow_id = null;
  }

  /**
   * Get the logger instance
   *
   * @return WC_Logger
   */
  private static function get_logger() {
    if ( null === self::$logger ) {
      self::$logger = wc_get_logger();
    }
    return self::$logger;
  }

  /**
   * Check if logging is enabled
   *
   * @return bool
   */
  private static function is_enabled() {
    if ( null === self::$enabled ) {
      $settings      = get_option( 'woocommerce_upos_settings', array() );
      self::$enabled = isset( $settings['logging'] ) && 'yes' === $settings['logging'];
    }
    return self::$enabled;
  }

  /**
   * Generate log prefix from context
   *
   * @param string $trace_id Optional trace ID for a specific operation.
   * @return string
   */
  private static function get_log_prefix( $trace_id = '' ) {
    $prefix = '';
    if ( self::$flow_id && $trace_id ) {
      $prefix = sprintf( '[%s | %s] ', self::$flow_id, $trace_id );
    } elseif ( self::$flow_id ) {
      $prefix = sprintf( '[%s] ', self::$flow_id );
    } elseif ( $trace_id ) {
      $prefix = sprintf( '[%s] ', $trace_id );
    }
    return $prefix;
  }

  /**
   * Log a debug message
   *
   * @param string $message Message to log.
   * @param array  $context Additional context. May include 'trace_id'.
   */
  public static function debug( $message, $context = array() ) {
    if ( self::is_enabled() ) {
      $trace_id = $context['trace_id'] ?? '';
      unset( $context['trace_id'] );
      $prefix = self::get_log_prefix( $trace_id );
      self::get_logger()->debug( $prefix . $message, array_merge( array( 'source' => self::$source ), $context ) );
    }
  }

  /**
   * Log an info message
   *
   * @param string $message Message to log.
   * @param array  $context Additional context. May include 'trace_id'.
   */
  public static function info( $message, $context = array() ) {
    if ( self::is_enabled() ) {
      $trace_id = $context['trace_id'] ?? '';
      unset( $context['trace_id'] );
      $prefix = self::get_log_prefix( $trace_id );
      self::get_logger()->info( $prefix . $message, array_merge( array( 'source' => self::$source ), $context ) );
    }
  }

  /**
   * Log a warning message
   *
   * @param string $message Message to log.
   * @param array  $context Additional context. May include 'trace_id'.
   */
  public static function warning( $message, $context = array() ) {
    if ( self::is_enabled() ) {
      $trace_id = $context['trace_id'] ?? '';
      unset( $context['trace_id'] );
      $prefix = self::get_log_prefix( $trace_id );
      self::get_logger()->warning( $prefix . $message, array_merge( array( 'source' => self::$source ), $context ) );
    }
  }

  /**
   * Log an error message
   *
   * @param string $message Message to log.
   * @param array  $context Additional context. May include 'trace_id'.
   */
  public static function error( $message, $context = array() ) {
    if ( self::is_enabled() ) {
      $trace_id = $context['trace_id'] ?? '';
      unset( $context['trace_id'] );
      $prefix = self::get_log_prefix( $trace_id );
      self::get_logger()->error( $prefix . $message, array_merge( array( 'source' => self::$source ), $context ) );
    }
  }

  /**
   * Log API request
   *
   * @param string $endpoint API endpoint.
   * @param array  $data     Request data.
   * @param string $trace_id Trace ID for this request.
   */
  public static function log_request( $endpoint, $data = array(), $trace_id = '' ) {
    self::info(
      sprintf( 'API Request to %s', $endpoint ),
      array(
        'data'     => wp_json_encode( $data ),
        'trace_id' => $trace_id,
      )
    );
  }

  /**
   * Log API response
   *
   * @param string $endpoint    API endpoint.
   * @param mixed  $response    Response data.
   * @param int    $status_code HTTP status code.
   * @param string $trace_id    Trace ID for this request.
   */
  public static function log_response( $endpoint, $response, $status_code = 200, $trace_id = '' ) {
    self::info(
      sprintf( 'API Response from %s (HTTP %d)', $endpoint, $status_code ),
      array(
        'response' => wp_json_encode( $response ),
        'trace_id' => $trace_id,
      )
    );
  }
}
