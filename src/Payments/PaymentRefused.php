<?php
/**
 * Customer-facing refusal.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when an order can't get a payment link; the message is safe HTML for the customer.
 */
final class PaymentRefused extends \RuntimeException {
}
