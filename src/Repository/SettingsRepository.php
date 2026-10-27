<?php
/**
 * Plugin settings storage.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Repository;

use Ifthenpay\LearnDash\Host;

defined( 'ABSPATH' ) || exit;

/**
 * Reads our options.
 *
 * `iftp_ld_settings` belongs to our LearnDash settings section: LearnDash's own
 * Save writes it, and LearnDash refuses any write to it that doesn't carry the
 * section nonce. So what the Connect/Disconnect AJAX calls write lives apart, in
 * `iftp_ld_account`, and the Backoffice Key has its own non-autoloaded option
 * that never leaves the server.
 *
 * On Save the section also stores a snapshot of the enabled methods (account,
 * logo, catalog position, star), so checkout and link creation never need the
 * catalog or the Gateway Key list from ifthenpay.
 *
 * @phpstan-type GatewayRow array{key: string, alias: string, accounts: array<string, string>}
 * @phpstan-type CatalogEntry array{entity: string, alias: string, logo: string, logo_dark: string, position: int}
 * @phpstan-type MethodRow array{entity: string, alias: string, logo: string, logo_dark: string, position: int, account: string, provisioned: bool, enabled: bool, is_default: bool}
 * @phpstan-type Snapshot array{entity: string, alias: string, logo: string, logo_dark: string, position: int, account: string, is_default: bool}
 * @phpstan-type Settings array{enabled: bool, gateway_key: string, methods: list<string>, default_method: string, snapshot: list<Snapshot>, pbl_description: string, expiry_days: int, checkout_info: string}
 */
final class SettingsRepository {

	public const OPTION            = 'iftp_ld_settings';
	public const OPTION_ACCOUNT    = 'iftp_ld_account';
	public const OPTION_BACKOFFICE = 'iftp_ld_backoffice_key';
	public const OPTION_CALLBACK   = 'iftp_ld_callback';
	public const MIN_EXPIRY_DAYS   = 1;
	public const MAX_EXPIRY_DAYS   = 365;
	public const DEFAULT_EXPIRY    = 3;

	/**
	 * Cached settings for this request.
	 *
	 * @var array<string, mixed>|null
	 * @phpstan-var Settings|null
	 */
	private $settings = null;

	/**
	 * Cached Gateway Key rows for this request.
	 *
	 * @var list<array{key: string, alias: string, accounts: array<string, string>}>|null
	 */
	private $gateways = null;

	/**
	 * All settings, typed.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Settings
	 */
	public function all(): array {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$this->settings = array(
			'enabled'         => 'yes' === ( $stored['enabled'] ?? '' ),
			'gateway_key'     => is_string( $stored['gateway_key'] ?? null ) ? $stored['gateway_key'] : '',
			'methods'         => self::entities( $stored['methods'] ?? array() ),
			'default_method'  => self::entity( $stored['default_method'] ?? '' ),
			'snapshot'        => self::clean_snapshot( $stored['snapshot'] ?? array() ),
			'pbl_description' => is_string( $stored['pbl_description'] ?? null ) ? $stored['pbl_description'] : '',
			'expiry_days'     => self::clamp_expiry( is_numeric( $stored['expiry_days'] ?? null ) ? (int) $stored['expiry_days'] : self::DEFAULT_EXPIRY ),
			'checkout_info'   => is_string( $stored['checkout_info'] ?? null ) ? $stored['checkout_info'] : '',
		);

		return $this->settings;
	}

	/**
	 * Drops the per-request cache (after LearnDash saved the section).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->settings = null;
		$this->gateways = null;
	}

	/**
	 * The stored Backoffice Key.
	 *
	 * @return string
	 */
	public function backoffice_key(): string {
		return (string) get_option( self::OPTION_BACKOFFICE, '' );
	}

	/**
	 * Whether a Backoffice Key is stored.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return '' !== $this->backoffice_key();
	}

	/**
	 * Stores the Backoffice Key and the Gateway Keys it can see.
	 *
	 * @param string $backoffice_key Validated key.
	 * @param array  $gateways       Rows from ifthenpay.
	 * @phpstan-param list<GatewayRow> $gateways
	 * @return void
	 */
	public function connect( string $backoffice_key, array $gateways ): void {
		update_option( self::OPTION_BACKOFFICE, $backoffice_key, false );
		update_option( self::OPTION_ACCOUNT, array( 'gateways' => $gateways ), true );

		$this->flush();
	}

