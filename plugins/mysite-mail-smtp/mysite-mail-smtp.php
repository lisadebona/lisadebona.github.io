<?php
/**
 * Plugin Name: MySite Mail SMTP
 * Version: 1.1.0
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Description: Send WordPress emails through PHP Mail, the Gmail API, or Microsoft 365 SMTP. A lightweight SMTP setup with only the mailers you need.
 * Author: L.D. Webmaster
 * License: GNU General Public License v3.0 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mysite-mail-smtp
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 */
define( 'MYSITE_MAIL_SMTP_VERSION', '1.1.0' );

/**
 * Plugin main file path.
 */
define( 'MYSITE_MAIL_SMTP_FILE', __FILE__ );

/**
 * Plugin directory path, with a trailing slash.
 */
define( 'MYSITE_MAIL_SMTP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL, with a trailing slash.
 */
define( 'MYSITE_MAIL_SMTP_URL', plugin_dir_url( __FILE__ ) );

require_once MYSITE_MAIL_SMTP_DIR . 'includes/class-mysite-mail-smtp.php';

MYSITE_Mail_SMTP::instance();
