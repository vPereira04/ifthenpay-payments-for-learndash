<?php
/**
 * Payment email template: default copy, tags and sanitizing.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * The editable "Send payment email" template.
 *
 * The admin writes HTML with {tags}. I sanitize it on save and again on send, then swap the tags for escaped
 * values in one pass, so nothing a student typed can turn into markup or into another tag.
 */
final class PaymentEmailTemplate {

	public const TAG_PAYMENT_URL = '{payment_url}';

	public const MAX_BODY_LENGTH    = 100000;
	public const MAX_SUBJECT_LENGTH = 255;

	// Dropped with their contents, since kses would leave e.g. a script's code behind as visible text.
	private const STRIPPED_ELEMENTS = 'script|style|head|title|noscript|template|iframe|object|embed|textarea|select|svg|math';

	// Inline CSS kses doesn't allow by default but email layouts rely on.
	private const EXTRA_SAFE_CSS = array( 'word-break', 'overflow-wrap', 'word-wrap', 'mso-line-height-rule' );

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Tag => what it becomes, for the editor's tag list.
	 *
	 * @return array<string, string>
	 */
	public static function tags(): array {
		return array(
			'{greeting}'          => __( '"Hi Ana," or just "Hi," when there’s no name', 'ifthenpay-payments-for-learndash' ),
			'{student_name}'      => __( 'Student name', 'ifthenpay-payments-for-learndash' ),
			'{amount}'            => __( 'Amount, e.g. 12,50 €', 'ifthenpay-payments-for-learndash' ),
			'{reference}'         => __( 'Payment reference', 'ifthenpay-payments-for-learndash' ),
			self::TAG_PAYMENT_URL => __( 'Payment link, for a button or link href (required)', 'ifthenpay-payments-for-learndash' ),
			'{course}'            => __( 'Course or group name', 'ifthenpay-payments-for-learndash' ),
			'{expires}'           => __( 'Last day the link can be paid', 'ifthenpay-payments-for-learndash' ),
			'{site_name}'         => __( 'Site name', 'ifthenpay-payments-for-learndash' ),
		);
	}

	/**
	 * The default subject, in the current locale.
	 *
	 * @return string
	 */
	public static function default_subject(): string {
		/* translators: %s: site name tag. */
		return sprintf( __( 'Complete your payment to %s', 'ifthenpay-payments-for-learndash' ), '{site_name}' );
	}

