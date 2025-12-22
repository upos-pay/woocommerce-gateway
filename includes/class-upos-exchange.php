<?php
/**
 * UPOS Exchange Rate Handler
 *
 * Handles fetching and caching exchange rates for cryptocurrency conversions.
 * Uses CoinGecko API with a 5-minute transient cache.
 *
 * @package UPOS_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Exchange class
 */
class UPOS_Exchange {

  const CACHE_GROUP      = 'upos_exchange_rates';
  const CACHE_EXPIRATION = 5 * MINUTE_IN_SECONDS; // 5 minutes

  /**
   * Fetch USDT exchange rate against a given fiat currency.
   * Caches the result using WordPress Transients.
   *
   * @param string $fiat_currency The fiat currency code (e.g., 'twd', 'usd', 'eur').
   * @return float|false The exchange rate (1 USDT = X fiat_currency) or false on failure.
   */
  public static function get_usdt_rate( $fiat_currency ) {
    $fiat_currency = strtolower( $fiat_currency );

    $cache_key = self::CACHE_GROUP . '_usdt_' . $fiat_currency;
    $rate      = get_transient( $cache_key );

    if ( false === $rate ) {
      // Fetch from CoinGecko API
      $url = sprintf(
        'https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=%s',
        $fiat_currency
      );

      $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

      if ( is_wp_error( $response ) ) {
        UPOS_Logger::error( sprintf( 'CoinGecko API error for %s: %s', $fiat_currency, $response->get_error_message() ) );
        return false;
      }

      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );

      if ( ! isset( $data['tether'][ $fiat_currency ] ) ) {
        UPOS_Logger::error( sprintf( 'CoinGecko API: Invalid response or rate not found for tether/%s. Response: %s', $fiat_currency, $body ) );
        return false;
      }

      $new_rate = (float) $data['tether'][ $fiat_currency ];

      if ( $new_rate > 0 ) {
        set_transient( $cache_key, $new_rate, self::CACHE_EXPIRATION );
        return $new_rate;
      }

      return false;
    }

    return (float) $rate;
  }

  /**
   * Get the full list of supported vs_currencies from CoinGecko for reference.
   * This is a heavy call, should be cached longer if needed in UI.
   *
   * @return array|false
   */
  public static function get_supported_fiat_currencies() {
    $cache_key  = self::CACHE_GROUP . '_fiat_list';
    $currencies = get_transient( $cache_key );

    if ( false === $currencies ) {
      $response = wp_remote_get( 'https://api.coingecko.com/api/v3/simple/supported_vs_currencies', array( 'timeout' => 30 ) );
      if ( is_wp_error( $response ) ) {
        UPOS_Logger::error( 'CoinGecko API error fetching supported currencies: ' . $response->get_error_message() );
        return false;
      }
      $body       = wp_remote_retrieve_body( $response );
      $currencies = json_decode( $body, true );
      if ( is_array( $currencies ) ) {
        set_transient( $cache_key, $currencies, defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ); // Cache for a day
        return $currencies;
      }
      return false;
    }
    return $currencies;
  }

  /**
   * Check if a fiat currency is supported by CoinGecko.
   *
   * @param string $currency Currency code (e.g. 'TWD').
   * @return bool
   */
  public static function is_fiat_supported( $currency ) {
    $currency = strtolower( $currency );

    // Always support USD and USDT
    if ( 'usd' === $currency || 'usdt' === $currency ) {
      return true;
    }

    $supported = self::get_supported_fiat_currencies();

    // If we can't get the list (API error), default to true to avoid blocking checkout
    // The actual conversion in process_payment will handle the error if it fails then.
    if ( false === $supported ) {
      return true;
    }

    return in_array( $currency, $supported, true );
  }
}