	/**
	 * Forgets the key and everything fetched with it.
	 *
	 * The section's own values (Gateway Key, methods, texts) stay in LearnDash's option:
	 * without a connection is_ready() is false anyway, and reconnecting the same account
	 * brings the configuration back.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		delete_option( self::OPTION_BACKOFFICE );
		delete_option( self::OPTION_ACCOUNT );
		delete_option( self::OPTION_CALLBACK );

		$this->flush();
	}

	/**
	 * Gateway Keys the connected account can use.
	 *
	 * @return list<array{key: string, alias: string, accounts: array<string, string>}>
	 */
	public function gateways(): array {
		if ( null !== $this->gateways ) {
			return $this->gateways;
		}

		$stored = get_option( self::OPTION_ACCOUNT, array() );
		$rows   = array();

		foreach ( (array) ( is_array( $stored ) ? ( $stored['gateways'] ?? array() ) : array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && is_scalar( $row['key'] ) && '' !== (string) $row['key'] ) {
				$rows[] = array(
					'key'      => (string) $row['key'],
					'alias'    => is_scalar( $row['alias'] ?? null ) ? (string) $row['alias'] : '',
					'accounts' => array_map( 'strval', array_filter( (array) ( $row['accounts'] ?? array() ), 'is_scalar' ) ),
				);
			}
		}

		$this->gateways = $rows;

		return $rows;
	}