	/**
	 * The default body, in the current locale.
	 *
	 * Inline styles and tables only, since that's all most mail clients render. I write the styles the way
	 * kses re-serializes them, so saving the default untouched doesn't look like an edit.
	 *
	 * @return string
	 */
	public static function default_body(): string {
		$pay_link = '<a href="{payment_url}" style="color:#73738c;text-decoration:underline">' . esc_html__( 'Open the payment link', 'ifthenpay-payments-for-learndash' ) . '</a>';

		/* translators: 1: course or group name tag, 2: site name tag. */
		$intro = sprintf( esc_html__( 'You started paying for %1$s on %2$s but didn’t finish. You can pick it back up in a few seconds.', 'ifthenpay-payments-for-learndash' ), '<strong>{course}</strong>', '{site_name}' );
		/* translators: %s: formatted amount tag. */
		$pay_label = sprintf( esc_html__( 'Pay %s', 'ifthenpay-payments-for-learndash' ), '{amount}' );
		/* translators: %s: "Open the payment link", linked to the payment page. */
		$fallback = sprintf( esc_html__( 'Button not working? %s', 'ifthenpay-payments-for-learndash' ), $pay_link );
		/* translators: %s: date tag. */
		$expires = sprintf( esc_html__( 'You can pay until %s.', 'ifthenpay-payments-for-learndash' ), '{expires}' );

		return '<div style="margin:0;padding:40px 12px;background-color:#f7f9fc;font-family:-apple-system,BlinkMacSystemFont,&apos;Segoe UI&apos;,Helvetica,Arial,sans-serif;color:#0e0e2c">
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:520px;margin:0 auto;border-collapse:separate">
		<tr>
			<td style="background-color:#ffffff;border:1px solid #dce1e8;border-radius:8px;padding:36px 32px">
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;border-collapse:separate">
					<tr>
						<td style="padding:6px 12px 6px 10px;background-color:#f9eec8;border:1px solid #f4e29f;border-radius:4px;font-size:12px;font-weight:600;color:#2c3338;line-height:1">' . esc_html__( 'Payment pending', 'ifthenpay-payments-for-learndash' ) . '</td>
					</tr>
				</table>
				<h1 style="margin:0 0 12px;font-size:26px;line-height:1.25;font-weight:700;color:#0e0e2c">' . esc_html__( 'Finish your payment', 'ifthenpay-payments-for-learndash' ) . '</h1>
				<p style="margin:0 0 6px;font-size:15px;line-height:1.65;color:#0e0e2c">{greeting}</p>
				<p style="margin:0 0 30px;font-size:15px;line-height:1.65;color:#4a4a68">' . $intro . '</p>
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse">
					<tr>
						<td style="padding:22px 0 4px;border-top:1px solid #dce1e8;font-size:13px;color:#4a4a68">' . esc_html__( 'Total', 'ifthenpay-payments-for-learndash' ) . '</td>
					</tr>
					<tr>
						<td style="padding:0 0 22px;font-size:40px;line-height:1.1;font-weight:700;color:#0e0e2c">{amount}</td>
					</tr>
					<tr>
						<td style="padding:0">
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse">
								<tr>
									<td style="padding:14px 0;border-top:1px solid #dce1e8;border-bottom:1px solid #dce1e8;font-size:13px;color:#4a4a68">' . esc_html__( 'Reference', 'ifthenpay-payments-for-learndash' ) . '</td>
									<td align="right" style="padding:14px 0;border-top:1px solid #dce1e8;border-bottom:1px solid #dce1e8;font-family:Consolas,Menlo,monospace;font-size:13px;font-weight:600;color:#0e0e2c">{reference}</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:30px 0 0;border-collapse:separate">
					<tr>
						<td align="center" bgcolor="#235af3" style="background-color:#235af3;border-radius:6px">
							<a href="{payment_url}" style="display:block;padding:16px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px">' . $pay_label . ' &rarr;</a>
						</td>
					</tr>
				</table>
				<p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#4a4a68;text-align:center">' . $expires . ' ' . esc_html__( 'Secured by ifthenpay.', 'ifthenpay-payments-for-learndash' ) . '</p>
			</td>
		</tr>
		<tr>
			<td style="padding:24px 8px 0;font-size:12px;line-height:1.7;color:#4a4a68;text-align:center">
				' . esc_html__( 'Already paid? You can ignore this email.', 'ifthenpay-payments-for-learndash' ) . '<br />
				' . $fallback . '
			</td>
		</tr>
	</table>
</div>';
	}

