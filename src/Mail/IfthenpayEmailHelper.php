<?php
/**
 * Activation request emails to ifthenpay support.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the structured "please activate this method" email.
 */
final class IfthenpayEmailHelper {

	public const SUPPORT_EMAIL = 'suporte@ifthenpay.com';

	/**
	 * Sends an activation request.
	 *
	 * @param array<string, string> $data gateway_key, entity, backoffice_key, customer_email, site_url, site_name, wp_version, plugin_version, learndash_version.
	 * @return bool What wp_mail() returned.
	 */
	public static function send_activation_email( array $data ): bool {
		/**
		 * Filters where activation requests go.
		 *
		 * @param string                $email Recipient.
		 * @param array<string, string> $data  Request data.
		 */
		$to = (string) apply_filters( 'iftp_ld_support_email', self::SUPPORT_EMAIL, $data );

		if ( ! is_email( $to ) ) {
			$to = self::SUPPORT_EMAIL;
		}

		$entity  = strtoupper( $data['entity'] ?? '' );
		$subject = '[dev_ifthenpay] [' . $entity . ']: Ativacao de Servico';

		$rows = array(
			'Gateway Key'      => $data['gateway_key'] ?? '',
			'Método'           => $entity,
			'Backoffice Key'   => $data['backoffice_key'] ?? '',
			'Email do cliente' => $data['customer_email'] ?? '',
			'Site'             => $data['site_name'] ?? '',
			'URL'              => $data['site_url'] ?? '',
			'WordPress'        => $data['wp_version'] ?? '',
			'LearnDash'        => $data['learndash_version'] ?? '',
			'Versão do plugin' => $data['plugin_version'] ?? '',
		);

		$table = '';
		foreach ( $rows as $label => $value ) {
			$table .= '<tr>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #eef0f3;color:#6b7280;font-size:13px;white-space:nowrap;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #eef0f3;color:#111827;font-size:13px;font-weight:600;">' . esc_html( $value ) . '</td>'
				. '</tr>';
		}

		$body = '<div style="background:#f4f6f8;padding:24px;font-family:Arial,Helvetica,sans-serif;">'
			. '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
			. '<div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;">'
			. '<h2 style="margin:0;font-size:18px;color:#111827;">Pedido de ativação de serviço</h2>'
			. '<p style="margin:8px 0 0;font-size:14px;color:#4b5563;">Um cliente pediu a ativação do método <strong>' . esc_html( $entity ) . '</strong> na sua Gateway Key (LearnDash LMS).</p>'
			. '</div>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $table . '</table>'
			. '<p style="margin:0;padding:16px 24px;font-size:12px;color:#9ca3af;">Email enviado automaticamente pelo plugin ifthenpay | Payments for LearnDash.</p>'
			. '</div>'
			. '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( ! empty( $data['customer_email'] ) && is_email( $data['customer_email'] ) ) {
			$headers[] = 'Reply-To: ' . $data['customer_email'];
		}

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}
}
