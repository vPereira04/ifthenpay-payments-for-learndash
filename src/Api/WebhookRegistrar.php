<?php
/**
 * Keeps ifthenpay's callback registration in step with this site.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Api;

use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers our callback URL for the saved Gateway Key.
 *
 * I remember md5( gateway key | callback URL ) once ifthenpay accepts it, so a
 * changed key or a moved site (new URL) re-registers on its own. A failed
 * attempt waits 15 minutes before the drift check tries again.
 */
final class WebhookRegistrar {

	private const RETRY_TRANSIENT = 'iftp_ld_callback_retry';

	/**
	 * Settings.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var IfthenpayClient
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings.
	 * @param IfthenpayClient    $client   API client.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Registers when needed (or always, when forced).
	 *
	 * @param bool $force Skip the drift check and the retry pause.
	 * @return bool Whether the current Gateway Key has a registered callback.
	 */
	public function sync( bool $force = false ): bool {
		$gateway_key = $this->settings->gateway_key();

		if ( '' === $gateway_key || ! $this->settings->is_connected() ) {
			return false;
		}

		$url  = WebhookController::callback_url();
		$hash = md5( $gateway_key . '|' . $url );

		if ( ! $force && $this->registered_hash() === $hash ) {
			return true;
		}

		if ( ! $force && false !== get_transient( self::RETRY_TRANSIENT ) ) {
			return false;
		}

		if ( ! $this->client->activate_callback( $gateway_key, $url ) ) {
			set_transient( self::RETRY_TRANSIENT, 1, 15 * MINUTE_IN_SECONDS );
			return false;
		}

		delete_transient( self::RETRY_TRANSIENT );
		update_option(
			SettingsRepository::OPTION_CALLBACK,
			array(
				'hash' => $hash,
				'at'   => time(),
			),
			false
		);

		return true;
	}

	/**
	 * Whether the saved Gateway Key's callback is registered for this URL.
	 *
	 * @return bool
	 */
	public function is_registered(): bool {
		$gateway_key = $this->settings->gateway_key();

		return '' !== $gateway_key && $this->registered_hash() === md5( $gateway_key . '|' . WebhookController::callback_url() );
	}

	/**
	 * Stored hash.
	 *
	 * @return string
	 */
	private function registered_hash(): string {
		$state = get_option( SettingsRepository::OPTION_CALLBACK, array() );

		return is_array( $state ) ? (string) ( $state['hash'] ?? '' ) : '';
	}
}
