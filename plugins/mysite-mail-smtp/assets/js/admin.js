/**
 * MySite Mail SMTP admin behaviour.
 */
(function () {
	'use strict';

	var radios = document.querySelectorAll('input[name="mysite_mail_smtp[mailer]"]');

	function selectedMailer() {
		var checked = document.querySelector('input[name="mysite_mail_smtp[mailer]"]:checked');

		return checked ? checked.value : 'php';
	}

	function updateMailer() {
		var mailer = selectedMailer();

		document.querySelectorAll('.mysite-mailer').forEach(function (card) {
			var input = card.querySelector('input[type="radio"]');

			card.classList.toggle('is-selected', !!(input && input.checked));
		});

		document.querySelectorAll('[data-mysite-when-mailer]').forEach(function (element) {
			element.hidden = element.getAttribute('data-mysite-when-mailer') !== mailer;
		});
	}

	radios.forEach(function (radio) {
		radio.addEventListener('change', updateMailer);
	});

	updateMailer();

	document.querySelectorAll('[data-mysite-copy]').forEach(function (button) {
		button.addEventListener('click', function () {
			var input = document.getElementById(button.getAttribute('data-mysite-copy'));

			if (!input) {
				return;
			}

			input.focus();
			input.select();
			input.setSelectionRange(0, input.value.length);

			var original = button.textContent;

			function done() {
				button.textContent = 'Copied';

				window.setTimeout(function () {
					button.textContent = original;
				}, 1500);
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(input.value).then(done, function () {
					document.execCommand('copy');
					done();
				});
			} else {
				document.execCommand('copy');
				done();
			}
		});
	});
}());
