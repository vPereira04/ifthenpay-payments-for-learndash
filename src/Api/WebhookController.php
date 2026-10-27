<?php
/**
 * The ifthenpay server-to-server callback.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Api;

use Ifthenpay\LearnDash\Payments\PurchaseCompleter;
use Ifthenpay\LearnDash\Payments\TransactionStore;

defined( 'ABSPATH' ) || exit;

/**
 * `?learndash-integration=ifthenpay`: LearnDash's own webhook URL shape, and
 * the only place a payment becomes paid.
 *
 * Check order: ref → row (404) → anti-phishing key against the row's own
 * Gateway Key (403), on every branch, failure notices included → the status
 * branch → amount (400) → atomic claim → the LearnDash order.
 */
final class WebhookController {

	public const QUERY_VAR = 'learndash-integration';
	public const NAME      = 'ifthenpay';

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Turns a paid attempt into access and a LearnDash order.
	 *
	 * @var PurchaseCompleter
	 */
	private $completer;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore  $store     Attempts.
	 * @param PurchaseCompleter $completer Order recording.
	 */
	public function __construct( TransactionStore $store, PurchaseCompleter $completer ) {
		$this->store     = $store;
		$this->completer = $completer;

		add_action( 'wp_loaded', array( $this, 'maybe_handle' ) );
	}

	/**
	 * The URL we register with ifthenpay, placeholders included.
	 *
	 * Built by hand: add_query_arg() would encode the brackets.
	 *
	 * @return string
	 */
	public static function callback_url(): string {
		$base = home_url( '/' );
		$glue = false === strpos( $base, '?' ) ? '?' : '&';

		return $base . $glue . self::QUERY_VAR . '=' . self::NAME
			. '&iftp_ld_ref=[ORDER_ID]'
			. '&iftp_ld_apk=[ANTI_PHISHING_KEY]'
			. '&iftp_ld_val=[AMOUNT]'
			. '&iftp_ld_mtd=[PAYMENT_METHOD]'
			. '&iftp_ld_req=[REQUEST_ID]';
	}

	/**
	 * Handles the request when it's ours; returns at once otherwise.
	 *
	 * @return void
	 */
	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Server-to-server callback from ifthenpay; the anti-phishing key authenticates it.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || self::NAME !== sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) ) {
			return;
		}

		$params = array(
			'ref'    => isset( $_GET['iftp_ld_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['iftp_ld_ref'] ) ) : '',
			'apk'    => isset( $_GET['iftp_ld_apk'] ) ? (string) wp_unslash( $_GET['iftp_ld_apk'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against the base64 alphabet in is_authentic().
			'val'    => isset( $_GET['iftp_ld_val'] ) ? sanitize_text_field( wp_unslash( $_GET['iftp_ld_val'] ) ) : '',
			'mtd'    => isset( $_GET['iftp_ld_mtd'] ) ? sanitize_text_field( wp_unslash( $_GET['iftp_ld_mtd'] ) ) : '',
			'req'    => isset( $_GET['iftp_ld_req'] ) ? sanitize_text_field( wp_unslash( $_GET['iftp_ld_req'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->handle( $params );

		self::reply( $result['code'], $result['message'] );
	}

	/**
	 * Handles one callback.
	 *
	 * @param array{ref: string, apk: string, val: string, mtd: string, req: string, status: string} $params Sanitized query params.
	 * @return array{code: int, message: string}
	 */
	public function handle( array $params ): array {
		$row = '' === $params['ref'] ? null : $this->store->get( $params['ref'] );

		if ( null === $row ) {
			return self::result( 404, 'Unknown reference' );
		}

		if ( ! self::is_authentic( $row['gateway_key'], $params['apk'] ) ) {
			return self::result( 403, 'Invalid anti-phishing key' );
		}

		if ( '' !== $params['status'] ) {
			return $this->handle_failure( $row['ref'], $params['status'] );
		}

		$value = str_replace( ',', '.', $params['val'] );

		if ( ! is_numeric( $value ) || abs( (float) $value - $row['amount'] ) > 0.01 ) {
			return self::result( 400, 'Amount mismatch' );
		}

		$method     = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $params['mtd'] ) );
		$request_id = substr( $params['req'], 0, 64 );
		$previous   = $row['status'];

		if ( TransactionStore::STATUS_PAID === $previous || ! $this->store->mark_paid( $row['ref'], $method, $request_id ) ) {
			return self::result( 200, 'Already paid' );
		}

		$paid = $this->store->get( $row['ref'] );

		if ( null === $paid || ! $this->completer->complete( $paid ) ) {
			// LearnDash couldn't record it; I let ifthenpay's redelivery finish it.
			$this->store->release_paid( $row['ref'], $previous );

			return self::result( 500, 'Order not recorded, retry' );
		}

		return self::result( 200, 'OK' );
	}

	/**
	 * Cancelled or failed notice. Never moves a paid row.
	 *
	 * @param string $ref    Reference.
	 * @param string $status Raw status.
	 * @return array{code: int, message: string}
	 */
	private function handle_failure( string $ref, string $status ): array {
		$cancelled = in_array( $status, array( 'cancelled', 'canceled', 'cancel' ), true );
		$changed   = $cancelled ? $this->store->mark_cancelled( $ref ) : $this->store->mark_failed( $ref );

		return $changed ? self::result( 200, 'OK' ) : self::result( 200, 'Already paid' );
	}

	/**
	 * `hash_equals( $gateway_key, base64_decode( $apk, true ) )`, refusing empty and placeholder keys.
	 *
	 * @param string $gateway_key The row's Gateway Key.
	 * @param string $raw_apk     Raw anti-phishing key.
	 * @return bool
	 */
	private static function is_authentic( string $gateway_key, string $raw_apk ): bool {
		if ( '' === $gateway_key || TransactionStore::MANUAL_GATEWAY_KEY === $gateway_key ) {
			return false;
		}

		// Base64 alphabet only; a '+' that arrived as a space is put back.
		$apk = filter_var( $raw_apk, FILTER_VALIDATE_REGEXP, array( 'options' => array( 'regexp' => '#^[A-Za-z0-9+/= ]+$#' ) ) );

		if ( ! is_string( $apk ) ) {
			return false;
		}

		$decoded = base64_decode( str_replace( ' ', '+', $apk ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- ifthenpay sends the Gateway Key base64-encoded.

		return false !== $decoded && hash_equals( $gateway_key, $decoded );
	}

	/**
	 * Result shape.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Message.
	 * @return array{code: int, message: string}
	 */
	private static function result( int $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Plain JSON reply, then stop: nothing else on this request should run.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Message.
	 * @return void
	 */
	private static function reply( int $code, string $message ): void {
		nocache_headers();
		wp_send_json( array( 'message' => $message ), $code );
	}
}
