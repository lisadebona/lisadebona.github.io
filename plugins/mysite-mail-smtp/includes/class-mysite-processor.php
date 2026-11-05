<?php
/**
 * Hooks into wp_mail() and PHPMailer.
 *
 * @package MYSITE_Mail_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the selected mailer and From Email settings to outgoing email.
 */
final class MYSITE_Processor {

	/**
	 * Main plugin instance.
	 *
	 * @var MYSITE_Mail_SMTP
	 */
	private $plugin;

	/**
	 * The From address passed to the wp_mail_from filter before we changed it.
	 *
	 * @var string
	 */
	private $original_from_email = '';

	/**
	 * Constructor.
	 *
	 * @param MYSITE_Mail_SMTP $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {

		$this->plugin = $plugin;

		add_action( 'phpmailer_init', array( $this, 'phpmailer_init' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
	}

	/**
	 * Configure PHPMailer for the selected mailer.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 *
	 * @return void
	 */
	public function phpmailer_init( $phpmailer ) {

		if ( ! is_object( $phpmailer ) ) {
			return;
		}

		$options = $this->plugin->get_options();

		// When the From Email is forced, keep the address the site originally
		// wanted to use as the Reply-To address.
		if (
			! empty( $options['from_email_force'] ) &&
			! empty( $this->original_from_email ) &&
			$this->original_from_email !== $phpmailer->From &&
			method_exists( $phpmailer, 'getReplyToAddresses' ) &&
			method_exists( $phpmailer, 'addReplyTo' ) &&
			empty( $phpmailer->getReplyToAddresses() )
		) {
			$phpmailer->addReplyTo( $this->original_from_email );
		}

		$mailer = $this->plugin->get_mailer();

		if ( 'microsoft365' === $mailer ) {
			$this->configure_microsoft365( $phpmailer, $options );

			return;
		}

		if ( 'gmail' !== $mailer ) {
			// Hand the message to PHP's mail() function.
			$phpmailer->Mailer = 'mail';

			return;
		}

		// MYSITE_PHPMailer::mailSend() recognises this value and sends the
		// message through the Gmail API instead of PHP mail().
		$phpmailer->Mailer = 'mysite_gmail';

		$account_email = $this->plugin->gmail()->get_connected_email();

		if ( ! $account_email ) {
			// Leave the message alone. mailSend() will return a clear error if
			// the message cannot be sent.
			return;
		}

		$this->apply_mailbox_from(
			$phpmailer,
			$account_email,
			is_email( $options['from_email'] ) ? $options['from_email'] : ''
		);
	}

	/**
	 * Configure the Microsoft 365 SMTP transport.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @param array                          $options   Plugin settings.
	 *
	 * @return void
	 */
	private function configure_microsoft365( $phpmailer, $options ) {

		$settings = $options['microsoft'];

		$phpmailer->isSMTP();
		$phpmailer->Host        = $settings['host'];
		$phpmailer->Port        = (int) $settings['port'];
		$phpmailer->Timeout     = 30;
		$phpmailer->SMTPAuth    = true;
		$phpmailer->Username    = $settings['user'];
		$phpmailer->Password    = $settings['pass'];
		$phpmailer->SMTPAutoTLS = 'none' !== $settings['encryption'];

		if ( 'none' === $settings['encryption'] ) {
			$phpmailer->SMTPSecure = '';
		} else {
			$phpmailer->SMTPSecure = $settings['encryption'];
		}

		$this->apply_mailbox_from(
			$phpmailer,
			is_email( $settings['user'] ) ? $settings['user'] : '',
			is_email( $options['from_email'] ) ? $options['from_email'] : ''
		);
	}

	/**
	 * Use the authenticated mailbox as the From address unless the site
	 * explicitly configured another address (for example an alias).
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer  PHPMailer instance.
	 * @param string                         $mailbox    Authenticated mailbox email, if known.
	 * @param string                         $configured Configured From Email, if any.
	 *
	 * @return void
	 */
	private function apply_mailbox_from( $phpmailer, $mailbox, $configured ) {

		if ( empty( $mailbox ) ) {
			return;
		}

		$from = $phpmailer->From;

		if ( strcasecmp( $from, $mailbox ) === 0 ) {
			return;
		}

		/*
		 * Keep an address the site explicitly configured (it may be a verified
		 * alias) and replace anything else, such as the default
		 * wordpress@example.com address or a value injected by another plugin,
		 * with the authenticated mailbox.
		 */
		if ( '' !== $configured && strcasecmp( $from, $configured ) === 0 ) {
			return;
		}

		$phpmailer->From   = $mailbox;
		$phpmailer->Sender = $mailbox;
	}

	/**
	 * Filter the From Email address.
	 *
	 * @param string $email From email passed by WordPress.
	 *
	 * @return string
	 */
	public function filter_from_email( $email ) {

		$email = is_email( $email ) ? $email : '';

		$options    = $this->plugin->get_options();
		$default    = $this->get_default_email();
		$configured = is_email( $options['from_email'] ) ? $options['from_email'] : '';

		// Remember a From address set by the site or another plugin.
		$this->original_from_email = ( $email && $email !== $default ) ? $email : '';

		if ( ! empty( $options['from_email_force'] ) && $configured ) {
			return $configured;
		}

		if ( $default && $email !== $default ) {
			return $email;
		}

		return $configured ? $configured : $email;
	}

	/**
	 * Filter the From Name.
	 *
	 * @param string $name From name passed by WordPress.
	 *
	 * @return string
	 */
	public function filter_from_name( $name ) {

		$options    = $this->plugin->get_options();
		$configured = trim( (string) $options['from_name'] );

		if ( ! empty( $options['from_name_force'] ) && '' !== $configured ) {
			return $configured;
		}

		if ( 'WordPress' !== $name ) {
			return $name;
		}

		return '' !== $configured ? $configured : $name;
	}

	/**
	 * The default From address WordPress would use.
	 *
	 * @return string
	 */
	private function get_default_email() {

		$host = wp_parse_url( network_home_url(), PHP_URL_HOST );

		if ( empty( $host ) ) {
			return '';
		}

		$host = strtolower( $host );

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return 'wordpress@' . $host;
	}
}
