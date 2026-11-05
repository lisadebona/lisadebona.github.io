=== MySite Mail SMTP ===
Contributors: vikingarms
Tags: smtp, gmail, microsoft 365, outlook, email, mail, wp mail smtp
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Send WordPress email through PHP Mail, the Gmail API, or Microsoft 365 SMTP. A focused replacement for WP Mail SMTP with only the mailers this site needs.

== Description ==

MySite Mail SMTP routes all email sent with `wp_mail()` through one of three mailers:

* **PHP Mail** — uses your web server's built-in `mail()` function, exactly like a stock WordPress site.
* **Google / Gmail** — sends through the Gmail API using OAuth 2.0. No SMTP password is stored, and no Google SDK is bundled.
* **Microsoft 365 / Outlook SMTP** — sends through `smtp.office365.com` with STARTTLS using a mailbox address and password or app password.

Features:

* Global From Email and From Name, with optional "force" toggles.
* One-click Gmail authorization from the settings screen.
* Automatic access-token refresh and a clear re-authorization message when Google revokes access.
* Gmail sends from the connected account by default. An explicitly configured From Email is kept so verified Gmail aliases keep working; Gmail validates the address when the message is sent.
* Microsoft 365 SMTP with configurable server, port, and encryption (STARTTLS, SSL, or none).
* Test email screen with the actual error message shown when delivery fails.
* Attachments, CC, BCC, Reply-To, HTML email and custom headers are preserved.
* No email logging tables, tracking, or external services beyond Google and Microsoft.

== Installation ==

1. Upload the `mysite-mail-smtp` folder to `/wp-content/plugins/`.
2. Activate **MySite Mail SMTP** in the Plugins screen.
3. Open **Settings → MySite Mail SMTP** and choose PHP Mail, Google / Gmail, or Microsoft 365.
4. Use the **Email Test** tab to send a test message.

== Gmail setup ==

1. In the [Google Cloud Console](https://console.cloud.google.com/apis/credentials), create a project (or select an existing one).
2. Enable the [Gmail API](https://console.cloud.google.com/apis/library/gmail.googleapis.com) for that project.
3. Configure the OAuth consent screen. While the app is in **Testing** mode, refresh tokens expire after 7 days. Add your Google account as a test user, or publish the app.
4. Create an OAuth client ID of type **Web application**.
5. Copy the **Authorized redirect URI** shown in **Settings → MySite Mail SMTP** into the OAuth client's **Authorized redirect URIs** field.
6. Paste the Client ID and Client Secret into the plugin, save, then click **Allow plugin to send emails using your Google account**.

The Gmail API must be enabled for the same Google Cloud project, otherwise sending fails with a "Gmail API has not been used" error.

If the site is not reachable at its normal HTTPS address (for example, a local development domain), you can define a different callback in `wp-config.php`:

`define( 'MYSITE_GMAIL_REDIRECT_URI', 'https://example.com/wp-admin/admin.php?page=mysite-mail-smtp&mysite_oauth=gmail' );`

The URL must be registered in Google Cloud exactly as shown.

== Microsoft 365 setup ==

1. Enable Authenticated SMTP for the sending mailbox. In the Microsoft 365 admin center, open **Users → Active users**, select the user, choose **Mail → Email apps**, and enable **Authenticated SMTP**.
2. Alternatively, run this in Exchange Online PowerShell:

`Set-CASMailbox -Identity user@yourdomain.com -SmtpClientAuthenticationDisabled $false`

3. In **Settings → MySite Mail SMTP**, select **Microsoft 365**.
4. Use server `smtp.office365.com`, port `587`, and encryption `STARTTLS`. Port `465` is typical when you select `SSL`.
5. Enter the full mailbox address as the Username. If multi-factor authentication is enabled, use an app password when your tenant permits it.
6. Save the settings and send a test from the **Email Test** tab.

Microsoft has been retiring basic SMTP authentication for Exchange Online. If your tenant rejects username/password SMTP, use an SMTP relay or a mail provider that supports OAuth. See the [Microsoft 365 SMTP setup guide](https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365).

== Frequently Asked Questions ==

= Does this replace WP Mail SMTP? =

Yes. It is standalone and does not require WP Mail SMTP. If WP Mail SMTP is active, deactivate it so the two plugins do not fight over the From address and PHPMailer.

= What happens to the Viking Arms theme mailer? =

When this plugin is active, it imports the existing settings from the theme's old Mailer Settings screen the first time it runs, disables the theme's mail hooks, and redirects the old settings page to the plugin. The original theme option is left untouched, so removing the plugin restores the old behaviour.

= Where are settings stored? =

In the `mysite_mail_smtp_options` option. Gmail OAuth tokens, the Google Client Secret, and the Microsoft 365 SMTP password are stored there. Uninstalling the plugin removes the option.

= Which Gmail permissions are requested? =

`openid`, `email`, and `https://www.googleapis.com/auth/gmail.send`. The plugin can see the Google account's email address and send email on its behalf. It cannot read the mailbox. Because `gmail.send` cannot list aliases, a From Email you configure explicitly is trusted and passed to Gmail, which accepts only the connected account or a verified alias.

= Why does Microsoft 365 reject the SMTP login? =

Check that Authenticated SMTP is enabled for that mailbox, that the Username is the full mailbox address, and that the password is current. Tenants with MFA usually require an app password. Microsoft is retiring basic SMTP authentication, so some tenants reject username/password SMTP entirely; in that case use an SMTP relay or a provider with OAuth.

= Does the Microsoft 365 mailer use OAuth? =

No. It uses Microsoft 365's SMTP server with a mailbox username and password or app password. OAuth for Microsoft 365 is not part of this focused plugin.

== Changelog ==

= 1.1.0 =
* Added a Microsoft 365 / Outlook SMTP mailer with STARTTLS, SSL, and app-password support.
* Moved the settings screen under Settings → MySite Mail SMTP.

= 1.0.0 =
* Initial release with PHP Mail and Gmail mailers.
