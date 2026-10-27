<?php
/**
 * Pay by Link payload shaping.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Pure transformations for the PBL request; nothing here reads WordPress state.
 *
 * @phpstan-import-type Snapshot from \Ifthenpay\LearnDash\Repository\SettingsRepository
 */
final class IfthenpayPayload {

	/**
	 * Two decimals, period, no thousands separator.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function format_amount( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * `ENTITY|ACCOUNT;…` for the enabled methods.
	 *
	 * @param array $rows Enabled methods.
	 * @phpstan-param list<Snapshot> $rows
	 * @return string
	 */
	public static function accounts( array $rows ): string {
		$parts = array();

		foreach ( $rows as $row ) {
			// ifthenpay's Gateway Key rows already hold "MB | EDT-501474" (or "11686 | 000"), so I only tidy the spaces.
			// A bare account (the Mobile API fallback) still needs its entity in front.
			$account = (string) preg_replace( '/\s*\|\s*/', '|', trim( $row['account'] ) );

			if ( '' === $account ) {
				continue;
			}

			$parts[] = false !== strpos( $account, '|' ) ? $account : $row['entity'] . '|' . $account;
		}

		return implode( ';', $parts );
	}

	/**
	 * Catalog position of the starred method, as ifthenpay wants it ('' when none).
	 *
	 * @param array $rows Enabled methods.
	 * @phpstan-param list<Snapshot> $rows
	 * @return string
	 */
	public static function selected_method( array $rows ): string {
		foreach ( $rows as $row ) {
			if ( $row['is_default'] && $row['position'] > 0 ) {
				return (string) $row['position'];
			}
		}

		return '';
	}

	/**
	 * Hosted page language from a WordPress locale.
	 *
	 * @param string $locale Locale, e.g. pt_PT.
	 * @return string
	 */
	public static function language( string $locale ): string {
		$prefix = strtolower( substr( $locale, 0, 2 ) );

		return in_array( $prefix, array( 'pt', 'es', 'fr' ), true ) ? $prefix : 'en';
	}

	/**
	 * Builds the PBL body.
	 *
	 * @param array<string, string> $data id, amount, description, accounts, success_url, error_url, cancel_url, lang, selected_method, expiredate, email.
	 * @return array<string, string>
	 */
	public static function pay_by_link( array $data ): array {
		$payload = array(
			'id'          => $data['id'],
			'amount'      => $data['amount'],
			'description' => mb_substr( $data['description'], 0, 200 ),
			'accounts'    => $data['accounts'],
			'success_url' => $data['success_url'],
			'error_url'   => $data['error_url'],
			'cancel_url'  => $data['cancel_url'],
			'otp'         => 'true',
			'lang'        => $data['lang'],
		);

		foreach ( array( 'selected_method', 'expiredate', 'email' ) as $optional ) {
			if ( isset( $data[ $optional ] ) && '' !== $data[ $optional ] ) {
				$payload[ $optional ] = $data[ $optional ];
			}
		}

		return $payload;
	}
}
