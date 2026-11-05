<?php
/**
 * Gmail OAuth and API service.
 *
 * @package MYSITE_Mail_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Google OAuth and the Gmail API.
 */
final class MYSITE_Gmail {

	/**
	 * Google OAuth authorization endpoint.
	 */
	const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Google OAuth token endpoint.
	 */
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * Gmail API base URL for the authenticated user.
	 */
	const API_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me';

	/**
	 * Scopes: identify the Google account and send email. No mailbox access.
	 */
	const SCOPES = 'openid email https://www.googleapis.com/auth/gmail.send';

	/**
	 * OpenID Connect user info endpoint, used only to read the account email.
	 */
	const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

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
	}

	/**
	 * URL Google redirects back to after authorization.
	 *
	 * Define MYSITE_GMAIL_REDIRECT_URI in wp-config.php to override this, for
	 * example when using an HTTPS tunnel during local development.
	 *
	 * @return string
	 */
	public static function redirect_uri() {

		if ( defined( 'MYSITE_GMAIL_REDIRECT_URI' ) && MYSITE_GMAIL_REDIRECT_URI ) {
			return (string) MYSITE_GMAIL_REDIRECT_URI;
		}

		return admin_url( 'admin.php?page=' . MYSITE_Mail_SMTP::SLUG . '&mysite_oauth=gmail' );
	}

	/**
	 * Whether a Client ID and Client Secret have been saved.
	 *
	 * @return bool
	 */
	public function is_configured() {

		$gmail = $this->plugin->get_gmail_settings();

		return ! empty( $gmail['client_id'] ) && ! empty( $gmail['client_secret'] );
	}

	/**
	 * Whether the site is connected to a Google account.
	 *
	 * @return bool
	 */
	public function is_authorized() {

		$gmail = $this->plugin->get_gmail_settings();

		if ( ! empty( $gmail['refresh_token'] ) ) {
			return true;
		}

		return ! empty( $gmail['access_token'] ) && (int) $gmail['token_expires'] > time();
	}

	/**
	 * Build the Google authorization URL.
	 *
	 * @return string Empty string when the client credentials are missing.
	 */
	public function get_auth_url() {

		if ( ! $this->is_configured() ) {
			return '';
		}

		// add_query_arg() does not encode newly added values, so encode the
		// values that contain reserved characters ourselves.
		return add_query_arg(
			array(
				'client_id'              => rawurlencode( $this->plugin->get_gmail_settings()['client_id'] ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPES ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'false',
				'state'                  => rawurlencode( wp_create_nonce( 'mysite_gmail_oauth' ) ),
			),
			self::AUTH_ENDPOINT
		);
	}

	/**
	 * Exchange an authorization code for access and refresh tokens.
	 *
	 * @param string $code Authorization code returned by Google.
	 *
	 * @return true|WP_Error
	 */
	public function exchange_code( $code ) {

		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'mysite_gmail_no_clients',
				__( 'Save your Client ID and Client Secret before authorizing.', 'mysite-mail-smtp' )
			);
		}

		$gmail = $this->plugin->get_gmail_settings();

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $gmail['client_id'],
					'client_secret' => $gmail['client_secret'],
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mysite_gmail_http',
				sprintf(
					/* translators: %s: HTTP error message. */
					__( 'Could not reach Google: %s', 'mysite-mail-smtp' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : array();

		if ( 200 !== $status || empty( $body['access_token'] ) ) {
			return new WP_Error( 'mysite_gmail_token', $this->get_token_error_message( $body, $status ) );
		}

		$refresh_token = ! empty( $body['refresh_token'] ) ? $body['refresh_token'] : $gmail['refresh_token'];

		$data = array(
			'access_token'  => $body['access_token'],
			'refresh_token' => $refresh_token,
			'token_expires' => time() + max( 60, (int) $body['expires_in'] ) - 60,
		);

		$email = $this->email_from_id_token( isset( $body['id_token'] ) ? $body['id_token'] : '' );

		if ( ! $email ) {
			$email = $this->fetch_userinfo_email( $body['access_token'] );
		}

		if ( $email ) {
			$data['user_email'] = $email;
		}

		$this->plugin->set_gmail_data( $data );

		if ( $email ) {
			// Like WP Mail SMTP, use the connected address as the From Email
			// when the site has not configured one yet.
			$options = $this->plugin->get_options();

			if ( empty( $options['from_email'] ) ) {
				$options['from_email'] = $email;
				$this->plugin->save_options( $options );
			}
		}

		return true;
	}

	/**
	 * Get the email address of the connected Google account.
	 *
	 * Resolves and stores it from the OpenID userinfo endpoint when it is
	 * missing. This only needs the "email" scope.
	 *
	 * @return string Empty string on failure.
	 */
	public function get_connected_email() {

		$gmail = $this->plugin->get_gmail_settings();

		if ( ! empty( $gmail['user_email'] ) && is_email( $gmail['user_email'] ) ) {
			return $gmail['user_email'];
		}

		if ( ! $this->is_authorized() ) {
			return '';
		}

		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return '';
		}

		$email = $this->fetch_userinfo_email( $token );

		if ( $email ) {
			$this->plugin->set_gmail_data( array( 'user_email' => $email ) );
		}

		return $email;
	}

	/**
	 * Read the account email from an OpenID Connect ID token.
	 *
	 * The token is received directly from Google's token endpoint over HTTPS.
	 * Its payload is decoded only to read the address; the signature does not
	 * need to be verified in this server-to-server flow.
	 *
	 * @param string $id_token JWT ID token.
	 *
	 * @return string Email address, or an empty string.
	 */
	private function email_from_id_token( $id_token ) {

		if ( ! is_string( $id_token ) || '' === $id_token ) {
			return '';
		}

		$parts = explode( '.', $id_token );

		if ( 3 !== count( $parts ) ) {
			return '';
		}

		$encoded = strtr( $parts[1], '-_', '+/' );
		$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload = base64_decode( $encoded, true );

		if ( false === $payload ) {
			return '';
		}

		$claims = json_decode( $payload, true );

		if ( ! is_array( $claims ) || empty( $claims['email'] ) || ! is_string( $claims['email'] ) ) {
			return '';
		}

		if ( isset( $claims['email_verified'] ) && ! $claims['email_verified'] ) {
			return '';
		}

		$email = sanitize_email( $claims['email'] );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Ask Google's userinfo endpoint for the account email address.
	 *
	 * @param string $token Access token.
	 *
	 * @return string Email address, or an empty string.
	 */
	private function fetch_userinfo_email( $token ) {

		$response = wp_remote_get(
			self::USERINFO_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['email'] ) || ! is_string( $data['email'] ) ) {
			return '';
		}

		if ( isset( $data['email_verified'] ) && ! $data['email_verified'] ) {
			return '';
		}

		$email = sanitize_email( $data['email'] );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Send a raw MIME message through the Gmail API.
	 *
	 * @param string $raw Raw MIME message, with CRLF line endings.
	 *
	 * @return array|WP_Error Gmail API response or error.
	 */
	public function send_raw( $raw ) {

		if ( ! $this->is_authorized() ) {
			return new WP_Error(
				'mysite_gmail_not_authorized',
				__(
					'Gmail is not connected. Go to MySite Mail SMTP → Settings, then click "Allow plugin to send emails using your Google account".',
					'mysite-mail-smtp'
				)
			);
		}

		// Gmail requires RFC 5322 CRLF line endings.
		$raw = preg_replace( '/\r\n|\r|\n/', "\r\n", $raw );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded = rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );

		$response = $this->api_request( 'POST', '/messages/send', array( 'raw' => $encoded ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['id'] ) ) {
			return new WP_Error(
				'mysite_gmail_no_id',
				__( 'The Gmail API accepted the request but did not return a message ID.', 'mysite-mail-smtp' )
			);
		}

		return $response;
	}

	/**
	 * Get a valid access token, refreshing it when needed.
	 *
	 * @return string|WP_Error
	 */
	private function get_access_token() {

		$gmail = $this->plugin->get_gmail_settings();

		if (
			! empty( $gmail['access_token'] ) &&
			! empty( $gmail['token_expires'] ) &&
			(int) $gmail['token_expires'] > time() + 60
		) {
			return $gmail['access_token'];
		}

		if ( empty( $gmail['refresh_token'] ) ) {
			return new WP_Error(
				'mysite_gmail_not_authorized',
				__(
					'Gmail is not connected. Go to MySite Mail SMTP → Settings, then click "Allow plugin to send emails using your Google account".',
					'mysite-mail-smtp'
				)
			);
		}

		return $this->refresh_access_token();
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 *
	 * @return string|WP_Error
	 */
	private function refresh_access_token() {

		$gmail = $this->plugin->get_gmail_settings();

		if ( empty( $gmail['refresh_token'] ) ) {
			return new WP_Error(
				'mysite_gmail_not_authorized',
				__( 'Gmail is not connected. Please re-authorize the plugin.', 'mysite-mail-smtp' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $gmail['client_id'],
					'client_secret' => $gmail['client_secret'],
					'refresh_token' => $gmail['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mysite_gmail_http',
				sprintf(
					/* translators: %s: HTTP error message. */
					__( 'Could not reach Google: %s', 'mysite-mail-smtp' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : array();

		if ( 200 !== $status || empty( $body['access_token'] ) ) {
			return new WP_Error( 'mysite_gmail_refresh', $this->get_token_error_message( $body, $status ) );
		}

		$data = array(
			'access_token'  => $body['access_token'],
			'token_expires' => time() + max( 60, (int) $body['expires_in'] ) - 60,
		);

		if ( ! empty( $body['refresh_token'] ) ) {
			$data['refresh_token'] = $body['refresh_token'];
		}

		$email = $this->email_from_id_token( isset( $body['id_token'] ) ? $body['id_token'] : '' );

		if ( $email ) {
			$data['user_email'] = $email;
		}

		$this->plugin->set_gmail_data( $data );

		return $body['access_token'];
	}

	/**
	 * Make an authenticated request to the Gmail API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path, starting with a slash.
	 * @param array|null $body   JSON body.
	 * @param bool       $retry  Whether a 401 response may be retried once.
	 *
	 * @return array|WP_Error
	 */
	private function api_request( $method, $path, $body = null, $retry = true ) {

		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_ENDPOINT . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mysite_gmail_http',
				sprintf(
					/* translators: %s: HTTP error message. */
					__( 'Could not reach the Gmail API: %s', 'mysite-mail-smtp' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();

		// The access token may have been revoked or expired early. Refresh once
		// and retry the request.
		if ( 401 === $status && $retry ) {
			$refresh = $this->refresh_access_token();

			if ( ! is_wp_error( $refresh ) ) {
				return $this->api_request( $method, $path, $body, false );
			}
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = '';

			if ( isset( $data['error'] ) && is_array( $data['error'] ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			}

			if ( '' === $message ) {
				$message = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Gmail API returned HTTP status %d.', 'mysite-mail-smtp' ),
					$status
				);
			}

			return new WP_Error( 'mysite_gmail_api', $this->explain_api_error( $message ) );
		}

		return $data;
	}

	/**
	 * Build a readable message for an OAuth token endpoint error.
	 *
	 * @param array $body   Decoded response body.
	 * @param int   $status HTTP status code.
	 *
	 * @return string
	 */
	private function get_token_error_message( $body, $status ) {

		$error = isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : '';
		$text  = isset( $body['error_description'] ) && is_string( $body['error_description'] ) ? $body['error_description'] : '';

		if ( 'invalid_grant' === $error ) {
			return __( 'Google rejected the stored authorization. Please remove the OAuth connection and authorize the plugin again.', 'mysite-mail-smtp' );
		}

		if ( 'invalid_client' === $error ) {
			return __( 'Google rejected the Client ID or Client Secret. Double-check both values, save the settings, and try again.', 'mysite-mail-smtp' );
		}

		if ( '' === $text ) {
			$text = $error;
		}

		if ( '' === $text ) {
			$text = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Google returned HTTP status %d.', 'mysite-mail-smtp' ),
				$status
			);
		}

		return $text;
	}

	/**
	 * Append a hint to common Gmail API errors.
	 *
	 * @param string $message Error message from Google.
	 *
	 * @return string
	 */
	private function explain_api_error( $message ) {

		if ( false !== stripos( $message, 'invalid_grant' ) ) {
			return $message . ' ' . __( 'Please remove the OAuth connection and authorize the plugin again.', 'mysite-mail-smtp' );
		}

		if ( false !== stripos( $message, 'insufficient permission' ) || false !== stripos( $message, 'insufficientPermissions' ) ) {
			return $message . ' ' . __( 'Make sure the Gmail API is enabled for your Google Cloud project and that the plugin is authorized with the gmail.send scope.', 'mysite-mail-smtp' );
		}

		if ( false !== stripos( $message, 'Delegation denied' ) || false !== stripos( $message, 'not a valid origin' ) ) {
			return $message . ' ' . __( 'Check that the Authorized redirect URI in Google Cloud matches the URL shown in the plugin settings exactly.', 'mysite-mail-smtp' );
		}

		return $message;
	}
}
