<?php
/**
 * LearnDash › Settings › Payments › ifthenpay.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Api\WebhookController;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Plugin;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Our section in LearnDash's Payments settings, saved by LearnDash's own Save.
 *
 * LearnDash only keeps posted keys that are registered fields with a
 * validate_callback, so every input we print (Gateway Key, the methods table,
 * the star) is a real field here with our own display and validate callbacks.
 * Connect, Disconnect, the Gateway Key switch and Request activation stay on
 * AJAX (Ajax\SettingsController), and their data lives in other options.
 *
 * @phpstan-import-type Settings from SettingsRepository
 */
class PaymentsSection extends \LearnDash_Settings_Section {

	public const SECTION_KEY = 'settings_ifthenpay';

	/**
	 * Constructor; LearnDash builds the one instance through add_section_instance().
	 */
	protected function __construct() {
		$this->settings_page_id               = 'learndash_lms_payments';
		$this->setting_option_key             = SettingsRepository::OPTION;
		$this->setting_field_prefix           = SettingsRepository::OPTION;
		$this->settings_section_key           = self::SECTION_KEY;
		$this->settings_section_label         = esc_html__( 'ifthenpay Settings', 'ifthenpay-payments-for-learndash' );
		$this->settings_parent_section_key    = 'settings_payments_list';
		$this->settings_section_listing_label = esc_html__( 'ifthenpay', 'ifthenpay-payments-for-learndash' );

		parent::__construct();

		add_filter( 'learndash_settings_field_html_after', array( $this, 'add_copy_button' ), 10, 2 );
	}

