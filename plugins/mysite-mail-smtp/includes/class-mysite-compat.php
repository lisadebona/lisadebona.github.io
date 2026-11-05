<?php
/**
 * Compatibility with the Viking Arms theme mailer.
 *
 * @package MYSITE_Mail_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports the theme's existing mail settings and stops the theme mailer from
 * competing with this plugin for PHPMailer and the wp_mail filters.
 */
final class MYSITE_Compat {

	/**
	 * Main plugin instance.
	 *
	 * @var MYSITE_Mail_SMTP
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param MYSITE_Mail_SMTP $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {

		$this->plugin = $plugin;

		$this->maybe_migrate_theme_settings();

		if ( did_action( 'after_setup_theme' ) ) {
			$this->disable_theme_mailer();
		} else {
			add_action( 'after_setup_theme', array( $this, 'disable_theme_mailer' ), 99 );
		}
	}

	/**
	 * Import settings from the theme's Mailer Settings screen.
	 *
	 * Only runs when this plugin has never saved its own settings, so existing
	 * plugin values are never overwritten.
	 *
	 * @return void
	 */
	private function maybe_migrate_theme_settings() {

		if ( null !== get_option( MYSITE_Mail_SMTP::OPTION_KEY, null ) ) {
			return;
		}

		$theme = get_option( 'va_mail_settings' );

		if ( ! is_array( $theme ) ) {
			return;
		}

		$from_email = isset( $theme['from_email'] ) ? sanitize_email( $theme['from_email'] ) : '';
		$from_name  = isset( $theme['from_name'] ) ? sanitize_text_field( $theme['from_name'] ) : '';

		$options = array(
			'mailer'           => isset( $theme['transport'] ) && 'gmail' === $theme['transport'] ? 'gmail' : 'php',
			'from_email'       => $from_email,
			'from_name'        => $from_name,
			'from_email_force' => (bool) is_email( $from_email ),
			'from_name_force'  => '' !== $from_name,
			'gmail'            => array(
				'client_id'     => isset( $theme['client_id'] ) ? sanitize_text_field( $theme['client_id'] ) : '',
				'client_secret' => isset( $theme['client_secret'] ) ? sanitize_text_field( $theme['client_secret'] ) : '',
				'access_token'  => '',
				'refresh_token' => isset( $theme['refresh_token'] ) ? sanitize_text_field( $theme['refresh_token'] ) : '',
				'token_expires' => 0,
				'user_email'    => isset( $theme['user'] ) ? sanitize_email( $theme['user'] ) : '',
			),
		);

		update_option( MYSITE_Mail_SMTP::OPTION_KEY, $options, false );
	}

	/**
	 * Remove the theme's mail hooks.
	 *
	 * The theme registers these from inc/mailer.php. Leaving them in place
	 * would let the theme override the mailer selected in this plugin.
	 *
	 * @return void
	 */
	public function disable_theme_mailer() {

		if ( ! function_exists( 'va_mail_config' ) ) {
			return;
		}

		remove_filter( 'pre_wp_mail', 'va_mail_block_unready_gmail', 10 );
		remove_action( 'phpmailer_init', 'va_configure_phpmailer', 999 );
		remove_filter( 'wp_mail_from', 'va_mail_from_email', PHP_INT_MAX );
		remove_filter( 'wp_mail_from_name', 'va_mail_from_name', PHP_INT_MAX );
		remove_action( 'wp_mail_failed', 'va_mail_capture_failure' );

		// Hide the theme's now-inactive Mailer Settings screen so there is only
		// one place to configure outbound email.
		remove_action( 'admin_menu', 'va_register_mailer_settings_page' );
		remove_action( 'admin_init', 'va_register_mailer_settings' );

		// Also disable the theme's OAuth and test-email endpoints, so an old
		// bookmark cannot update settings the plugin will never read.
		remove_action( 'admin_post_va_mail_test', 'va_mail_send_test' );
		remove_action( 'admin_post_va_gmail_oauth_start', 'va_mail_gmail_oauth_start' );
		remove_action( 'admin_post_va_gmail_oauth_callback', 'va_mail_gmail_oauth_callback' );

		add_action( 'admin_init', array( $this, 'redirect_legacy_settings_page' ), 1 );
	}

	/**
	 * Send old Mailer Settings links to the plugin screen.
	 *
	 * @return void
	 */
	public function redirect_legacy_settings_page() {

		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'va-mailer-settings' !== $page ) {
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . MYSITE_Mail_SMTP::SLUG ) );
		exit;
	}
}
