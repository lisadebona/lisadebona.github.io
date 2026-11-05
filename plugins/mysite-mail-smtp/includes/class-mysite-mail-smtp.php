<?php
/**
 * Core plugin class.
 *
 * @package MYSITE_Mail_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin controller.
 */
final class MYSITE_Mail_SMTP {

	/**
	 * Option name where all settings are stored.
	 */
	const OPTION_KEY = 'mysite_mail_smtp_options';

	/**
	 * Admin page slug.
	 */
	const SLUG = 'mysite-mail-smtp';

	/**
	 * Singleton instance.
	 *
	 * @var MYSITE_Mail_SMTP|null
	 */
	private static $instance = null;

	/**
	 * Cached options.
	 *
	 * @var array|null
	 */
	private $options = null;

	/**
	 * Gmail service.
	 *
	 * @var MYSITE_Gmail|null
	 */
	private $gmail = null;

	/**
	 * wp_mail processor.
	 *
	 * @var MYSITE_Processor|null
	 */
	private $processor = null;

	/**
	 * Admin screen controller.
	 *
	 * @var MYSITE_Admin|null
	 */
	private $admin = null;

	/**
	 * Whether the plugin has already been loaded.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return MYSITE_Mail_SMTP
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {

		add_action( 'plugins_loaded', array( $this, 'load' ), 5 );
	}

	/**
	 * Load the plugin.
	 *
	 * @return void
	 */
	public function load() {

		if ( $this->loaded ) {
			return;
		}

		$this->loaded = true;

		require_once MYSITE_MAIL_SMTP_DIR . 'includes/class-mysite-compat.php';
		require_once MYSITE_MAIL_SMTP_DIR . 'includes/class-mysite-gmail.php';
		require_once MYSITE_MAIL_SMTP_DIR . 'includes/class-mysite-processor.php';
		require_once MYSITE_MAIL_SMTP_DIR . 'includes/class-mysite-admin.php';

		new MYSITE_Compat( $this );

		$this->install_phpmailer();

		$this->gmail     = new MYSITE_Gmail( $this );
		$this->processor = new MYSITE_Processor( $this );

		if ( is_admin() ) {
			$this->admin = new MYSITE_Admin( $this );
		}
	}

	/**
	 * Replace the global PHPMailer instance so Gmail emails can be intercepted
	 * right before they would be handed to PHP's mail() function.
	 *
	 * @return void
	 */
	private function install_phpmailer() {

		if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		}

		if ( ! class_exists( 'PHPMailer\\PHPMailer\\Exception', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		if ( ! class_exists( 'PHPMailer\\PHPMailer\\SMTP', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		}

		require_once MYSITE_MAIL_SMTP_DIR . 'includes/class-mysite-phpmailer.php';

		// Use WordPress' own address validation, exactly like wp_mail() does.
		MYSITE_PHPMailer::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};

		global $phpmailer;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$phpmailer = new MYSITE_PHPMailer( true );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {

		return array(
			'mailer'           => 'php',
			'from_email'       => '',
			'from_name'        => '',
			'from_email_force' => false,
			'from_name_force'  => false,
			'gmail'            => array(
				'client_id'     => '',
				'client_secret' => '',
				'access_token'  => '',
				'refresh_token' => '',
				'token_expires' => 0,
				'user_email'    => '',
			),
			'microsoft'        => array(
				'host'       => 'smtp.office365.com',
				'port'       => 587,
				'encryption' => 'tls',
				'user'       => '',
				'pass'       => '',
			),
		);
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array
	 */
	public function get_options() {

		if ( null === $this->options ) {
			$this->refresh_options();
		}

		return $this->options;
	}

	/**
	 * Reload settings from the database.
	 *
	 * @return array
	 */
	public function refresh_options() {

		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$defaults = self::defaults();
		$options  = wp_parse_args( $saved, $defaults );

		$options['gmail'] = wp_parse_args(
			is_array( $options['gmail'] ) ? $options['gmail'] : array(),
			$defaults['gmail']
		);

		$options['microsoft'] = wp_parse_args(
			is_array( $options['microsoft'] ) ? $options['microsoft'] : array(),
			$defaults['microsoft']
		);

		$mailer = is_string( $options['mailer'] ) ? $options['mailer'] : 'php';

		$options['mailer']           = in_array( $mailer, array( 'php', 'gmail', 'microsoft365' ), true ) ? $mailer : 'php';
		$options['from_email_force'] = (bool) $options['from_email_force'];
		$options['from_name_force']  = (bool) $options['from_name_force'];

		$host = is_string( $options['microsoft']['host'] ) ? trim( $options['microsoft']['host'] ) : '';
		$host = preg_replace( '#^https?://#i', '', $host );
		$host = rtrim( (string) $host, '/' );

		$options['microsoft']['host'] = '' !== $host ? $host : $defaults['microsoft']['host'];

		$port = (int) $options['microsoft']['port'];

		$options['microsoft']['port']       = ( $port >= 1 && $port <= 65535 ) ? $port : $defaults['microsoft']['port'];
		$options['microsoft']['encryption'] = in_array( $options['microsoft']['encryption'], array( 'tls', 'ssl', 'none' ), true )
			? $options['microsoft']['encryption']
			: $defaults['microsoft']['encryption'];

		$this->options = $options;

		return $this->options;
	}

	/**
	 * Persist settings and refresh the in-memory cache.
	 *
	 * @param array $options Settings to save.
	 *
	 * @return void
	 */
	public function save_options( array $options ) {

		update_option( self::OPTION_KEY, $options, false );
		$this->options = $options;
		$this->refresh_options();
	}

	/**
	 * Get the Gmail settings group.
	 *
	 * @return array
	 */
	public function get_gmail_settings() {

		$options = $this->get_options();

		return $options['gmail'];
	}

	/**
	 * Merge values into the Gmail settings group.
	 *
	 * @param array $data Values to store.
	 *
	 * @return void
	 */
	public function set_gmail_data( array $data ) {

		$options          = $this->get_options();
		$options['gmail'] = array_merge( $options['gmail'], $data );

		$this->save_options( $options );
	}

	/**
	 * Remove all stored Gmail OAuth data, keeping the client credentials.
	 *
	 * @return void
	 */
	public function clear_gmail_connection() {

		$this->set_gmail_data(
			array(
				'access_token'  => '',
				'refresh_token' => '',
				'token_expires' => 0,
				'user_email'    => '',
			)
		);
	}

	/**
	 * Get the selected mailer slug.
	 *
	 * @return string Either "php" or "gmail".
	 */
	public function get_mailer() {

		$options = $this->get_options();

		return in_array( $options['mailer'], array( 'gmail', 'microsoft365' ), true ) ? $options['mailer'] : 'php';
	}

	/**
	 * Get the Gmail service.
	 *
	 * @return MYSITE_Gmail
	 */
	public function gmail() {

		return $this->gmail;
	}
}
