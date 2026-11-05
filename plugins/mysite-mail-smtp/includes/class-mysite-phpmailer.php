<?php
/**
 * PHPMailer subclass used by MySite Mail SMTP.
 *
 * @package MYSITE_Mail_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through PHP mail() for the PHP mailer, or through the Gmail API when
 * the Gmail mailer is selected.
 */
final class MYSITE_PHPMailer extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Deliver the message.
	 *
	 * PHPMailer calls this method for every non-SMTP mailer, after the MIME
	 * message has been built by preSend().
	 *
	 * @param string $header MIME headers.
	 * @param string $body   MIME body.
	 *
	 * @throws \PHPMailer\PHPMailer\Exception When Gmail sending fails.
	 *
	 * @return bool
	 */
	protected function mailSend( $header, $body ) {

		$plugin = MYSITE_Mail_SMTP::instance();

		if ( 'gmail' !== $plugin->get_mailer() ) {
			return parent::mailSend( $header, $body );
		}

		$gmail = $plugin->gmail();

		if ( ! $gmail->is_authorized() ) {
			throw new \PHPMailer\PHPMailer\Exception(
				__(
					'MySite Mail SMTP (Gmail): Gmail is not connected. Go to Settings → MySite Mail SMTP, add your Google Client ID and Secret, then click "Allow plugin to send emails using your Google account".',
					'mysite-mail-smtp'
				)
			);
		}

		// Build the raw RFC 5322 message, including attachments and custom
		// headers that PHPMailer has already prepared.
		$raw = $this->getSentMIMEMessage();

		// Gmail requires CRLF line endings.
		$raw = preg_replace( '/\r\n|\r|\n/', "\r\n", $raw );

		$raw = $this->add_bcc_header( $raw );

		$result = $gmail->send_raw( $raw );

		if ( is_wp_error( $result ) ) {
			throw new \PHPMailer\PHPMailer\Exception(
				sprintf(
					/* translators: %s: error message returned by the Gmail API. */
					__( 'MySite Mail SMTP (Gmail): %s', 'mysite-mail-smtp' ),
					$result->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Add a Bcc header when Bcc recipients are present.
	 *
	 * PHPMailer only includes the Bcc header for mail(), sendmail and qmail.
	 * The Gmail API builds the envelope from the raw message headers, so Bcc
	 * recipients would otherwise be dropped.
	 *
	 * @param string $raw Raw MIME message with CRLF line endings.
	 *
	 * @return string
	 */
	private function add_bcc_header( $raw ) {

		$bcc = $this->getBccAddresses();

		if ( empty( $bcc ) ) {
			return $raw;
		}

		$addresses = array();

		foreach ( $bcc as $recipient ) {
			$addresses[] = $this->addrFormat( $recipient );
		}

		$header = 'Bcc: ' . implode( ', ', $addresses ) . "\r\n";

		$separator = strpos( $raw, "\r\n\r\n" );

		if ( false === $separator ) {
			return $raw;
		}

		return substr( $raw, 0, $separator ) . "\r\n" . $header . substr( $raw, $separator + 2 );
	}
}
