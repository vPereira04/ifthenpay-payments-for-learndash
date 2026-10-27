<?php
/**
 * Pay by Link creation with the one-live-link rule.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Payments;

use Ifthenpay\LearnDash\Api\IfthenpayClient;
use Ifthenpay\LearnDash\Api\IfthenpayPayload;
use Ifthenpay\LearnDash\Api\ReturnHandler;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Repository\SettingsRepository;
use LearnDash\Core\Models\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Hands out the payment link for a student and a course or group.
 *
 * A Pay by Link can't be revoked, so every link I create stays payable
 * until it's paid or expires. That's why there is only one live link per student
 * and product: I reuse it while the price matches, refuse while another price is
 * still payable, and only then create a new one, all under a lock.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
final class PaymentService {

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
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings.
	 * @param IfthenpayClient    $client   API client.
	 * @param TransactionStore   $store    Attempts.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client, TransactionStore $store ) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->store    = $store;
	}

	/**
	 * The payment link for a student and a product: the live one, or a new one.
	 *
	 * @param Product  $product Course or group.
	 * @param \WP_User $user    Student.
	 * @return string pinpay URL.
	 * @throws PaymentRefused When there can't be a link now; the message is safe HTML for the student.
	 */
	public function link_for( Product $product, \WP_User $user ): string {
		if ( ! $this->settings->is_ready() ) {
			throw new PaymentRefused( esc_html__( 'ifthenpay is not available right now. Please choose another payment method.', 'ifthenpay-payments-for-learndash' ) );
		}

		if ( ! $product->is_price_type_paynow() ) {
			throw new PaymentRefused( esc_html__( 'ifthenpay can’t be used for this course’s price type.', 'ifthenpay-payments-for-learndash' ) );
		}

		if ( $product->user_has_access( $user ) ) {
			/* translators: %s: "course" or "group", LearnDash's own label. */
			throw new PaymentRefused( esc_html( sprintf( __( 'You’re already enrolled in this %s.', 'ifthenpay-payments-for-learndash' ), self::label( $product ) ) ) );
		}

		if ( ! $product->can_be_purchased( $user ) ) {
			throw new PaymentRefused( esc_html__( 'This course can’t be purchased right now.', 'ifthenpay-payments-for-learndash' ) );
		}

		$price = (float) $product->get_final_price( $user );

		// A 100% coupon goes through LearnDash's own free enrolment, never through a gateway.
		if ( $price <= 0 ) {
			throw new PaymentRefused( esc_html__( 'There is nothing to pay for this course.', 'ifthenpay-payments-for-learndash' ) );
		}

		$amount = IfthenpayPayload::format_amount( $price );
		$lock   = 'link|u' . $user->ID . '|p' . $product->get_id();

		if ( ! Locks::acquire( $lock ) ) {
			throw new PaymentRefused( esc_html__( 'Your payment is being prepared. Please wait a moment and try again.', 'ifthenpay-payments-for-learndash' ) );
		}

		try {
			$live = $this->store->live_for( $user->ID, $product->get_id() );

			if ( null !== $live ) {
				if ( IfthenpayPayload::format_amount( $live['amount'] ) === $amount ) {
					return $live['payment_url'];
				}

				throw new PaymentRefused( $this->in_progress_message( $live ) );
			}

			return $this->create_link( $product, $user, $price );
		} finally {
			Locks::release( $lock );
		}
	}

	/**
	 * A link for an existing row that has none yet (admin "Send payment email").
	 *
	 * @param array<string, mixed> $row Our transaction row.
	 * @phpstan-param Transaction $row
	 * @return string pinpay URL.
	 * @throws PaymentRefused When ifthenpay isn't set up or refuses.
	 */
	public function link_for_row( array $row ): string {
		if ( ! $this->settings->is_connected() || '' === $this->settings->gateway_key() || array() === $this->settings->snapshot() ) {
			throw new PaymentRefused( esc_html__( 'Connect ifthenpay and save a Gateway Key with at least one method first.', 'ifthenpay-payments-for-learndash' ) );
		}

		if ( TransactionStore::STATUS_PENDING === $row['status'] && '' !== $row['payment_url'] && $row['expires_at'] > time() ) {
			return $row['payment_url'];
		}

		$expiry = $this->expiry();
		$url    = $this->request_link( $row['ref'], IfthenpayPayload::format_amount( $row['amount'] ), $row['customer_email'], $expiry['day'] );

		$this->store->record_payment_link( $row['ref'], $url, $this->settings->gateway_key(), $expiry['at'] );

		return $url;
	}

	/**
	 * "Payment in progress" notice for a live link.
	 *
	 * @param array<string, mixed> $row The live row.
	 * @phpstan-param Transaction $row
	 * @return string Safe HTML.
	 */
	public function in_progress_message( array $row ): string {
		return sprintf(
			/* translators: 1: opening link tag, 2: closing link tag, 3: date the payment link expires. */
			esc_html__( 'You already have a payment in progress for this course. %1$sContinue payment%2$s (open until %3$s).', 'ifthenpay-payments-for-learndash' ),
			'<a href="' . esc_url( $row['payment_url'] ) . '">',
			'</a>',
			esc_html( (string) wp_date( (string) get_option( 'date_format' ), $row['expires_at'] ) )
		);
	}

	/**
	 * LearnDash's lower-case product label.
	 *
	 * @param Product $product Course or group.
	 * @return string
	 */
	private static function label( Product $product ): string {
		$key = 'groups' === get_post_type( $product->get_id() ) ? 'group' : 'course';

		return function_exists( 'learndash_get_custom_label_lower' ) ? (string) learndash_get_custom_label_lower( $key ) : $key;
	}

	/**
	 * Creates a new checkout link and records the attempt.
	 *
	 * @param Product  $product Course or group.
	 * @param \WP_User $user    Student.
	 * @param float    $price   Final price (coupon applied).
	 * @return string
	 * @throws PaymentRefused When ifthenpay refuses.
	 */
	private function create_link( Product $product, \WP_User $user, float $price ): string {
		$product_id = $product->get_id();
		$ref        = $this->store->unique_ref( 'LD_' . $product_id . '_', 6 );
		$expiry     = $this->expiry();
		$url        = $this->request_link( $ref, IfthenpayPayload::format_amount( $price ), (string) $user->user_email, $expiry['day'] );

		$pricing = $product->get_pricing( $user )->to_array();

		$stored = $this->store->store_pending(
			array(
				'ref'            => $ref,
				'product_id'     => $product_id,
				'product_type'   => TransactionStore::product_type( $product_id ),
				'user_id'        => (int) $user->ID,
				'gateway_key'    => $this->settings->gateway_key(),
				'amount'         => $price,
				'payment_url'    => $url,
				'expires_at'     => $expiry['at'],
				'customer_name'  => trim( (string) $user->display_name ),
				'customer_email' => (string) $user->user_email,
				'snapshot'       => array(
					'pricing' => $pricing,
					'coupon'  => Host::attached_coupon( $product_id, (int) $user->ID ),
				),
			)
		);

		// Without the row the webhook couldn't confirm this payment, so I don't hand the link out.
		if ( ! $stored ) {
			throw new PaymentRefused( esc_html__( 'We couldn’t open the ifthenpay payment page. Please try again in a moment.', 'ifthenpay-payments-for-learndash' ) );
		}

		return $url;
	}

	/**
	 * Asks ifthenpay for a Pay by Link.
	 *
	 * @param string $ref    Reference.
	 * @param string $amount Formatted amount.
	 * @param string $email  Payer email.
	 * @param string $day    Last valid day, Ymd.
	 * @return string
	 * @throws PaymentRefused When ifthenpay refuses.
	 */
	private function request_link( string $ref, string $amount, string $email, string $day ): string {
		$snapshot = $this->settings->snapshot();
		$payload  = IfthenpayPayload::pay_by_link(
			array(
				'id'              => $ref,
				'amount'          => $amount,
				'description'     => $this->settings->pbl_description(),
				'accounts'        => IfthenpayPayload::accounts( $snapshot ),
				'success_url'     => ReturnHandler::url( ReturnHandler::SUCCESS, $ref ),
				'error_url'       => ReturnHandler::url( ReturnHandler::ERROR, $ref ),
				'cancel_url'      => ReturnHandler::url( ReturnHandler::CANCEL, $ref ),
				'lang'            => IfthenpayPayload::language( determine_locale() ),
				'selected_method' => IfthenpayPayload::selected_method( $snapshot ),
				'expiredate'      => $day,
				'email'           => (string) sanitize_email( $email ),
			)
		);

		$result = $this->client->create_pay_by_link( $this->settings->gateway_key(), $payload );

		if ( ! $result['ok'] ) {
			error_log( 'ifthenpay Pay by Link failed for ' . $ref . ': ' . $result['error'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server-side diagnostics only; the student sees the generic notice below.

			throw new PaymentRefused( esc_html__( 'We couldn’t open the ifthenpay payment page. Please try again in a moment.', 'ifthenpay-payments-for-learndash' ) );
		}

		return $result['url'];
	}

	/**
	 * Last valid day (site timezone) and the matching end-of-day timestamp.
	 *
	 * @return array{day: string, at: int}
	 */
	private function expiry(): array {
		$days     = $this->settings->all()['expiry_days'];
		$last_day = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . $days . ' days' );

		return array(
			'day' => $last_day->format( 'Ymd' ),
			'at'  => $last_day->setTime( 23, 59, 59 )->getTimestamp(),
		);
	}
}
