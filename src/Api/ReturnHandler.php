<?php
/**
 * Browser returns from ifthenpay and "Try again".
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Api;

use Ifthenpay\LearnDash\Gateway\IfthenpayGateway;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Payments\PaymentRefused;
use Ifthenpay\LearnDash\Payments\PaymentService;
use Ifthenpay\LearnDash\Payments\TransactionStore;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the student back to LearnDash.
 *
 * The return never changes the payment state: the URL is reachable by anyone,
 * and Multibanco/Payshop return "success" before the money arrives. Only the
 * webhook marks a payment paid. A paid return goes through LearnDash's own
 * success chain (enrollment URL → return URL → registration success page →
 * course); anything else lands on the course page, where StatusBlock shows the
 * payment's state and keeps checking.
 */
final class ReturnHandler {

	public const SUCCESS = 'success';
	public const ERROR   = 'error';
	public const CANCEL  = 'cancel';

	public const ARG_ACTION = 'iftp_ld_pay';
	public const ARG_REF    = 'iftp_ld_txn_ref';
	public const ARG_TXN    = 'iftp_ld_txn_id';
	public const ARG_SIG    = 'iftp_ld_sig';
	public const ARG_STATUS = 'iftp_ld_status';
	public const ARG_RETRY  = 'iftp_ld_retry';

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Link creation.
	 *
	 * @var PaymentService
	 */
	private $payments;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore $store    Attempts.
	 * @param PaymentService   $payments Link creation.
	 */
	public function __construct( TransactionStore $store, PaymentService $payments ) {
		$this->store    = $store;
		$this->payments = $payments;

		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Return URL for ifthenpay, with the literal [TRANSACTIONID] placeholder.
	 *
	 * Built by hand: http_build_query()/esc_url() would encode the brackets and
	 * ifthenpay would no longer recognise the placeholder.
	 *
	 * @param string $action success|error|cancel.
	 * @param string $ref    Reference.
	 * @return string
	 */
	public static function url( string $action, string $ref ): string {
		$base = home_url( '/' );
		$glue = false === strpos( $base, '?' ) ? '?' : '&';

		return $base . $glue
			. self::ARG_ACTION . '=' . rawurlencode( $action )
			. '&' . self::ARG_REF . '=' . rawurlencode( $ref )
			. '&' . self::ARG_SIG . '=' . rawurlencode( self::sign( $action . '|' . $ref ) )
			. '&' . self::ARG_TXN . '=[TRANSACTIONID]';
	}

	/**
	 * The course page showing a payment's status block.
	 *
	 * @param array<string, mixed> $row Our transaction row.
	 * @param string               $hint What the student came back from: success|error|cancel|''.
	 * @return string
	 */
	public static function status_url( array $row, string $hint = '' ): string {
		$ref = (string) ( $row['ref'] ?? '' );

		return add_query_arg(
			array(
				self::ARG_STATUS => rawurlencode( $ref ),
				self::ARG_ACTION => '' !== $hint ? $hint : false,
				self::ARG_SIG    => rawurlencode( self::status_sig( $ref ) ),
			),
			(string) get_permalink( (int) ( $row['product_id'] ?? 0 ) )
		);
	}

	/**
	 * "Try again" URL: a fresh link for the same student and course.
	 *
	 * @param array<string, mixed> $row Our transaction row.
	 * @return string
	 */
	public static function retry_url( array $row ): string {
		$ref = (string) ( $row['ref'] ?? '' );

		return add_query_arg(
			array(
				self::ARG_RETRY => rawurlencode( $ref ),
				self::ARG_SIG   => rawurlencode( self::sign( 'retry|' . $ref ) ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Signature that lets the course page (and its poller) read one reference's status.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function status_sig( string $ref ): string {
		return self::sign( 'status|' . $ref );
	}

	/**
	 * Whether a status-block signature is genuine.
	 *
	 * @param string $ref Reference.
	 * @param string $sig Signature.
	 * @return bool
	 */
	public static function is_status_signed( string $ref, string $sig ): bool {
		return '' !== $ref && hash_equals( self::status_sig( $ref ), $sig );
	}

	/**
	 * Routes a return or a retry.
	 *
	 * @return void
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Visitor returning from ifthenpay; the HMAC signature authenticates it (and retry also checks the logged-in student).
		if ( isset( $_GET[ self::ARG_REF ], $_GET[ self::ARG_ACTION ], $_GET[ self::ARG_SIG ] ) ) {
			$this->handle_return(
				sanitize_key( wp_unslash( $_GET[ self::ARG_ACTION ] ) ),
				sanitize_text_field( wp_unslash( $_GET[ self::ARG_REF ] ) ),
				sanitize_text_field( wp_unslash( $_GET[ self::ARG_SIG ] ) ),
				isset( $_GET[ self::ARG_TXN ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::ARG_TXN ] ) ) : ''
			);
		}

		if ( isset( $_GET[ self::ARG_RETRY ], $_GET[ self::ARG_SIG ] ) ) {
			$this->handle_retry(
				sanitize_text_field( wp_unslash( $_GET[ self::ARG_RETRY ] ) ),
				sanitize_text_field( wp_unslash( $_GET[ self::ARG_SIG ] ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Student back from the hosted page.
	 *
	 * @param string $action success|error|cancel.
	 * @param string $ref    Reference.
	 * @param string $sig    Signature.
	 * @param string $txn    ifthenpay transaction ID (report-only).
	 * @return void
	 */
	private function handle_return( string $action, string $ref, string $sig, string $txn ): void {
		if ( ! in_array( $action, array( self::SUCCESS, self::ERROR, self::CANCEL ), true ) || ! hash_equals( self::sign( $action . '|' . $ref ), $sig ) ) {
			return;
		}

		$row = $this->store->get( $ref );

		if ( null === $row || $row['product_id'] <= 0 ) {
			return;
		}

		// I keep the transaction ID for the entries screen only; it proves nothing.
		if ( '' !== $txn && '[TRANSACTIONID]' !== $txn && '' === $row['transaction_id'] ) {
			$this->store->record_transaction_id( $ref, substr( $txn, 0, 64 ) );
		}

		if ( TransactionStore::STATUS_PAID === $row['status'] ) {
			$this->redirect_paid( $row );
		}

		wp_safe_redirect( self::status_url( $row, $action ) );
		exit;
	}

	/**
	 * "Try again": a fresh link for the logged-in student who owns the attempt.
	 *
	 * @param string $ref Reference.
	 * @param string $sig Signature.
	 * @return void
	 */
	private function handle_retry( string $ref, string $sig ): void {
		if ( ! hash_equals( self::sign( 'retry|' . $ref ), $sig ) ) {
			return;
		}

		$row  = $this->store->get( $ref );
		$user = wp_get_current_user();

		if ( null === $row || 0 === $user->ID || $row['user_id'] !== $user->ID ) {
			return;
		}

		$product = Host::product( $row['product_id'] );

		if ( null === $product ) {
			return;
		}

		try {
			$link = $this->payments->link_for( $product, $user );
		} catch ( PaymentRefused $e ) {
			wp_safe_redirect( self::status_url( $row ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the link came from ifthenpay's API and its host was checked (pinpay.pt / ifthenpay.com).
		wp_redirect( $link );
		exit;
	}

	/**
	 * Paid: LearnDash's own success destination.
	 *
	 * @param array<string, mixed> $row Our transaction row.
	 * @return void
	 */
	private function redirect_paid( array $row ): void {
		$product = Host::product( (int) $row['product_id'] );
		$url     = null === $product ? '' : IfthenpayGateway::get_url_success( array( $product ) );

		wp_safe_redirect( '' !== $url ? $url : (string) get_permalink( (int) $row['product_id'] ) );
		exit;
	}

	/**
	 * HMAC for return URLs.
	 *
	 * @param string $data Signed data.
	 * @return string
	 */
	private static function sign( string $data ): string {
		return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) . 'iftp_ld_return' ), 0, 32 );
	}
}
