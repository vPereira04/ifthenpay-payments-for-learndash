<?php
/**
 * Our rows on LearnDash's order edit screen.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Gateway\IfthenpayGateway;
use Ifthenpay\LearnDash\Payments\TransactionStore;
use Ifthenpay\LearnDash\Plugin;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Reference, Method and a link to our entry under LearnDash's own Payment rows.
 *
 * LearnDash already shows our request ID as its "Session ID", so I only repeat it
 * when the two differ.
 */
final class OrderScreen {

	private const TEMPLATE = 'modules/payments/orders/edit/details/payment/table-body';

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Settings (for method logos).
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore   $store    Attempts.
	 * @param SettingsRepository $settings Settings.
	 */
	public function __construct( TransactionStore $store, SettingsRepository $settings ) {
		$this->store    = $store;
		$this->settings = $settings;

		add_action( 'learndash_template_after_include:' . self::TEMPLATE, array( $this, 'rows' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Our rowgroup, right after LearnDash's Payment rows.
	 *
	 * @param mixed $args Template args (holds `transaction`).
	 * @return void
	 */
	public function rows( $args ): void {
		$transaction = is_array( $args ) ? ( $args['transaction'] ?? null ) : null;

		if ( ! $transaction instanceof \LearnDash\Core\Models\Transaction || IfthenpayGateway::NAME !== $transaction->get_gateway_name() ) {
			return;
		}

		$parent = $transaction->is_parent() ? $transaction : $transaction->get_parent();
		$row    = null === $parent ? null : $this->store->find_by_order( $parent->get_id() );

		if ( null === $row ) {
			return;
		}

		echo '<div class="ld-order-details__tbody iftp-ld-order-payment" role="rowgroup">';

		echo '<div role="row"><span role="cell">' . wp_kses(
			sprintf(
				/* translators: 1: payment reference, 2: copy button. */
				__( 'Reference: %1$s %2$s', 'ifthenpay-payments-for-learndash' ),
				esc_html( $row['ref'] ),
				self::copy_button( $row['ref'], __( 'Copy reference', 'ifthenpay-payments-for-learndash' ) )
			),
			self::copy_allowed_html()
		) . '</span></div>';

		if ( '' !== $row['pay_method'] ) {
			echo '<div role="row"><span role="cell">' . wp_kses(
				/* translators: %s: payment method (logo and name). */
				sprintf( __( 'Method: %s', 'ifthenpay-payments-for-learndash' ), $this->method_html( $row['pay_method'] ) ),
				array(
					'img' => array(
						'class'  => true,
						'src'    => true,
						'alt'    => true,
						'width'  => true,
						'height' => true,
					),
				)
			) . '</span></div>';
		}

		if ( '' !== $row['request_id'] && $row['request_id'] !== (string) $transaction->get_gateway_transaction_id() ) {
			echo '<div role="row"><span role="cell">' . wp_kses(
				sprintf(
					/* translators: 1: ifthenpay request ID, 2: copy button. */
					__( 'Request ID: %1$s %2$s', 'ifthenpay-payments-for-learndash' ),
					esc_html( $row['request_id'] ),
					self::copy_button( $row['request_id'], __( 'Copy request ID', 'ifthenpay-payments-for-learndash' ) )
				),
				self::copy_allowed_html()
			) . '</span></div>';
		}

		echo '<div role="row"><span role="cell"><a href="' . esc_url( self::entry_url( $row['ref'] ) ) . '">' . esc_html__( 'View ifthenpay entry', 'ifthenpay-payments-for-learndash' ) . '</a></span></div>';

		echo '</div>';
	}

	/**
	 * The small stylesheet, on LearnDash order edit screens only.
	 *
	 * @param string $hook_suffix Admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( 'post.php' !== $hook_suffix || null === $screen || \Ifthenpay\LearnDash\Host::orders_post_type() !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'iftp-ld-order', IFTP_LD_URL . 'assets/css/order.css', wp_style_is( 'learndash-admin', 'registered' ) ? array( 'learndash-admin' ) : array(), Plugin::asset_version( 'assets/css/order.css' ) );
	}

	/**
	 * Method logo + name, from the saved methods (or the code alone).
	 *
	 * @param string $code Method code ifthenpay reported.
	 * @return string
	 */
	private function method_html( string $code ): string {
		$wanted = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $code ) );

		foreach ( $this->settings->snapshot() as $method ) {
			$names = array( $method['entity'], strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $method['alias'] ) ) );

			if ( in_array( $wanted, $names, true ) ) {
				$logo = '' !== $method['logo'] ? '<img class="iftp-ld-order-payment__logo" src="' . esc_url( $method['logo'] ) . '" alt="" width="40" height="20" />' : '';

				return $logo . esc_html( '' !== $method['alias'] ? $method['alias'] : $method['entity'] );
			}
		}

		return esc_html( $code );
	}

	/**
	 * Link to our entry: its own page when the entries screen provides one.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	private static function entry_url( string $ref ): string {
		if ( class_exists( EntryPage::class ) && method_exists( EntryPage::class, 'url' ) ) {
			return (string) EntryPage::url( $ref );
		}

		return add_query_arg(
			array(
				'page' => 'iftp-ld-payments',
				's'    => rawurlencode( $ref ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * LearnDash's copy-text button.
	 *
	 * @param string $text    Text to copy.
	 * @param string $tooltip Tooltip.
	 * @return string
	 */
	private static function copy_button( string $text, string $tooltip ): string {
		if ( ! class_exists( '\LearnDash\Core\Template\Template' ) ) {
			return '';
		}

		return \LearnDash\Core\Template\Template::get_admin_template(
			'common/copy-text',
			array(
				'text'            => $text,
				'tooltip_default' => $tooltip,
			)
		);
	}

	/**
	 * The allow-list LearnDash uses for its own copy-text rows.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function copy_allowed_html(): array {
		return array(
			'button' => array(
				'class'                => true,
				'data-tooltip'         => true,
				'data-tooltip-default' => true,
				'data-tooltip-success' => true,
				'data-text'            => true,
			),
			'span'   => array(
				'class'       => true,
				'aria-hidden' => true,
			),
		);
	}
}
