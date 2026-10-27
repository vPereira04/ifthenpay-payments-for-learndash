<?php
/**
 * Custom transactions table storage.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Every ifthenpay payment attempt.
 *
 * LearnDash has no pending order: its orders only exist once paid. So every
 * attempt lives here (pending, paid, failed, cancelled, expired), and the
 * webhook turns a paid row into a real LearnDash order, whose ID goes back
 * into `order_id`.
 *
 * @phpstan-type Transaction array{ref: string, product_id: int, product_type: string, user_id: int, order_id: int, gateway_key: string, amount: float, pricing_snapshot: string, pay_method: string, request_id: string, transaction_id: string, payment_url: string, customer_name: string, customer_email: string, status: string, expires_at: int, created_at: int, updated_at: int}
 * @phpstan-type Snapshot array{pricing?: array<string, mixed>, coupon?: array<string, mixed>}
 */
final class TransactionStore {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_PAID      = 'paid';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_EXPIRED   = 'expired';

	public const ALL_STATUSES = array(
		self::STATUS_PENDING,
		self::STATUS_PAID,
		self::STATUS_FAILED,
		self::STATUS_CANCELLED,
		self::STATUS_EXPIRED,
	);

	/**
	 * Stamped on "New payment" rows, which never go through ifthenpay.
	 */
	public const MANUAL_GATEWAY_KEY = 'manual';
	public const AD_HOC_METHOD      = 'Ad Hoc';

	/**
	 * Allow-list for query()'s orderby, since a column name can't go through prepare().
	 */
	public const SORTABLE_COLUMNS = array( 'ref', 'customer_name', 'amount', 'status', 'created_at' );

	public const SCHEMA_OPTION = 'iftp_ld_db_version';

	// The table's own schema revision, separate from the plugin version.
	private const SCHEMA_REVISION = 1;

	private const CUSTOMER_MAX_LENGTH = 191;

	// The list and the CSV never show the price snapshot, so I leave the LONGTEXT out of their reads.
	private const LIST_COLUMNS = 't.ref, t.product_id, t.product_type, t.user_id, t.order_id, t.gateway_key, t.amount, t.pay_method, t.request_id, t.transaction_id, t.payment_url, t.customer_name, t.customer_email, t.status, t.expires_at, t.created_at, t.updated_at';

	private const CACHE_GROUP     = 'iftp_ld';
	private const ENTRY_CACHE_TTL = 600;
	private const LIST_CACHE_TTL  = 30;

