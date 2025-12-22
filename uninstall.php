<?php
/**
 * UPOS WooCommerce Uninstall
 *
 * Uninstalling UPOS deletes user roles, pages, tables, and options.
 *
 * @package UPOS_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options
delete_option( 'upos_version' );
delete_option( 'woocommerce_upos_settings' );



// Clean up transients
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_upos_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_upos_%'" );
