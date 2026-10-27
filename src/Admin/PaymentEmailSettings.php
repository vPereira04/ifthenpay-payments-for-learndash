<?php
/**
 * Payment email template editor, opened from Payments.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Mail\PaymentEmailTemplate;
use Ifthenpay\LearnDash\Mail\PaymentRequestEmail;

defined( 'ABSPATH' ) || exit;

/**
 * The "Payment email" dialog: subject, HTML body, tags and a live preview. The template lives in its own option.
 */
final class PaymentEmailSettings {

	public const OPTION = 'iftp_ld_payment_email';

	// Debounce for the live preview, in ms.
	private const PREVIEW_DELAY = 400;

	/**
	 * Registers the AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_iftp_ld_save_payment_email', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_iftp_ld_preview_payment_email', array( $this, 'ajax_preview' ) );
	}

	/**
	 * The stored subject and body; '' means the default (so it follows the site language).
	 *
	 * @return array{subject: string, body: string}
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return array(
			'subject' => is_array( $stored ) && is_string( $stored['subject'] ?? null ) ? $stored['subject'] : '',
			'body'    => is_array( $stored ) && is_string( $stored['body'] ?? null ) ? $stored['body'] : '',
		);
	}

	/**
	 * Loads WP's CodeMirror and the media modal, and returns what entries.js needs.
	 * The editor is false when the admin turned syntax highlighting off; the plain textarea stays then.
	 *
	 * @return array{editor: array<string, mixed>|false, defaults: array{subject: string, body: string}, previewDelay: int}
	 */
	public function enqueue(): array {
		$editor = wp_enqueue_code_editor(
			array(
				'type'       => 'text/html',
				'codemirror' => array(
					'lineWrapping' => true,
					// Tab moves focus instead of indenting, so keyboard users aren't stuck in the editor.
					'extraKeys'    => array(
						'Tab'       => false,
						'Shift-Tab' => false,
					),
				),
			)
		);

		// For "Add image", so a logo from the Media Library can go in the email.
		wp_enqueue_media();

		return array(
			'editor'       => $editor,
			'defaults'     => $this->defaults(),
			'previewDelay' => self::PREVIEW_DELAY,
		);
	}

