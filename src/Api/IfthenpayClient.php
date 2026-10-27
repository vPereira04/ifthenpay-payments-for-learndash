<?php
/**
 * HTTP client for ifthenpay.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Every call this plugin makes to ifthenpay.
 *
 * @phpstan-import-type GatewayRow from \Ifthenpay\LearnDash\Repository\SettingsRepository
 * @phpstan-import-type CatalogEntry from \Ifthenpay\LearnDash\Repository\SettingsRepository
 * @phpstan-type LinkResult array{ok: bool, url: string, error: string}
 */
final class IfthenpayClient {

	public const API_BASE         = 'https://api.ifthenpay.com';
	public const MOBILE_API       = 'https://ifthenpay.com/IfmbWS/ifthenpaymobile.asmx';
	public const GATEWAY_TYPE     = 'Forminator';
	public const CALLBACK_CMS     = 'Forminator';
	public const CATALOG_CACHE    = 'iftp_ld_methods_catalog';
	private const TIMEOUT         = 20;
	private const CATALOG_TTL     = 12 * HOUR_IN_SECONDS;
	private const ACCOUNT_ALIASES = array(
		'MB'     => array( 'MULTIBANCO' ),
		'MBWAY'  => array( 'MBW' ),
		'CCARD'  => array( 'CARD', 'CREDITCARD', 'CARTAO' ),
		'GOOGLE' => array( 'GOOGLEPAY' ),
		'APPLE'  => array( 'APPLEPAY' ),
	);

	/**
	 * Gateway Keys the Backoffice Key can see.
	 *
	 * @param string $backoffice_key Backoffice Key.
	 * @return list<GatewayRow>|null Null when ifthenpay couldn't be reached.
	 */
	public function get_gateway_rows( string $backoffice_key ): ?array {
		$url = add_query_arg(
			array(
				'boKey' => rawurlencode( $backoffice_key ),
				/**
				 * Filters the gateway type tag used to list Gateway Keys.
				 *
				 * @param string $type Type tag.
				 */
				'Type'  => rawurlencode( (string) apply_filters( 'iftp_ld_gateway_type', self::GATEWAY_TYPE ) ),
			),
			self::API_BASE . '/gateway/get'
		);

		$body = $this->get_json( $url );

		if ( null === $body ) {
			return null;
		}

		$list = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
		$rows = array();

		foreach ( $list as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$key = self::first_string( $raw, array( 'GatewayKey', 'gatewayKey', 'Chave', 'chave', 'Key', 'key' ) );

			if ( '' === $key ) {
				continue;
			}

			$rows[] = array(
				'key'      => $key,
				'alias'    => self::first_string( $raw, array( 'Alias', 'alias', 'Descricao', 'descricao', 'Description', 'description' ) ),
				'accounts' => self::accounts_from_row( $raw ),
			);
		}

		return $rows;
	}

	/**
	 * Per-method accounts through the Mobile API, for rows that don't carry them.
	 *
	 * @param string $backoffice_key Backoffice Key.
	 * @param string $gateway_key    Gateway Key.
	 * @return array<string, string> Entity => account.
	 */
	public function get_accounts_by_gateway_key( string $backoffice_key, string $gateway_key ): array {
		$url = add_query_arg(
			array(
				'backofficekey' => rawurlencode( $backoffice_key ),
				'gatewayKey'    => rawurlencode( $gateway_key ),
			),
			self::MOBILE_API . '/GetAccountsByGatewayKey'
		);

		$body     = $this->get_json( $url );
		$accounts = array();

		foreach ( (array) $body as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$entity  = strtoupper( self::first_string( $raw, array( 'Entidade', 'entidade', 'Entity' ) ) );
			$account = self::first_string( $raw, array( 'Conta', 'conta', 'Account' ) );

			// A numeric entity is a Multibanco entity number.
			if ( '' !== $entity && ctype_digit( $entity ) ) {
				$entity = 'MB';
			}

			if ( '' !== $entity && '' !== $account && ! isset( $accounts[ $entity ] ) ) {
				$accounts[ $entity ] = $account;
			}
		}

		return $accounts;
	}

	/**
	 * The visible methods catalog, ordered by position.
	 *
	 * @return list<CatalogEntry>
	 */
	public function get_methods_catalog(): array {
		$cached = get_transient( self::CATALOG_CACHE );

		if ( is_array( $cached ) && array() !== $cached ) {
			/**
			 * Cached catalog.
			 *
			 * @var list<CatalogEntry> $cached
			 */
			return $cached;
		}

		$body    = $this->get_json( self::API_BASE . '/gateway/methods/available' );
		$catalog = array();

		foreach ( (array) $body as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['IsVisible'] ) ) {
				continue;
			}

			$entity = strtoupper( self::first_string( $raw, array( 'Entity', 'entity' ) ) );

			if ( '' === $entity ) {
				continue;
			}

