<?php
/**
 * AJAX for the Payments screen.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Ajax;

use Ifthenpay\LearnDash\Admin\EntriesPage;
use Ifthenpay\LearnDash\Admin\EntryCells;
use Ifthenpay\LearnDash\Admin\PaymentEmailSettings;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Mail\PaymentRequestEmail;
use Ifthenpay\LearnDash\Payments\PaymentRefused;
use Ifthenpay\LearnDash\Payments\PaymentService;
use Ifthenpay\LearnDash\Payments\PurchaseCompleter;
use Ifthenpay\LearnDash\Payments\TransactionStore;

defined( 'ABSPATH' ) || exit;

/**
 * Every mutation on the screen: details, delete, mark as, new payment, emails, columns.
 * Each answers with the re-rendered view, so the page never reloads.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
final class EntriesController {

	// Emails are slow (SMTP, maybe an ifthenpay call each), so a request stops taking refs after this many seconds.
	private const EMAIL_TIME_BUDGET = 15;

	private const EMAIL_SENT        = 'sent';
	private const EMAIL_INELIGIBLE  = 'ineligible';
	private const EMAIL_NO_LINK     = 'no_link';
	private const EMAIL_MAIL_FAILED = 'mail_failed';

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Link service.
	 *
	 * @var PaymentService
	 */
	private $payments;

	/**
	 * Paid → LearnDash order.
	 *
	 * @var PurchaseCompleter
	 */
	private $completer;

	/**
	 * The screen, for the re-rendered view.
	 *
	 * @var EntriesPage
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore  $store     Attempts.
	 * @param PaymentService    $payments  Link service.
	 * @param PurchaseCompleter $completer Paid → LearnDash order.
	 * @param EntriesPage       $page      The screen.
	 */
	public function __construct( TransactionStore $store, PaymentService $payments, PurchaseCompleter $completer, EntriesPage $page ) {
		$this->store     = $store;
		$this->payments  = $payments;
		$this->completer = $completer;
		$this->page      = $page;

		add_action( 'wp_ajax_iftp_ld_entry_details', array( $this, 'details' ) );
		add_action( 'wp_ajax_iftp_ld_delete_entries', array( $this, 'delete' ) );
		add_action( 'wp_ajax_iftp_ld_update_status', array( $this, 'update_status' ) );
		add_action( 'wp_ajax_iftp_ld_create_entry', array( $this, 'create' ) );
		add_action( 'wp_ajax_iftp_ld_send_payment_emails', array( $this, 'send_emails' ) );
		add_action( 'wp_ajax_iftp_ld_save_columns', array( $this, 'save_columns' ) );
	}

	/**
	 * One row's details panel, fetched the first time View opens it.
	 *
	 * @return void
	 */
	public function details(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$row = $this->store->get( isset( $_POST['ref'] ) && is_string( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );

		if ( null === $row ) {
			wp_send_json_error( array( 'message' => __( 'This payment doesn’t exist anymore.', 'ifthenpay-payments-for-learndash' ) ), 404 );
		}

		wp_send_json_success( array( 'html' => $this->page->details_html( $row ) ) );
	}

	/**
	 * Deletes the given refs, except paid ones.
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->guard();

		$deleted = $this->store->delete_unpaid( $this->bulk_refs() );

		wp_send_json_success( array( 'deleted' => $deleted ) + $this->view( '' ) );
	}

	/**
	 * Bulk "Mark as". Marking paid enrols each checkout student and records the LearnDash order.
	 *
	 * @return void
	 */
	public function update_status(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$status = isset( $_POST['status'] ) && is_string( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! in_array( $status, TransactionStore::ALL_STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown status.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		$refs         = $this->bulk_refs();
		$updated      = $this->store->admin_set_status_many( $refs, $status );
		$not_recorded = array();

		if ( TransactionStore::STATUS_PAID === $status ) {
			$this->store->prime( $refs );

			foreach ( $refs as $ref ) {
				$row = $this->store->get( $ref );

				// complete() skips ad hoc rows and rows that already have their order, so a retry is safe.
				if ( null !== $row && TransactionStore::STATUS_PAID === $row['status'] && ! $this->completer->complete( $row ) ) {
					$not_recorded[] = $ref;
				}
			}
		}

		wp_send_json_success(
			array(
				'updated'     => $updated,
				'status'      => $status,
				'notRecorded' => $not_recorded,
			) + $this->view( '' )
		);
	}

	/**
	 * "New payment": an ad hoc row, optionally emailed right away.
	 *
	 * @return void
	 */
	public function create(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$amount     = isset( $_POST['amount'] ) && is_string( $_POST['amount'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0.0;
		$email      = isset( $_POST['customer_email'] ) && is_string( $_POST['customer_email'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_email'] ) ) : '';
		$name       = isset( $_POST['customer_name'] ) && is_string( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$status     = isset( $_POST['status'] ) && is_string( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) && is_string( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$send       = ! empty( $_POST['send_email'] );
		// phpcs:enable

		if ( $amount < 0.01 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Enter an amount of at least 0,01 €.', 'ifthenpay-payments-for-learndash' ),
					'field'   => 'amount',
				),
				400
			);
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Enter a valid email, or leave it blank.', 'ifthenpay-payments-for-learndash' ),
					'field'   => 'customer_email',
				),
				400
			);
		}

		if ( ! in_array( $status, TransactionStore::ALL_STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown status.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		if ( $product_id > 0 && null === Host::product( $product_id ) ) {
			$product_id = 0;
		}

		$ref = $this->store->create_manual( $name, $email, round( $amount, 2 ), $status, $product_id );

		if ( null === $ref ) {
			wp_send_json_error( array( 'message' => __( 'Couldn’t save the payment. Try again.', 'ifthenpay-payments-for-learndash' ) ), 500 );
		}

		$email_outcome = '';

		if ( $send && TransactionStore::STATUS_PENDING === $status ) {
			$email_outcome = $this->send_payment_email( $this->store->get( $ref ) );
		}

		wp_send_json_success(
			array(
				'ref'     => $ref,
				'email'   => $email_outcome,
				'message' => self::EMAIL_SENT === $email_outcome || '' === $email_outcome
					? ''
					: self::outcome_message( $email_outcome ),
			) + $this->view( 'view_' )
		);
	}

	/**
	 * "Send payment email" for a chunk of refs (one ref from a details row or the entry page).
	 * Stops taking refs once the time budget is spent; entries.js sends the rest again.
	 *
	 * @return void
	 */
	public function send_emails(): void {
		$this->guard();

		$refs    = array_slice( $this->bulk_refs(), 0, EntriesPage::EMAIL_CHUNK_SIZE );
		$started = microtime( true );
		$result  = array(
			'sent'    => array(),
			'skipped' => array(),
			'failed'  => array(),
			'message' => '',
		);

		$this->store->prime( $refs );

		foreach ( $refs as $ref ) {
			if ( microtime( true ) - $started > self::EMAIL_TIME_BUDGET ) {
				break;
			}

			$outcome = $this->send_payment_email( $this->store->get( $ref ) );

			if ( self::EMAIL_SENT === $outcome ) {
				$result['sent'][] = $ref;
			} elseif ( self::EMAIL_INELIGIBLE === $outcome ) {
				$result['skipped'][] = $ref;
			} else {
				$result['failed'][] = $ref;
				$result['message']  = self::outcome_message( $outcome );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		if ( ! empty( $_POST['render'] ) ) {
			$result += $this->view( '' );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Remembers the admin's hidden columns and their order.
	 *
	 * @return void
	 */
	public function save_columns(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$hidden = isset( $_POST['hidden'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['hidden'] ) ) : array();
		$order  = isset( $_POST['order'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['order'] ) ) : null;
		// phpcs:enable

		wp_send_json_success( $this->page->save_columns( $hidden, $order ) );
	}

	/**
	 * Emails a pending payment its link, creating the link first when it has no live one.
	 * Eligibility is re-checked here: the row may have been paid since the page loaded.
	 *
	 * @param array<string, mixed>|null $row Transaction row.
	 * @phpstan-param Transaction|null $row
	 * @return string EMAIL_*.
	 */
	private function send_payment_email( ?array $row ): string {
		if ( null === $row || ! EntryCells::can_email( $row ) ) {
			return self::EMAIL_INELIGIBLE;
		}

		try {
			$url = $this->payments->link_for_row( $row );
		} catch ( PaymentRefused $e ) {
			return self::EMAIL_NO_LINK;
		}

		$fresh    = $this->store->get( $row['ref'] );
		$template = PaymentEmailSettings::stored();
		$sent     = PaymentRequestEmail::send(
			array(
				'ref'            => $row['ref'],
				'amount'         => $row['amount'],
				'payment_url'    => $url,
				'customer_name'  => $row['customer_name'],
				'customer_email' => $row['customer_email'],
				'product_id'     => $row['product_id'],
				'expires_at'     => null !== $fresh ? $fresh['expires_at'] : $row['expires_at'],
			),
			$template['subject'],
			$template['body']
		);

		return $sent ? self::EMAIL_SENT : self::EMAIL_MAIL_FAILED;
	}

	/**
	 * What went wrong with an email, for the toast.
	 *
	 * @param string $outcome EMAIL_*.
	 * @return string
	 */
	private static function outcome_message( string $outcome ): string {
		switch ( $outcome ) {
			case self::EMAIL_INELIGIBLE:
				return __( 'This payment is no longer pending, or has no student email.', 'ifthenpay-payments-for-learndash' );
			case self::EMAIL_NO_LINK:
				return __( 'Couldn’t create a payment link. Check the ifthenpay connection, Gateway Key and methods in the settings.', 'ifthenpay-payments-for-learndash' );
			default:
				return __( 'Couldn’t send the payment email. Check that this site can send mail.', 'ifthenpay-payments-for-learndash' );
		}
	}

	/**
	 * The re-rendered view for the browser's view state.
	 *
	 * @param string $prefix '' or 'view_'.
	 * @return array<string, mixed>
	 */
	private function view( string $prefix ): array {
		return $this->page->rendered_view( $this->page->view_state_from_request( $prefix ) );
	}

	/**
	 * The posted refs, refusing an oversized batch.
	 *
	 * @return array<int, string>
	 */
	private function bulk_refs(): array {
		$refs = EntriesPage::refs_from_request();

		if ( count( $refs ) > EntriesPage::BULK_MAX_REFS ) {
			wp_send_json_error(
				array(
					/* translators: %s: maximum payments per request. */
					'message' => sprintf( __( 'Too many payments in one request (the limit is %s).', 'ifthenpay-payments-for-learndash' ), number_format_i18n( EntriesPage::BULK_MAX_REFS ) ),
				),
				400
			);
		}

		return $refs;
	}

	/**
	 * Nonce plus LearnDash's admin capability.
	 *
	 * @return void
	 */
	private function guard(): void {
		check_ajax_referer( EntriesPage::NONCE_ACTION, 'nonce' );

		if ( ! Host::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-learndash' ) ), 403 );
		}
	}
}