	/**
	 * The settings page URL for this section.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=learndash_lms_payments&section-payment=' . self::SECTION_KEY );
	}

	/**
	 * Whether the current request is this section's settings screen.
	 *
	 * @return bool
	 */
	public static function is_current_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		return is_admin() && isset( $_GET['page'], $_GET['section-payment'] ) && 'learndash_lms_payments' === $_GET['page'] && self::SECTION_KEY === $_GET['section-payment'];
	}

	/**
	 * The fields, in LearnDash's row format.
	 *
	 * @return void
	 */
	public function load_settings_fields() {
		$repository = Plugin::instance()->settings();
		$settings   = $repository->all();
		$connected  = $repository->is_connected();
		$banner     = $this->readiness_message( $repository );
		$fields     = array();

		if ( '' !== $banner ) {
			$fields['readiness'] = array(
				'name'       => 'readiness',
				'type'       => 'html',
				'label_none' => true,
				'input_full' => true,
				'value'      => '<div class="ld-settings-info-banner ld-settings-info-banner-alert"><p>' . esc_html( $banner ) . '</p></div>',
			);
		}

		if ( ! $connected ) {
			$fields['intro'] = array(
				'name'       => 'intro',
				'type'       => 'html',
				'label_none' => true,
				'input_full' => true,
				'value'      => '<p>' . esc_html__( 'Connect your ifthenpay account to take payments by Multibanco, MB WAY, card and more. You’ll need the Backoffice Key from your ifthenpay account.', 'ifthenpay-payments-for-learndash' ) . '</p>',
			);
		}

		$fields['connection_button'] = array(
			'name'              => 'connection_button',
			'type'              => 'text',
			'label_none'        => true,
			'input_full'        => true,
			'value'             => null,
			'display_callback'  => array( $this, 'render_connection_button' ),
			'validate_callback' => array( $this, 'validate_nothing' ),
		);

		if ( $connected ) {
			$fields['connection_status'] = array(
				'name'  => 'connection_status',
				'type'  => 'html',
				'label' => esc_html__( 'Connection', 'ifthenpay-payments-for-learndash' ),
				'value' => '<span class="iftp-ld-connection"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Connected', 'ifthenpay-payments-for-learndash' ) . '</span>',
			);
		}

		$fields['enabled'] = array(
			'name'    => 'enabled',
			'type'    => 'checkbox-switch',
			'label'   => esc_html__( 'Active', 'ifthenpay-payments-for-learndash' ),
			'value'   => $settings['enabled'] ? 'yes' : '',
			'options' => array(
				'yes' => '',
				''    => '',
			),
		);

		if ( $connected ) {
			$fields = array_merge( $fields, $this->connected_fields( $repository, $settings ) );
		}

		/** This filter is documented in LearnDash's includes/settings/settings-metaboxes/class-ld-settings-metabox-course-access-settings.php */
		$this->setting_option_fields = apply_filters( 'learndash_settings_fields', $fields, $this->settings_section_key );

		parent::load_settings_fields();
	}

	/**
	 * Merges the post into what's stored, and rebuilds the methods snapshot.
	 *
	 * The list page's toggle and a disconnected sub-page post only some fields; a merge
	 * keeps the rest (LearnDash's Razorpay section does the same for its toggle).
	 *
	 * @param mixed  $value                Validated values.
	 * @param mixed  $old_value            Stored values.
	 * @param string $settings_section_key Section key.
	 * @param string $settings_screen_id   Screen ID.
	 * @return mixed
	 */
	public function filter_section_save_fields( $value, $old_value, $settings_section_key, $settings_screen_id ) {
		if ( $settings_section_key !== $this->settings_section_key ) {
			return $value;
		}

		$value     = is_array( $value ) ? $value : array();
		$old_value = is_array( $old_value ) ? $old_value : array();

		// The Payments list posts the switch alone.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- LearnDash verified its own settings nonce before this filter runs.
		if ( isset( $_POST['learndash_settings_payments_list_nonce'] ) ) {
			return array_merge( $old_value, array( 'enabled' => 'yes' === ( $value['enabled'] ?? '' ) ? 'yes' : '' ) );
		}

		$repository = Plugin::instance()->settings();
		$merged     = array_merge( $old_value, $value );

		$merged['enabled'] = 'yes' === ( $value['enabled'] ?? '' ) ? 'yes' : '';

		// Read-only rows and buttons are never stored.
		unset( $merged['connection_button'], $merged['connection_status'], $merged['readiness'], $merged['intro'], $merged['webhook_url'] );

		if ( ! $repository->is_connected() ) {
			return $merged;
		}

		// All switches off posts no `methods` at all.
		$merged['methods'] = SettingsRepository::entities( $value['methods'] ?? array() );

		// The star is a radio inside the methods table; LearnDash registers no field for it, so I read it from the same post.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by LearnDash; reduced to [A-Z0-9] by entity().
		$posted                   = isset( $_POST[ SettingsRepository::OPTION ] ) && is_array( $_POST[ SettingsRepository::OPTION ] ) ? wp_unslash( $_POST[ SettingsRepository::OPTION ] ) : array();
		$default                  = SettingsRepository::entity( $posted['default_method'] ?? '' );
		$merged['default_method'] = in_array( $default, $merged['methods'], true ) ? $default : '';

		$gateway_key = is_string( $merged['gateway_key'] ?? null ) ? $merged['gateway_key'] : '';
		$catalog     = Plugin::instance()->client()->get_methods_catalog();

		// Without the catalog (ifthenpay unreachable) I keep the old snapshot rather than switching every method off.
		if ( array() !== $catalog ) {
			$rows               = $repository->method_rows( $gateway_key, $catalog, $merged['methods'], $merged['default_method'] );
			$merged['snapshot'] = SettingsRepository::snapshot_from_rows( $rows );
		}

		return $merged;
	}

	/**
	 * Connect / Disconnect button (the dialogs print in the footer, outside LearnDash's form).
	 *
	 * @param array<string, mixed> $field_args Field args.
	 * @return void
	 */
	public function render_connection_button( $field_args ): void {
		$connected = Plugin::instance()->settings()->is_connected();
		$logo      = '<span class="iftp-ld-connect__logo" aria-hidden="true"><img src="' . esc_url( IFTP_LD_URL . 'assets/img/icon-color.svg' ) . '" alt="" /></span>';

		echo '<div class="iftp-ld-connect">';

		if ( $connected ) {
			echo '<button type="button" class="button button-primary iftp-ld-connect__button iftp-ld-connect__button--disconnect" data-iftp-ld-disconnect>'
				. $logo // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url().
				. '<span>' . esc_html__( 'Disconnect ifthenpay', 'ifthenpay-payments-for-learndash' ) . '</span></button>';
		} else {
			echo '<button type="button" class="iftp-ld-connect__button iftp-ld-connect__button--connect" aria-haspopup="dialog" aria-controls="iftp-ld-connect-dialog" data-iftp-ld-connect>'
				. $logo // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url().
				. '<span>' . esc_html__( 'Connect ifthenpay', 'ifthenpay-payments-for-learndash' ) . '</span></button>';
			echo '<noscript><p>' . esc_html__( 'Turn on JavaScript to connect ifthenpay.', 'ifthenpay-payments-for-learndash' ) . '</p></noscript>';
		}

		echo '</div>';
		echo '<div class="screen-reader-text" aria-live="polite" id="iftp-ld-settings-live"></div>';
	}

	/**
	 * Gateway Key: a select, or the one key in an account pill.
	 *
	 * @param array<string, mixed> $field_args Field args.
	 * @return void
	 */
	public function render_gateway_key( $field_args ): void {
		$repository = Plugin::instance()->settings();
		$gateways   = $repository->gateways();
		$selected   = $repository->selected_gateway_key();
		$name       = SettingsRepository::OPTION . '[gateway_key]';

		if ( 1 === count( $gateways ) ) {
			echo '<span class="iftp-ld-methods__account">' . esc_html( $gateways[0]['key'] ) . '</span>';
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $gateways[0]['key'] ) . '" data-iftp-ld-gateway-select />';
			return;
		}

		echo '<span class="ld-select">';
		echo '<select id="iftp-ld-gateway-key" name="' . esc_attr( $name ) . '" class="learndash-section-field learndash-section-field-select" data-iftp-ld-gateway-select>';
		echo '<option value="">' . esc_html__( 'Choose a Gateway Key', 'ifthenpay-payments-for-learndash' ) . '</option>';

		foreach ( $gateways as $row ) {
			$label = '' !== $row['alias'] ? $row['alias'] . ' (' . $row['key'] . ')' : $row['key'];
			echo '<option value="' . esc_attr( $row['key'] ) . '"' . selected( $selected, $row['key'], false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></span>';
		echo '<span class="spinner iftp-ld-gateway-spinner" aria-hidden="true"></span>';
	}

	/**
	 * The live methods table.
	 *
	 * @param array<string, mixed> $field_args Field args.
	 * @return void
	 */
	public function render_methods( $field_args ): void {
		$plugin      = Plugin::instance();
		$gateway_key = $plugin->settings()->selected_gateway_key();
		$rows        = '' === $gateway_key ? array() : $plugin->settings()->method_rows( $gateway_key, $plugin->client()->get_methods_catalog() );

		echo MethodsTable::html( $rows, $gateway_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MethodsTable escapes every value.
	}

	/**
	 * Gateway Key: one of the connected account's.
	 *
	 * @param mixed                $val  Posted value.
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $args Validation args.
	 * @return string
	 */
	public function validate_gateway_key( $val, $key, $args ): string {
		$gateway_key = is_string( $val ) ? sanitize_text_field( $val ) : '';

		return null !== Plugin::instance()->settings()->gateway_row( $gateway_key ) ? $gateway_key : '';
	}

	/**
	 * Methods: entity codes only.
	 *
	 * @param mixed                $val  Posted value.
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $args Validation args.
	 * @return list<string>
	 */
	public function validate_methods( $val, $key, $args ): array {
		return SettingsRepository::entities( $val );
	}

	/**
	 * Plain one-line text.
	 *
	 * @param mixed                $val  Posted value.
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $args Validation args.
	 * @return string
	 */
	public function validate_text( $val, $key, $args ): string {
		return is_string( $val ) ? sanitize_text_field( $val ) : '';
	}

	/**
	 * Expiry days, clamped.
	 *
	 * @param mixed                $val  Posted value.
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $args Validation args.
	 * @return int
	 */
	public function validate_expiry( $val, $key, $args ): int {
		return SettingsRepository::clamp_expiry( is_numeric( $val ) ? (int) $val : SettingsRepository::DEFAULT_EXPIRY );
	}

	/**
	 * Buttons post nothing worth storing.
	 *
	 * @param mixed                $val  Posted value.
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $args Validation args.
	 * @return string
	 */
	public function validate_nothing( $val, $key, $args ): string {
		return '';
	}

	/**
	 * LearnDash's copy button after the callback URL, as its Stripe section does for its webhook URL.
	 *
	 * @param string               $html       Field HTML so far.
	 * @param array<string, mixed> $field_args Field args.
	 * @return string
	 */
	public function add_copy_button( $html, $field_args ) {
		if ( ! is_array( $field_args ) || ( $field_args['setting_option_key'] ?? '' ) !== $this->setting_option_key || 'webhook_url' !== ( $field_args['name'] ?? '' ) || ! class_exists( '\LearnDash\Core\Template\Template' ) ) {
			return $html;
		}

		return (string) $html . wp_kses(
			\LearnDash\Core\Template\Template::get_admin_template(
				'common/copy-text',
				array(
					'text'            => (string) $field_args['value'],
					'tooltip_default' => esc_html__( 'Copy callback URL', 'ifthenpay-payments-for-learndash' ),
					'tooltip_success' => esc_html__( 'Copied', 'ifthenpay-payments-for-learndash' ),
				)
			),
			array(
				'button' => array(
					'class'                => true,
					'data-tooltip'         => true,
					'data-tooltip-default' => true,
					'data-tooltip-success' => true,
					'data-text'            => true,
					'type'                 => true,
				),
				'span'   => array(
					'class'       => true,
					'aria-hidden' => true,
				),
			)
		);
	}

	/**
	 * Fields shown once an account is connected.
	 *
	 * @param SettingsRepository   $repository Settings.
	 * @param array<string, mixed> $settings   Typed settings.
	 * @phpstan-param Settings $settings
	 * @return array<string, array<string, mixed>>
	 */
	private function connected_fields( SettingsRepository $repository, array $settings ): array {
		$registered = Plugin::instance()->registrar()->is_registered();

		return array(
			'gateway_key'     => array(
				'name'              => 'gateway_key',
				'type'              => 'text',
				'label'             => esc_html__( 'Gateway Key', 'ifthenpay-payments-for-learndash' ),
				'help_text'         => esc_html__( 'The Gateway Key that receives these payments. Changing it reloads the methods below.', 'ifthenpay-payments-for-learndash' ),
				'value'             => $repository->selected_gateway_key(),
				'display_callback'  => array( $this, 'render_gateway_key' ),
				'validate_callback' => array( $this, 'validate_gateway_key' ),
			),
			'methods'         => array(
				'name'              => 'methods',
				'type'              => 'text',
				'label'             => esc_html__( 'Payment methods', 'ifthenpay-payments-for-learndash' ),
				'help_text'         => esc_html__( 'Turn on the methods students can use. The star marks the one ifthenpay’s page opens with.', 'ifthenpay-payments-for-learndash' ),
				'label_full'        => true,
				'input_full'        => true,
				'value'             => $settings['methods'],
				'display_callback'  => array( $this, 'render_methods' ),
				'validate_callback' => array( $this, 'validate_methods' ),
			),
			'pbl_description' => array(
				'name'              => 'pbl_description',
				'type'              => 'text',
				'label'             => esc_html__( 'Payment page description', 'ifthenpay-payments-for-learndash' ),
				'help_text'         => esc_html__( 'Shown to the student on ifthenpay’s payment page.', 'ifthenpay-payments-for-learndash' ),
				'class'             => 'regular-text',
				'placeholder'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'value'             => $settings['pbl_description'],
				'validate_callback' => array( $this, 'validate_text' ),
			),
			'expiry_days'     => array(
				'name'              => 'expiry_days',
				'type'              => 'number',
				'label'             => esc_html__( 'Link expires after', 'ifthenpay-payments-for-learndash' ),
				'help_text'         => esc_html__( 'Unpaid links expire after this many days. Multibanco references stay payable until then.', 'ifthenpay-payments-for-learndash' ),
				'class'             => '-small',
				'input_label'       => esc_html__( 'days', 'ifthenpay-payments-for-learndash' ),
				'attrs'             => array(
					'min'  => (string) SettingsRepository::MIN_EXPIRY_DAYS,
					'max'  => (string) SettingsRepository::MAX_EXPIRY_DAYS,
					'step' => '1',
				),
				'value'             => $settings['expiry_days'],
				'validate_callback' => array( $this, 'validate_expiry' ),
			),
			'checkout_info'   => array(
				'name'              => 'checkout_info',
				'type'              => 'text',
				'label'             => esc_html__( 'Checkout message', 'ifthenpay-payments-for-learndash' ),
				'help_text'         => esc_html__( 'Shown under the ifthenpay option on the registration page. Leave it empty for the default message.', 'ifthenpay-payments-for-learndash' ),
				'class'             => 'regular-text',
				'placeholder'       => $repository->checkout_info(),
				'value'             => $settings['checkout_info'],
				'validate_callback' => array( $this, 'validate_text' ),
			),
			'webhook_url'     => array(
				'name'              => 'webhook_url',
				'type'              => 'text',
				'label'             => esc_html__( 'Callback URL', 'ifthenpay-payments-for-learndash' ),
				'help_text'         => esc_html__( 'ifthenpay calls this URL when a payment is paid. It’s registered for you when you connect and each time you save.', 'ifthenpay-payments-for-learndash' ),
				'class'             => 'regular-text',
				'value'             => WebhookController::callback_url(),
				'attrs'             => array(
					'readonly' => 'readonly',
					'disabled' => 'disabled',
				),
				'input_note'        => $registered
					? '<span class="iftp-ld-callback iftp-ld-callback--ok"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' . esc_html__( 'Registered with ifthenpay', 'ifthenpay-payments-for-learndash' ) . '</span>'
					: '<span class="iftp-ld-callback iftp-ld-callback--pending"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html__( 'Not registered yet. Save your settings to register it.', 'ifthenpay-payments-for-learndash' ) . '</span>',
				'validate_callback' => array( $this, 'validate_nothing' ),
			),
		);
	}

	/**
	 * The one readiness problem worth showing, or ''.
	 *
	 * @param SettingsRepository $repository Settings.
	 * @return string
	 */
	private function readiness_message( SettingsRepository $repository ): string {
		$settings = $repository->all();
		$currency = Host::currency();

		if ( '' === $currency ) {
			return __( 'ifthenpay only takes payments in euro, and LearnDash has no currency set yet. Set it to Euro (EUR) under Settings › Payments, then come back here.', 'ifthenpay-payments-for-learndash' );
		}

		if ( 'EUR' !== $currency ) {
			/* translators: %s: currency code, e.g. USD. */
			return sprintf( __( 'ifthenpay only takes payments in euro, and your LearnDash currency is %s. Change it to Euro (EUR) under Settings › Payments, then come back here.', 'ifthenpay-payments-for-learndash' ), $currency );
		}

		if ( ! $settings['enabled'] ) {
			return '';
		}

		if ( ! $repository->is_connected() ) {
			return __( 'ifthenpay is on but not connected, so students won’t see it. Connect your account below.', 'ifthenpay-payments-for-learndash' );
		}

		if ( '' === $repository->gateway_key() ) {
			return __( 'ifthenpay is on but no Gateway Key is saved, so students won’t see it. Choose a Gateway Key below and save.', 'ifthenpay-payments-for-learndash' );
		}

		if ( array() === $repository->snapshot() ) {
			return __( 'ifthenpay is on but no payment method is enabled, so students won’t see it. Turn on at least one method below and save.', 'ifthenpay-payments-for-learndash' );
		}

		if ( '' === $settings['default_method'] ) {
			return __( 'Pick a default method: click the star next to the method ifthenpay’s page should open with.', 'ifthenpay-payments-for-learndash' );
		}

		return '';
	}
}
