<?php
/**
 * Removes the plugin's options, transients and cron event.
 *
 * The `iftp_ld_transactions` table and LearnDash's own orders stay: they are
 * the site's payment history.
 *
 * @package Ifthenpay\LearnDash
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans one site.
 *
 * @return void
 */
function ifthenpay_payments_for_learndash_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'iftp_ld_settings', 'iftp_ld_account', 'iftp_ld_backoffice_key', 'iftp_ld_callback', 'iftp_ld_payment_email' ) as $option ) {
		delete_option( $option );
	}

	delete_transient( 'iftp_ld_methods_catalog' );
	delete_transient( 'iftp_ld_callback_retry' );
	delete_transient( 'iftp_ld_callback_failed' );
	wp_clear_scheduled_hook( 'iftp_ld_daily' );

	// Activation cooldowns and leftover locks carry hashed names.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of our own rows on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_iftp_ld_activation_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_iftp_ld_activation_' ) . '%',
			$wpdb->esc_like( 'iftp_ld_lock_' ) . '%'
		)
	);
}

/**
 * Cleans every site on multisite.
 *
 * @return void
 */
function ifthenpay_payments_for_learndash_uninstall(): void {
	if ( ! is_multisite() ) {
		ifthenpay_payments_for_learndash_uninstall_site();
		return;
	}

	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		ifthenpay_payments_for_learndash_uninstall_site();
		restore_current_blog();
	}
}

ifthenpay_payments_for_learndash_uninstall();
