/**
 * LearnDash › Settings › Payments › ifthenpay.
 *
 * Dialogs are jQuery UI, the same as LearnDash's PayPal Checkout section.
 * The section itself saves with LearnDash's own Save button; only Connect,
 * Disconnect, the Gateway Key switch and Request activation talk to us.
 */
(function ($) {
	'use strict';

	var cfg = window.iftpLdSettings || {};
	var i18n = cfg.i18n || {};
	var dialogClass = 'iftp-ld-dialog iftp-ld-scope';

	function post(action, data) {
		return $.ajax({
			type: 'POST',
			url: cfg.ajaxUrl,
			dataType: 'json',
			data: $.extend({ action: action, nonce: cfg.nonce }, data || {})
		});
	}

	function errorMessage(xhr) {
		var json = xhr && xhr.responseJSON;

		return json && json.data && json.data.message ? json.data.message : i18n.network;
	}

	function announce(text) {
		var $live = $('#iftp-ld-settings-live');

		// Clearing first makes screen readers repeat an identical message.
		$live.text('');
		window.setTimeout(function () {
			$live.text(text);
		}, 50);
	}

	function message(title, text) {
		var $dialog = $('#iftp-ld-message-dialog');

		$dialog.find('.iftp-ld-dialog__message').text(text);
		$dialog.dialog({
			title: title,
			modal: true,
			draggable: false,
			resizable: false,
			width: 'auto',
			maxWidth: 480,
			dialogClass: dialogClass + ' iftp-ld-dialog--message',
			buttons: [
				{
					text: i18n.ok,
					class: 'button button-primary',
					click: function () {
						$(this).dialog('close');
					}
				}
			]
		});
	}

	/* Connect */

	function connectDialog() {
		var $dialog = $('#iftp-ld-connect-dialog');
		var $input = $('#iftp-ld-backoffice-key');
		var $error = $('#iftp-ld-connect-error');

		function showError(text) {
			$input.attr('aria-invalid', 'true');
			$error.find('.iftp-ld-dialog__error-text').text(text);
			$error.prop('hidden', false);
			$input.trigger('focus').trigger('select');
		}

		function submit() {
			var key = $.trim($input.val());
			var $pane = $dialog.parent().find('.ui-dialog-buttonpane');
			var $primary = $pane.find('.button-primary');

			if (key === '') {
				showError(i18n.emptyKey);
				return;
			}

			$input.attr('aria-invalid', 'false');
			$error.prop('hidden', true);
			$primary.prop('disabled', true).text(i18n.connecting);
			$pane.find('.spinner').addClass('is-active');

			post('iftp_ld_connect', { backoffice_key: key })
				.done(function (response) {
					if (response && response.success) {
						$dialog.dialog('close');
						window.location.assign(cfg.settingsUrl + '&iftp_ld_connected=1');
						return;
					}

					showError(response && response.data ? response.data.message : i18n.network);
				})
				.fail(function (xhr) {
					showError(errorMessage(xhr));
				})
				.always(function () {
					$primary.prop('disabled', false).text(i18n.connect);
					$pane.find('.spinner').removeClass('is-active');
				});
		}

		$dialog.find('form').off('submit.iftp').on('submit.iftp', function (event) {
			event.preventDefault();
			submit();
		});

		$dialog.dialog({
			modal: true,
			draggable: false,
			resizable: false,
			width: Math.min(480, Math.round(window.innerWidth * 0.92)),
			dialogClass: dialogClass + ' iftp-ld-dialog--connect',
			open: function () {
				var $pane = $(this).parent().find('.ui-dialog-buttonpane');

				if (!$pane.find('.spinner').length) {
					$pane.find('.ui-dialog-buttonset').prepend('<span class="spinner" aria-hidden="true"></span>');
				}

				$input.val('').attr('aria-invalid', 'false');
				$error.prop('hidden', true);
				window.setTimeout(function () {
					$input.trigger('focus');
				}, 0);
			},
			buttons: [
				{
					text: i18n.cancel,
					class: 'button button-secondary',
					click: function () {
						$(this).dialog('close');
					}
				},
				{
					text: i18n.connect,
					class: 'button button-primary',
					click: submit
				}
			]
		});
	}

	$(document).on('click', '[data-iftp-ld-connect]', function (event) {
		event.preventDefault();

		// A successful connect reloads the page, which would throw unsaved edits away (LearnDash's PayPal rule).
		if ($(this).closest('.settings-not-saved, form.settings-not-saved').length || $('.sfwd_options.settings_ifthenpay').hasClass('settings-not-saved')) {
			message(i18n.unsavedTitle, i18n.unsavedText);
			return;
		}

		connectDialog();
	});

	/* Disconnect */

	$(document).on('click', '[data-iftp-ld-disconnect]', function (event) {
		event.preventDefault();

		var $button = $(this);

		$('#iftp-ld-disconnect-dialog').dialog({
			modal: true,
			draggable: false,
			resizable: false,
			width: Math.max(320, Math.round(window.innerWidth * 0.4)),
			dialogClass: dialogClass + ' iftp-ld-dialog--disconnect',
			buttons: [
				{
					text: i18n.cancel,
					class: 'button button-secondary',
					click: function () {
						$(this).dialog('close');
					}
				},
				{
					text: i18n.disconnect,
					class: 'button button-primary',
					click: function () {
						var $dialog = $(this);

						$dialog.parent().find('.button-primary').prop('disabled', true);
						$button.prop('disabled', true);

						post('iftp_ld_disconnect')
							.done(function () {
								window.location.assign(cfg.settingsUrl + '&iftp_ld_disconnected=1');
							})
							.fail(function (xhr) {
								$dialog.dialog('close');
								$button.prop('disabled', false);
								message(i18n.disconnectTitle, errorMessage(xhr));
							});
					}
				}
			]
		});
	});

	/* Methods table: the star follows its row's switch */

	function syncStar($row) {
		var enabled = $row.find('.iftp-ld-methods__enable').is(':checked');
		var $radio = $row.find('.iftp-ld-methods__default');

		$radio.prop('disabled', !enabled);
		$row.find('.iftp-ld-methods__star').toggleClass('iftp-ld-methods__star--hidden', !enabled);

		if (!enabled && $radio.is(':checked')) {
			$radio.prop('checked', false);
		}
	}

	$(document).on('change', '.iftp-ld-methods__enable', function () {
		syncStar($(this).closest('.iftp-ld-methods__row'));
	});

	$(document).on('change', '.iftp-ld-methods__default', function () {
		var $star = $(this).next('.iftp-ld-methods__star');
		var label = $(this).closest('.iftp-ld-methods__row').find('.iftp-ld-methods__label').text();

		// Removing and re-adding the class replays the wink on repeat clicks.
		$star.removeClass('iftp-ld-methods__star--wink');
		if ($star[0]) {
			$star[0].getBoundingClientRect();
		}
		$star.addClass('iftp-ld-methods__star--wink');

		announce((i18n.defaultLive || '%s').replace('%s', label));
	});

	/* Gateway Key switch */

	function loadMethods(gatewayKey) {
		var $table = $('#iftp-ld-methods');
		var $spinner = $('.iftp-ld-gateway-spinner');

		$table.attr('aria-busy', 'true').addClass('iftp-ld-methods--busy');
		$spinner.addClass('is-active');

		post('iftp_ld_methods', { gateway_key: gatewayKey })
			.done(function (response) {
				if (response && response.success) {
					$table.find('tbody').replaceWith(response.data.tbody);
					$table.attr('data-iftp-ld-gateway', response.data.gateway_key);
					$table.find('caption').trigger('focus');
					announce(i18n.methodsUpdated);
					return;
				}

				announce(i18n.methodsLoadFailed);
			})
			.fail(function () {
				announce(i18n.methodsLoadFailed);
			})
			.always(function () {
				$table.attr('aria-busy', 'false').removeClass('iftp-ld-methods--busy');
				$spinner.removeClass('is-active');
			});
	}

	$(document).on('change', 'select[data-iftp-ld-gateway-select]', function () {
		loadMethods($(this).val());
	});

	$(document).on('click', '[data-iftp-ld-retry]', function (event) {
		event.preventDefault();
		loadMethods($('[data-iftp-ld-gateway-select]').val() || $('#iftp-ld-methods').attr('data-iftp-ld-gateway'));
	});

	/* Request activation */

	$(document).on('click', '.iftp-ld-methods__request', function (event) {
		event.preventDefault();

		var $button = $(this);
		var original = $button.text();
		var label = $button.data('label') || $button.data('entity');

		$button.prop('disabled', true).text(i18n.requesting);

		post('iftp_ld_request_activation', {
			gateway_key: $('#iftp-ld-methods').attr('data-iftp-ld-gateway'),
			entity: $button.data('entity')
		})
			.done(function (response) {
				if (response && response.success) {
					$button.text(i18n.requested);
					announce((i18n.requestedLive || '%s').replace('%s', label));
					message(i18n.requestedTitle, (i18n.requestedText || '%s').replace('%s', label));
					return;
				}

				$button.prop('disabled', false).text(original);
				message(i18n.requestFailed, response && response.data ? response.data.message : i18n.network);
			})
			.fail(function (xhr) {
				$button.prop('disabled', false).text(original);
				message(i18n.requestFailed, errorMessage(xhr));
			});
	});
})(jQuery);
