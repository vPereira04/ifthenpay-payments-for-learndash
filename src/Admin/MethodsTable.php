<?php
/**
 * Live methods table, in LearnDash's settings table markup.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Ajax\SettingsController;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One row per method on the Gateway Key: LearnDash switch, LearnDash star, logo, account, status.
 *
 * @phpstan-import-type MethodRow from SettingsRepository
 */
final class MethodsTable {

	/**
	 * The whole table.
	 *
	 * @param array  $rows        Method rows.
	 * @phpstan-param list<MethodRow> $rows
	 * @param string $gateway_key Gateway Key the rows belong to.
	 * @return string
	 */
	public static function html( array $rows, string $gateway_key ): string {
		return '<table class="learndash-settings-table widefat striped iftp-ld-methods" id="iftp-ld-methods" aria-describedby="iftp-ld-methods-hint" aria-busy="false" data-iftp-ld-gateway="' . esc_attr( $gateway_key ) . '">'
			/* translators: %s: Gateway Key. */
			. '<caption class="screen-reader-text" tabindex="-1">' . esc_html( sprintf( __( 'Methods for Gateway Key %s', 'ifthenpay-payments-for-learndash' ), $gateway_key ) ) . '</caption>'
			. '<thead><tr>'
			. '<th scope="col" class="iftp-ld-methods__col iftp-ld-methods__col--enabled">' . esc_html__( 'Enabled', 'ifthenpay-payments-for-learndash' ) . '</th>'
			. '<th scope="col" class="iftp-ld-methods__col iftp-ld-methods__col--default">' . esc_html__( 'Default', 'ifthenpay-payments-for-learndash' ) . '</th>'
			. '<th scope="col" class="iftp-ld-methods__col iftp-ld-methods__col--method">' . esc_html__( 'Method', 'ifthenpay-payments-for-learndash' ) . '</th>'
			. '<th scope="col" class="iftp-ld-methods__col iftp-ld-methods__col--account">' . esc_html__( 'Account', 'ifthenpay-payments-for-learndash' ) . '</th>'
			. '<th scope="col" class="iftp-ld-methods__col iftp-ld-methods__col--status">' . esc_html__( 'Status', 'ifthenpay-payments-for-learndash' ) . '</th>'
			. '</tr></thead>'
			. self::tbody( $rows, $gateway_key )
			. '</table>'
			. '<p id="iftp-ld-methods-hint" class="screen-reader-text">' . esc_html__( 'The star marks the default method. Only enabled methods can be the default.', 'ifthenpay-payments-for-learndash' ) . '</p>';
	}

