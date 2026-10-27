<?php
/**
 * Plugin bootstrap.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash;

use Ifthenpay\LearnDash\Admin\AdminMenu;
use Ifthenpay\LearnDash\Admin\EntriesPage;
use Ifthenpay\LearnDash\Admin\EntryCells;
use Ifthenpay\LearnDash\Admin\EntryPage;
use Ifthenpay\LearnDash\Admin\OrderScreen;
use Ifthenpay\LearnDash\Admin\PaymentEmailSettings;
use Ifthenpay\LearnDash\Admin\SettingsScreen;
use Ifthenpay\LearnDash\Ajax\EntriesController;
use Ifthenpay\LearnDash\Ajax\SettingsController;
use Ifthenpay\LearnDash\Api\IfthenpayClient;
use Ifthenpay\LearnDash\Api\ReturnHandler;
use Ifthenpay\LearnDash\Api\WebhookController;
use Ifthenpay\LearnDash\Api\WebhookRegistrar;
use Ifthenpay\LearnDash\Cron\DailyCron;
use Ifthenpay\LearnDash\Frontend\StatusBlock;
use Ifthenpay\LearnDash\Gateway\IfthenpayGateway;
use Ifthenpay\LearnDash\Payments\PaymentService;
use Ifthenpay\LearnDash\Payments\PurchaseCompleter;
use Ifthenpay\LearnDash\Payments\TransactionStore;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the services; one instance of each per request.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Settings.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var IfthenpayClient
	 */
	private $client;

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Link service.
	 *
	 * @var PaymentService
	 */
	private $payments;

	/**
	 * Paid → LearnDash order.
	 *
	 * @var PurchaseCompleter
	 */
	private $completer;

	/**
	 * Callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private $registrar;

	/**
	 * The gateway, built the first time LearnDash (or we) need it.
	 *
	 * @var IfthenpayGateway|null
	 */
	private $gateway = null;

	/**
	 * The instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings  = new SettingsRepository();
		$this->client    = new IfthenpayClient();
		$this->store     = new TransactionStore();
		$this->payments  = new PaymentService( $this->settings, $this->client, $this->store );
		$this->completer = new PurchaseCompleter( $this->store, array( $this, 'gateway' ) );
		$this->registrar = new WebhookRegistrar( $this->settings, $this->client );
	}

	/**
	 * Activation: the table and the daily event. Runs without LearnDash loaded.
	 *
	 * @return void
	 */
	public static function activate(): void {
		TransactionStore::maybe_create_table();
		DailyCron::schedule();
	}

	/**
	 * Registers the gateway and the services each request can use.
	 *
	 * @return void
	 */
	public function boot(): void {
		// The stored schema revision is autoloaded, so this is one in-memory compare per request.
		TransactionStore::maybe_create_table();

		add_filter( 'learndash_payment_gateways', array( $this, 'register_gateway' ) );

		new WebhookController( $this->store, $this->completer );
		new ReturnHandler( $this->store, $this->payments );
		new SettingsScreen( $this->settings, $this->registrar );
		new StatusBlock( $this->store );

		if ( is_admin() ) {
			new SettingsController( $this->settings, $this->client, $this->registrar );
			new OrderScreen( $this->store, $this->settings );
		}

		if ( is_admin() ) {
			$cells   = new EntryCells( $this->settings, $this->client );
			$entries = new EntriesPage( $this->store, $this->settings, $cells, new PaymentEmailSettings(), new EntryPage( $this->store, $cells ) );

			new AdminMenu( $entries );
			new EntriesController( $this->store, $this->payments, $this->completer, $entries );
		}

		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			new DailyCron( $this->store, $this->registrar );
		}
	}

	/**
	 * Adds our gateway to LearnDash's list.
	 *
	 * @param mixed $gateways Gateway instances.
	 * @return mixed
	 */
	public function register_gateway( $gateways ) {
		$gateway = $this->gateway();

		if ( is_array( $gateways ) && null !== $gateway ) {
			$gateways[] = $gateway;
		}

		return $gateways;
	}

	/**
	 * The gateway instance, or null while LearnDash's payment classes aren't loaded.
	 *
	 * @return IfthenpayGateway|null
	 */
	public function gateway(): ?IfthenpayGateway {
		if ( null === $this->gateway && class_exists( '\Learndash_Payment_Gateway' ) ) {
			$this->gateway = new IfthenpayGateway( $this->settings, $this->payments );
		}

		return $this->gateway;
	}

	/**
	 * Settings.
	 *
	 * @return SettingsRepository
	 */
	public function settings(): SettingsRepository {
		return $this->settings;
	}

	/**
	 * API client.
	 *
	 * @return IfthenpayClient
	 */
	public function client(): IfthenpayClient {
		return $this->client;
	}

	/**
	 * Attempts.
	 *
	 * @return TransactionStore
	 */
	public function store(): TransactionStore {
		return $this->store;
	}

	/**
	 * Link service.
	 *
	 * @return PaymentService
	 */
	public function payments(): PaymentService {
		return $this->payments;
	}

	/**
	 * Paid → LearnDash order.
	 *
	 * @return PurchaseCompleter
	 */
	public function completer(): PurchaseCompleter {
		return $this->completer;
	}

	/**
	 * Callback registration.
	 *
	 * @return WebhookRegistrar
	 */
	public function registrar(): WebhookRegistrar {
		return $this->registrar;
	}

	/**
	 * `?ver=` for our assets: file time while debugging, the version otherwise.
	 *
	 * @param string $relative Path inside the plugin.
	 * @return string
	 */
	public static function asset_version( string $relative ): string {
		$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$path  = IFTP_LD_PATH . $relative;

		return $debug && file_exists( $path ) ? (string) filemtime( $path ) : IFTP_LD_VERSION;
	}
}
