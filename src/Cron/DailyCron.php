<?php
/**
 * Daily housekeeping.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Cron;

use Ifthenpay\LearnDash\Api\WebhookRegistrar;
use Ifthenpay\LearnDash\Payments\TransactionStore;

defined( 'ABSPATH' ) || exit;

/**
 * Expires links past their date and re-checks the callback registration.
 *
 * A Multibanco reference paid after its link expired still completes: the
 * webhook only refuses rows that are already paid.
 */
final class DailyCron {

	public const HOOK = 'iftp_ld_daily';

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private $registrar;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore $store     Attempts.
	 * @param WebhookRegistrar $registrar Callback registration.
	 */
	public function __construct( TransactionStore $store, WebhookRegistrar $registrar ) {
		$this->store     = $store;
		$this->registrar = $registrar;

		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the event (activation, and sites that were already active).
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs the housekeeping.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->store->expire_due( time() );
		$this->registrar->sync();
	}
}
