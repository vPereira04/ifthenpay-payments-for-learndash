<?php
/**
 * Payment status on the course/group page.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Frontend;

use Ifthenpay\LearnDash\Api\ReturnHandler;
use Ifthenpay\LearnDash\Gateway\IfthenpayGateway;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Payments\TransactionStore;
use Ifthenpay\LearnDash\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * LearnDash has no "order received" page, so the course page itself tells the
 * student where their payment stands, in LearnDash's own alert (classic or
 * modern), and keeps checking until it's paid.
 *
 * It shows after a return from ifthenpay (signed URL), and on any later visit
 * while the student still has a live link for this course.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
final class StatusBlock {

	public const AJAX_ACTION = 'iftp_ld_status';

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Whether the block already printed on this request (classic and modern hooks can both fire).
	 *
	 * @var bool
	 */
	private $printed = false;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore $store Attempts.
	 */
	public function __construct( TransactionStore $store ) {
		$this->store = $store;

		add_action( 'learndash-course-certificate-link-after', array( $this, 'render_classic' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- LearnDash's own hook name.
		add_action( 'learndash_group_certificate_link_after', array( $this, 'render_classic' ) );
		add_action( 'learndash_template_after_include:modern/components/alerts', array( $this, 'render_modern' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'status' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'status' ) );
	}

	/**
	 * Classic LD30 course/group template.
	 *
	 * @param mixed $post_id Course or group ID.
	 * @return void
	 */
	public function render_classic( $post_id = 0 ): void {
		$this->render( (int) $post_id, false );
	}

	/**
	 * Modern LD30 header, right after LearnDash's own alerts.
	 *
	 * @return void
	 */
	public function render_modern(): void {
		$post_id = (int) get_the_ID();

		if ( ! in_array( get_post_type( $post_id ), array( 'sfwd-courses', 'groups' ), true ) ) {
			return;
		}

		$this->render( $post_id, true );
	}

	/**
	 * AJAX: where a payment stands, for the polling script.
	 *
	 * @return void
	 */
	public function status(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only; the per-reference HMAC from ReturnHandler authorises it (the page may be cached, so no nonce).
		$ref = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$row = ReturnHandler::is_status_signed( $ref, $sig ) ? $this->store->get( $ref ) : null;

		if ( null === $row ) {
			wp_send_json_error( array( 'message' => 'Unknown payment' ), 404 );
		}

		wp_send_json_success(
			array(
				'state'      => self::state( $row, '' ),
				'successUrl' => self::success_url( $row ),
			)
		);
	}

	/**
	 * Prints the block when there's something to say.
	 *
	 * @param int  $post_id Course or group ID.
	 * @param bool $modern  Modern markup.
	 * @return void
	 */
	private function render( int $post_id, bool $modern ): void {
		if ( $this->printed || $post_id <= 0 ) {
			return;
		}

		$found = $this->row_for( $post_id );

		if ( null === $found ) {
			return;
		}

		$row   = $found['row'];
		$state = self::state( $row, $found['hint'] );

		if ( 'paid' === $state ) {
			return;
		}

		$this->printed = true;
		$this->enqueue( $row, $modern );

		$attrs = ' data-ref="' . esc_attr( $row['ref'] ) . '"'
			. ' data-sig="' . esc_attr( ReturnHandler::status_sig( (string) $row['ref'] ) ) . '"'
			. ' data-state="' . esc_attr( $state ) . '"'
			. ' data-theme="' . ( $modern ? 'modern' : 'classic' ) . '"';

		echo '<div class="iftp-ld-status" id="iftp-ld-status"' . $attrs . ( 'checking' === $state ? ' aria-live="polite"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute is escaped above.

		if ( 'checking' === $state ) {
			echo '<div class="iftp-ld-status__checking ld-loading" aria-busy="true"><span class="screen-reader-text">' . esc_html__( 'Checking your payment…', 'ifthenpay-payments-for-learndash' ) . '</span></div>';
		} else {
			echo $modern ? self::modern_alert( $row, $state ) : self::classic_alert( $row, $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}

		echo '</div>';
	}

	/**
	 * The row to show for this page: a signed return, or the student's live link.
	 *
	 * @param int $post_id Course or group ID.
	 * @return array{row: array<string, mixed>, hint: string}|null
	 */
	private function row_for( int $post_id ): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; the HMAC authenticates the reference.
		if ( isset( $_GET[ ReturnHandler::ARG_STATUS ], $_GET[ ReturnHandler::ARG_SIG ] ) ) {
			$ref  = sanitize_text_field( wp_unslash( $_GET[ ReturnHandler::ARG_STATUS ] ) );
			$sig  = sanitize_text_field( wp_unslash( $_GET[ ReturnHandler::ARG_SIG ] ) );
			$hint = isset( $_GET[ ReturnHandler::ARG_ACTION ] ) ? sanitize_key( wp_unslash( $_GET[ ReturnHandler::ARG_ACTION ] ) ) : '';
			$row  = ReturnHandler::is_status_signed( $ref, $sig ) ? $this->store->get( $ref ) : null;

			if ( null !== $row && $row['product_id'] === $post_id ) {
				return array(
					'row'  => $row,
					'hint' => $hint,
				);
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$live = $this->store->live_for( get_current_user_id(), $post_id );

		return null === $live ? null : array(
			'row'  => $live,
			'hint' => '',
		);
	}

	/**
	 * Display state for a row.
	 *
	 * @param array<string, mixed> $row  Our row.
	 * @param string               $hint What the student came back from.
	 * @return string checking|confirming|pending|failed|cancelled|expired|paid
	 */
	private static function state( array $row, string $hint ): string {
		$status = (string) $row['status'];

		if ( TransactionStore::STATUS_PENDING === $status && (int) $row['expires_at'] > 0 && (int) $row['expires_at'] < time() ) {
			$status = TransactionStore::STATUS_EXPIRED;
		}

		if ( TransactionStore::STATUS_PENDING === $status && ReturnHandler::SUCCESS === $hint ) {
			return 'checking';
		}

		return in_array( $status, TransactionStore::ALL_STATUSES, true ) ? $status : TransactionStore::STATUS_PENDING;
	}

	/**
	 * Classic LD30 alert (LearnDash's modules/alert.php shape).
	 *
	 * @param array<string, mixed> $row   Our row.
	 * @param string               $state Display state.
	 * @return string
	 */
	private static function classic_alert( array $row, string $state ): string {
		$copy   = self::copy( $row )[ $state ];
		$type   = 'confirming' === $state ? 'success' : 'warning';
		$icon   = in_array( $state, array( 'confirming', 'pending' ), true ) ? 'clock' : 'alert';
		$action = self::action( $row, $state );

		return '<div class="ld-alert ld-alert-' . esc_attr( $type ) . '" role="status">'
			. '<div class="ld-alert-content">'
			. '<div class="ld-alert-icon ld-icon ld-icon-' . esc_attr( $icon ) . '" aria-hidden="true"></div>'
			. '<div class="ld-alert-messages">' . esc_html( $copy ) . '</div>'
			. '</div>'
			. ( null === $action ? '' : '<a class="ld-button" href="' . esc_url( $action['url'] ) . '"><span class="ld-icon ld-icon-arrow-right" aria-hidden="true"></span>' . esc_html( $action['label'] ) . '</a>' )
			. '</div>';
	}

	/**
	 * Modern LD30 alert (LearnDash's modern/components/alerts/alert shape).
	 *
	 * @param array<string, mixed> $row   Our row.
	 * @param string               $state Display state.
	 * @return string
	 */
	private static function modern_alert( array $row, string $state ): string {
		$look   = self::modern_look()[ $state ];
		$action = self::action( $row, $state );

		return '<div class="ld-alert ld-alert--modern ld-alert--' . esc_attr( $look['type'] ) . ( null === $action ? '' : ' ld-alert--action-button' ) . '" role="status">'
			. '<div class="ld-alert__icon ld-alert__icon--' . esc_attr( $look['type'] ) . '">' . self::icon( $look['icon'], 'ld-alert__icon-svg' ) . '</div>'
			. '<div class="ld-alert__content">'
			. '<span class="ld-alert__message">' . esc_html( self::copy( $row )[ $state ] ) . '</span>'
			. ( null === $action ? '' : '<div class="ld-alert__action ld-alert__action--button"><a class="ld-alert__button" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . self::icon( 'caret-right', 'ld-alert__button-icon' ) . '</a></div>' )
			. '</div>'
			. '</div>';
	}

	/**
	 * Modern alert type and icon per state.
	 *
	 * @return array<string, array{type: string, icon: string}>
	 */
	private static function modern_look(): array {
		return array(
			'confirming' => array(
				'type' => 'info',
				'icon' => 'clock',
			),
			'pending'    => array(
				'type' => 'warning',
				'icon' => 'clock',
			),
			'failed'     => array(
				'type' => 'error',
				'icon' => 'error',
			),
			'cancelled'  => array(
				'type' => 'warning',
				'icon' => 'warning-2',
			),
			'expired'    => array(
				'type' => 'warning',
				'icon' => 'warning-2',
			),
		);
	}

	/**
	 * Messages per state.
	 *
	 * @param array<string, mixed> $row Our row.
	 * @return array<string, string>
	 */
	private static function copy( array $row ): array {
		$label = IfthenpayGateway::product_label( (int) $row['product_id'] );

		return array(
			'confirming' => __( 'Payment received. We’re confirming it with ifthenpay, and this page will update on its own.', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: "course" or "group", LearnDash's own label. */
			'pending'    => sprintf( __( 'We’re waiting for your payment. If you’ve already paid, your %s unlocks as soon as ifthenpay confirms it.', 'ifthenpay-payments-for-learndash' ), $label ),
			'failed'     => __( 'Your payment didn’t go through. You can try again.', 'ifthenpay-payments-for-learndash' ),
			'cancelled'  => __( 'You cancelled the payment. You can try again whenever you’re ready.', 'ifthenpay-payments-for-learndash' ),
			'expired'    => __( 'This payment link has expired. Start a new payment to enrol.', 'ifthenpay-payments-for-learndash' ),
		);
	}

	/**
	 * The one action a state offers.
	 *
	 * @param array<string, mixed> $row   Our row.
	 * @param string               $state Display state.
	 * @return array{label: string, url: string}|null
	 */
	private static function action( array $row, string $state ): ?array {
		if ( 'pending' === $state && '' !== $row['payment_url'] ) {
			return array(
				'label' => __( 'Continue payment', 'ifthenpay-payments-for-learndash' ),
				'url'   => (string) $row['payment_url'],
			);
		}

		if ( in_array( $state, array( 'failed', 'cancelled', 'expired' ), true ) && is_user_logged_in() ) {
			return array(
				'label' => __( 'Try again', 'ifthenpay-payments-for-learndash' ),
				'url'   => ReturnHandler::retry_url( $row ),
			);
		}

		return null;
	}

	/**
	 * Script, styles and the strings the poller swaps in.
	 *
	 * @param array<string, mixed> $row    Our row.
	 * @param bool                 $modern Modern markup.
	 * @return void
	 */
	private function enqueue( array $row, bool $modern ): void {
		wp_enqueue_style( 'iftp-ld-checkout', IFTP_LD_URL . 'assets/css/checkout.css', array(), Plugin::asset_version( 'assets/css/checkout.css' ) );
		wp_enqueue_script( 'iftp-ld-status', IFTP_LD_URL . 'assets/js/status.js', array(), Plugin::asset_version( 'assets/js/status.js' ), true );

		$looks = array();

		foreach ( array( 'confirming', 'pending', 'failed', 'cancelled', 'expired' ) as $state ) {
			$looks[ $state ] = array(
				'html' => $modern ? self::modern_alert( $row, $state ) : self::classic_alert( $row, $state ),
			);
		}

		wp_localize_script(
			'iftp-ld-status',
			'iftpLdStatus',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => self::AJAX_ACTION,
				'successUrl' => self::success_url( $row ),
				'states'     => $looks,
				'i18n'       => array(
					'refresh' => __( 'Refresh the page to check again.', 'ifthenpay-payments-for-learndash' ),
				),
			)
		);
	}

	/**
	 * Where a paid student goes: LearnDash's own success chain.
	 *
	 * @param array<string, mixed> $row Our row.
	 * @return string
	 */
	private static function success_url( array $row ): string {
		$product = Host::product( (int) $row['product_id'] );
		$url     = null === $product ? '' : IfthenpayGateway::get_url_success( array( $product ) );

		return '' !== $url ? $url : (string) get_permalink( (int) $row['product_id'] );
	}

	/**
	 * A LearnDash icon template.
	 *
	 * @param string $name  Icon name.
	 * @param string $extra_class Extra class.
	 * @return string
	 */
	private static function icon( string $name, string $extra_class ): string {
		if ( ! class_exists( '\LearnDash\Core\Template\Template' ) ) {
			return '';
		}

		return \LearnDash\Core\Template\Template::get_template(
			'components/icons/' . $name,
			array(
				'classes'        => array( $extra_class ),
				'is_aria_hidden' => true,
			)
		);
	}
}
