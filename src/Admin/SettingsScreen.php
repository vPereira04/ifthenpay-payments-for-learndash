<?php
/**
 * Glue around our LearnDash settings section.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Ajax\SettingsController;
use Ifthenpay\LearnDash\Api\WebhookRegistrar;
use Ifthenpay\LearnDash\Plugin;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the section, re-registers the callback after LearnDash saves it,
 * and adds the assets, dialogs and notices of the ifthenpay sub-page.
 */
final class SettingsScreen {

	private const CALLBACK_FAILED = 'iftp_ld_callback_failed';

	/**
	 * Settings.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private $registrar;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings  Settings.
	 * @param WebhookRegistrar   $registrar Callback registration.
	 */
	public function __construct( SettingsRepository $settings, WebhookRegistrar $registrar ) {
		$this->settings  = $settings;
		$this->registrar = $registrar;

		add_action( 'learndash_settings_sections_init', array( PaymentsSection::class, 'add_section_instance' ) );
		add_action( 'add_option_' . SettingsRepository::OPTION, array( $this, 'after_save' ), 10, 0 );
		add_action( 'update_option_' . SettingsRepository::OPTION, array( $this, 'after_save' ), 10, 0 );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'admin_footer', array( $this, 'dialogs' ) );
			add_action( 'admin_notices', array( $this, 'notices' ) );
			add_action( 'current_screen', array( $this, 'drift_check' ) );
		}
	}

	/**
	 * LearnDash saved the section: register the callback for the (maybe new) Gateway Key.
	 *
	 * @return void
	 */
	public function after_save(): void {
		$this->settings->flush();

		if ( '' === $this->settings->gateway_key() ) {
			return;
		}

		if ( $this->registrar->sync( true ) ) {
			delete_transient( self::CALLBACK_FAILED );
		} else {
			set_transient( self::CALLBACK_FAILED, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * A moved site or a lost registration re-registers when the sub-page opens.
	 *
	 * @return void
	 */
	public function drift_check(): void {
		if ( PaymentsSection::is_current_screen() && $this->settings->is_connected() ) {
			$this->registrar->sync();
		}
	}

	/**
	 * Assets, on the ifthenpay sub-page only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! PaymentsSection::is_current_screen() ) {
			return;
		}

		wp_enqueue_style( 'wp-jquery-ui-dialog' );
		wp_enqueue_style( 'dashicons' );

		$deps = array( 'wp-jquery-ui-dialog', 'dashicons' );

		foreach ( array( 'learndash-admin', 'learndash-admin-settings-page' ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				$deps[] = $handle;
			}
		}

		wp_enqueue_style( 'iftp-ld-tokens', IFTP_LD_URL . 'assets/css/tokens.css', $deps, Plugin::asset_version( 'assets/css/tokens.css' ) );
		wp_enqueue_style( 'iftp-ld-settings', IFTP_LD_URL . 'assets/css/settings.css', array( 'iftp-ld-tokens' ), Plugin::asset_version( 'assets/css/settings.css' ) );
		wp_enqueue_script( 'iftp-ld-settings', IFTP_LD_URL . 'assets/js/settings.js', array( 'jquery', 'jquery-ui-dialog' ), Plugin::asset_version( 'assets/js/settings.js' ), true );

		wp_localize_script(
			'iftp-ld-settings',
			'iftpLdSettings',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( SettingsController::NONCE ),
				'settingsUrl' => PaymentsSection::url(),
				'i18n'        => array(
					'cancel'            => __( 'Cancel', 'ifthenpay-payments-for-learndash' ),
					'ok'                => __( 'OK', 'ifthenpay-payments-for-learndash' ),
					'connect'           => __( 'Connect', 'ifthenpay-payments-for-learndash' ),
					'connecting'        => __( 'Connecting…', 'ifthenpay-payments-for-learndash' ),
					'disconnect'        => __( 'Disconnect', 'ifthenpay-payments-for-learndash' ),
					'disconnectTitle'   => __( 'Disconnect ifthenpay?', 'ifthenpay-payments-for-learndash' ),
					'emptyKey'          => __( 'Enter your Backoffice Key.', 'ifthenpay-payments-for-learndash' ),
					'network'           => __( 'We couldn’t reach ifthenpay. Check your connection and try again.', 'ifthenpay-payments-for-learndash' ),
					'unsavedTitle'      => __( 'Unsaved changes', 'ifthenpay-payments-for-learndash' ),
					'unsavedText'       => __( 'Save your settings before connecting ifthenpay.', 'ifthenpay-payments-for-learndash' ),
					'requesting'        => __( 'Requesting…', 'ifthenpay-payments-for-learndash' ),
					'requested'         => __( 'Requested', 'ifthenpay-payments-for-learndash' ),
					'requestedTitle'    => __( 'Activation requested', 'ifthenpay-payments-for-learndash' ),
					/* translators: %s: payment method name. */
					'requestedText'     => __( 'We’ve asked ifthenpay to activate %s. You’ll get an email when it’s ready.', 'ifthenpay-payments-for-learndash' ),
					'requestFailed'     => __( 'Request not sent', 'ifthenpay-payments-for-learndash' ),
					/* translators: %s: payment method name. */
					'requestedLive'     => __( 'Activation requested for %s', 'ifthenpay-payments-for-learndash' ),
					'methodsUpdated'    => __( 'Methods updated', 'ifthenpay-payments-for-learndash' ),
					/* translators: %s: payment method name. */
					'defaultLive'       => __( 'Default method: %s', 'ifthenpay-payments-for-learndash' ),
					'methodsLoadFailed' => __( 'We couldn’t load the methods for this Gateway Key. Try again in a moment.', 'ifthenpay-payments-for-learndash' ),
				),
			)
		);
	}

	/**
	 * Dialog markup, outside LearnDash's settings form (a nested form would be dropped).
	 *
	 * @return void
	 */
	public function dialogs(): void {
		if ( ! PaymentsSection::is_current_screen() ) {
			return;
		}
		?>
		<div id="iftp-ld-connect-dialog" class="hidden" title="<?php esc_attr_e( 'Connect ifthenpay', 'ifthenpay-payments-for-learndash' ); ?>">
			<form class="iftp-ld-dialog__form" novalidate>
				<p class="iftp-ld-dialog__intro"><?php esc_html_e( 'Paste the Backoffice Key from your ifthenpay account.', 'ifthenpay-payments-for-learndash' ); ?></p>
				<label class="iftp-ld-dialog__label" for="iftp-ld-backoffice-key"><?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-learndash' ); ?></label>
				<input type="text" id="iftp-ld-backoffice-key" class="regular-text iftp-ld-dialog__input" autocomplete="off" spellcheck="false" autocapitalize="off" placeholder="<?php esc_attr_e( 'Insert your Backoffice Key here...', 'ifthenpay-payments-for-learndash' ); ?>" aria-describedby="iftp-ld-connect-help iftp-ld-connect-error" aria-invalid="false" />
				<p id="iftp-ld-connect-help" class="description"><?php esc_html_e( 'The key from your ifthenpay Backoffice account.', 'ifthenpay-payments-for-learndash' ); ?></p>
				<p id="iftp-ld-connect-error" class="iftp-ld-dialog__error" role="alert" hidden><span class="dashicons dashicons-warning" aria-hidden="true"></span><span class="iftp-ld-dialog__error-text"></span></p>
			</form>
		</div>
		<div id="iftp-ld-disconnect-dialog" class="hidden" title="<?php esc_attr_e( 'Disconnect ifthenpay?', 'ifthenpay-payments-for-learndash' ); ?>">
			<p><?php esc_html_e( 'Students won’t be able to pay with ifthenpay until you connect again. Payments already in Payments stay there.', 'ifthenpay-payments-for-learndash' ); ?></p>
		</div>
		<div id="iftp-ld-message-dialog" class="hidden" title="">
			<p class="iftp-ld-dialog__message"></p>
		</div>
		<?php
	}

	/**
	 * Page-top notices after connect, disconnect, or a failed callback registration.
	 *
	 * @return void
	 */
	public function notices(): void {
		if ( ! PaymentsSection::is_current_screen() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirects.
		if ( isset( $_GET['iftp_ld_connected'] ) ) {
			wp_admin_notice(
				esc_html__( 'ifthenpay is connected. Pick the Gateway Key and the methods to offer, then save.', 'ifthenpay-payments-for-learndash' ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}

		if ( isset( $_GET['iftp_ld_disconnected'] ) ) {
			wp_admin_notice(
				esc_html__( 'ifthenpay is disconnected. Students can’t pay with it until you connect again.', 'ifthenpay-payments-for-learndash' ),
				array(
					'type'        => 'info',
					'dismissible' => true,
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( false !== get_transient( self::CALLBACK_FAILED ) ) {
			delete_transient( self::CALLBACK_FAILED );
			wp_admin_notice(
				esc_html__( 'Settings saved, but ifthenpay didn’t accept the callback URL. We’ll retry within 15 minutes. If this keeps happening, contact ifthenpay support.', 'ifthenpay-payments-for-learndash' ),
				array(
					'type'        => 'warning',
					'dismissible' => true,
				)
			);
		}
	}
}