	/**
	 * Cleans admin-written email HTML down to what's safe and makes sense in a mail client.
	 *
	 * @param string $html Raw template.
	 * @return string
	 */
	public static function sanitize_body( string $html ): string {
		$html = (string) preg_replace( '#<(' . self::STRIPPED_ELEMENTS . ')\b[^>]*>.*?</\1\s*>#is', '', $html );
		// An unclosed one would otherwise leave its code behind once kses drops the tag.
		$html = (string) preg_replace( '#<(' . self::STRIPPED_ELEMENTS . ')\b[^>]*>.*$#is', '', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', '', $html );

		add_filter( 'safe_style_css', array( self::class, 'allow_email_css' ) );
		$clean = wp_kses( $html, self::allowed_html(), self::allowed_protocols() );
		remove_filter( 'safe_style_css', array( self::class, 'allow_email_css' ) );

		return trim( $clean );
	}

	/**
	 * Cleans the subject to one plain-text line.
	 *
	 * @param string $subject Raw subject.
	 * @return string
	 */
	public static function sanitize_subject( string $subject ): string {
		return mb_substr( sanitize_text_field( $subject ), 0, self::MAX_SUBJECT_LENGTH );
	}

	/**
	 * Whether sanitizing removed something, ignoring the way kses re-spaces styles and quotes.
	 *
	 * @param string $raw   What the admin wrote.
	 * @param string $clean What sanitize_body() made of it.
	 * @return bool
	 */
	public static function lost_markup( string $raw, string $clean ): bool {
		$normalize = static function ( string $html ): string {
			return strtolower( (string) preg_replace( '/[\s;"\']+/', '', $html ) );
		};

		return $normalize( $raw ) !== $normalize( $clean );
	}

	/**
	 * Fills the body's tags.
	 *
	 * @param string                $template Body template.
	 * @param array<string, string> $values   Tag => plain value; I escape each for HTML here.
	 * @return string
	 */
	public static function render_body( string $template, array $values ): string {
		$replacements = array();

		foreach ( $values as $tag => $value ) {
			$replacements[ $tag ] = self::TAG_PAYMENT_URL === $tag ? esc_url( $value ) : esc_html( $value );
		}

		// strtr() is a single pass, so a value that contains "{payment_url}" stays literal text.
		return strtr( self::sanitize_body( $template ), $replacements );
	}

	/**
	 * Fills the subject's tags.
	 *
	 * @param string                $template Subject template.
	 * @param array<string, string> $values   Tag => plain value.
	 * @return string
	 */
	public static function render_subject( string $template, array $values ): string {
		// One line, so nothing a student typed can break into the mail headers.
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( strtr( self::sanitize_subject( $template ), $values ) ) ) );
	}

	/**
	 * Adds the email-layout CSS properties to kses's list, only while I sanitize.
	 *
	 * @param mixed $properties Allowed CSS properties.
	 * @return array<int, string>
	 */
	public static function allow_email_css( $properties ): array {
		return array_merge( is_array( $properties ) ? $properties : array(), self::EXTRA_SAFE_CSS );
	}

	/**
	 * Mail-client markup only: no scripts, forms, embeds or event handlers.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed_html(): array {
		$common = array(
			'style' => true,
			'class' => true,
			'id'    => true,
			'title' => true,
			'dir'   => true,
			'lang'  => true,
			'align' => true,
			'role'  => true,
		);
		$cell   = $common + array(
			'width'   => true,
			'height'  => true,
			'colspan' => true,
			'rowspan' => true,
			'bgcolor' => true,
			'valign'  => true,
			'nowrap'  => true,
			'scope'   => true,
		);

		$tags = array_fill_keys(
			array( 'abbr', 'b', 'big', 'blockquote', 'br', 'caption', 'center', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'li', 'p', 'pre', 's', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'tbody', 'tfoot', 'thead', 'u' ),
			$common
		);

		$tags['a']     = $common + array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
			'name'   => true,
		);
		$tags['img']   = $common + array(
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
			'border' => true,
		);
		$tags['hr']    = $common + array(
			'width' => true,
			'size'  => true,
			'color' => true,
		);
		$tags['font']  = $common + array(
			'color' => true,
			'face'  => true,
			'size'  => true,
		);
		$tags['ol']    = $common + array(
			'start' => true,
			'type'  => true,
		);
		$tags['ul']    = $common + array( 'type' => true );
		$tags['table'] = $common + array(
			'width'       => true,
			'height'      => true,
			'border'      => true,
			'cellpadding' => true,
			'cellspacing' => true,
			'bgcolor'     => true,
		);
		$tags['tr']    = $common + array(
			'bgcolor' => true,
			'valign'  => true,
			'height'  => true,
		);
		$tags['td']    = $cell;
		$tags['th']    = $cell;

		return $tags;
	}

	/**
	 * Link/image protocols an email may use.
	 *
	 * @return array<int, string>
	 */
	private static function allowed_protocols(): array {
		return array( 'http', 'https', 'mailto', 'tel' );
	}
}
