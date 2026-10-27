<?php
/**
 * One payment on its own page, inside Payments.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Payments\TransactionStore;

defined( 'ABSPATH' ) || exit;

/**
 * The separate entry page (`&entry_id={ref}&_wpnonce=…` on the same admin page), laid out like
 * LearnDash's order edit screen: hero and journey, Transaction and Course cards, an aside with
 * Actions, Student and Timestamps. Its tables are also the inline details row's.
 *
 * @phpstan-import-type Transaction from TransactionStore
 */
final class EntryPage {

	private const NONCE_PREFIX = 'iftp_ld_entry_';

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Shared cell markup.
	 *
	 * @var EntryCells
	 */
	private $cells;

	/**
	 * The row being shown, loaded on `load-{hook}`.
	 *
	 * @var array<string, mixed>|null
	 * @phpstan-var Transaction|null
	 */
	private $row = null;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore $store Attempts.
	 * @param EntryCells       $cells Shared cell markup.
	 */
	public function __construct( TransactionStore $store, EntryCells $cells ) {
		$this->store = $store;
		$this->cells = $cells;
	}

	/**
	 * The entry page URL for a reference, with its view nonce.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function url( string $ref ): string {
		return add_query_arg(
			array(
				'page'     => AdminMenu::PAGE_SLUG,
				'entry_id' => rawurlencode( $ref ),
				'_wpnonce' => wp_create_nonce( self::NONCE_PREFIX . $ref ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether this request asks for an entry.
	 *
	 * @return bool
	 */
	public function is_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; load() verifies the nonce.
		return isset( $_GET['entry_id'] );
	}

	/**
	 * `load-{hook}`: checks the nonce and the row, or sends the admin back to the list.
	 *
	 * @return void
	 */
	public function load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below.
		$ref   = isset( $_GET['entry_id'] ) && is_string( $_GET['entry_id'] ) ? sanitize_text_field( wp_unslash( $_GET['entry_id'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$row   = '' !== $ref && false !== wp_verify_nonce( $nonce, self::NONCE_PREFIX . $ref ) ? $this->store->get( $ref ) : null;

		if ( null === $row ) {
			wp_safe_redirect( AdminMenu::url( array( 'iftp_ld_missing' => '1' ) ) );
			exit;
		}

		$this->row = $row;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		$row = $this->row;

		if ( null === $row ) {
			return;
		}

		$status  = $row['status'];
		$accent  = TransactionStore::STATUS_PAID === $status ? 'paid' : ( TransactionStore::STATUS_PENDING === $status ? 'pending' : 'muted' );
		$product = $row['product_id'] > 0 ? $this->cells->product_title( $row['product_id'] ) : __( 'Ad Hoc', 'ifthenpay-payments-for-learndash' );
		$data    = array(
			'ref'      => $row['ref'],
			'status'   => $status,
			'student'  => '' !== $row['customer_name'] ? $row['customer_name'] : $row['customer_email'],
			'email'    => $row['customer_email'],
			'product'  => $row['product_id'] > 0 ? $product : '',
			'checkout' => $row['user_id'] > 0 && $row['product_id'] > 0,
		);
		?>
		<div id="iftp-ld-entry" class="wrap iftp-ld-entry iftp-ld-scope" data-iftp-row="<?php echo esc_attr( rawurlencode( (string) wp_json_encode( $data ) ) ); ?>">
			<hr class="wp-header-end" />
			<p class="iftp-ld-entry__back"><a href="<?php echo esc_url( AdminMenu::url() ); ?>" data-iftp-back><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Payments', 'ifthenpay-payments-for-learndash' ); ?></a></p>
			<div class="iftp-ld-entry__title-row">
				<?php /* translators: %s: payment reference. */ ?>
				<h2 class="iftp-ld-entry__title"><?php echo esc_html( sprintf( __( 'Payment %s', 'ifthenpay-payments-for-learndash' ), $row['ref'] ) ); ?></h2>
				<?php if ( $row['order_id'] > 0 ) : ?>
					<?php /* translators: %s: LearnDash order ID. */ ?>
					<span class="iftp-ld-entry__chip"><?php echo esc_html( sprintf( __( 'Order #%s', 'ifthenpay-payments-for-learndash' ), (string) $row['order_id'] ) ); ?></span>
				<?php endif; ?>
			</div>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<section class="postbox iftp-ld-hero iftp-ld-hero--<?php echo esc_attr( $accent ); ?>" aria-labelledby="iftp-ld-hero-amount">
							<p class="iftp-ld-hero__eyebrow"><?php echo esc_html( $row['ref'] . ' · ' . $product ); ?></p>
							<p class="iftp-ld-hero__amount" id="iftp-ld-hero-amount"><?php echo esc_html( EntryCells::format_amount( $row['amount'] ) ); ?></p>
							<?php $this->cells->print_status( $status ); ?>
							<dl class="iftp-ld-hero__meta">
								<div><dt><?php esc_html_e( 'Method', 'ifthenpay-payments-for-learndash' ); ?></dt><dd><?php $this->cells->print_method( $row['pay_method'], $status ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Student', 'ifthenpay-payments-for-learndash' ); ?></dt><dd><?php $this->cells->print_student( $row ); ?></dd></div>
							</dl>
							<?php $this->print_journey( $row ); ?>
						</section>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<?php $this->print_actions_box( $row ); ?>
						<section class="postbox iftp-ld-aside">
							<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Student', 'ifthenpay-payments-for-learndash' ); ?></h2></div>
							<div class="inside"><?php $this->print_student_table( $row, false ); ?></div>
						</section>
						<section class="postbox iftp-ld-aside">
							<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Timestamps', 'ifthenpay-payments-for-learndash' ); ?></h2></div>
							<div class="inside">
								<dl class="iftp-ld-timestamps">
									<dt><?php esc_html_e( 'Created', 'ifthenpay-payments-for-learndash' ); ?></dt><dd><?php $this->cells->print_full_date( $row['created_at'] ); ?></dd>
									<dt><?php esc_html_e( 'Updated', 'ifthenpay-payments-for-learndash' ); ?></dt><dd><?php $this->cells->print_full_date( $row['updated_at'] ); ?></dd>
									<dt><?php esc_html_e( 'Link expires', 'ifthenpay-payments-for-learndash' ); ?></dt><dd><?php $this->cells->print_full_date( '' !== $row['payment_url'] ? $row['expires_at'] : 0 ); ?></dd>
								</dl>
							</div>
						</section>
					</div>

					<div id="postbox-container-2" class="postbox-container">
						<section class="postbox iftp-ld-card-box">
							<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Transaction', 'ifthenpay-payments-for-learndash' ); ?></h2></div>
							<div class="inside"><div class="ld-order-details__container"><?php $this->print_payment_table( $row, false ); ?></div></div>
						</section>
						<section class="postbox iftp-ld-card-box">
							<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Course and LearnDash order', 'ifthenpay-payments-for-learndash' ); ?></h2></div>
							<div class="inside"><div class="ld-order-details__container"><?php $this->print_product_table( $row, false ); ?></div></div>
						</section>
					</div>
				</div>
			</div>

			<div id="iftp-ld-confirm-dialog" hidden>
				<p class="iftp-ld-dialog__message" data-iftp-confirm-message></p>
				<p class="iftp-ld-dialog__note" data-iftp-confirm-note hidden></p>
			</div>
			<div class="iftp-ld-toast iftp-ld-scope" data-iftp-toast role="status" aria-live="polite" hidden><span class="iftp-ld-toast__icon" aria-hidden="true"></span><span data-iftp-toast-message></span></div>
		</div>
		<?php
	}

	/**
	 * Created → Link generated → Status updated.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	private function print_journey( array $row ): void {
		$steps = array(
			array( __( 'Created', 'ifthenpay-payments-for-learndash' ), $row['created_at'] > 0, $row['created_at'] ),
			// The link time isn't stored; a checkout link is made with the row, an emailed one later.
			array( __( 'Link generated', 'ifthenpay-payments-for-learndash' ), '' !== $row['payment_url'], $row['user_id'] > 0 ? $row['created_at'] : 0 ),
			array( __( 'Status updated', 'ifthenpay-payments-for-learndash' ), TransactionStore::STATUS_PENDING !== $row['status'], $row['updated_at'] ),
		);
		?>
		<ol class="iftp-ld-journey">
			<?php foreach ( $steps as $step ) : ?>
				<li class="iftp-ld-journey__step<?php echo $step[1] ? ' iftp-ld-journey__step--done' : ''; ?>">
					<span class="iftp-ld-journey__label"><?php echo esc_html( $step[0] ); ?><?php echo $step[1] ? '<span class="screen-reader-text"> ' . esc_html__( '(done)', 'ifthenpay-payments-for-learndash' ) . '</span>' : ''; ?></span>
					<span class="iftp-ld-journey__time">
						<?php
						if ( ! $step[1] ) {
							esc_html_e( 'Not yet', 'ifthenpay-payments-for-learndash' );
						} elseif ( $step[2] > 0 ) {
							$this->cells->print_created( (int) $step[2] );
						} else {
							esc_html_e( 'Done', 'ifthenpay-payments-for-learndash' );
						}
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * The Actions box, in LearnDash's order-edit #submitdiv shape.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	private function print_actions_box( array $row ): void {
		?>
		<section class="postbox iftp-ld-actions">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Actions', 'ifthenpay-payments-for-learndash' ); ?></h2></div>
			<div class="inside">
				<div class="iftp-ld-actions__main">
					<?php if ( EntryCells::has_live_link( $row ) ) : ?>
						<button type="button" class="button" data-iftp-copy-link="<?php echo esc_attr( $row['payment_url'] ); ?>"><?php esc_html_e( 'Copy link', 'ifthenpay-payments-for-learndash' ); ?></button>
					<?php endif; ?>
					<?php if ( EntryCells::can_email( $row ) ) : ?>
						<button type="button" class="button" data-iftp-send-email="<?php echo esc_attr( $row['ref'] ); ?>" data-iftp-send-to="<?php echo esc_attr( $row['customer_email'] ); ?>"><?php esc_html_e( 'Send payment email', 'ifthenpay-payments-for-learndash' ); ?></button>
					<?php endif; ?>
					<div class="iftp-ld-actions__mark">
						<label for="iftp-ld-entry-mark"><?php esc_html_e( 'Mark as', 'ifthenpay-payments-for-learndash' ); ?></label>
						<select id="iftp-ld-entry-mark" data-iftp-entry-mark>
							<?php foreach ( EntryCells::status_labels() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $row['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" data-iftp-entry-mark-apply><?php esc_html_e( 'Apply', 'ifthenpay-payments-for-learndash' ); ?></button>
					</div>
				</div>
				<?php if ( TransactionStore::STATUS_PAID !== $row['status'] ) : ?>
					<div class="iftp-ld-actions__bar">
						<button type="button" class="button-link button-link-delete" data-iftp-row-delete="<?php echo esc_attr( $row['ref'] ); ?>"><?php esc_html_e( 'Delete payment', 'ifthenpay-payments-for-learndash' ); ?></button>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Opens one of LearnDash's order-details tables.
	 *
	 * @param string $label   Table label.
	 * @param bool   $heading Print the header row (the entry page's postbox already titles it).
	 * @return void
	 */
	private static function open_table( string $label, bool $heading ): void {
		echo '<div class="ld-order-details__table" role="table" aria-label="' . esc_attr( $label ) . '">';

		if ( $heading ) {
			echo '<div class="ld-order-details__thead" role="rowgroup"><div role="row"><span role="columnheader">' . esc_html( $label ) . '</span></div></div>';
		}

		echo '<div class="ld-order-details__tbody" role="rowgroup">';
	}

	/**
	 * One labelled row: "Label: value".
	 *
	 * @param string   $label Label.
	 * @param callable $value Prints the value.
	 * @return void
	 */
	private static function table_row( string $label, callable $value ): void {
		echo '<div role="row"><span role="cell"><span class="iftp-ld-details__key">' . esc_html( $label ) . ':</span> ';
		$value();
		echo '</span></div>';
	}

	/**
	 * The Payment table: reference, IDs, link, expiry and the price line.
	 *
	 * @param array<string, mixed> $row     Transaction row (full, from get()).
	 * @param bool                 $heading Print the header row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	public function print_payment_table( array $row, bool $heading = true ): void {
		self::open_table( __( 'Payment', 'ifthenpay-payments-for-learndash' ), $heading );

		self::table_row(
			__( 'Reference', 'ifthenpay-payments-for-learndash' ),
			static function () use ( $row ): void {
				echo '<code>' . esc_html( $row['ref'] ) . '</code> ';
				EntriesPage::print_copy_button( $row['ref'], __( 'Copy reference', 'ifthenpay-payments-for-learndash' ) );
			}
		);

		foreach ( array(
			'request_id'     => __( 'Request ID', 'ifthenpay-payments-for-learndash' ),
			'transaction_id' => __( 'Transaction ID', 'ifthenpay-payments-for-learndash' ),
		) as $key => $label ) {
			self::table_row(
				$label,
				static function () use ( $row, $key, $label ): void {
					if ( '' === $row[ $key ] ) {
						EntryCells::print_empty( __( 'None yet', 'ifthenpay-payments-for-learndash' ) );
						return;
					}

					echo esc_html( $row[ $key ] ) . ' ';
					/* translators: %s: field label, e.g. "Request ID". */
					EntriesPage::print_copy_button( $row[ $key ], sprintf( __( 'Copy %s', 'ifthenpay-payments-for-learndash' ), $label ) );
				}
			);
		}

		self::table_row(
			__( 'Payment link', 'ifthenpay-payments-for-learndash' ),
			static function () use ( $row ): void {
				if ( '' === $row['payment_url'] ) {
					EntryCells::print_empty( __( 'No payment link', 'ifthenpay-payments-for-learndash' ) );
					return;
				}

				echo '<a href="' . esc_url( $row['payment_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open', 'ifthenpay-payments-for-learndash' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ifthenpay-payments-for-learndash' ) . '</span></a> ';
				EntriesPage::print_copy_button( $row['payment_url'], __( 'Copy link', 'ifthenpay-payments-for-learndash' ) );
			}
		);

		if ( '' !== $row['payment_url'] && $row['expires_at'] > 0 ) {
			$cells = $this->cells;
			self::table_row(
				__( 'Expires', 'ifthenpay-payments-for-learndash' ),
				static function () use ( $row, $cells ): void {
					$cells->print_full_date( $row['expires_at'] );
				}
			);
		}

		$this->print_pricing( $row );

		echo '</div></div>';
	}

	/**
	 * LearnDash's price line from the checkout snapshot: old price, paid price, coupon.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	private function print_pricing( array $row ): void {
		$snapshot = TransactionStore::decode_snapshot( $row['pricing_snapshot'] ?? '' );
		$price    = isset( $snapshot['pricing']['price'] ) && is_numeric( $snapshot['pricing']['price'] ) ? (float) $snapshot['pricing']['price'] : 0.0;
		$code     = isset( $snapshot['coupon']['code'] ) && is_string( $snapshot['coupon']['code'] ) ? $snapshot['coupon']['code'] : '';

		echo '<div role="row"><span role="cell"><span class="ld-order-items__item-pricing-details">';

		if ( $price > 0 && abs( $price - $row['amount'] ) >= 0.01 ) {
			echo '<span class="ld-order-items__old-price">' . esc_html( EntryCells::format_amount( $price ) ) . '</span> ';
		}

		echo '<span class="ld-order-items__price">' . esc_html( EntryCells::format_amount( $row['amount'] ) ) . '</span>';

		if ( '' !== $code ) {
			/* translators: %s: coupon code. */
			echo ' <span class="ld-order-items__price-status">' . esc_html( sprintf( __( 'Coupon: %s', 'ifthenpay-payments-for-learndash' ), $code ) ) . '</span>';
		}

		echo '</span></span></div>';
	}

	/**
	 * The Student table: name, email, Edit information.
	 *
	 * @param array<string, mixed> $row     Transaction row.
	 * @param bool                 $heading Print the header row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	public function print_student_table( array $row, bool $heading = true ): void {
		self::open_table( __( 'Student', 'ifthenpay-payments-for-learndash' ), $heading );

		echo '<div role="row"><span role="cell">';
		if ( '' !== $row['customer_name'] ) {
			echo esc_html( $row['customer_name'] );
		} else {
			EntryCells::print_empty( __( 'No name', 'ifthenpay-payments-for-learndash' ) );
		}
		echo '</span></div>';

		if ( '' !== $row['customer_email'] ) {
			echo '<div role="row"><span role="cell">';
			EntryCells::print_email( $row['customer_email'], 'iftp-ld-details__email' );
			echo '</span></div>';
		}

		$link = $row['user_id'] > 0 ? (string) get_edit_user_link( $row['user_id'] ) : '';

		if ( '' !== $link ) {
			echo '<div role="row"><span role="cell"><a class="ld-order-details__edit-information-link" href="' . esc_url( $link ) . '">' . esc_html__( 'Edit information', 'ifthenpay-payments-for-learndash' ) . '</a></span></div>';
		}

		echo '</div></div>';
	}

	/**
	 * The Course table: product, LearnDash order and last update.
	 *
	 * @param array<string, mixed> $row     Transaction row.
	 * @param bool                 $heading Print the header row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	public function print_product_table( array $row, bool $heading = true ): void {
		$label = $row['product_id'] > 0 ? $this->cells->product_type_label( $row['product_type'] ) : __( 'Course or group', 'ifthenpay-payments-for-learndash' );
		$cells = $this->cells;

		self::open_table( $label, $heading );

		echo '<div role="row"><span role="cell">';
		$this->cells->print_product( $row );
		echo '</span></div>';

		self::table_row(
			__( 'LearnDash order', 'ifthenpay-payments-for-learndash' ),
			static function () use ( $row, $cells ): void {
				$cells->print_order( $row );
			}
		);

		self::table_row(
			__( 'Last updated', 'ifthenpay-payments-for-learndash' ),
			static function () use ( $row, $cells ): void {
				$cells->print_created( $row['updated_at'] );
			}
		);

		echo '</div></div>';
	}
}
