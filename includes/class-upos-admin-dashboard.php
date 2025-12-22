<?php
/**
 * UPOS Admin Dashboard
 *
 * Adds a dashboard widget to the main WordPress dashboard.
 *
 * @package UPOS_WooCommerce
 */
defined( 'ABSPATH' ) || exit;

/**
 * UPOS_Admin_Dashboard class
 */
class UPOS_Admin_Dashboard {
  /**
   * Initialize the dashboard widget.
   */
  public static function init() {
    add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
  }

  /**
   * Add the dashboard widget.
   */
  public static function add_dashboard_widget() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
      return;
    }

    wp_add_dashboard_widget(
      'upos_dashboard_status',
      __( 'UPOS Payments Stats', 'upos-woocommerce' ),
      array( __CLASS__, 'render_dashboard_widget' )
    );
  }

  /**
   * Render the dashboard widget content.
   */
  public static function render_dashboard_widget() {
    $settings   = get_option( 'woocommerce_upos_settings', array() );
    $public_key = isset( $settings['public_key'] ) ? $settings['public_key'] : '';
    $secret_key = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';

    if ( empty( $public_key ) || empty( $secret_key ) ) {
      echo '<p>' . esc_html__( 'Please configure UPOS API keys in WooCommerce Settings.', 'upos-woocommerce' ) . '</p>';
      echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=upos' ) ) . '" class="button button-primary">' . esc_html__( 'Go to Settings', 'upos-woocommerce' ) . '</a>';
      return;
    }

    try {
      $api = new UPOS_Api( $public_key, $secret_key );

      $timezone_string = wp_timezone_string();
      $params = array(
        'timezone'       => $timezone_string,
        'week-starts-on' => get_option( 'start_of_week', 0 ),
      );

      // Fetch data (Note: This is synchronous, ideally should be cached/transient)
      $stats = $api->get_disbursement_statistics( $params );

      self::render_stats_view( $stats );

    } catch ( Exception $e ) {

      $msg = sprintf( 'Unable to fetch statistics: %s (Code: %d)', $e->getMessage(), $e->getCode() );
      UPOS_Logger::error( $msg );
      echo '<div class="notice notice-error inline"><p>' . esc_html( $msg ) . '</p></div>';
      echo '<p style="text-align:right; margin-top: 15px;"><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=upos' ) ) . '">' . esc_html__( 'Configure', 'upos-woocommerce' ) . ' &rarr;</a></p>';
    }
  }

  /**
   * Render the statistics view.
   *
   * @param array $stats Statistics data from API.
   */
  private static function render_stats_view( $stats ) {
    if ( empty( $stats ) ) {
          echo '<p>' . esc_html__( 'No data available.', 'upos-woocommerce' ) . '</p>';
          return;
    }

    // Helper for amount formatting
    $fmt = function( $val ) {
        $num = isset( $val['usdt'] ) ? (float) $val['usdt'] : 0;
        return number_format_i18n( $num, 2 ) . ' USDT';
    };

    echo '<div class="upos-dashboard-stats main">';

    echo '
      <style>
        .upos-dashboard-stats { display: flex; flex-direction: column; gap: 15px; }
        .upos-stat-group { border: 1px solid #dcdcde; background: #fff; border-radius: 4px; overflow: hidden; }
        .upos-stat-group-header { background: #f6f7f7; padding: 10px 15px; border-bottom: 1px solid #dcdcde; font-weight: 600; color: #1d2327; display: flex; align-items: center; gap: 8px; }
        .upos-stat-group-body { display: flex; flex-direction: column; }
        .upos-stat-item { padding: 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f1; }
        .upos-stat-item:last-child { border-bottom: none; }
        .upos-stat-item h4 { margin: 0; font-size: 12px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px; }          .upos-stat-item .value { font-size: 18px; font-weight: 600; color: #1d2327; }
        .upos-stat-single .upos-stat-item { text-align: left; display: flex; justify-content: space-between; align-items: center; }
        .upos-icon { width: 20px; height: 20px; fill: #50575e; }
      </style>
    ';

    // Pending Disbursement
    $pending = $stats['pendingDisbursement'] ?? array();

    ?>
      <div class="upos-stat-group upos-stat-single">
        <div class="upos-stat-group-header">
          <span class="dashicons dashicons-clock"></span>
          <?php esc_html_e( 'Pending Disbursement', 'upos-woocommerce' ); ?>
        </div>

        <div class="upos-stat-item" style="border:none;">
          <span class="value"><?php echo esc_html( $fmt( $pending ) ); ?></span>
        </div>
      </div>
    <?php

    // Disbursed
    $disbursed = $stats['disbursed'] ?? array();

    ?>
      <div class="upos-stat-group">
        <div class="upos-stat-group-header">
          <span class="dashicons dashicons-money-alt"></span>
          <?php esc_html_e( 'Disbursed', 'upos-woocommerce' ); ?>
        </div>

        <div class="upos-stat-group-body">
          <!-- Yesterday -->
          <div class="upos-stat-item">
            <h4><?php esc_html_e( 'Yesterday', 'upos-woocommerce' ); ?></h4>
            <div class="value"><?php echo esc_html( $fmt( $disbursed['yesterday'] ?? [] ) ); ?></div>
          </div>

          <!-- This Week -->
          <div class="upos-stat-item">
            <h4><?php esc_html_e( 'This Week', 'upos-woocommerce' ); ?></h4>
            <div class="value"><?php echo esc_html( $fmt( $disbursed['thisWeek'] ?? [] ) ); ?></div>
          </div>

          <!-- All Time -->
          <div class="upos-stat-item">
            <h4><?php esc_html_e( 'All Time', 'upos-woocommerce' ); ?></h4>
            <div class="value"><?php echo esc_html( $fmt( $disbursed['all'] ?? [] ) ); ?></div>
          </div>
        </div>
      </div>
    <?php

    echo '</div>';

    echo '<p style="text-align:right; margin-top: 15px;"><a href="' . esc_url( admin_url( 'edit.php?post_type=shop_order' ) ) . '">' . esc_html__( 'View Orders', 'upos-woocommerce' ) . ' &rarr;</a></p>';
  }
}