	/**
	 * The `<tbody>`, also returned alone when the Gateway Key changes.
	 *
	 * @param array  $rows        Method rows.
	 * @phpstan-param list<MethodRow> $rows
	 * @param string $gateway_key Gateway Key.
	 * @return string
	 */
	public static function tbody( array $rows, string $gateway_key ): string {
		if ( '' === $gateway_key ) {
			return self::message_row( esc_html__( 'Choose a Gateway Key to see its payment methods.', 'ifthenpay-payments-for-learndash' ) );
		}

		if ( array() === $rows ) {
			return self::message_row(
				esc_html__( 'We couldn’t load the methods for this Gateway Key. Try again in a moment.', 'ifthenpay-payments-for-learndash' )
				. ' <button type="button" class="button-link" data-iftp-ld-retry>' . esc_html__( 'Try again', 'ifthenpay-payments-for-learndash' ) . '</button>'
			);
		}

		$provisioned = array_filter(
			$rows,
			static function ( array $row ): bool {
				return $row['provisioned'];
			}
		);

		$html = '<tbody>';

		if ( array() === $provisioned ) {
			$html .= '<tr class="iftp-ld-methods__row iftp-ld-methods__row--message"><td colspan="5">' . esc_html__( 'This Gateway Key has no payment methods yet. Ask ifthenpay to add some, then reload this page.', 'ifthenpay-payments-for-learndash' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$html .= self::row( $row, $gateway_key );
		}

		return $html . '</tbody>';
	}

	/**
	 * One method row.
	 *
	 * @param array<string, mixed> $row         Row.
	 * @phpstan-param MethodRow $row
	 * @param string               $gateway_key Gateway Key.
	 * @return string
	 */
	private static function row( array $row, string $gateway_key ): string {
		$entity   = $row['entity'];
		$label    = '' !== $row['alias'] ? $row['alias'] : $entity;
		$name     = SettingsRepository::OPTION;
		$check_id = 'iftp-ld-method-' . strtolower( $entity );
		$star_id  = 'iftp-ld-default-' . strtolower( $entity );

		$switch = '<span class="ld-switch">'
			. '<input type="checkbox" class="ld-switch__input iftp-ld-methods__enable" id="' . esc_attr( $check_id ) . '" name="' . esc_attr( $name ) . '[methods][]" value="' . esc_attr( $entity ) . '"'
			. ( $row['enabled'] ? ' checked' : '' )
			. ( $row['provisioned'] ? '' : ' disabled' ) . ' />'
			. '<span class="ld-switch__track"></span><span class="ld-switch__thumb"></span><span class="ld-switch__on-off"></span>'
			. '</span>'
			/* translators: %s: payment method name. */
			. '<label class="screen-reader-text" for="' . esc_attr( $check_id ) . '">' . esc_html( sprintf( __( 'Enable %s', 'ifthenpay-payments-for-learndash' ), $label ) ) . '</label>';

		$star = '<input type="radio" class="screen-reader-text iftp-ld-methods__default" id="' . esc_attr( $star_id ) . '" name="' . esc_attr( $name ) . '[default_method]" value="' . esc_attr( $entity ) . '"'
			. ( $row['is_default'] ? ' checked' : '' )
			. ( $row['enabled'] ? '' : ' disabled' ) . ' />'
			. '<label for="' . esc_attr( $star_id ) . '" class="iftp-ld-methods__star' . ( $row['enabled'] ? '' : ' iftp-ld-methods__star--hidden' ) . '">'
			. self::icon( 'star', 'iftp-ld-methods__star-on' )
			. self::icon( 'star-blank', 'iftp-ld-methods__star-off' )
			/* translators: %s: payment method name. */
			. '<span class="screen-reader-text">' . esc_html( sprintf( __( 'Make %s the default method', 'ifthenpay-payments-for-learndash' ), $label ) ) . '</span>'
			. '</label>';

		$logo = '' !== $row['logo']
			? '<img class="iftp-ld-methods__logo" src="' . esc_url( $row['logo'] ) . '" alt="" loading="lazy" decoding="async" />'
			: '';

		$method = '<span class="iftp-ld-methods__method">'
			. '<span class="iftp-ld-methods__logo-slot">' . $logo . '</span>'
			. '<span class="iftp-ld-methods__label">' . esc_html( $label ) . '</span>'
			. '</span>';

		$account = $row['provisioned']
			? '<span class="iftp-ld-methods__account">' . esc_html( (string) preg_replace( '/\s*\|\s*/', ' | ', $row['account'] ) ) . '</span>'
			: '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'No account', 'ifthenpay-payments-for-learndash' ) . '</span>';

		if ( $row['provisioned'] ) {
			$status = '<span class="iftp-ld-methods__status iftp-ld-methods__status--active"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' . esc_html__( 'Activated', 'ifthenpay-payments-for-learndash' ) . '</span>';
		} else {
			$requested = SettingsController::is_cooling_down( $gateway_key, $entity );
			$status    = '<span class="iftp-ld-methods__activation">'
				. '<span class="iftp-ld-methods__status iftp-ld-methods__status--inactive">' . esc_html__( 'Not activated', 'ifthenpay-payments-for-learndash' ) . '</span>'
				. '<button type="button" class="button iftp-ld-methods__request" data-entity="' . esc_attr( $entity ) . '" data-label="' . esc_attr( $label ) . '"' . ( $requested ? ' disabled' : '' ) . '>'
				. ( $requested ? esc_html__( 'Requested', 'ifthenpay-payments-for-learndash' ) : esc_html__( 'Request activation', 'ifthenpay-payments-for-learndash' ) )
				. '</button>'
				. '</span>';
		}

		return '<tr class="iftp-ld-methods__row' . ( $row['provisioned'] ? '' : ' iftp-ld-methods__row--unprovisioned' ) . '" data-entity="' . esc_attr( $entity ) . '">'
			. '<td class="iftp-ld-methods__cell iftp-ld-methods__cell--enabled col-valign-middle">' . $switch . '</td>'
			. '<td class="iftp-ld-methods__cell iftp-ld-methods__cell--default col-valign-middle">' . $star . '</td>'
			. '<td class="iftp-ld-methods__cell iftp-ld-methods__cell--method col-valign-middle">' . $method . '</td>'
			. '<td class="iftp-ld-methods__cell iftp-ld-methods__cell--account col-valign-middle">' . $account . '</td>'
			. '<td class="iftp-ld-methods__cell iftp-ld-methods__cell--status col-valign-middle">' . $status . '</td>'
			. '</tr>';
	}

	/**
	 * A one-cell message tbody.
	 *
	 * @param string $html Safe HTML.
	 * @return string
	 */
	private static function message_row( string $html ): string {
		return '<tbody><tr class="iftp-ld-methods__row iftp-ld-methods__row--message"><td colspan="5">' . $html . '</td></tr></tbody>';
	}

	/**
	 * LearnDash's own star icons.
	 *
	 * @param string $name  `star` or `star-blank`.
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
