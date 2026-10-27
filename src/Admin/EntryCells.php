<?php
/**
 * Cell markup shared by the entries list, its details row and the entry page.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Api\IfthenpayClient;
use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Payments\TransactionStore;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one payment's values the way every Payments surface shows them.
 *
 * A page can hold 250 rows, so titles, labels and the logo catalog are memoized per request.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
final class EntryCells {

	// "2 hours ago" up to this age, then the short date.
	private const RELATIVE_DATE_MAX_AGE = 30 * DAY_IN_SECONDS;

	// Names that match no entity code or alias even after method_key().
	private const METHOD_SYNONYMS = array( 'CREDITCARD' => 'CCARD' );

	/**
	 * Settings, for the enabled methods' logos.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * API client, for the full methods catalog.
	 *
	 * @var IfthenpayClient
	 */
	private $client;

	/**
	 * Per-request memo.
	 *
	 * @var array<string, string>
	 */
	private $memo = array();

	/**
	 * Method key => logos and label.
	 *
	 * @var array<string, array{entity: string, alias: string, logo: string, logo_dark: string}>|null
	 */
	private $methods = null;

	/**
	 * Whether the remote catalog was merged in already.
	 *
	 * @var bool
	 */
	private $catalog_loaded = false;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings.
	 * @param IfthenpayClient    $client   API client.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Status slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			TransactionStore::STATUS_PENDING   => __( 'Pending', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_PAID      => __( 'Paid', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_FAILED    => __( 'Failed', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_CANCELLED => __( 'Cancelled', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_EXPIRED   => __( 'Expired', 'ifthenpay-payments-for-learndash' ),
		);
	}

	/**
	 * A status's label.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		return self::status_labels()[ $status ] ?? $status;
	}

	/**
	 * Prints LearnDash's order status pill. Paid is LearnDash's base pill; expired is our modifier in the same family.
	 *
	 * @param string $status Status slug.
	 * @return void
	 */
	public function print_status( string $status ): void {
		$class = 'post-type-sfwd-transactions__transaction-status';

		if ( TransactionStore::STATUS_PAID !== $status ) {
			$class .= ' post-type-sfwd-transactions__transaction-status--' . sanitize_html_class( $status );
		}

		echo '<span class="' . esc_attr( $class ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/**
	 * An amount in the site's number format.
	 *
	 * @param float $amount Euros.
	 * @return string
	 */
	public static function format_amount( float $amount ): string {
		return number_format_i18n( $amount, 2 ) . ' €';
	}

	/**
	 * Prints the dash for an empty value, the way core list tables do.
	 *
	 * @param string $label What's missing, already translated.
	 * @return void
	 */
	public static function print_empty( string $label ): void {
		echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Prints an email, as a mailto only when it can't inject headers (is_email() lets ?&#% through).
	 *
	 * @param string $email Email.
	 * @param string $css_class Class for the element.
	 * @return void
	 */
	public static function print_email( string $email, string $css_class ): void {
		if ( '' === $email ) {
			return;
		}

		if ( false === strpbrk( $email, '?&#%' ) ) {
			echo '<a class="' . esc_attr( $css_class ) . '" href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			return;
		}

		echo '<span class="' . esc_attr( $css_class ) . '">' . esc_html( $email ) . '</span>';
	}

	/**
	 * Prints the student: name (linked to the user) over the email.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	public function print_student( array $row ): void {
		$name  = $row['customer_name'];
		$email = $row['customer_email'];

		if ( '' === $name && '' === $email ) {
			self::print_empty( __( 'No student', 'ifthenpay-payments-for-learndash' ) );
			return;
		}

		$link = $row['user_id'] > 0 ? (string) get_edit_user_link( $row['user_id'] ) : '';

		echo '<span class="iftp-ld-student">';

		if ( '' !== $name ) {
			if ( '' !== $link ) {
				echo '<a class="iftp-ld-student__name" href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
			} else {
				echo '<span class="iftp-ld-student__name">' . esc_html( $name ) . '</span>';
			}
		}

		self::print_email( $email, 'iftp-ld-student__email' );

		echo '</span>';
	}

	/**
	 * A course or group title, or "#id" when it's gone.
	 *
	 * @param int $product_id Course or group ID.
	 * @return string
	 */
	public function product_title( int $product_id ): string {
		$key = 'title_' . $product_id;

		if ( ! isset( $this->memo[ $key ] ) ) {
			$title              = get_post( $product_id ) instanceof \WP_Post ? get_the_title( $product_id ) : '';
			$this->memo[ $key ] = '' !== $title ? $title : '#' . $product_id;
		}

		return $this->memo[ $key ];
	}

	/**
	 * "Course" or "Group" with LearnDash's custom labels, from the stored type so a deleted product still reads right.
	 *
	 * @param string $product_type `course` or `group`.
	 * @return string
	 */
	public function product_type_label( string $product_type ): string {
		$key = 'type_' . $product_type;

		if ( ! isset( $this->memo[ $key ] ) ) {
			$label_key          = 'group' === $product_type ? 'group' : 'course';
			$this->memo[ $key ] = class_exists( '\LearnDash_Custom_Label' ) ? (string) \LearnDash_Custom_Label::get_label( $label_key ) : ucfirst( $label_key );
		}

		return $this->memo[ $key ];
	}

	/**
	 * Prints the Course/Group cell: title linked to its edit screen, then the type caption. Ad hoc rows without one say so.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	public function print_product( array $row ): void {
		if ( $row['product_id'] <= 0 ) {
			echo '<span class="iftp-ld-product__adhoc">' . esc_html__( 'Ad Hoc', 'ifthenpay-payments-for-learndash' ) . '</span>';
			return;
		}

		$title = $this->product_title( $row['product_id'] );
		$link  = (string) get_edit_post_link( $row['product_id'], 'raw' );

		echo '<span class="iftp-ld-product">';

		if ( '' !== $link ) {
			echo '<a class="iftp-ld-product__title" href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo '<span class="iftp-ld-product__title">' . esc_html( $title ) . '</span>';
		}

		echo '<span class="iftp-ld-product__type">' . esc_html( $this->product_type_label( $row['product_type'] ) ) . '</span>';
		echo '</span>';
	}

	/**
	 * Prints a hover/focus tip on a short text, in LearnDash's copy-text tooltip look.
	 *
	 * @param string $text Visible text.
	 * @param string $tip  Tooltip.
	 * @return void
	 */
	public static function print_tip( string $text, string $tip ): void {
		printf(
			'<span class="iftp-ld-tip" tabindex="0" data-tooltip="%1$s">%2$s <span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span class="screen-reader-text">%3$s</span></span>',
			esc_attr( $tip ),
			esc_html( $text ),
			esc_html( '(' . $tip . ')' )
		);
	}

	/**
	 * Prints the Order cell: LearnDash's order once paid, otherwise why there's none.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	public function print_order( array $row ): void {
		if ( $row['order_id'] > 0 ) {
			$url = Host::order_edit_url( $row['order_id'] );

			if ( '' !== $url ) {
				echo '<a class="iftp-ld-order-link" href="' . esc_url( $url ) . '">#' . esc_html( (string) $row['order_id'] ) . '</a>';
			} else {
				self::print_tip( '#' . $row['order_id'], __( 'This LearnDash order was deleted.', 'ifthenpay-payments-for-learndash' ) );
			}

			return;
		}

		$status = $row['status'];

		if ( TransactionStore::STATUS_PENDING === $status ) {
			self::print_tip( __( 'Not paid yet', 'ifthenpay-payments-for-learndash' ), __( 'LearnDash creates the order once the payment is paid.', 'ifthenpay-payments-for-learndash' ) );
			return;
		}

		if ( TransactionStore::STATUS_PAID !== $status ) {
			echo '<span class="iftp-ld-order-none">' . esc_html__( 'Not paid', 'ifthenpay-payments-for-learndash' ) . '</span>';
			return;
		}

		if ( $row['user_id'] <= 0 ) {
			self::print_tip( __( 'No order', 'ifthenpay-payments-for-learndash' ), __( 'Ad hoc payments don’t enrol anyone, so LearnDash has no order for them.', 'ifthenpay-payments-for-learndash' ) );
			return;
		}

		self::print_tip( __( 'Not recorded', 'ifthenpay-payments-for-learndash' ), __( 'LearnDash didn’t record this order. Mark the payment as paid again to retry.', 'ifthenpay-payments-for-learndash' ) );
	}

	/**
	 * Prints the Method cell: bare logo plus label, or a placeholder while there's no method.
	 *
	 * @param string $pay_method The row's pay_method.
	 * @param string $status     The row's status.
	 * @return void
	 */
	public function print_method( string $pay_method, string $status ): void {
		if ( '' === $pay_method ) {
			if ( TransactionStore::STATUS_PENDING === $status ) {
				echo '<span class="iftp-ld-method iftp-ld-method--placeholder" title="' . esc_attr__( 'The student hasn’t picked a payment method yet.', 'ifthenpay-payments-for-learndash' ) . '">' . esc_html__( 'Awaiting selection', 'ifthenpay-payments-for-learndash' ) . '</span>';
			} elseif ( TransactionStore::STATUS_EXPIRED === $status ) {
				echo '<span class="iftp-ld-method iftp-ld-method--placeholder" title="' . esc_attr__( 'The payment expired before the student picked a payment method.', 'ifthenpay-payments-for-learndash' ) . '">' . esc_html__( 'Expired payment', 'ifthenpay-payments-for-learndash' ) . '</span>';
			} else {
				self::print_empty( __( 'No payment method', 'ifthenpay-payments-for-learndash' ) );
			}

			return;
		}

		if ( TransactionStore::AD_HOC_METHOD === $pay_method ) {
			echo '<span class="iftp-ld-method iftp-ld-method--placeholder">' . esc_html__( 'Ad hoc', 'ifthenpay-payments-for-learndash' ) . '</span>';
			return;
		}

		$method = $this->method( $pay_method );

		echo '<span class="iftp-ld-method"' . ( null !== $method ? ' data-entity="' . esc_attr( $method['entity'] ) . '"' : '' ) . '>';
		echo '<span class="iftp-ld-method__logo-slot" aria-hidden="true">';

		if ( null !== $method && '' !== $method['logo'] ) {
			echo '<img class="iftp-ld-method__logo" src="' . esc_url( $method['logo'] ) . '" alt="" loading="lazy" />';
		}

		echo '</span>';
		echo '<span class="iftp-ld-method__label">' . esc_html( $this->method_label( $pay_method ) ) . '</span>';
		echo '</span>';
	}

	/**
	 * A method's display label: its alias when known, the stored code otherwise.
	 *
	 * @param string $pay_method Stored method.
	 * @return string
	 */
	public function method_label( string $pay_method ): string {
		if ( TransactionStore::AD_HOC_METHOD === $pay_method ) {
			return __( 'Ad hoc', 'ifthenpay-payments-for-learndash' );
		}

		$method = $this->method( $pay_method );

		return null !== $method && '' !== $method['alias'] ? $method['alias'] : $pay_method;
	}

	/**
	 * Prints a timestamp: "2 hours ago" while recent, the short date after that, the full date on hover.
	 *
	 * @param int $timestamp Unix time.
	 * @return void
	 */
	public function print_created( int $timestamp ): void {
		if ( $timestamp <= 0 ) {
			self::print_empty( __( 'No date', 'ifthenpay-payments-for-learndash' ) );
			return;
		}

		$age = time() - $timestamp;

		if ( $age >= 0 && $age < self::RELATIVE_DATE_MAX_AGE ) {
			/* translators: %s: time since, e.g. "2 hours". */
			$label = sprintf( __( '%s ago', 'ifthenpay-payments-for-learndash' ), human_time_diff( $timestamp ) );
		} else {
			$label = (string) wp_date( $this->short_datetime_format(), $timestamp );
		}

		printf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( (string) wp_date( 'c', $timestamp ) ),
			esc_attr( $this->full_date( $timestamp ) ),
			esc_html( $label )
		);
	}

	/**
	 * Prints a full date in a <time>.
	 *
	 * @param int $timestamp Unix time; 0 prints the empty dash.
	 * @return void
	 */
	public function print_full_date( int $timestamp ): void {
		if ( $timestamp <= 0 ) {
			self::print_empty( __( 'No date', 'ifthenpay-payments-for-learndash' ) );
			return;
		}

		printf( '<time datetime="%1$s">%2$s</time>', esc_attr( (string) wp_date( 'c', $timestamp ) ), esc_html( $this->full_date( $timestamp ) ) );
	}

	/**
	 * The site's date + time.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public function full_date( int $timestamp ): string {
		if ( ! isset( $this->memo['datetime_format'] ) ) {
			$this->memo['datetime_format'] = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
		}

		return (string) wp_date( $this->memo['datetime_format'], $timestamp );
	}

	/**
	 * A compact, translatable date + time format.
	 *
	 * @return string
	 */
	private function short_datetime_format(): string {
		if ( ! isset( $this->memo['short_format'] ) ) {
			/* translators: compact date and time format for the payments table, see https://www.php.net/manual/datetime.format.php */
			$this->memo['short_format'] = _x( 'd/m/Y H:i', 'entries date', 'ifthenpay-payments-for-learndash' );
		}

		return $this->memo['short_format'];
	}

	/**
	 * Whether a row can be sent the payment email now: pending, with a valid student email.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return bool
	 */
	public static function can_email( array $row ): bool {
		return TransactionStore::STATUS_PENDING === $row['status'] && false !== is_email( $row['customer_email'] );
	}

	/**
	 * Whether a row's link can still be paid.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return bool
	 */
	public static function has_live_link( array $row ): bool {
		return TransactionStore::STATUS_PENDING === $row['status'] && '' !== $row['payment_url'] && ( 0 === $row['expires_at'] || $row['expires_at'] > time() );
	}

	/**
	 * The logos and label for a stored method, or null when nothing matches.
	 *
	 * @param string $pay_method Stored method (entity code or display name).
	 * @return array{entity: string, alias: string, logo: string, logo_dark: string}|null
	 */
	private function method( string $pay_method ): ?array {
		$key = self::method_key( $pay_method );

		if ( null === $this->methods ) {
			$this->methods = array();
			$this->add_methods( $this->settings->snapshot() );
		}

		// The enabled methods cover most rows; the catalog (cached 12h) only loads for the rest.
		if ( ! isset( $this->methods[ $key ] ) && ! $this->catalog_loaded && $this->settings->is_connected() ) {
			$this->catalog_loaded = true;
			$this->add_methods( $this->client->get_methods_catalog() );
		}

		return $this->methods[ $key ] ?? null;
	}

	/**
	 * Indexes methods by entity code and alias, keeping what's there.
	 *
	 * @param array<int, array<string, mixed>> $methods Snapshot or catalog entries.
	 * @return void
	 */
	private function add_methods( array $methods ): void {
		foreach ( $methods as $method ) {
			$entity = is_string( $method['entity'] ?? null ) ? $method['entity'] : '';

			if ( '' === $entity ) {
				continue;
			}

			$entry = array(
				'entity'    => $entity,
				'alias'     => is_string( $method['alias'] ?? null ) ? $method['alias'] : '',
				'logo'      => is_string( $method['logo'] ?? null ) ? $method['logo'] : '',
				'logo_dark' => is_string( $method['logo_dark'] ?? null ) ? $method['logo_dark'] : '',
			);

			$this->methods += array( self::method_key( $entity ) => $entry );

			if ( '' !== $entry['alias'] ) {
				$this->methods += array( self::method_key( $entry['alias'] ) => $entry );
			}
		}

		unset( $this->methods[''] );
	}

	/**
	 * Normalizes a method name: `MB WAY`, `mbway` and `MBWAY` are one key.
	 *
	 * @param string $name Entity code, alias or display name.
	 * @return string
	 */
	private static function method_key( string $name ): string {
		$key = (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $name ) );

		return self::METHOD_SYNONYMS[ $key ] ?? $key;
	}
}
