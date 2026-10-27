<?php
/**
 * The LearnDash payment gateway.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Gateway;

use Ifthenpay\LearnDash\Frontend\Logos;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Payments\PaymentRefused;
use Ifthenpay\LearnDash\Payments\PaymentService;
use Ifthenpay\LearnDash\Payments\TransactionStore;
use Ifthenpay\LearnDash\Plugin;
use Ifthenpay\LearnDash\Repository\SettingsRepository;
use LearnDash\Core\Models\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Pay by Link inside LearnDash's own payment buttons and checkout.
 *
 * LearnDash creates an order only for a confirmed payment, so the button never
 * records anything in LearnDash: it opens a link (our table keeps the attempt),
 * and complete_purchase() turns a paid attempt into a real LearnDash order, the
 * same way LearnDash's own Stripe gateway does it from its webhook.
 *
 * The webhook itself runs from WebhookController on every request, not from
 * process_webhook(): LearnDash only hooks that while the gateway is ready, and
 * links already sent must still confirm after the merchant switches it off.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
class IfthenpayGateway extends \Learndash_Payment_Gateway {

	public const NAME = 'ifthenpay';

	/**
	 * Settings Section Key used to configure this gateway (drives get_settings_url()).
	 *
	 * @var string
	 */
	protected string $settings_section_key = 'settings_ifthenpay';

	/**
	 * Our settings.
	 *
	 * @var SettingsRepository
	 */
	private $repository;

	/**
	 * Link creation.
	 *
	 * @var PaymentService
	 */
	private $payments;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $repository Settings.
	 * @param PaymentService     $payments   Link creation.
	 */
	public function __construct( SettingsRepository $repository, PaymentService $payments ) {
		$this->repository = $repository;
		$this->payments   = $payments;

		parent::__construct();
	}

	/**
	 * Gateway slug, stored on every LearnDash order as `ld_payment_processor`.
	 *
	 * @return string
	 */
	public static function get_name(): string {
		return self::NAME;
	}

	/**
	 * Gateway label in LearnDash's lists.
	 *
	 * @return string
	 */
	public static function get_label(): string {
		return 'ifthenpay';
	}

	/**
	 * No extra hooks: Plugin wires the services.
	 *
	 * @return void
	 */
	public function add_extra_hooks(): void {
	}

	/**
	 * Checkout script, once LearnDash printed one of our buttons.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_script(
			'iftp-ld-checkout',
			IFTP_LD_URL . 'assets/js/checkout.js',
			array( 'jquery', 'learndash-payments' ),
			Plugin::asset_version( 'assets/js/checkout.js' ),
			true
		);

		wp_localize_script(
			'iftp-ld-checkout',
			'iftpLdCheckout',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'error' => __( 'We couldn’t open the ifthenpay payment page. Please try again in a moment.', 'ifthenpay-payments-for-learndash' ),
				),
			)
		);

		wp_enqueue_style( 'iftp-ld-checkout', IFTP_LD_URL . 'assets/css/checkout.css', array(), Plugin::asset_version( 'assets/css/checkout.css' ) );
	}

	/**
	 * AJAX: a payment link for the clicked course or group.
	 *
	 * @return void
	 */
	public function setup_payment(): void {
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( '' === $nonce || 0 === $post_id || ! wp_verify_nonce( $nonce, $this->get_nonce_name() ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Your session expired. Please reload the page and try again.', 'ifthenpay-payments-for-learndash' ) ), 403 );
		}

		$user    = wp_get_current_user();
		$product = Host::product( $post_id );

		if ( 0 === $user->ID ) {
			wp_send_json_error( array( 'message' => esc_html( sprintf( /* translators: %s: "course" or "group", LearnDash's own label. */ __( 'Log in to buy this %s.', 'ifthenpay-payments-for-learndash' ), self::product_label( $post_id ) ) ) ), 401 );
		}

		if ( null === $product ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This course is no longer available.', 'ifthenpay-payments-for-learndash' ) ), 404 );
		}

		try {
			$url = $this->payments->link_for( $product, $user );
		} catch ( PaymentRefused $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 409 );
		}

		wp_send_json_success( array( 'redirect_url' => $url ) );
	}

	/**
	 * LearnDash's lower-case label for a product: "course", "group", or the site's own wording.
	 *
	 * @param int $post_id Course or group ID.
	 * @return string
	 */
	public static function product_label( int $post_id ): string {
		$key = 'groups' === get_post_type( $post_id ) ? 'group' : 'course';

		return function_exists( 'learndash_get_custom_label_lower' ) ? (string) learndash_get_custom_label_lower( $key ) : $key;
	}

	/**
	 * Loads our settings.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->settings = $this->repository->all();
	}

	/**
	 * Whether LearnDash may show our button.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return $this->repository->is_ready();
	}

	/**
	 * No sandbox per Gateway Key on ifthenpay's side; every order is live.
	 *
	 * @return bool
	 */
	protected function is_test_mode(): bool {
		return false;
	}

	/**
	 * The button: a form our checkout.js submits over AJAX, like LearnDash's Razorpay one.
	 *
	 * @param array<mixed> $params Button params.
	 * @param \WP_Post     $post   Course or group.
	 * @return string
	 */
	protected function map_payment_button_markup( array $params, \WP_Post $post ): string {
		$product = Host::product( (int) $post->ID );

		// Recurring prices need LearnDash's renewal processor, which we don't run.
		if ( null === $product || ! $product->is_price_type_paynow() ) {
			return '';
		}

		$label = $this->map_payment_button_label( __( 'Use ifthenpay', 'ifthenpay-payments-for-learndash' ), $post );
		$info  = $this->get_checkout_info_text( (string) $product->get_type() );

		return '<form class="' . esc_attr( $this->get_form_class_name() ) . '" method="post"'
			. ' data-action="' . esc_attr( $this->get_ajax_action_name_setup() ) . '"'
			. ' data-nonce="' . esc_attr( wp_create_nonce( $this->get_nonce_name() ) ) . '"'
			. ' data-post_id="' . esc_attr( (string) $post->ID ) . '">'
			. '<button type="submit" class="' . esc_attr( \Learndash_Payment_Button::map_button_class_name() ) . '" id="' . esc_attr( \Learndash_Payment_Button::map_button_id() ) . '" aria-label="' . esc_attr( $label . '. ' . $info ) . '">'
			. esc_html( $label )
			. '</button>'
			. Logos::html( $this->repository->snapshot(), 'button' )
			. '<p class="iftp-ld-checkout__error" role="alert" hidden></p>'
			. '</form>';
	}

	/**
	 * Label on LearnDash's checkout method picker.
	 *
	 * @return string
	 */
	public function get_checkout_label(): string {
		return __( 'ifthenpay', 'ifthenpay-payments-for-learndash' );
	}

	/**
	 * The enabled methods' logos, bare, next to the option.
	 *
	 * @return string
	 */
	public function get_checkout_meta_html(): string {
		return Logos::html( $this->repository->snapshot() );
	}

	/**
	 * Text under the option.
	 *
	 * @param string $product_type `course` or `group`.
	 * @return string
	 */
	public function get_checkout_info_text( string $product_type ): string {
		return $this->repository->checkout_info( $product_type );
	}

	/**
	 * LearnDash hooks this only while we're ready; WebhookController handles callbacks on every request instead.
	 *
	 * @return void
	 */
	public function process_webhook(): void {
	}

	/**
	 * LearnDash's order meta for a paid attempt.
	 *
	 * @param mixed   $data    Our transaction row.
	 * @param Product $product Course or group.
	 * @return \Learndash_Transaction_Meta_DTO
	 * @throws \Learndash_DTO_Validation_Exception When LearnDash rejects the meta.
	 */
	protected function map_transaction_meta( $data, Product $product ): \Learndash_Transaction_Meta_DTO {
		$row      = is_array( $data ) ? $data : array();
		$snapshot = TransactionStore::decode_snapshot( (string) ( $row['pricing_snapshot'] ?? '' ) );
		$pricing  = $snapshot['pricing'] ?? $product->get_pricing( (int) ( $row['user_id'] ?? 0 ) )->to_array();

		$pricing['currency'] = 'EUR';

		return \Learndash_Transaction_Meta_DTO::create(
			array(
				'ld_payment_processor' => self::NAME,
				'is_test_mode'         => false,
				'price_type'           => LEARNDASH_PRICE_TYPE_PAYNOW,
				'pricing_info'         => \Learndash_Pricing_DTO::create( $pricing ),
				'gateway_transaction'  => \Learndash_Transaction_Gateway_Transaction_DTO::create(
					array(
						'id'          => (string) ( '' !== ( $row['request_id'] ?? '' ) ? $row['request_id'] : ( $row['ref'] ?? '' ) ),
						'customer_id' => '',
						'event'       => array(
							'ref'            => (string) ( $row['ref'] ?? '' ),
							'method'         => (string) ( $row['pay_method'] ?? '' ),
							'request_id'     => (string) ( $row['request_id'] ?? '' ),
							'transaction_id' => (string) ( $row['transaction_id'] ?? '' ),
							'amount'         => (float) ( $row['amount'] ?? 0 ),
						),
					)
				),
				'has_trial'            => false,
				'has_free_trial'       => false,
			)
		);
	}

	/**
	 * Turns a paid attempt into access plus a LearnDash order.
	 *
	 * @param array<string, mixed> $row Our transaction row, already marked paid.
	 * @phpstan-param Transaction $row
	 * @return int Parent order ID, or 0 when there's no student or course to record it for.
	 */
	public function complete_purchase( array $row ): int {
		$user    = $row['user_id'] > 0 ? get_user_by( 'id', $row['user_id'] ) : false;
		$product = Host::product( $row['product_id'] );

		if ( ! $user instanceof \WP_User || null === $product ) {
			return 0;
		}

		$snapshot = TransactionStore::decode_snapshot( $row['pricing_snapshot'] );

		// LearnDash records and redeems the coupon from its day-long transient; a late Multibanco payment outlives it.
		Host::reattach_coupon( $product->get_id(), $user->ID, $snapshot['coupon'] ?? array() );

		$this->add_access_to_products( array( $product ), $user );

		// One order per attempt, even when one request completes several (bulk "Mark as paid").
		$this->parent_transaction_id = 0;

		try {
			$meta = $this->map_transaction_meta( $row, $product )->to_array();
		} catch ( \Learndash_DTO_Validation_Exception $e ) {
			$this->log_error( 'ifthenpay: LearnDash rejected the order data for ' . $row['ref'] . ': ' . $e->getMessage() );
			return 0;
		}

		$this->record_transaction( $meta, $product->get_post(), $user );

		$order_id = $this->parent_transaction_id;

		$this->log_info( 'ifthenpay: ' . $row['ref'] . ' recorded as order #' . $order_id . '.' );

		return $order_id;
	}
}
