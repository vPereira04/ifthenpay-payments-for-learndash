<?php
/**
 * AJAX endpoints behind the settings section.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Ajax;

use Ifthenpay\LearnDash\Admin\MethodsTable;
use Ifthenpay\LearnDash\Api\IfthenpayClient;
use Ifthenpay\LearnDash\Api\WebhookRegistrar;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Mail\IfthenpayEmailHelper;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Connect, Disconnect, the Gateway Key switch and Request activation.
 *
 * Every endpoint checks the nonce and LearnDash's admin capability, and none of
 * them ever sends the Backoffice Key back to the browser.
 *
 * @phpstan-import-type GatewayRow from SettingsRepository
 */
final class SettingsController {

	public const NONCE             = 'iftp_ld_settings';
	private const COOLDOWN_PREFIX  = 'iftp_ld_activation_';
	private const BACKOFFICE_REGEX = '/^\d{4}-\d{4}-\d{4}-\d{4}$/';

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
	 * Callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private $registrar;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings  Settings.
	 * @param IfthenpayClient    $client    API client.
	 * @param WebhookRegistrar   $registrar Callback registration.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client, WebhookRegistrar $registrar ) {
		$this->settings  = $settings;
		$this->client    = $client;
		$this->registrar = $registrar;

		add_action( 'wp_ajax_iftp_ld_connect', array( $this, 'connect' ) );
		add_action( 'wp_ajax_iftp_ld_disconnect', array( $this, 'disconnect' ) );
		add_action( 'wp_ajax_iftp_ld_methods', array( $this, 'methods' ) );
		add_action( 'wp_ajax_iftp_ld_request_activation', array( $this, 'request_activation' ) );
	}

	/**
	 * Connect with a Backoffice Key.
	 *
	 * @return void
	 */
	public function connect(): void {
		$this->guard();

		$key = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['backoffice_key'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.

		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Enter your Backoffice Key.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		if ( 1 !== preg_match( self::BACKOFFICE_REGEX, $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Backoffice Key...', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		$rows = $this->client->get_gateway_rows( $key );

		if ( null === $rows ) {
			wp_send_json_error( array( 'message' => __( 'We couldn’t reach ifthenpay. Check your connection and try again.', 'ifthenpay-payments-for-learndash' ) ), 502 );
		}

		if ( array() === $rows ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Backoffice Key...', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		$this->settings->connect( $key, $this->with_accounts( $key, $rows ) );
		$this->registrar->sync( true );

		wp_send_json_success( array( 'message' => __( 'Connected to ifthenpay.', 'ifthenpay-payments-for-learndash' ) ) );
	}

	/**
	 * Disconnect.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$this->guard();

		$this->settings->disconnect();

		wp_send_json_success( array( 'message' => __( 'Disconnected from ifthenpay.', 'ifthenpay-payments-for-learndash' ) ) );
	}

	/**
	 * Methods `<tbody>` for another Gateway Key (saved with LearnDash's Save).
	 *
	 * @return void
	 */
	public function methods(): void {
		$this->guard();

		$gateway_key = $this->posted_gateway_key();
		$rows        = '' === $gateway_key ? array() : $this->settings->method_rows( $gateway_key, $this->client->get_methods_catalog() );

		wp_send_json_success(
			array(
				'tbody'       => MethodsTable::tbody( $rows, $gateway_key ),
				'gateway_key' => $gateway_key,
			)
		);
	}

	/**
	 * Emails ifthenpay support to activate a method on a Gateway Key.
	 *
	 * @return void
	 */
	public function request_activation(): void {
		$this->guard();

		$gateway_key = $this->posted_gateway_key();
		$entity      = SettingsRepository::entity( sanitize_text_field( wp_unslash( (string) ( $_POST['entity'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.

		if ( '' === $gateway_key || ! in_array( $entity, array_column( $this->client->get_methods_catalog(), 'entity' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown payment method.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		$cooldown = self::cooldown_key( $gateway_key, $entity );

		if ( false !== get_transient( $cooldown ) ) {
			wp_send_json_error( array( 'message' => __( 'You already requested this method in the last 24 hours.', 'ifthenpay-payments-for-learndash' ) ), 429 );
		}

		$user = wp_get_current_user();
		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'       => $gateway_key,
				'entity'            => $entity,
				'backoffice_key'    => $this->settings->backoffice_key(),
				'customer_email'    => (string) $user->user_email,
				'site_url'          => home_url( '/' ),
				'site_name'         => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'wp_version'        => (string) get_bloginfo( 'version' ),
				'plugin_version'    => IFTP_LD_VERSION,
				'learndash_version' => Host::version(),
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'We couldn’t send the request. Please check this site can send email, or contact ifthenpay support.', 'ifthenpay-payments-for-learndash' ) ), 500 );
		}

		set_transient( $cooldown, time(), DAY_IN_SECONDS );

		wp_send_json_success( array( 'message' => __( 'Request sent. ifthenpay will get back to you by email.', 'ifthenpay-payments-for-learndash' ) ) );
	}

	/**
	 * Whether a method's activation request is cooling down.
	 *
	 * @param string $gateway_key Gateway Key.
	 * @param string $entity      Entity.
	 * @return bool
	 */
	public static function is_cooling_down( string $gateway_key, string $entity ): bool {
		return false !== get_transient( self::cooldown_key( $gateway_key, $entity ) );
	}

	/**
	 * Cooldown transient name, per Gateway Key and method.
	 *
	 * @param string $gateway_key Gateway Key.
	 * @param string $entity      Entity.
	 * @return string
	 */
	private static function cooldown_key( string $gateway_key, string $entity ): string {
		return self::COOLDOWN_PREFIX . md5( $gateway_key . '|' . strtoupper( $entity ) );
	}

	/**
	 * Nonce + capability, for every endpoint.
	 *
	 * @return void
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! Host::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to change these settings.', 'ifthenpay-payments-for-learndash' ) ), 403 );
		}
	}

	/**
	 * A posted Gateway Key, which must be one of the account's.
	 *
	 * @return string
	 */
	private function posted_gateway_key(): string {
		$gateway_key = sanitize_text_field( wp_unslash( (string) ( $_POST['gateway_key'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.

		if ( '' !== $gateway_key && null === $this->settings->gateway_row( $gateway_key ) ) {
			wp_send_json_error( array( 'message' => __( 'That Gateway Key isn’t on this ifthenpay account.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		return $gateway_key;
	}

	/**
	 * Fills accounts from the Mobile API for rows that came without any.
	 *
	 * @param string $backoffice_key Backoffice Key.
	 * @param array  $rows           Rows.
	 * @phpstan-param list<GatewayRow> $rows
	 * @return list<GatewayRow>
	 */
	private function with_accounts( string $backoffice_key, array $rows ): array {
		foreach ( $rows as $index => $row ) {
			if ( array() === $row['accounts'] ) {
				$rows[ $index ]['accounts'] = $this->client->get_accounts_by_gateway_key( $backoffice_key, $row['key'] );
			}
		}

		return $rows;
	}
}
