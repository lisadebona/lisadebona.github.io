<?php
/**
 * Admin settings screen.
 *
 * @package MYSITE_Mail_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the plugin admin page, settings saving and Gmail authorization.
 */
final class MYSITE_Admin {

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

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the settings page under Settings.
	 *
	 * @return void
	 */
	public function register_menu() {

		add_options_page(
			__( 'MySite Mail SMTP', 'mysite-mail-smtp' ),
			__( 'MySite Mail SMTP', 'mysite-mail-smtp' ),
			'manage_options',
			MYSITE_Mail_SMTP::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load admin styles and scripts on the plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook ) {

		if ( 'settings_page_' . MYSITE_Mail_SMTP::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'mysite-mail-smtp-admin',
			MYSITE_MAIL_SMTP_URL . 'assets/css/admin.css',
			array(),
			MYSITE_MAIL_SMTP_VERSION
		);

		wp_enqueue_script(
			'mysite-mail-smtp-admin',
			MYSITE_MAIL_SMTP_URL . 'assets/js/admin.js',
			array(),
			MYSITE_MAIL_SMTP_VERSION,
			true
		);
	}

	/**
	 * Handle POST/GET actions before the page is rendered.
	 *
	 * @return void
	 */
	public function handle_actions() {

		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( MYSITE_Mail_SMTP::SLUG !== $page ) {
			return;
		}

		if ( isset( $_GET['mysite_oauth'] ) ) {
			$this->handle_oauth_callback();
		}

		if ( isset( $_POST['mysite_mail_smtp_save'] ) ) {
			$this->save_settings();
		}

		if ( isset( $_GET['mysite_disconnect'] ) && is_string( $_GET['mysite_disconnect'] ) && '1' === $_GET['mysite_disconnect'] ) {
			$this->disconnect_gmail();
		}

		if ( isset( $_POST['mysite_mail_smtp_test'] ) ) {
			$this->send_test_email();
		}
	}

	/**
	 * Save the settings form.
	 *
	 * @return void
	 */
	private function save_settings() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'mysite-mail-smtp' ) );
		}

		check_admin_referer( 'mysite_mail_smtp_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field below.
		$raw     = isset( $_POST['mysite_mail_smtp'] ) && is_array( $_POST['mysite_mail_smtp'] ) ? wp_unslash( $_POST['mysite_mail_smtp'] ) : array();
		$options = $this->plugin->get_options();
		$old     = $options;

		$mailer            = isset( $raw['mailer'] ) && is_string( $raw['mailer'] ) ? $raw['mailer'] : 'php';
		$options['mailer'] = in_array( $mailer, array( 'php', 'gmail', 'microsoft365' ), true ) ? $mailer : 'php';

		$from_email = isset( $raw['from_email'] ) && is_string( $raw['from_email'] ) ? sanitize_text_field( $raw['from_email'] ) : '';

		if ( '' !== $from_email && ! is_email( $from_email ) ) {
			$this->redirect_notice( 'settings', 'settings_error', __( 'The From Email address is not valid. No settings were saved.', 'mysite-mail-smtp' ), 'error' );
		}

		$options['from_email']       = '' === $from_email ? '' : sanitize_email( $from_email );
		$options['from_name']        = isset( $raw['from_name'] ) && is_string( $raw['from_name'] ) ? sanitize_text_field( $raw['from_name'] ) : '';
		$options['from_email_force'] = ! empty( $raw['from_email_force'] );
		$options['from_name_force']  = ! empty( $raw['from_name_force'] );

		$gmail = isset( $raw['gmail'] ) && is_array( $raw['gmail'] ) ? $raw['gmail'] : array();

		$client_id = array_key_exists( 'client_id', $gmail ) && is_string( $gmail['client_id'] ) ? sanitize_text_field( $gmail['client_id'] ) : $old['gmail']['client_id'];
		$secret    = isset( $gmail['client_secret'] ) && is_string( $gmail['client_secret'] ) ? sanitize_text_field( $gmail['client_secret'] ) : '';

		$options['gmail']['client_id'] = $client_id;

		if ( ! empty( $gmail['client_secret_clear'] ) ) {
			$options['gmail']['client_secret'] = '';
		} elseif ( '' !== $secret ) {
			$options['gmail']['client_secret'] = $secret;
		}

		// Tokens belong to a specific OAuth client. Reset them when the
		// Client ID changes so stale tokens cannot be used silently.
		if ( $old['gmail']['client_id'] !== $options['gmail']['client_id'] ) {
			$options['gmail']['access_token']  = '';
			$options['gmail']['refresh_token'] = '';
			$options['gmail']['token_expires'] = 0;
			$options['gmail']['user_email']    = '';
		}

		$microsoft = isset( $raw['microsoft'] ) && is_array( $raw['microsoft'] ) ? $raw['microsoft'] : array();
		$defaults  = MYSITE_Mail_SMTP::defaults();

		$ms_host = array_key_exists( 'host', $microsoft ) && is_string( $microsoft['host'] ) ? sanitize_text_field( $microsoft['host'] ) : $old['microsoft']['host'];
		$ms_host = preg_replace( '#^https?://#i', '', trim( $ms_host ) );
		$ms_host = rtrim( (string) $ms_host, '/' );

		$options['microsoft']['host'] = '' !== $ms_host ? $ms_host : $defaults['microsoft']['host'];

		$ms_port = isset( $microsoft['port'] ) && is_numeric( $microsoft['port'] ) ? absint( $microsoft['port'] ) : (int) $old['microsoft']['port'];

		$options['microsoft']['port'] = ( $ms_port >= 1 && $ms_port <= 65535 ) ? $ms_port : $defaults['microsoft']['port'];

		$ms_encryption = isset( $microsoft['encryption'] ) && is_string( $microsoft['encryption'] ) ? $microsoft['encryption'] : $old['microsoft']['encryption'];

		$options['microsoft']['encryption'] = in_array( $ms_encryption, array( 'tls', 'ssl', 'none' ), true )
			? $ms_encryption
			: $defaults['microsoft']['encryption'];

		$options['microsoft']['user'] = array_key_exists( 'user', $microsoft ) && is_string( $microsoft['user'] )
			? sanitize_text_field( $microsoft['user'] )
			: $old['microsoft']['user'];

		$ms_password = isset( $microsoft['pass'] ) && is_string( $microsoft['pass'] ) ? sanitize_text_field( $microsoft['pass'] ) : '';

		if ( ! empty( $microsoft['pass_clear'] ) ) {
			$options['microsoft']['pass'] = '';
		} elseif ( '' !== $ms_password ) {
			$options['microsoft']['pass'] = $ms_password;
		}

		$this->plugin->save_options( $options );

		$this->redirect_notice( 'settings', 'saved', __( 'Settings saved.', 'mysite-mail-smtp' ) );
	}

	/**
	 * Remove the stored Google authorization.
	 *
	 * @return void
	 */
	private function disconnect_gmail() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'mysite-mail-smtp' ) );
		}

		check_admin_referer( 'mysite_gmail_disconnect' );

		$this->plugin->clear_gmail_connection();

		$this->redirect_notice( 'settings', 'gmail_removed', __( 'The Gmail connection has been removed. You can authorize a different account at any time.', 'mysite-mail-smtp' ) );
	}

	/**
	 * Handle the Google OAuth redirect back to the site.
	 *
	 * @return void
	 */
	private function handle_oauth_callback() {

		$oauth = isset( $_GET['mysite_oauth'] ) && is_string( $_GET['mysite_oauth'] ) ? sanitize_key( wp_unslash( $_GET['mysite_oauth'] ) ) : '';

		if ( 'gmail' !== $oauth ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'mysite-mail-smtp' ) );
		}

		$state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( '' === $state || ! wp_verify_nonce( $state, 'mysite_gmail_oauth' ) ) {
			$this->redirect_notice(
				'settings',
				'gmail_error',
				__( 'The Google authorization request could not be verified. Please try connecting again.', 'mysite-mail-smtp' ),
				'error'
			);
		}

		if ( isset( $_GET['error'] ) && is_string( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );

			$message = 'access_denied' === $error
				? __( 'Authorization was cancelled. The plugin was not connected to your Google account.', 'mysite-mail-smtp' )
				: sprintf(
					/* translators: %s: error code returned by Google. */
					__( 'Google returned an authorization error: %s', 'mysite-mail-smtp' ),
					$error
				);

			$this->redirect_notice( 'settings', 'gmail_error', $message, 'error' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		$code = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '';

		if ( ! is_string( $code ) || '' === $code || strlen( $code ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $code ) ) {
			$this->redirect_notice(
				'settings',
				'gmail_error',
				__( 'Google did not return a valid authorization code. Please try connecting again.', 'mysite-mail-smtp' ),
				'error'
			);
		}

		$result = $this->plugin->gmail()->exchange_code( $code );

		if ( is_wp_error( $result ) ) {
			$this->redirect_notice( 'settings', 'gmail_error', $result->get_error_message(), 'error' );
		}

		$gmail = $this->plugin->get_gmail_settings();
		$email = ! empty( $gmail['user_email'] ) ? $gmail['user_email'] : '';

		$message = $email
			? sprintf(
				/* translators: %s: connected Gmail address. */
				__( 'Connected to %s. Gmail is ready to send email.', 'mysite-mail-smtp' ),
				$email
			)
			: __( 'Your Google account is connected. Gmail is ready to send email.', 'mysite-mail-smtp' );

		$this->redirect_notice( 'settings', 'gmail_connected', $message );
	}

	/**
	 * Send the test email.
	 *
	 * @return void
	 */
	private function send_test_email() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'mysite-mail-smtp' ) );
		}

		check_admin_referer( 'mysite_mail_smtp_test' );

		$to = isset( $_POST['mysite_test_to'] ) && is_string( $_POST['mysite_test_to'] ) ? sanitize_email( wp_unslash( $_POST['mysite_test_to'] ) ) : '';

		if ( ! is_email( $to ) ) {
			$this->redirect_notice( 'test', 'test_error', __( 'Enter a valid email address for the test.', 'mysite-mail-smtp' ), 'error' );
		}

		$mailer = $this->mailer_label();

		$subject = sprintf(
			/* translators: %s: mailer name. */
			__( 'MySite Mail SMTP: Test email (%s)', 'mysite-mail-smtp' ),
			$mailer
		);

		$body = sprintf(
			'<p>%s</p><p>%s</p><p>%s</p>',
			esc_html__( 'This test email was sent by MySite Mail SMTP.', 'mysite-mail-smtp' ),
			sprintf(
				/* translators: 1: mailer name, 2: site name. */
				esc_html__( 'Mailer: %1$s — Site: %2$s', 'mysite-mail-smtp' ),
				esc_html( $mailer ),
				esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )
			),
			esc_html( sprintf( /* translators: %s: current date and time. */ __( 'Sent at %s.', 'mysite-mail-smtp' ), wp_date( 'Y-m-d H:i:s' ) ) )
		);

		$error_message = '';

		$capture_error = static function ( $wp_error ) use ( &$error_message ) {
			$error_message = $wp_error->get_error_message();
		};

		add_action( 'wp_mail_failed', $capture_error );
		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $capture_error );

		if ( $sent ) {
			$this->redirect_notice(
				'test',
				'test_success',
				sprintf(
					/* translators: 1: recipient email, 2: mailer name. */
					__( 'Test email accepted for delivery to %1$s using %2$s.', 'mysite-mail-smtp' ),
					$to,
					$mailer
				)
			);
		}

		if ( '' === $error_message ) {
			$error_message = __( 'wp_mail() returned false. Check your server configuration or the plugin settings.', 'mysite-mail-smtp' );
		}

		$this->redirect_notice( 'test', 'test_error', $error_message, 'error' );
	}

	/**
	 * Redirect back to a tab with a notice.
	 *
	 * @param string $tab     Tab slug.
	 * @param string $notice  Notice key.
	 * @param string $message Optional notice message.
	 * @param string $type    "success" or "error".
	 *
	 * @return void
	 */
	private function redirect_notice( $tab, $notice, $message = '', $type = 'success' ) {

		if ( '' !== $message ) {
			set_transient(
				$this->notice_key(),
				array(
					'type'    => $type,
					'message' => $message,
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => MYSITE_Mail_SMTP::SLUG,
					'tab'         => $tab,
					'mysite_notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);

		exit;
	}

	/**
	 * Transient key for the current user's admin notice.
	 *
	 * @return string
	 */
	private function notice_key() {

		return 'mysite_mail_smtp_notice_' . get_current_user_id();
	}

	/**
	 * Display a notice after a redirect.
	 *
	 * @return void
	 */
	private function render_notices() {

		$notice = isset( $_GET['mysite_notice'] ) && is_string( $_GET['mysite_notice'] ) ? sanitize_key( wp_unslash( $_GET['mysite_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$stored = get_transient( $this->notice_key() );

		if ( false !== $stored ) {
			delete_transient( $this->notice_key() );
		}

		$type    = 'success';
		$message = '';

		if ( is_array( $stored ) && ! empty( $stored['message'] ) ) {
			$type    = 'error' === $stored['type'] ? 'error' : 'success';
			$message = $stored['message'];
		}

		if ( '' === $message ) {
			$defaults = array(
				'saved'           => __( 'Settings saved.', 'mysite-mail-smtp' ),
				'gmail_connected' => __( 'Your Google account is connected.', 'mysite-mail-smtp' ),
				'gmail_removed'   => __( 'The Gmail connection has been removed.', 'mysite-mail-smtp' ),
				'gmail_error'     => __( 'The Google authorization could not be completed.', 'mysite-mail-smtp' ),
				'test_success'    => __( 'The test email was accepted for delivery.', 'mysite-mail-smtp' ),
				'test_error'      => __( 'The test email could not be sent.', 'mysite-mail-smtp' ),
				'settings_error'  => __( 'The settings could not be saved.', 'mysite-mail-smtp' ),
			);

			if ( isset( $defaults[ $notice ] ) ) {
				$message = $defaults[ $notice ];
			}
		}

		if ( '' === $message ) {
			return;
		}

		$type = in_array( $notice, array( 'gmail_error', 'test_error', 'settings_error' ), true ) ? 'error' : $type;

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab     = isset( $_GET['tab'] ) && 'test' === $_GET['tab'] ? 'test' : 'settings';
		$options = $this->plugin->get_options();

		?>
		<div class="wrap mysite-wrap">
			<div class="mysite-header">
				<div class="mysite-brand">
					<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'MySite Mail SMTP', 'mysite-mail-smtp' ); ?></h1>
						<span class="mysite-version">
							<?php
							printf(
								/* translators: %s: plugin version. */
								esc_html__( 'Version %s', 'mysite-mail-smtp' ),
								esc_html( MYSITE_MAIL_SMTP_VERSION )
							);
							?>
						</span>
					</div>
				</div>
				<nav class="mysite-tabs" aria-label="<?php esc_attr_e( 'Plugin settings', 'mysite-mail-smtp' ); ?>">
					<a href="<?php echo esc_url( $this->page_url( 'settings' ) ); ?>" class="<?php echo 'settings' === $tab ? 'is-active' : ''; ?>">
						<?php esc_html_e( 'Settings', 'mysite-mail-smtp' ); ?>
					</a>
					<a href="<?php echo esc_url( $this->page_url( 'test' ) ); ?>" class="<?php echo 'test' === $tab ? 'is-active' : ''; ?>">
						<?php esc_html_e( 'Email Test', 'mysite-mail-smtp' ); ?>
					</a>
				</nav>
			</div>

			<?php $this->render_notices(); ?>

			<?php
			if ( 'test' === $tab ) {
				$this->render_test_tab( $options );
			} else {
				$this->render_settings_tab( $options );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the Settings tab.
	 *
	 * @param array $options Plugin settings.
	 *
	 * @return void
	 */
	private function render_settings_tab( $options ) {

		$is_gmail     = 'gmail' === $options['mailer'];
		$is_microsoft = 'microsoft365' === $options['mailer'];
		$gmail        = $this->plugin->gmail();

		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'mysite_mail_smtp_save' ); ?>

			<div class="mysite-layout">
				<div class="mysite-main">

					<section class="mysite-card">
						<h2><?php esc_html_e( 'Mailer', 'mysite-mail-smtp' ); ?></h2>
						<p class="mysite-card-desc"><?php esc_html_e( 'Choose how WordPress should deliver its email.', 'mysite-mail-smtp' ); ?></p>

						<div class="mysite-mailers" role="radiogroup" aria-label="<?php esc_attr_e( 'Mailer', 'mysite-mail-smtp' ); ?>">
							<label class="mysite-mailer<?php echo 'php' === $options['mailer'] ? ' is-selected' : ''; ?>">
								<input type="radio" name="mysite_mail_smtp[mailer]" value="php" <?php checked( 'php', $options['mailer'] ); ?>>
								<span class="mysite-mailer-box">
									<span class="mysite-mailer-head">
										<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
										<strong><?php esc_html_e( 'PHP Mail', 'mysite-mail-smtp' ); ?></strong>
									</span>
									<span class="mysite-mailer-text">
										<?php esc_html_e( 'Uses your web server\'s built-in mail() function. No third-party account is required, but deliverability depends entirely on your host.', 'mysite-mail-smtp' ); ?>
									</span>
								</span>
							</label>

							<label class="mysite-mailer<?php echo 'gmail' === $options['mailer'] ? ' is-selected' : ''; ?>">
								<input type="radio" name="mysite_mail_smtp[mailer]" value="gmail" <?php checked( 'gmail', $options['mailer'] ); ?>>
								<span class="mysite-mailer-box">
									<span class="mysite-mailer-head">
										<span class="dashicons dashicons-google" aria-hidden="true"></span>
										<strong><?php esc_html_e( 'Google / Gmail', 'mysite-mail-smtp' ); ?></strong>
									</span>
									<span class="mysite-mailer-text">
										<?php esc_html_e( 'Sends through the Gmail API using OAuth. Works with any Gmail or Google Workspace account and is more secure than storing SMTP credentials.', 'mysite-mail-smtp' ); ?>
									</span>
								</span>
							</label>

							<label class="mysite-mailer<?php echo $is_microsoft ? ' is-selected' : ''; ?>">
								<input type="radio" name="mysite_mail_smtp[mailer]" value="microsoft365" <?php checked( $is_microsoft ); ?>>
								<span class="mysite-mailer-box">
									<span class="mysite-mailer-head">
										<span class="dashicons dashicons-cloud" aria-hidden="true"></span>
										<strong><?php esc_html_e( 'Microsoft 365', 'mysite-mail-smtp' ); ?></strong>
									</span>
									<span class="mysite-mailer-text">
										<?php esc_html_e( 'Sends through smtp.office365.com with STARTTLS using your mailbox address and password or app password.', 'mysite-mail-smtp' ); ?>
									</span>
								</span>
							</label>
						</div>
					</section>

					<section class="mysite-card">
						<h2><?php esc_html_e( 'From Email', 'mysite-mail-smtp' ); ?></h2>
						<p class="mysite-card-desc"><?php esc_html_e( 'Set the name and address WordPress uses when it sends email.', 'mysite-mail-smtp' ); ?></p>

						<div class="mysite-field">
							<label for="mysite-from-email"><?php esc_html_e( 'From Email', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="email"
									id="mysite-from-email"
									class="regular-text"
									name="mysite_mail_smtp[from_email]"
									value="<?php echo esc_attr( $options['from_email'] ); ?>"
									placeholder="<?php echo esc_attr( $this->default_from_email() ); ?>"
								>
								<p class="description">
									<?php esc_html_e( 'Leave blank to use the WordPress default. For Gmail and Microsoft 365, a blank value uses the connected account or mailbox; a configured address must be an allowed sender or alias.', 'mysite-mail-smtp' ); ?>
								</p>

								<label class="mysite-toggle">
									<input type="checkbox" name="mysite_mail_smtp[from_email_force]" value="1" <?php checked( ! empty( $options['from_email_force'] ) ); ?>>
									<span class="mysite-toggle-track" aria-hidden="true"></span>
									<span><?php esc_html_e( 'Force From Email', 'mysite-mail-smtp' ); ?></span>
								</label>
								<p class="description"><?php esc_html_e( 'Apply this address to every email, even when another plugin sets its own From address.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>

						<div class="mysite-field">
							<label for="mysite-from-name"><?php esc_html_e( 'From Name', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="text"
									id="mysite-from-name"
									class="regular-text"
									name="mysite_mail_smtp[from_name]"
									value="<?php echo esc_attr( $options['from_name'] ); ?>"
									placeholder="<?php esc_attr_e( 'WordPress', 'mysite-mail-smtp' ); ?>"
								>
								<p class="description"><?php esc_html_e( 'Leave blank to use the name WordPress already provides.', 'mysite-mail-smtp' ); ?></p>

								<label class="mysite-toggle">
									<input type="checkbox" name="mysite_mail_smtp[from_name_force]" value="1" <?php checked( ! empty( $options['from_name_force'] ) ); ?>>
									<span class="mysite-toggle-track" aria-hidden="true"></span>
									<span><?php esc_html_e( 'Force From Name', 'mysite-mail-smtp' ); ?></span>
								</label>
								<p class="description"><?php esc_html_e( 'Apply this name to every email, even when another plugin sets its own From name.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>
					</section>

					<section class="mysite-card mysite-gmail-card" data-mysite-when-mailer="gmail" <?php echo $is_gmail ? '' : 'hidden'; ?>>
						<h2><?php esc_html_e( 'Google / Gmail', 'mysite-mail-smtp' ); ?></h2>
						<p class="mysite-card-desc">
							<?php esc_html_e( 'Connect a Google Cloud OAuth client to send email through Gmail. This is a one-time setup.', 'mysite-mail-smtp' ); ?>
						</p>

						<ol class="mysite-steps">
							<li>
								<?php
								printf(
									/* translators: %s: Google Cloud Console URL. */
									esc_html__( 'Create a project and OAuth client in the %s.', 'mysite-mail-smtp' ),
									'<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud Console', 'mysite-mail-smtp' ) . '</a>'
								);
								?>
							</li>
							<li>
								<?php
								printf(
									/* translators: %s: Gmail API URL. */
									esc_html__( 'Enable the %s.', 'mysite-mail-smtp' ),
									'<a href="https://console.cloud.google.com/apis/library/gmail.googleapis.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Gmail API', 'mysite-mail-smtp' ) . '</a>'
								);
								?>
							</li>
							<li><?php esc_html_e( 'Create an OAuth client of type "Web application" and add the Authorized redirect URI below.', 'mysite-mail-smtp' ); ?></li>
							<li><?php esc_html_e( 'Paste the Client ID and Client Secret here, save, then click the authorization button.', 'mysite-mail-smtp' ); ?></li>
						</ol>

						<div class="mysite-field">
							<label for="mysite-client-id"><?php esc_html_e( 'Client ID', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="text"
									id="mysite-client-id"
									class="regular-text code"
									name="mysite_mail_smtp[gmail][client_id]"
									value="<?php echo esc_attr( $options['gmail']['client_id'] ); ?>"
									autocomplete="off"
									spellcheck="false"
								>
							</div>
						</div>

						<div class="mysite-field">
							<label for="mysite-client-secret"><?php esc_html_e( 'Client Secret', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="password"
									id="mysite-client-secret"
									class="regular-text"
									name="mysite_mail_smtp[gmail][client_secret]"
									value=""
									placeholder="<?php echo ! empty( $options['gmail']['client_secret'] ) ? esc_attr__( 'Saved — leave blank to keep it', 'mysite-mail-smtp' ) : ''; ?>"
									autocomplete="new-password"
								>
								<?php if ( ! empty( $options['gmail']['client_secret'] ) ) : ?>
									<label class="mysite-check">
										<input type="checkbox" name="mysite_mail_smtp[gmail][client_secret_clear]" value="1">
										<?php esc_html_e( 'Remove the saved Client Secret', 'mysite-mail-smtp' ); ?>
									</label>
								<?php endif; ?>
							</div>
						</div>

						<div class="mysite-field">
							<label for="mysite-gmail-redirect"><?php esc_html_e( 'Authorized redirect URI', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<div class="mysite-input-row">
									<input
										type="text"
										id="mysite-gmail-redirect"
										class="regular-text code"
										value="<?php echo esc_attr( MYSITE_Gmail::redirect_uri() ); ?>"
										readonly
										onfocus="this.select();"
									>
									<button type="button" class="button" data-mysite-copy="mysite-gmail-redirect">
										<?php esc_html_e( 'Copy', 'mysite-mail-smtp' ); ?>
									</button>
								</div>
								<p class="description"><?php esc_html_e( 'Paste this exact URL into "Authorized redirect URIs" for your Google OAuth client.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>

						<div class="mysite-field">
							<span class="mysite-field-label"><?php esc_html_e( 'Authorization', 'mysite-mail-smtp' ); ?></span>
							<div class="mysite-field-control">
								<?php if ( $gmail->is_authorized() ) : ?>
									<p class="mysite-connected">
										<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
										<?php
										if ( ! empty( $options['gmail']['user_email'] ) ) {
											printf(
												/* translators: %s: connected Gmail address. */
												esc_html__( 'Connected as %s', 'mysite-mail-smtp' ),
												'<code>' . esc_html( $options['gmail']['user_email'] ) . '</code>'
											);
										} else {
											esc_html_e( 'Connected to Google', 'mysite-mail-smtp' );
										}
										?>
									</p>
									<a class="button mysite-danger-btn" href="<?php echo esc_url( $this->disconnect_url() ); ?>">
										<?php esc_html_e( 'Remove OAuth Connection', 'mysite-mail-smtp' ); ?>
									</a>
									<p class="description"><?php esc_html_e( 'Removing the connection lets you authorize a different Google account.', 'mysite-mail-smtp' ); ?></p>
									<?php if ( ! $gmail->is_configured() ) : ?>
										<p class="mysite-inline-note"><?php esc_html_e( 'The Client ID or Client Secret is missing. Sending will stop when the current access token expires.', 'mysite-mail-smtp' ); ?></p>
									<?php endif; ?>
								<?php elseif ( ! $gmail->is_configured() ) : ?>
									<p class="mysite-inline-note"><?php esc_html_e( 'Save your Client ID and Client Secret before you can authorize the plugin.', 'mysite-mail-smtp' ); ?></p>
								<?php else : ?>
									<a class="button mysite-google-btn" href="<?php echo esc_url( $gmail->get_auth_url() ); ?>">
										<?php esc_html_e( 'Allow plugin to send emails using your Google account', 'mysite-mail-smtp' ); ?>
									</a>
									<p class="description"><?php esc_html_e( 'You will be redirected to Google to grant permission, then brought back here.', 'mysite-mail-smtp' ); ?></p>
								<?php endif; ?>
							</div>
						</div>

						<p class="mysite-footnote">
							<?php esc_html_e( 'Only the send permission and primary account email are requested; the plugin cannot read your mailbox. Gmail has API rate limits and works best for lower-volume sites. If your Google Cloud app is still in "Testing" mode, refresh tokens expire after 7 days unless you add the account as a test user and re-authorize.', 'mysite-mail-smtp' ); ?>
						</p>
					</section>

					<section class="mysite-card mysite-microsoft-card" data-mysite-when-mailer="microsoft365" <?php echo $is_microsoft ? '' : 'hidden'; ?>>
						<h2><?php esc_html_e( 'Microsoft 365 / Outlook', 'mysite-mail-smtp' ); ?></h2>
						<p class="mysite-card-desc">
							<?php esc_html_e( 'Send email through Microsoft 365 using its SMTP server. Microsoft requires SMTP AUTH to be enabled for the mailbox.', 'mysite-mail-smtp' ); ?>
						</p>

						<div class="mysite-field">
							<label for="mysite-microsoft-host"><?php esc_html_e( 'Server', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="text"
									id="mysite-microsoft-host"
									class="regular-text code"
									name="mysite_mail_smtp[microsoft][host]"
									value="<?php echo esc_attr( $options['microsoft']['host'] ); ?>"
									placeholder="smtp.office365.com"
								>
								<p class="description"><?php esc_html_e( 'Microsoft 365 uses smtp.office365.com.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>

						<div class="mysite-field">
							<label for="mysite-microsoft-port"><?php esc_html_e( 'Port', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="number"
									min="1"
									max="65535"
									id="mysite-microsoft-port"
									class="small-text"
									name="mysite_mail_smtp[microsoft][port]"
									value="<?php echo esc_attr( (string) $options['microsoft']['port'] ); ?>"
								>
								<p class="description"><?php esc_html_e( 'Use 587 with STARTTLS, or 465 with SSL.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>

						<div class="mysite-field">
							<label for="mysite-microsoft-encryption"><?php esc_html_e( 'Encryption', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<select id="mysite-microsoft-encryption" name="mysite_mail_smtp[microsoft][encryption]">
									<option value="tls" <?php selected( $options['microsoft']['encryption'], 'tls' ); ?>><?php esc_html_e( 'STARTTLS (recommended)', 'mysite-mail-smtp' ); ?></option>
									<option value="ssl" <?php selected( $options['microsoft']['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL', 'mysite-mail-smtp' ); ?></option>
									<option value="none" <?php selected( $options['microsoft']['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'mysite-mail-smtp' ); ?></option>
								</select>
							</div>
						</div>

						<div class="mysite-field">
							<label for="mysite-microsoft-user"><?php esc_html_e( 'Username', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="email"
									id="mysite-microsoft-user"
									class="regular-text"
									name="mysite_mail_smtp[microsoft][user]"
									value="<?php echo esc_attr( $options['microsoft']['user'] ); ?>"
									placeholder="you@yourdomain.com"
									autocomplete="username"
								>
								<p class="description"><?php esc_html_e( 'The mailbox that sends the email. It is used as the From Email when From Email is blank.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>

						<div class="mysite-field">
							<label for="mysite-microsoft-pass"><?php esc_html_e( 'Password', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="password"
									id="mysite-microsoft-pass"
									class="regular-text"
									name="mysite_mail_smtp[microsoft][pass]"
									value=""
									placeholder="<?php echo ! empty( $options['microsoft']['pass'] ) ? esc_attr__( 'Saved — leave blank to keep it', 'mysite-mail-smtp' ) : ''; ?>"
									autocomplete="new-password"
								>
								<?php if ( ! empty( $options['microsoft']['pass'] ) ) : ?>
									<label class="mysite-check">
										<input type="checkbox" name="mysite_mail_smtp[microsoft][pass_clear]" value="1">
										<?php esc_html_e( 'Remove the saved password', 'mysite-mail-smtp' ); ?>
									</label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Use an app password when multi-factor authentication is enabled.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>

						<p class="mysite-footnote">
							<?php
							printf(
								/* translators: %s: Microsoft 365 SMTP setup guide URL. */
								esc_html__( 'Before this mailer can send, enable Authenticated SMTP for the mailbox in the Microsoft 365 admin center (Users → Active users → Mail → Email apps). Microsoft has been retiring basic SMTP authentication; if your tenant blocks it, use an SMTP relay or a provider that supports OAuth. Read the %s.', 'mysite-mail-smtp' ),
								'<a href="https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Microsoft 365 SMTP setup guide', 'mysite-mail-smtp' ) . '</a>'
							);
							?>
						</p>
					</section>

					<div class="mysite-actions">
						<button type="submit" name="mysite_mail_smtp_save" value="1" class="button button-primary button-hero">
							<?php esc_html_e( 'Save Settings', 'mysite-mail-smtp' ); ?>
						</button>
					</div>
				</div>

				<aside class="mysite-side">
					<?php $this->render_status_card( $options ); ?>

					<section class="mysite-card">
						<h2><?php esc_html_e( 'Need a hand?', 'mysite-mail-smtp' ); ?></h2>
						<?php if ( $is_microsoft ) : ?>
							<p class="mysite-side-text"><?php esc_html_e( 'Microsoft 365 only accepts SMTP logins for mailboxes with Authenticated SMTP enabled. If multi-factor authentication is on, use an app password. Changes in the Microsoft 365 admin center can take several minutes to apply.', 'mysite-mail-smtp' ); ?></p>
							<p class="mysite-side-text">
								<a href="https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Microsoft 365 SMTP setup documentation', 'mysite-mail-smtp' ); ?>
								</a>
							</p>
						<?php else : ?>
							<p class="mysite-side-text"><?php esc_html_e( 'The redirect URI must match exactly, including the scheme and trailing parameters. If Google reports a redirect_uri_mismatch, copy the URI from this page again after saving your changes.', 'mysite-mail-smtp' ); ?></p>
							<p class="mysite-side-text">
								<a href="https://developers.google.com/gmail/api/guides/sending" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Gmail API sending documentation', 'mysite-mail-smtp' ); ?>
								</a>
							</p>
						<?php endif; ?>
					</section>
				</aside>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the Email Test tab.
	 *
	 * @param array $options Plugin settings.
	 *
	 * @return void
	 */
	private function render_test_tab( $options ) {

		$user = wp_get_current_user();

		?>
		<div class="mysite-layout">
			<div class="mysite-main">
				<section class="mysite-card">
					<h2><?php esc_html_e( 'Send a Test Email', 'mysite-mail-smtp' ); ?></h2>
					<p class="mysite-card-desc"><?php esc_html_e( 'Send a message through the selected mailer to confirm everything is working.', 'mysite-mail-smtp' ); ?></p>

					<form method="post" action="">
						<?php wp_nonce_field( 'mysite_mail_smtp_test' ); ?>

						<div class="mysite-field">
							<label for="mysite-test-to"><?php esc_html_e( 'Recipient', 'mysite-mail-smtp' ); ?></label>
							<div class="mysite-field-control">
								<input
									type="email"
									id="mysite-test-to"
									class="regular-text"
									name="mysite_test_to"
									value="<?php echo esc_attr( $user->user_email ); ?>"
									required
								>
								<p class="description"><?php esc_html_e( 'Enter the address that should receive the test message.', 'mysite-mail-smtp' ); ?></p>
							</div>
						</div>

						<button type="submit" name="mysite_mail_smtp_test" value="1" class="button button-primary">
							<?php esc_html_e( 'Send Test Email', 'mysite-mail-smtp' ); ?>
						</button>
					</form>
				</section>
			</div>

			<aside class="mysite-side">
				<?php $this->render_status_card( $options ); ?>
			</aside>
		</div>
		<?php
	}

	/**
	 * Render the "Current Status" sidebar card.
	 *
	 * @param array $options Plugin settings.
	 *
	 * @return void
	 */
	private function render_status_card( $options ) {

		$mailer = $this->mailer_label();

		?>
		<section class="mysite-card">
			<h2><?php esc_html_e( 'Current Status', 'mysite-mail-smtp' ); ?></h2>
			<ul class="mysite-status">
				<li>
					<span><?php esc_html_e( 'Mailer', 'mysite-mail-smtp' ); ?></span>
					<strong><?php echo esc_html( $mailer ); ?></strong>
				</li>
				<li>
					<span><?php esc_html_e( 'From Email', 'mysite-mail-smtp' ); ?></span>
					<strong>
						<?php
						echo $options['from_email']
							? esc_html( $options['from_email'] )
							: esc_html__( 'WordPress default', 'mysite-mail-smtp' );
						?>
					</strong>
				</li>
				<?php if ( 'gmail' === $options['mailer'] ) : ?>
					<li>
						<span><?php esc_html_e( 'Gmail', 'mysite-mail-smtp' ); ?></span>
						<strong>
							<?php
							if ( $this->plugin->gmail()->is_authorized() ) {
								echo $options['gmail']['user_email']
									? esc_html( $options['gmail']['user_email'] )
									: esc_html__( 'Connected', 'mysite-mail-smtp' );
							} else {
								esc_html_e( 'Not connected', 'mysite-mail-smtp' );
							}
							?>
						</strong>
					</li>
				<?php elseif ( 'microsoft365' === $options['mailer'] ) : ?>
					<li>
						<span><?php esc_html_e( 'SMTP server', 'mysite-mail-smtp' ); ?></span>
						<strong><?php echo esc_html( $options['microsoft']['host'] . ':' . $options['microsoft']['port'] ); ?></strong>
					</li>
					<li>
						<span><?php esc_html_e( 'SMTP login', 'mysite-mail-smtp' ); ?></span>
						<strong>
							<?php
							echo $options['microsoft']['user']
								? esc_html( $options['microsoft']['user'] )
								: esc_html__( 'Not configured', 'mysite-mail-smtp' );
							?>
						</strong>
					</li>
				<?php endif; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Human-readable mailer label.
	 *
	 * @return string
	 */
	private function mailer_label() {

		switch ( $this->plugin->get_mailer() ) {
			case 'gmail':
				return __( 'Google / Gmail', 'mysite-mail-smtp' );

			case 'microsoft365':
				return __( 'Microsoft 365', 'mysite-mail-smtp' );

			default:
				return __( 'PHP Mail', 'mysite-mail-smtp' );
		}
	}

	/**
	 * Default From address used by WordPress.
	 *
	 * @return string
	 */
	private function default_from_email() {

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

	/**
	 * Admin page URL for a tab.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return string
	 */
	private function page_url( $tab = 'settings' ) {

		return add_query_arg(
			array(
				'page' => MYSITE_Mail_SMTP::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Nonced URL that removes the Gmail connection.
	 *
	 * @return string
	 */
	private function disconnect_url() {

		$url = add_query_arg(
			array(
				'page'           => MYSITE_Mail_SMTP::SLUG,
				'mysite_disconnect' => '1',
			),
			admin_url( 'options-general.php' )
		);

		return wp_nonce_url( $url, 'mysite_gmail_disconnect' );
	}
}