	/**
	 * A Gateway Key row of the connected account.
	 *
	 * @param string $gateway_key Gateway Key.
	 * @return array{key: string, alias: string, accounts: array<string, string>}|null
	 */
	public function gateway_row( string $gateway_key ): ?array {
		foreach ( $this->gateways() as $row ) {
			if ( $row['key'] === $gateway_key ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The saved Gateway Key, or '' when it isn't on the connected account.
	 *
	 * @return string
	 */
	public function gateway_key(): string {
		$gateway_key = $this->all()['gateway_key'];

		return '' !== $gateway_key && null !== $this->gateway_row( $gateway_key ) ? $gateway_key : '';
	}

	/**
	 * The Gateway Key the section should preselect.
	 *
	 * @return string
	 */
	public function selected_gateway_key(): string {
		$gateway_key = $this->gateway_key();

		if ( '' === $gateway_key && 1 === count( $this->gateways() ) ) {
			$gateway_key = $this->gateways()[0]['key'];
		}

		return $gateway_key;
	}

	/**
	 * Method rows for a Gateway Key: the visible catalog merged with the row's accounts.
	 *
	 * @param string            $gateway_key     Gateway Key.
	 * @param array             $catalog         Methods catalog.
	 * @phpstan-param list<CatalogEntry> $catalog
	 * @param list<string>|null $enabled_methods Enabled entities; null = the saved ones.
	 * @param string|null       $default_method  Starred entity; null = the saved one.
	 * @return list<MethodRow>
	 */
	public function method_rows( string $gateway_key, array $catalog, ?array $enabled_methods = null, ?string $default_method = null ): array {
		$row      = $this->gateway_row( $gateway_key );
		$accounts = null === $row ? array() : $row['accounts'];
		$saved    = $gateway_key === $this->all()['gateway_key'];
		$enabled  = $enabled_methods ?? ( $saved ? $this->all()['methods'] : array() );
		$default  = $default_method ?? ( $saved ? $this->all()['default_method'] : '' );
		$rows     = array();

		foreach ( $catalog as $entry ) {
			$account     = $accounts[ $entry['entity'] ] ?? '';
			$provisioned = '' !== $account;
			$is_enabled  = $provisioned && in_array( $entry['entity'], $enabled, true );

			$rows[] = array(
				'entity'      => $entry['entity'],
				'alias'       => $entry['alias'],
				'logo'        => $entry['logo'],
				'logo_dark'   => $entry['logo_dark'] ?? '',
				'position'    => $entry['position'],
				'account'     => $account,
				'provisioned' => $provisioned,
				'enabled'     => $is_enabled,
				'is_default'  => $is_enabled && $entry['entity'] === $default,
			);
		}

		return $rows;
	}

	/**
	 * The snapshot for some method rows: the enabled ones only.
	 *
	 * @param array $rows Method rows.
	 * @phpstan-param list<MethodRow> $rows
	 * @return list<Snapshot>
	 */
	public static function snapshot_from_rows( array $rows ): array {
		$snapshot = array();

		foreach ( $rows as $row ) {
			if ( $row['enabled'] ) {
				$snapshot[] = array(
					'entity'     => $row['entity'],
					'alias'      => $row['alias'],
					'logo'       => $row['logo'],
					'logo_dark'  => $row['logo_dark'],
					'position'   => $row['position'],
					'account'    => $row['account'],
					'is_default' => $row['is_default'],
				);
			}
		}

		return $snapshot;
	}

	/**
	 * Enabled methods as saved.
	 *
	 * @return list<Snapshot>
	 */
	public function snapshot(): array {
		return $this->all()['snapshot'];
	}

	/**
	 * Whether checkout can offer ifthenpay.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return $this->all()['enabled']
			&& $this->is_connected()
			&& '' !== $this->gateway_key()
			&& array() !== $this->snapshot()
			&& self::is_eur_site();
	}

	/**
	 * Text under the checkout option; the default follows the locale until saved.
	 *
	 * @param string $product_type `course`, `group` or ''.
	 * @return string
	 */
	public function checkout_info( string $product_type = '' ): string {
		$info = trim( $this->all()['checkout_info'] );

		if ( '' !== $info ) {
			return $info;
		}

		if ( 'group' === $product_type ) {
			return __( 'You will be redirected to ifthenpay’s secure page to pay with Multibanco, MB WAY, card and more. Once the payment is confirmed, you get access to the group.', 'ifthenpay-payments-for-learndash' );
		}

		return __( 'You will be redirected to ifthenpay’s secure page to pay with Multibanco, MB WAY, card and more. Once the payment is confirmed, you get access to the course.', 'ifthenpay-payments-for-learndash' );
	}

	/**
	 * Text for ifthenpay's hosted page.
	 *
	 * @return string
	 */
	public function pbl_description(): string {
		$description = trim( $this->all()['pbl_description'] );

		return '' !== $description ? $description : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Whether LearnDash sells in euros.
	 *
	 * @return bool
	 */
	public static function is_eur_site(): bool {
		return 'EUR' === Host::currency();
	}

	/**
	 * Keeps expiry days inside the range we accept.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function clamp_expiry( int $days ): int {
		return max( self::MIN_EXPIRY_DAYS, min( self::MAX_EXPIRY_DAYS, $days ) );
	}

	/**
	 * An entity code reduced to [A-Z0-9].
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function entity( $value ): string {
		return is_scalar( $value ) ? strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) ) : '';
	}

	/**
	 * A list of entity codes.
	 *
	 * @param mixed $values Raw values.
	 * @return list<string>
	 */
	public static function entities( $values ): array {
		$entities = array();

		foreach ( (array) $values as $value ) {
			$entity = self::entity( $value );

			if ( '' !== $entity && ! in_array( $entity, $entities, true ) ) {
				$entities[] = $entity;
			}
		}

		return $entities;
	}

	/**
	 * A stored snapshot, typed.
	 *
	 * @param mixed $stored Raw snapshot.
	 * @return list<Snapshot>
	 */
	private static function clean_snapshot( $stored ): array {
		$snapshot = array();

		foreach ( (array) $stored as $row ) {
			if ( is_array( $row ) && ! empty( $row['entity'] ) && ! empty( $row['account'] ) ) {
				$snapshot[] = array(
					'entity'     => self::entity( $row['entity'] ),
					'alias'      => is_scalar( $row['alias'] ?? null ) ? (string) $row['alias'] : '',
					'logo'       => is_scalar( $row['logo'] ?? null ) ? (string) $row['logo'] : '',
					'logo_dark'  => is_scalar( $row['logo_dark'] ?? null ) ? (string) $row['logo_dark'] : '',
					'position'   => is_numeric( $row['position'] ?? null ) ? (int) $row['position'] : 0,
					'account'    => (string) $row['account'],
					'is_default' => ! empty( $row['is_default'] ),
				);
			}
		}

		return $snapshot;
	}
}