	/**
	 * The editor dialog's content. jQuery UI turns it into the dialog and adds Cancel / Save template.
	 *
	 * @return void
	 */
	public function render_dialog(): void {
		$template = $this->current();
		?>
		<div id="iftp-ld-email-dialog" class="iftp-ld-email" title="<?php esc_attr_e( 'Payment email', 'ifthenpay-payments-for-learndash' ); ?>" hidden>
			<form class="iftp-ld-dialog__form iftp-ld-email__form" data-iftp-email-form novalidate>
				<p class="iftp-ld-dialog__intro"><?php esc_html_e( 'What students get from "Send payment email". Click a tag to drop a payment detail where your cursor is; it’s filled in for each student when the email goes out.', 'ifthenpay-payments-for-learndash' ); ?></p>
				<p class="iftp-ld-dialog__error" data-iftp-email-error role="alert" hidden><span class="dashicons dashicons-warning" aria-hidden="true"></span><span data-iftp-email-error-text></span></p>

				<div class="iftp-ld-email__editor">
					<div class="iftp-ld-email__compose">
						<label class="iftp-ld-dialog__label" for="iftp-ld-email-subject"><?php esc_html_e( 'Subject', 'ifthenpay-payments-for-learndash' ); ?></label>
						<input type="text" id="iftp-ld-email-subject" class="regular-text iftp-ld-dialog__input" name="subject" maxlength="<?php echo esc_attr( (string) PaymentEmailTemplate::MAX_SUBJECT_LENGTH ); ?>" value="<?php echo esc_attr( $template['subject'] ); ?>" data-iftp-email-subject />

						<div class="iftp-ld-email__tags" role="group" aria-labelledby="iftp-ld-email-tags-label">
							<span id="iftp-ld-email-tags-label" class="iftp-ld-dialog__label"><?php esc_html_e( 'Insert a tag', 'ifthenpay-payments-for-learndash' ); ?></span>
							<ul class="iftp-ld-chips iftp-ld-email__tag-list">
								<?php foreach ( PaymentEmailTemplate::tags() as $tag => $description ) : ?>
									<li><button type="button" class="iftp-ld-chip iftp-ld-chip--button" data-iftp-email-tag="<?php echo esc_attr( $tag ); ?>" data-iftp-email-tag-help="<?php echo esc_attr( $description ); ?>" title="<?php echo esc_attr( $description ); ?>"><?php echo esc_html( $tag ); ?></button></li>
								<?php endforeach; ?>
							</ul>
							<p class="description iftp-ld-email__tag-help" data-iftp-email-tag-desc aria-live="polite"><?php esc_html_e( 'Hover or focus a tag to see what it becomes.', 'ifthenpay-payments-for-learndash' ); ?></p>
						</div>

						<div class="iftp-ld-email__body">
							<div class="iftp-ld-email__body-head">
								<label class="iftp-ld-dialog__label" for="iftp-ld-email-body"><?php esc_html_e( 'Body (HTML)', 'ifthenpay-payments-for-learndash' ); ?></label>
								<button type="button" class="button button-small" data-iftp-email-media hidden><span class="dashicons dashicons-format-image" aria-hidden="true"></span> <?php esc_html_e( 'Add image', 'ifthenpay-payments-for-learndash' ); ?></button>
							</div>
							<textarea id="iftp-ld-email-body" name="body" rows="16" spellcheck="false" data-iftp-email-body><?php echo esc_textarea( $template['body'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Inline styles and tables work best in mail clients. Scripts, forms, embeds and event handlers are removed when you save.', 'ifthenpay-payments-for-learndash' ); ?></p>
							<p><button type="button" class="button-link" data-iftp-email-reset><?php esc_html_e( 'Reset to default', 'ifthenpay-payments-for-learndash' ); ?></button></p>
						</div>
					</div>

					<div class="iftp-ld-email__preview">
						<p class="iftp-ld-email__preview-head">
							<span class="iftp-ld-dialog__label"><?php esc_html_e( 'Preview', 'ifthenpay-payments-for-learndash' ); ?></span>
							<span class="description"><?php esc_html_e( 'With sample data', 'ifthenpay-payments-for-learndash' ); ?></span>
						</p>
						<div class="iftp-ld-email__frame" data-iftp-email-preview-wrap>
							<p class="iftp-ld-email__preview-subject"><strong><?php esc_html_e( 'Subject:', 'ifthenpay-payments-for-learndash' ); ?></strong> <span data-iftp-email-preview-subject></span></p>
							<?php // No allow-scripts: whatever the HTML holds, nothing in the preview can run. ?>
							<iframe class="iftp-ld-email__iframe" sandbox="" title="<?php esc_attr_e( 'Email preview', 'ifthenpay-payments-for-learndash' ); ?>" data-iftp-email-preview></iframe>
						</div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX: saves the template after sanitizing it, and sends back what was stored.
	 *
	 * @return void
	 */
	public function ajax_save(): void {
		$this->guard();

		$posted  = $this->posted();
		$subject = PaymentEmailTemplate::sanitize_subject( $posted['subject'] );
		$body    = PaymentEmailTemplate::sanitize_body( $posted['body'] );

		if ( '' === $subject ) {
			wp_send_json_error( array( 'message' => __( 'Enter a subject.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		if ( '' === $body ) {
			wp_send_json_error( array( 'message' => __( 'The email body is empty.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		if ( false === strpos( $body, PaymentEmailTemplate::TAG_PAYMENT_URL ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: the {payment_url} tag. */
					'message' => sprintf( __( 'Add the %s tag to the body (as a button’s link, for example), or the student has no way to pay.', 'ifthenpay-payments-for-learndash' ), PaymentEmailTemplate::TAG_PAYMENT_URL ),
				),
				400
			);
		}

		$defaults = $this->defaults();

		// The default is stored as '', so it keeps following the site language.
		update_option(
			self::OPTION,
			array(
				'subject' => $subject === $defaults['subject'] ? '' : $subject,
				'body'    => $body === $defaults['body'] ? '' : $body,
			),
			false
		);

		wp_send_json_success(
			array(
				'subject' => $subject,
				'body'    => $body,
				'message' => PaymentEmailTemplate::lost_markup( $posted['body'], $body )
					? __( 'Payment email saved. Some HTML that isn’t allowed in emails was removed; the editor now shows what will be sent.', 'ifthenpay-payments-for-learndash' )
					: __( 'Payment email saved.', 'ifthenpay-payments-for-learndash' ),
			)
		);
	}

	/**
	 * AJAX: renders the unsaved template with sample data, through the same path a real send takes.
	 *
	 * @return void
	 */
	public function ajax_preview(): void {
		$this->guard();

		$posted = $this->posted();
		$email  = PaymentRequestEmail::render(
			array(
				'ref'            => 'LD_123_7Q2K9X',
				'amount'         => 49.0,
				// Inert: the preview frame is sandboxed.
				'payment_url'    => home_url( '/#ifthenpay-payment-link' ),
				'customer_name'  => 'Ana Silva',
				'customer_email' => 'ana.silva@example.com',
				'product_id'     => 0,
				'expires_at'     => time() + 3 * DAY_IN_SECONDS,
			),
			// An emptied field previews as blank rather than falling back to the default.
			'' !== trim( $posted['subject'] ) ? $posted['subject'] : ' ',
			'' !== trim( $posted['body'] ) ? str_replace( '{course}', __( 'Intro to Python', 'ifthenpay-payments-for-learndash' ), $posted['body'] ) : ' '
		);

		wp_send_json_success(
			array(
				'subject' => $email['subject'],
				'html'    => '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">' . $email['body'] . '</body></html>',
			)
		);
	}

	/**
	 * The saved template, with the defaults filled in for whatever isn't customized.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function current(): array {
		$defaults = $this->defaults();
		$stored   = self::stored();

		return array(
			'subject' => '' !== $stored['subject'] ? $stored['subject'] : $defaults['subject'],
			'body'    => '' !== $stored['body'] ? $stored['body'] : $defaults['body'],
		);
	}

	/**
	 * The default template in the site's language, the one students read.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function defaults(): array {
		$switched = switch_to_locale( get_locale() );

		$defaults = array(
			'subject' => PaymentEmailTemplate::default_subject(),
			'body'    => PaymentEmailTemplate::sanitize_body( PaymentEmailTemplate::default_body() ),
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		return $defaults;
	}

	/**
	 * The posted subject and body, unsanitized; callers sanitize for their own use.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified the nonce; the body is HTML, so PaymentEmailTemplate sanitizes it with its own kses allow-list.
		$subject = isset( $_POST['subject'] ) && is_string( $_POST['subject'] ) ? wp_unslash( $_POST['subject'] ) : '';
		$body    = isset( $_POST['body'] ) && is_string( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '';
		// phpcs:enable

		if ( strlen( $body ) > PaymentEmailTemplate::MAX_BODY_LENGTH ) {
			wp_send_json_error( array( 'message' => __( 'The email body is too long.', 'ifthenpay-payments-for-learndash' ) ), 400 );
		}

		return array(
			'subject' => $subject,
			'body'    => str_replace( "\r\n", "\n", $body ),
		);
	}

	/**
	 * Same nonce and capability check as the rest of Payments.
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