			$catalog[] = array(
				'entity'    => $entity,
				// The live API sends Method and SmallImageUrl/ImageUrl; Alias and Logo are the older names.
				'alias'     => self::first_string( $raw, array( 'Method', 'Alias', 'alias' ) ),
				'logo'      => esc_url_raw( self::first_string( $raw, array( 'SmallImageUrl', 'ImageUrl', 'Logo', 'logo' ) ) ),
				'logo_dark' => esc_url_raw( self::first_string( $raw, array( 'SmallImageUrlDark', 'ImageUrlDark' ) ) ),
				'position'  => (int) ( $raw['Position'] ?? 0 ),
			);
		}

		usort(
			$catalog,
			static function ( array $a, array $b ): int {
				return $a['position'] <=> $b['position'];
			}
		);

		// A catalog without a single logo means the API changed shape again; I don't want that stuck for a whole TTL.
		if ( array() !== array_filter( array_column( $catalog, 'logo' ) ) ) {
			set_transient( self::CATALOG_CACHE, $catalog, self::CATALOG_TTL );
		}

		return $catalog;
	}

	/**
	 * Creates a Pay by Link.
	 *
	 * @param string               $gateway_key Gateway Key.
	 * @param array<string, mixed> $payload     PBL body.
	 * @return array<string, mixed>
	 * @phpstan-return LinkResult
	 */
	public function create_pay_by_link( string $gateway_key, array $payload ): array {
		$response = wp_remote_post(
			self::API_BASE . '/gateway/pinpay/' . rawurlencode( $gateway_key ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
				'timeout' => self::TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'url'   => '',
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$url  = is_array( $body ) ? self::first_string( $body, array( 'redirect_url', 'RedirectUrl', 'redirectUrl' ) ) : '';

		if ( $code < 200 || $code >= 300 || '' === $url || ! self::is_ifthenpay_url( $url ) ) {
			return array(
				'ok'    => false,
				'url'   => '',
				'error' => is_array( $body ) ? self::first_string( $body, array( 'Message', 'message', 'error' ) ) : '',
			);
		}

		return array(
			'ok'    => true,
			'url'   => $url,
			'error' => '',
		);
	}

	/**
	 * Registers our callback URL for a Gateway Key.
	 *
	 * @param string $gateway_key  Gateway Key.
	 * @param string $callback_url URL with ifthenpay's placeholders.
	 * @return bool
	 */
	public function activate_callback( string $gateway_key, string $callback_url ): bool {
		/**
		 * Filters the CMS tag sent with the callback registration.
		 *
		 * @param string $cms CMS tag.
		 */
		$cms = (string) apply_filters( 'iftp_ld_callback_cms', self::CALLBACK_CMS );
		$url = add_query_arg( 'cms', rawurlencode( $cms ), self::API_BASE . '/endpoint/callback/activation/' );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						// ifthenpay echoes this back as the callback's anti-phishing key.
						'apKey' => base64_encode( $gateway_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ifthenpay's anti-phishing key format, not obfuscation.
						'chave' => $gateway_key,
						'urlCb' => $callback_url,
					)
				),
				'timeout' => self::TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}

	/**
	 * Pay by Link pages live on pinpay.pt or ifthenpay.com.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_ifthenpay_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		foreach ( array( 'pinpay.pt', 'ifthenpay.com' ) as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * GETs a JSON body; null on a transport error or a non-2xx answer.
	 *
	 * @param string $url URL.
	 * @return array<mixed>|null
	 */
	private function get_json( string $url ): ?array {
		$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$raw  = trim( (string) wp_remote_retrieve_body( $response ) );
		$body = json_decode( $raw, true );

		// The Mobile API wraps its JSON in an XML <string> element.
		if ( ! is_array( $body ) && '' !== $raw && '<' === $raw[0] ) {
			$body = json_decode( html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES | ENT_XML1 ), true );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Reads per-method account columns off a gateway row.
	 *
	 * The row's column names vary in casing (and MB can come as "Multibanco"), so I
	 * normalise them before matching each entity.
	 *
	 * @param array<mixed> $raw Row.
	 * @return array<string, string>
	 */
	private static function accounts_from_row( array $raw ): array {
		$columns = array();

		foreach ( $raw as $column => $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$columns[ strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $column ) ) ] = trim( $value );
			}
		}

		$accounts = array();

		foreach ( array( 'MB', 'MBWAY', 'PAYSHOP', 'CCARD', 'COFIDIS', 'GOOGLE', 'APPLE', 'PIX' ) as $entity ) {
			foreach ( array_merge( array( $entity ), self::ACCOUNT_ALIASES[ $entity ] ?? array() ) as $name ) {
				if ( isset( $columns[ $name ] ) ) {
					$accounts[ $entity ] = $columns[ $name ];
					break;
				}
			}
		}

		return $accounts;
	}

	/**
	 * First non-empty scalar among the given keys.
	 *
	 * @param array<mixed> $source Source.
	 * @param array        $keys   Candidate keys.
	 * @phpstan-param list<string> $keys
	 * @return string
	 */
	private static function first_string( array $source, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && '' !== trim( (string) $source[ $key ] ) ) {
				return trim( (string) $source[ $key ] );
			}
		}

		return '';
	}
}
