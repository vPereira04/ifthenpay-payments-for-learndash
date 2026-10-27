<?php
/**
 * Payment email sender.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Emails a student their pending payment link, from Payments' "Send payment email".
 *
 * @phpstan-type EmailPayment array{ref: string, amount: float, payment_url: string, customer_name: string, customer_email: string, product_id: int, expires_at: int}
 */
final class PaymentRequestEmail {

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Fills the template for one payment and sends it.
	 *
	 * @param array<string, mixed> $payment          The pending payment.
	 * @param string               $subject_template Subject, or '' for the default.
	 * @param string               $body_template    Body, or '' for the default.
	 * @phpstan-param EmailPayment $payment
	 * @return bool What wp_mail() returned.
	 */
	public static function send( array $payment, string $subject_template, string $body_template ): bool {
		$email   = self::render( $payment, $subject_template, $body_template );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = (string) get_option( 'admin_email' );

		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		return wp_mail( $payment['customer_email'], $email['subject'], $email['body'], $headers );
	}

	/**
	 * Fills a template for one payment, in the site's language rather than the admin's.
	 * The editor's preview runs this too, so it shows exactly what goes out.
	 *
	 * @param array<string, mixed> $payment          The payment.
	 * @param string               $subject_template Subject, or '' for the default.
	 * @param string               $body_template    Body, or '' for the default.
	 * @phpstan-param EmailPayment $payment
	 * @return array{subject: string, body: string}
	 */
	public static function render( array $payment, string $subject_template, string $body_template ): array {
		$switched = switch_to_locale( get_locale() );

		$values = self::tag_values( $payment );
		$email  = array(
			'subject' => PaymentEmailTemplate::render_subject( '' !== $subject_template ? $subject_template : PaymentEmailTemplate::default_subject(), $values ),
			'body'    => PaymentEmailTemplate::render_body( '' !== $body_template ? $body_template : PaymentEmailTemplate::default_body(), $values ),
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		return $email;
	}

	/**
	 * Tag => plain value; the template escapes each one for where it goes.
	 *
	 * @param array<string, mixed> $payment The payment.
	 * @phpstan-param EmailPayment $payment
	 * @return array<string, string>
	 */
	private static function tag_values( array $payment ): array {
		$name   = $payment['customer_name'];
		$course = $payment['product_id'] > 0 ? get_post_field( 'post_title', $payment['product_id'], 'raw' ) : '';

		return array(
			'{greeting}'                          => '' !== $name
				/* translators: %s: student name. */
				? sprintf( __( 'Hi %s,', 'ifthenpay-payments-for-learndash' ), $name )
				: __( 'Hi,', 'ifthenpay-payments-for-learndash' ),
			'{student_name}'                      => $name,
			'{amount}'                            => number_format_i18n( $payment['amount'], 2 ) . ' €',
			'{reference}'                         => $payment['ref'],
			PaymentEmailTemplate::TAG_PAYMENT_URL => $payment['payment_url'],
			'{course}'                            => is_string( $course ) && '' !== $course ? $course : __( 'your order', 'ifthenpay-payments-for-learndash' ),
			'{expires}'                           => $payment['expires_at'] > 0 ? (string) wp_date( (string) get_option( 'date_format' ), $payment['expires_at'] ) : '',
			// blogname is stored HTML-escaped; I want the plain text and escape it on output.
			'{site_name}'                         => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}
}
