<?php
/**
 * Uninstall routine.
 *
 * @package MYSITE_Mail_SMTP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'mysite_mail_smtp_options' );

global $wpdb;

// Remove any queued admin notices left behind by the plugin.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mysite_mail_smtp_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mysite_mail_smtp_notice_' ) . '%'
	)
);
