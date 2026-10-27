/**
 * ifthenpay button inside LearnDash's payment buttons and checkout.
 *
 * Same flow as LearnDash's own Razorpay form: POST the form's data-* to
 * LearnDash's setup action, then show LearnDash's "you'll be redirected"
 * alert and go to the hosted page.
 */
(function ($) {
	'use strict';

	var settings = window.iftpLdCheckout || {};

	function showError($form, message) {
		var $error = $form.find('.iftp-ld-checkout__error');

		// The server sends safe HTML (it can hold a "Continue payment" link).
		$error.html(message || (settings.i18n && settings.i18n.error) || '');
		$error.prop('hidden', false);
	}

	function redirect(url) {
		if (typeof window.learnDashPaymentsShowFormSubmittedSuccessfullyMessage === 'function') {
			window.learnDashPaymentsShowFormSubmittedSuccessfullyMessage(function () {
				window.location.assign(url);
			});
			return;
		}

		window.location.assign(url);
	}

	// ifthenpay ships dark logos for dark backgrounds; no white chip as a workaround.
	function backgroundLuminance(element) {
		var node = element.parentElement;

		while (node && node !== document.documentElement) {
			var color = window.getComputedStyle(node).backgroundColor;
			var rgb = color.match(/\d+(\.\d+)?/g);

			if (rgb && (rgb.length < 4 || parseFloat(rgb[3]) > 0)) {
				var channels = rgb.slice(0, 3).map(function (value) {
					var c = parseFloat(value) / 255;
					return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
				});

				return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
			}

			node = node.parentElement;
		}

		return 1;
	}

	function swapDarkLogos() {
		$('.iftp-ld-logos__logo[data-logo-dark]').each(function () {
			var $logo = $(this);

			if (!$logo.data('logo-light')) {
				$logo.data('logo-light', $logo.attr('src'));
			}

			$logo.attr('src', backgroundLuminance(this) < 0.4 ? $logo.attr('data-logo-dark') : $logo.data('logo-light'));
		});
	}

	$(swapDarkLogos);

	$(function () {
		var checkout = document.querySelector('.ld-registration-order__checkout');

		if (checkout && window.MutationObserver) {
			new window.MutationObserver(swapDarkLogos).observe(checkout, { attributes: true, attributeFilter: ['class'], subtree: true });
		}
	});

	$(document).on('submit', '.learndash-payment-gateway-form-ifthenpay', function (event) {
		event.preventDefault();

		var $form = $(this);
		var $button = $form.find('button[type="submit"]');

		if ($form.hasClass('ld-loading')) {
			return;
		}

		$form.addClass('ld-loading');
		$button.prop('disabled', true).attr('aria-busy', 'true');
		$form.find('.iftp-ld-checkout__error').prop('hidden', true).empty();

		$.ajax({
			type: 'POST',
			url: settings.ajaxUrl,
			dataType: 'json',
			data: {
				action: $form.data('action'),
				nonce: $form.data('nonce'),
				post_id: $form.data('post_id')
			}
		})
			.done(function (response) {
				if (response && response.success && response.data && response.data.redirect_url) {
					redirect(response.data.redirect_url);
					return;
				}

				showError($form, response && response.data ? response.data.message : '');
				$form.removeClass('ld-loading');
				$button.prop('disabled', false).removeAttr('aria-busy');
			})
			.fail(function (xhr) {
				var json = xhr && xhr.responseJSON;

				showError($form, json && json.data ? json.data.message : '');
				$form.removeClass('ld-loading');
				$button.prop('disabled', false).removeAttr('aria-busy');
			});
	});
})(jQuery);
