<?php
/**
 * Short-lived exclusive locks.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Option-row locks: INSERT IGNORE succeeds for exactly one request.
 *
 * Core's add_option() isn't exclusive (it upserts), so I insert the row myself. A lock
 * older than its TTL is treated as abandoned and taken over.
 */
final class Locks {

	private const PREFIX = 'iftp_ld_lock_';

	/**
	 * Tries to take a lock.
	 *
	 * @param string $name Lock name (any string; hashed).
	 * @param int    $ttl  Seconds before an abandoned lock may be reclaimed.
	 * @return bool
	 */
	public static function acquire( string $name, int $ttl = 300 ): bool {
		global $wpdb;

		$option = self::option( $name );
		$now    = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- exclusive insert; core's add_option() upserts.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$option,
				(string) $now
			)
		);

		if ( 1 === (int) $inserted ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the options cache to see the other request's row.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %s",
				(string) $now,
				$option,
				(string) ( $now - $ttl )
			)
		);

		return 1 === (int) $taken;
	}

	/**
	 * Releases a lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the row was written outside the options API too.
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::option( $name ) ) );
	}

	/**
	 * Option name for a lock.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option( string $name ): string {
		return self::PREFIX . md5( $name );
	}
}
