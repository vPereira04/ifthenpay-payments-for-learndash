<?php
/**
 * The enabled methods' logo strip.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Frontend;

use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Bare logos (no chip around them), starred default first.
 *
 * Each logo carries LearnDash's `ld-svglogo` class, so the modern registration
 * page greys it out and colours it in exactly like Stripe's logo. checkout.js
 * swaps in the dark variant on dark backgrounds.
 *
 * @phpstan-import-type Snapshot from SettingsRepository
 */
final class Logos {

	private const MAX_VISIBLE = 4;

	/**
	 * The strip, or '' when no method has a logo.
	 *
	 * @param array  $methods  Enabled methods.
	 * @phpstan-param list<Snapshot> $methods
	 * @param string $modifier Optional BEM modifier, e.g. `button`.
	 * @return string
	 */
	public static function html( array $methods, string $modifier = '' ): string {
		usort(
			$methods,
			static function ( array $a, array $b ): int {
				return (int) $b['is_default'] <=> (int) $a['is_default'];
			}
		);

		$methods = array_values(
			array_filter(
				$methods,
				static function ( array $method ): bool {
					return '' !== $method['logo'];
				}
			)
		);

		if ( array() === $methods ) {
			return '';
		}

		$visible = array_slice( $methods, 0, self::MAX_VISIBLE );
		$hidden  = array_slice( $methods, self::MAX_VISIBLE );
		$html    = '<span class="iftp-ld-logos' . ( '' !== $modifier ? ' iftp-ld-logos--' . sanitize_html_class( $modifier ) : '' ) . '" role="list" aria-label="' . esc_attr__( 'Payment methods', 'ifthenpay-payments-for-learndash' ) . '">';

		foreach ( $visible as $method ) {
			$html .= '<img role="listitem" class="ld-svglogo iftp-ld-logos__logo" src="' . esc_url( $method['logo'] ) . '"'
				. ( '' !== $method['logo_dark'] ? ' data-logo-dark="' . esc_url( $method['logo_dark'] ) . '"' : '' )
				. ' alt="' . esc_attr( self::label( $method ) ) . '" height="20" decoding="async" />';
		}

		if ( array() !== $hidden ) {
			$names = implode( ', ', array_map( array( self::class, 'label' ), $hidden ) );

			/* translators: 1: number of further methods, 2: their names. */
			$html .= '<span role="listitem" class="iftp-ld-logos__more" aria-label="' . esc_attr( sprintf( __( 'And %1$d more: %2$s', 'ifthenpay-payments-for-learndash' ), count( $hidden ), $names ) ) . '">+' . count( $hidden ) . '</span>';
		}

		return $html . '</span>';
	}

	/**
	 * A method's readable name.
	 *
	 * @param array<string, mixed> $method Method.
	 * @return string
	 */
	private static function label( array $method ): string {
		return '' !== ( $method['alias'] ?? '' ) ? (string) $method['alias'] : (string) ( $method['entity'] ?? '' );
	}
}
