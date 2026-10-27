<?php
/**
 * Paid attempt → LearnDash access and order.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Payments;

use Ifthenpay\LearnDash\Gateway\IfthenpayGateway;
use Ifthenpay\LearnDash\Host;

defined( 'ABSPATH' ) || exit;

/**
 * The one path from "paid" to a LearnDash order, for the webhook and the admin's "Mark as paid".
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
final class PurchaseCompleter {

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Returns the gateway (it only exists once LearnDash loaded its payment classes).
	 *
	 * @var callable(): ?IfthenpayGateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore              $store   Attempts.
	 * @param callable(): ?IfthenpayGateway $gateway Gateway factory.
	 */
	public function __construct( TransactionStore $store, callable $gateway ) {
		$this->store   = $store;
		$this->gateway = $gateway;
	}

	/**
	 * Enrols the student and records the LearnDash order for a paid row.
	 *
	 * @param array<string, mixed> $row Our row, already marked paid.
	 * @phpstan-param Transaction $row
	 * @return bool False only when LearnDash should have recorded it and didn't (the caller retries).
	 */
	public function complete( array $row ): bool {
		// Already recorded (a repeat), or nothing to record for: an ad hoc payment, or a student or course that's gone.
		if ( $row['order_id'] > 0 || $row['user_id'] <= 0 || null === Host::product( $row['product_id'] ) || ! get_user_by( 'id', $row['user_id'] ) ) {
			return true;
		}

		$gateway = ( $this->gateway )();

		if ( null === $gateway ) {
			return false;
		}

		$order_id = $gateway->complete_purchase( $row );

		if ( $order_id <= 0 ) {
			return false;
		}

		$this->store->record_order_id( $row['ref'], $order_id );

		return true;
	}
}