	/**
	 * The custom table name, including the site's table prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'iftp_ld_transactions';
	}

	/**
	 * Creates or upgrades the table; a no-op once the stored schema version is current.
	 *
	 * @return void
	 */
	public static function maybe_create_table(): void {
		if ( version_compare( (string) get_option( self::SCHEMA_OPTION, '0' ), (string) self::SCHEMA_REVISION, '>=' ) ) {
			return;
		}

		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
            ref VARCHAR(191) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_type VARCHAR(20) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gateway_key VARCHAR(100) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            pricing_snapshot LONGTEXT NULL,
            pay_method VARCHAR(50) NOT NULL DEFAULT '',
            request_id VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(100) NOT NULL DEFAULT '',
            payment_url VARCHAR(500) NOT NULL DEFAULT '',
            customer_name VARCHAR(191) NOT NULL DEFAULT '',
            customer_email VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (ref),
            KEY status (status),
            KEY product_id (product_id),
            KEY user_product (user_id,product_id),
            KEY order_id (order_id),
            KEY created_at (created_at),
            KEY status_created_at (status,created_at),
            KEY status_expires_at (status,expires_at),
            KEY product_id_created_at (product_id,created_at),
            KEY pay_method (pay_method),
            KEY customer_name (customer_name)
        ) {$charset_collate};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_REVISION, true );
	}

	/**
	 * Inserts a new pending checkout row.
	 *
	 * @param array{ref: string, product_id: int, product_type: string, user_id: int, gateway_key: string, amount: float, payment_url: string, expires_at: int, customer_name: string, customer_email: string, snapshot: array<string, mixed>} $row Row.
	 * @return bool
	 */
	public function store_pending( array $row ): bool {
		return $this->insert(
			array(
				'ref'              => $row['ref'],
				'product_id'       => $row['product_id'],
				'product_type'     => $row['product_type'],
				'user_id'          => $row['user_id'],
				'gateway_key'      => $row['gateway_key'],
				'amount'           => $row['amount'],
				'pricing_snapshot' => $row['snapshot'],
				'payment_url'      => $row['payment_url'],
				'customer_name'    => $row['customer_name'],
				'customer_email'   => $row['customer_email'],
				'status'           => self::STATUS_PENDING,
				'expires_at'       => $row['expires_at'],
			)
		);
	}

	/**
	 * Records a payment taken outside checkout ("New payment").
	 *
	 * @param string $customer_name  Name, or ''.
	 * @param string $customer_email Email, or ''.
	 * @param float  $amount         Amount.
	 * @param string $status         One of ALL_STATUSES.
	 * @param int    $product_id     Course or group the payment was for, or 0.
	 * @return string|null The generated ref, or null if the insert failed.
	 */
	public function create_manual( string $customer_name, string $customer_email, float $amount, string $status, int $product_id = 0 ): ?string {
		$ref = $this->unique_ref( 'MANUAL_', 8 );

		$inserted = $this->insert(
			array(
				'ref'              => $ref,
				'product_id'       => $product_id,
				'product_type'     => $product_id > 0 ? self::product_type( $product_id ) : '',
				'user_id'          => 0,
				'gateway_key'      => self::MANUAL_GATEWAY_KEY,
				'amount'           => $amount,
				'pricing_snapshot' => array(),
				'payment_url'      => '',
				'pay_method'       => self::AD_HOC_METHOD,
				'customer_name'    => $customer_name,
				'customer_email'   => $customer_email,
				'status'           => $status,
				'expires_at'       => 0,
			)
		);

		return $inserted ? $ref : null;
	}

	/**
	 * A reference no row uses yet.
	 *
	 * @param string $prefix Readable prefix, e.g. `LD_12_`.
	 * @param int    $length Random characters after it.
	 * @return string
	 */
	public function unique_ref( string $prefix, int $length ): string {
		do {
			$ref = $prefix . strtoupper( wp_generate_password( $length, false, false ) );
		} while ( null !== $this->get( $ref ) );

		return $ref;
	}

	/**
	 * Reads a row by reference.
	 *
	 * @param string $ref Reference.
	 * @return array<string, mixed>|null
	 * @phpstan-return Transaction|null
	 */
	public function get( string $ref ): ?array {
		global $wpdb;

		$found  = false;
		$cached = wp_cache_get( self::row_cache_key( $ref ), self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return is_array( $cached ) ? self::hydrate( $cached ) : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, cached right below.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s', self::table_name(), $ref ), ARRAY_A );

		$record = is_array( $row ) ? self::hydrate( $row ) : null;

		wp_cache_set( self::row_cache_key( $ref ), $record, self::CACHE_GROUP, self::ENTRY_CACHE_TTL );

		return $record;
	}

	/**
	 * The student's live link for a product: pending and not expired, newest first.
	 *
	 * @param int $user_id    User ID.
	 * @param int $product_id Course or group ID.
	 * @return array<string, mixed>|null
	 * @phpstan-return Transaction|null
	 */
	public function live_for( int $user_id, int $product_id ): ?array {
		global $wpdb;

		if ( $user_id <= 0 || $product_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; must see a link created a moment ago.
		$ref = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ref FROM %i WHERE user_id = %d AND product_id = %d AND status = %s AND expires_at > %d AND payment_url <> '' ORDER BY created_at DESC, ref DESC LIMIT 1",
				self::table_name(),
				$user_id,
				$product_id,
				self::STATUS_PENDING,
				time()
			)
		);

		return is_string( $ref ) ? $this->get( $ref ) : null;
	}

	/**
	 * The paid row behind a LearnDash order.
	 *
	 * @param int $order_id Parent order ID.
	 * @return array<string, mixed>|null
	 * @phpstan-return Transaction|null
	 */
	public function find_by_order( int $order_id ): ?array {
		global $wpdb;

		if ( $order_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; get() caches the row itself.
		$ref = $wpdb->get_var( $wpdb->prepare( 'SELECT ref FROM %i WHERE order_id = %d ORDER BY updated_at DESC LIMIT 1', self::table_name(), $order_id ) );

		return is_string( $ref ) ? $this->get( $ref ) : null;
	}

	/**
	 * The student's newest attempt for a product, whatever its status.
	 *
	 * @param int $user_id    User ID.
	 * @param int $product_id Course or group ID.
	 * @return array<string, mixed>|null
	 * @phpstan-return Transaction|null
	 */
	public function latest_for( int $user_id, int $product_id ): ?array {
		global $wpdb;

		if ( $user_id <= 0 || $product_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a fresh read for the course page.
		$ref = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ref FROM %i WHERE user_id = %d AND product_id = %d ORDER BY created_at DESC, ref DESC LIMIT 1',
				self::table_name(),
				$user_id,
				$product_id
			)
		);

		return is_string( $ref ) ? $this->get( $ref ) : null;
	}

	/**
	 * Marks a row paid. One conditional UPDATE, so when the webhook fires twice
	 * at once only one call gets true (and creates the LearnDash order).
	 *
	 * @param string $ref        Reference.
	 * @param string $pay_method Method code.
	 * @param string $request_id ifthenpay request ID.
	 * @return bool
	 */
	public function mark_paid( string $ref, string $pay_method, string $request_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, pay_method = %s, request_id = %s, updated_at = %d WHERE ref = %s AND status <> %s',
				self::table_name(),
				self::STATUS_PAID,
				$pay_method,
				$request_id,
				time(),
				$ref,
				self::STATUS_PAID
			)
		);

		$this->forget( array( $ref ) );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Puts a claimed row back when the LearnDash order couldn't be recorded, so ifthenpay's redelivery can retry.
	 *
	 * @param string $ref    Reference.
	 * @param string $status Status it had before.
	 * @return void
	 */
	public function release_paid( string $ref, string $status ): void {
		$this->update( $ref, array( 'status' => in_array( $status, self::ALL_STATUSES, true ) && self::STATUS_PAID !== $status ? $status : self::STATUS_PENDING ) );
	}

	/**
	 * Marks a row failed, unless it's already paid.
	 *
	 * @param string $ref Reference.
	 * @return bool
	 */
	public function mark_failed( string $ref ): bool {
		return $this->set_status_unless_paid( $ref, self::STATUS_FAILED );
	}

	/**
	 * Marks a row cancelled, unless it's already paid.
	 *
	 * @param string $ref Reference.
	 * @return bool
	 */
	public function mark_cancelled( string $ref ): bool {
		return $this->set_status_unless_paid( $ref, self::STATUS_CANCELLED );
	}

	/**
	 * Expires pending ifthenpay rows whose link ran out. A late webhook still wins: mark_paid() only skips paid rows.
	 *
	 * @param int $now Unix time.
	 * @return int How many rows changed.
	 */
	public function expire_due( int $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a cron sweep, caching it would be pointless.
		$refs = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ref FROM %i WHERE status = %s AND gateway_key <> %s AND expires_at > 0 AND expires_at < %d',
				self::table_name(),
				self::STATUS_PENDING,
				self::MANUAL_GATEWAY_KEY,
				$now
			)
		);

		return $this->set_status_many( array_map( 'strval', $refs ), self::STATUS_EXPIRED, self::STATUS_PENDING );
	}

	/**
	 * Records the LearnDash order created for a paid row.
	 *
	 * @param string $ref      Reference.
	 * @param int    $order_id Parent order ID.
	 * @return bool
	 */
	public function record_order_id( string $ref, int $order_id ): bool {
		return $this->update( $ref, array( 'order_id' => $order_id ) );
	}

	/**
	 * Records a link created later ("Send payment email") plus its Gateway Key, which the webhook validates against.
	 *
	 * @param string $ref         Reference.
	 * @param string $payment_url Hosted page URL.
	 * @param string $gateway_key Gateway Key the link belongs to.
	 * @param int    $expires_at  When the link stops working.
	 * @return bool
	 */
	public function record_payment_link( string $ref, string $payment_url, string $gateway_key, int $expires_at ): bool {
		return $this->update(
			$ref,
			array(
				'payment_url' => $payment_url,
				'gateway_key' => $gateway_key,
				'expires_at'  => $expires_at,
			)
		);
	}

	/**
	 * Records the ifthenpay transaction ID (report-only; it proves nothing).
	 *
	 * @param string $ref            Reference.
	 * @param string $transaction_id Transaction ID.
	 * @return bool
	 */
	public function record_transaction_id( string $ref, string $transaction_id ): bool {
		return $this->update( $ref, array( 'transaction_id' => $transaction_id ) );
	}

	/**
	 * Admin bulk override; unlike mark_*(), it can move a row off "paid".
	 *
	 * @param array<int, string> $refs   References.
	 * @param string             $status One of ALL_STATUSES.
	 * @return int How many rows changed.
	 */
	public function admin_set_status_many( array $refs, string $status ): int {
		if ( ! in_array( $status, self::ALL_STATUSES, true ) ) {
			return 0;
		}

		return $this->set_status_many( $refs, $status );
	}

	/**
	 * Deletes the given rows in one DELETE. Paid rows are payment history, so they stay.
	 *
	 * @param array<int, string> $refs References.
	 * @return int How many rows were deleted.
	 */
	public function delete_unpaid( array $refs ): int {
		global $wpdb;

		$refs = self::clean_refs( $refs );

		if ( array() === $refs ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( 'DELETE FROM %i WHERE ref IN ({refs}) AND status <> %s', $refs, array( self::table_name() ), array( self::STATUS_PAID ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Loads the given rows into the object cache in one SELECT, so a loop of get() calls doesn't query per ref.
	 *
	 * @param array<int, string> $refs References.
	 * @return void
	 */
	public function prime( array $refs ): void {
		global $wpdb;

		$refs   = self::clean_refs( $refs );
		$cached = wp_cache_get_multiple( array_map( array( self::class, 'row_cache_key' ), $refs ), self::CACHE_GROUP );
		$refs   = array_values(
			array_filter(
				$refs,
				static function ( string $ref ) use ( $cached ): bool {
					return false === ( $cached[ self::row_cache_key( $ref ) ] ?? false );
				}
			)
		);

		if ( array() === $refs ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cached right below; prepare_for_refs() prepares it.
		$rows  = $wpdb->get_results( self::prepare_for_refs( 'SELECT * FROM %i WHERE ref IN ({refs})', $refs, array( self::table_name() ) ), ARRAY_A );
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$record                  = self::hydrate( $row );
			$found[ $record['ref'] ] = $record;
		}

		// Misses are cached too, same as get() does.
		foreach ( $refs as $ref ) {
			wp_cache_set( self::row_cache_key( $ref ), $found[ $ref ] ?? null, self::CACHE_GROUP, self::ENTRY_CACHE_TTL );
		}
	}

	/**
	 * Full rows whose customer email matches, oldest first, for the privacy exporter.
	 *
	 * @param string $email  Email.
	 * @param int    $limit  Rows to fetch.
	 * @param int    $offset Rows to skip.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return array<int, Transaction>
	 */
	public function find_by_email( string $email, int $limit, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; a rare privacy request, caching it would be pointless.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_email = %s ORDER BY created_at ASC, ref ASC LIMIT %d OFFSET %d', self::table_name(), $email, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Strips the customer from the given rows. The payment itself (ref, amount, status) stays.
	 *
	 * @param array<int, string> $refs References.
	 * @return int How many rows changed.
	 */
	public function anonymize( array $refs ): int {
		global $wpdb;

		$refs = self::clean_refs( $refs );

		if ( array() === $refs ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( "UPDATE %i SET customer_name = '', customer_email = '', user_id = 0, updated_at = %d WHERE ref IN ({refs})", $refs, array( self::table_name(), time() ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * A stored price snapshot as an array, or empty when it's missing or malformed.
	 *
	 * @param string $json The pricing_snapshot column.
	 * @return array<string, mixed>
	 * @phpstan-return Snapshot
	 */
	public static function decode_snapshot( string $json ): array {
		$snapshot = '' !== $json ? json_decode( $json, true ) : null;

		if ( ! is_array( $snapshot ) ) {
			return array();
		}

		$clean = array();

		foreach ( array( 'pricing', 'coupon' ) as $key ) {
			if ( isset( $snapshot[ $key ] ) && is_array( $snapshot[ $key ] ) ) {
				$clean[ $key ] = $snapshot[ $key ];
			}
		}

		return $clean;
	}

	/**
	 * One page of the entries list, filtered, sorted and paginated in SQL.
	 *
	 * @param array<string, mixed> $args See build_where()/build_order(), plus paged and per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, paged: int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$cache_key = 'query_' . $this->last_changed() . '_' . md5( (string) wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) && isset( $cached['items'], $cached['total'], $cached['pages'], $cached['paged'] ) ) {
			return array(
				'items' => (array) $cached['items'],
				'total' => (int) $cached['total'],
				'pages' => (int) $cached['pages'],
				'paged' => (int) $cached['paged'],
			);
		}

		$table     = self::table_name();
		$where_sql = $this->build_where( $args );
		$order_sql = $this->build_order( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cached below; $where_sql is built from prepare() calls.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where_sql, $table ) );

		$per_page = max( 1, is_numeric( $args['per_page'] ?? null ) ? (int) $args['per_page'] : 20 );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( $pages, max( 1, is_numeric( $args['paged'] ?? null ) ? (int) $args['paged'] : 1 ) );

		$result = array(
			'items' => $this->fetch_page( $where_sql, $order_sql, $per_page, ( $paged - 1 ) * $per_page ),
			'total' => $total,
			'pages' => $pages,
			'paged' => $paged,
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $result;
	}

	/**
	 * Every row matching query()'s filters, unpaginated, for the export. Uncached, in batches, so memory stays flat.
	 *
	 * @param array<string, mixed> $args  Same filter/sort args as query(); paged/per_page are ignored.
	 * @param int                  $batch Rows per SELECT.
	 * @return \Generator<int, array<string, mixed>, void, void>
	 */
	public function iterate( array $args, int $batch = 500 ): \Generator {
		global $wpdb;

		$batch     = max( 1, $batch );
		$where_sql = $this->build_where( $args );
		$order_sql = $this->build_order( $args );
		$offset    = 0;

		do {
			$rows = $this->fetch_page( $where_sql, $order_sql, $batch, $offset );

			// wpdb keeps its own copy of the last result set; I drop it before yielding.
			$wpdb->flush();

			$fetched = count( $rows );

			yield from $rows;

			$offset += $batch;
		} while ( $fetched === $batch );
	}

	/**
	 * Courses/groups with at least one transaction, for the Course/Group filter.
	 *
	 * @return array<int, int>
	 */
	public function distinct_product_ids(): array {
		global $wpdb;

		$cache_key = 'distinct_product_ids_' . $this->last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, cached right below.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT product_id FROM %i WHERE product_id <> 0 ORDER BY product_id ASC', self::table_name() ) ) );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $ids;
	}

	/**
	 * Distinct non-empty payment methods, sorted, for the Method filter.
	 *
	 * @return array<int, string>
	 */
	public function distinct_methods(): array {
		global $wpdb;

		$cache_key = 'distinct_methods_' . $this->last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return array_map( 'strval', $cached );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table, cached right below.
		$methods = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pay_method FROM %i WHERE pay_method <> '' ORDER BY pay_method ASC", self::table_name() ) ) );

		wp_cache_set( $cache_key, $methods, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $methods;
	}

	/**
	 * Count and amount per status for the given filters, ignoring their `status`.
	 * Cancelled rolls into Failed, since the admin list has no Cancelled tab.
	 *
	 * @param array<string, mixed> $args Same filter args as build_where().
	 * @return array<string, array{count: int, amount: float}>
	 */
	public function status_summary( array $args = array() ): array {
		global $wpdb;

		unset( $args['status'] );

		$cache_key = 'status_summary_' . $this->last_changed() . '_' . md5( (string) wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			/**
			 * Only this method writes the key, so the shape is known.
			 *
			 * @var array<string, array{count: int, amount: float}> $cached
			 */
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cached below; the WHERE clause is built from prepare() calls.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total, SUM(amount) AS amount FROM %i' . $this->build_where( $args ) . ' GROUP BY status', self::table_name() ), ARRAY_A );

		$summary = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = self::STATUS_CANCELLED === $row['status'] ? self::STATUS_FAILED : (string) $row['status'];
			$prior  = $summary[ $status ] ?? array(
				'count'  => 0,
				'amount' => 0.0,
			);

			$summary[ $status ] = array(
				'count'  => $prior['count'] + (int) $row['total'],
				'amount' => round( $prior['amount'] + (float) $row['amount'], 2 ),
			);
		}

		wp_cache_set( $cache_key, $summary, self::CACHE_GROUP, self::LIST_CACHE_TTL );

		return $summary;
	}

	/**
	 * `course` or `group` for a LearnDash post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function product_type( int $post_id ): string {
		return 'groups' === get_post_type( $post_id ) ? 'group' : 'course';
	}

	/**
	 * Inserts a row, filling in the timestamps.
	 *
	 * @param array<string, mixed> $row The row to insert.
	 * @return bool
	 */
	private function insert( array $row ): bool {
		global $wpdb;

		$now      = time();
		$customer = $this->storable_customer( (string) $row['customer_name'], (string) $row['customer_email'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API available.
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'ref'              => (string) $row['ref'],
				'product_id'       => (int) $row['product_id'],
				'product_type'     => (string) $row['product_type'],
				'user_id'          => (int) $row['user_id'],
				'order_id'         => 0,
				'gateway_key'      => (string) $row['gateway_key'],
				'amount'           => (float) $row['amount'],
				'pricing_snapshot' => (string) wp_json_encode( $row['pricing_snapshot'] ),
				'pay_method'       => (string) ( $row['pay_method'] ?? '' ),
				'payment_url'      => (string) $row['payment_url'],
				'customer_name'    => $customer['name'],
				'customer_email'   => $customer['email'],
				'status'           => (string) $row['status'],
				'expires_at'       => (int) $row['expires_at'],
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		// unique_ref() cached a miss for this ref; a persistent cache would hide the row from the webhook.
		$this->forget( array( (string) $row['ref'] ) );

		return false !== $result;
	}

	/**
	 * Writes a status unless the row is already paid, in the WHERE clause, so a webhook landing at the same moment can't be overwritten.
	 *
	 * @param string $ref    Reference.
	 * @param string $status One of ALL_STATUSES.
	 * @return bool
	 */
	private function set_status_unless_paid( string $ref, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %d WHERE ref = %s AND status <> %s',
				self::table_name(),
				$status,
				time(),
				$ref,
				self::STATUS_PAID
			)
		);

		$this->forget( array( $ref ) );

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Sets one status on many rows in one UPDATE.
	 *
	 * @param array<int, string> $refs        References.
	 * @param string             $status      Status to write.
	 * @param string             $only_status Only touch rows currently in this status, or '' for any.
	 * @return int How many rows changed.
	 */
	private function set_status_many( array $refs, string $status, string $only_status = '' ): int {
		global $wpdb;

		$refs = self::clean_refs( $refs );

		if ( array() === $refs ) {
			return 0;
		}

		$sql     = '' !== $only_status
			? 'UPDATE %i SET status = %s, updated_at = %d WHERE ref IN ({refs}) AND status = %s'
			: 'UPDATE %i SET status = %s, updated_at = %d WHERE ref IN ({refs}) AND status <> %s';
		$guarded = '' !== $only_status ? $only_status : $status;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, cache invalidated below; prepare_for_refs() prepares it.
		$affected = $wpdb->query( self::prepare_for_refs( $sql, $refs, array( self::table_name(), $status, time() ), array( $guarded ) ) );

		$this->forget( $refs );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Updates a row and invalidates its cache entry.
	 *
	 * @param string               $ref    Reference.
	 * @param array<string, mixed> $fields Columns to update.
	 * @return bool
	 */
	private function update( string $ref, array $fields ): bool {
		global $wpdb;

		$fields['updated_at'] = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; cache is invalidated right below.
		$result = $wpdb->update( self::table_name(), $fields, array( 'ref' => $ref ) );

		$this->forget( array( $ref ) );

		return false !== $result;
	}

	/**
	 * One page of rows. I pick the refs first and join the rows back, so a deep OFFSET doesn't sort full rows.
	 *
	 * @param string $where_sql From build_where().
	 * @param string $order_sql From build_order().
	 * @param int    $limit     Rows to fetch.
	 * @param int    $offset    Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_page( string $where_sql, string $order_sql, int $limit, int $offset ): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom plugin table, callers cache; $where_sql is built from prepare() calls, $order_sql from SORTABLE_COLUMNS, and LIST_COLUMNS is a constant.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::LIST_COLUMNS . ' FROM %i AS t INNER JOIN (SELECT ref FROM %i' . $where_sql . $order_sql . ' LIMIT %d OFFSET %d) AS page USING (ref)' . $order_sql, $table, $table, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A fully prepared ` WHERE …` clause, or '' for no filter.
	 *
	 * @param array<string, mixed> $args status, product_id, pay_method, amount_min/max, refs, date_from/to, search.
	 * @return string
	 */
	private function build_where( array $args ): string {
		global $wpdb;

		$conditions = array();
		$status     = is_string( $args['status'] ?? null ) ? $args['status'] : '';

		if ( self::STATUS_FAILED === $status ) {
			// The admin list treats cancelled as a kind of failed.
			$conditions[] = $wpdb->prepare( 'status IN (%s, %s)', self::STATUS_FAILED, self::STATUS_CANCELLED );
		} elseif ( '' !== $status ) {
			$conditions[] = $wpdb->prepare( 'status = %s', $status );
		}

		$product_id = is_numeric( $args['product_id'] ?? null ) ? (int) $args['product_id'] : 0;

		if ( 0 !== $product_id ) {
			$conditions[] = $wpdb->prepare( 'product_id = %d', $product_id );
		}

		$pay_method = is_string( $args['pay_method'] ?? null ) ? $args['pay_method'] : '';

		if ( '' !== $pay_method ) {
			$conditions[] = $wpdb->prepare( 'pay_method = %s', $pay_method );
		}

		if ( is_numeric( $args['amount_min'] ?? null ) ) {
			$conditions[] = $wpdb->prepare( 'amount >= %f', (float) $args['amount_min'] );
		}

		if ( is_numeric( $args['amount_max'] ?? null ) ) {
			$conditions[] = $wpdb->prepare( 'amount <= %f', (float) $args['amount_max'] );
		}

		if ( isset( $args['refs'] ) && is_array( $args['refs'] ) && array() !== $args['refs'] ) {
			$refs = self::clean_refs( $args['refs'] );

			// A selection with nothing valid in it must match nothing, not everything.
			$conditions[] = array() === $refs ? '1 = 0' : self::prepare_for_refs( 'ref IN ({refs})', $refs );
		}

		$date_from = is_numeric( $args['date_from'] ?? null ) ? (int) $args['date_from'] : 0;

		if ( $date_from > 0 ) {
			$conditions[] = $wpdb->prepare( 'created_at >= %d', $date_from );
		}

		$date_to = is_numeric( $args['date_to'] ?? null ) ? (int) $args['date_to'] : 0;

		if ( $date_to > 0 ) {
			$conditions[] = $wpdb->prepare( 'created_at <= %d', $date_to );
		}

		$search = is_string( $args['search'] ?? null ) ? $args['search'] : '';

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$match = $wpdb->prepare( 'ref LIKE %s OR pay_method LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR request_id LIKE %s', $like, $like, $like, $like, $like );

			// The Order column shows "#19115", so I match an order ID typed with or without the "#".
			if ( 1 === preg_match( '/^#?(\d+)$/', trim( $search ), $order ) ) {
				$match .= $wpdb->prepare( ' OR order_id = %d', (int) $order[1] );
			}

			$conditions[] = '(' . $match . ')';
		}

		return array() === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions );
	}

	/**
	 * The ` ORDER BY …` clause. Ties break on `ref` so LIMIT/OFFSET pages never repeat or skip a row.
	 *
	 * @param array<string, mixed> $args orderby, order.
	 * @return string
	 */
	private function build_order( array $args ): string {
		$orderby = $args['orderby'] ?? '';

		if ( ! in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
			return ' ORDER BY created_at DESC, ref DESC';
		}

		$order = 'asc' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';

		return 'ref' === $orderby ? " ORDER BY ref {$order}" : " ORDER BY {$orderby} {$order}, ref {$order}";
	}

	/**
	 * Casts a raw ARRAY_A row to its typed shape once, so callers don't have to.
	 *
	 * @param array<string, mixed> $row A raw table row.
	 * @return array<string, mixed>
	 * @phpstan-return Transaction
	 */
	private static function hydrate( array $row ): array {
		$text = static function ( string $key ) use ( $row ): string {
			return is_scalar( $row[ $key ] ?? null ) ? (string) $row[ $key ] : '';
		};
		$int  = static function ( string $key ) use ( $row ): int {
			return is_numeric( $row[ $key ] ?? null ) ? (int) $row[ $key ] : 0;
		};

		return array(
			'ref'              => $text( 'ref' ),
			'product_id'       => $int( 'product_id' ),
			'product_type'     => $text( 'product_type' ),
			'user_id'          => $int( 'user_id' ),
			'order_id'         => $int( 'order_id' ),
			'gateway_key'      => $text( 'gateway_key' ),
			'amount'           => is_numeric( $row['amount'] ?? null ) ? (float) $row['amount'] : 0.0,
			'pricing_snapshot' => $text( 'pricing_snapshot' ),
			'pay_method'       => $text( 'pay_method' ),
			'request_id'       => $text( 'request_id' ),
			'transaction_id'   => $text( 'transaction_id' ),
			'payment_url'      => $text( 'payment_url' ),
			'customer_name'    => $text( 'customer_name' ),
			'customer_email'   => $text( 'customer_email' ),
			'status'           => $text( 'status' ),
			'expires_at'       => $int( 'expires_at' ),
			'created_at'       => $int( 'created_at' ),
			'updated_at'       => $int( 'updated_at' ),
		);
	}

	/**
	 * The customer cut down to what its columns can store.
	 * wpdb fails the whole insert on a character the charset can't hold (emoji on utf8mb3), so I strip those.
	 *
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @return array{name: string, email: string}
	 */
	private function storable_customer( string $name, string $email ): array {
		global $wpdb;

		$table = self::table_name();
		$email = false !== is_email( $email ) ? $email : '';
		$name  = $wpdb->strip_invalid_text_for_column( $table, 'customer_name', mb_substr( $name, 0, self::CUSTOMER_MAX_LENGTH ) );
		$email = $wpdb->strip_invalid_text_for_column( $table, 'customer_email', mb_substr( $email, 0, self::CUSTOMER_MAX_LENGTH ) );

		return array(
			'name'  => is_string( $name ) ? $name : '',
			'email' => is_string( $email ) ? $email : '',
		);
	}

	/**
	 * Prepares $sql with `{refs}` swapped for one %s per ref. The refs sit between $before and $after in the args.
	 *
	 * @param string             $sql    Query with a `{refs}` token inside its `IN ( … )`.
	 * @param array<int, string> $refs   Refs, already cleaned.
	 * @param array<int, mixed>  $before Args for the placeholders before `{refs}`.
	 * @param array<int, mixed>  $after  Args for the placeholders after it.
	 * @return string
	 */
	private static function prepare_for_refs( string $sql, array $refs, array $before = array(), array $after = array() ): string {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $refs ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- callers pass fixed SQL; {refs} becomes one %s per ref.
		return (string) $wpdb->prepare( str_replace( '{refs}', $placeholders, $sql ), array_merge( $before, $refs, $after ) );
	}

	/**
	 * Scalar, non-empty, de-duplicated refs, re-indexed.
	 *
	 * @param array<mixed> $refs Raw refs.
	 * @return array<int, string>
	 */
	private static function clean_refs( array $refs ): array {
		$refs = array_map( 'strval', array_filter( $refs, 'is_scalar' ) );

		return array_values(
			array_unique(
				array_filter(
					$refs,
					static function ( string $ref ): bool {
						return '' !== $ref;
					}
				)
			)
		);
	}

	/**
	 * Drops the given rows' cached copies and every cached list.
	 *
	 * @param array<int, string> $refs References.
	 * @return void
	 */
	private function forget( array $refs ): void {
		wp_cache_delete_multiple( array_map( array( self::class, 'row_cache_key' ), $refs ), self::CACHE_GROUP );
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
	}

	/**
	 * Cache key for one row.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	private static function row_cache_key( string $ref ): string {
		return 'txn_' . $ref;
	}

	/**
	 * A token bumped on every write, so list caches never need per-row invalidation.
	 *
	 * @return string
	 */
	private function last_changed(): string {
		$last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );

		if ( ! is_string( $last_changed ) ) {
			$last_changed = microtime();
			wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP );
		}

		return $last_changed;
	}
}
