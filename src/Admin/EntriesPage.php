<?php
/**
 * The list of every ifthenpay payment attempt.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Host;
use Ifthenpay\LearnDash\Payments\TransactionStore;
use Ifthenpay\LearnDash\Plugin;
use Ifthenpay\LearnDash\Repository\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The "Payments" screen under LearnDash LMS.
 *
 * LearnDash orders only exist once paid, so this is where pending, failed, cancelled and
 * expired attempts live. Sort, filters and pagination are plain GET; mutations go through
 * Ajax\EntriesController, which answers with the re-rendered view built here.
 *
 * @phpstan-import-type Transaction from TransactionStore
 * @phpstan-type View array{s: string, status: string, product_id: int, date_from: string, date_to: string, pay_method: string, amount_min: string, amount_max: string, per_page: int, paged: int, orderby: string, order: string}
 */
final class EntriesPage {

	public const NONCE_ACTION     = 'iftp_ld_entries';
	public const EXPORT_ACTION    = 'iftp_ld_export';
	public const BULK_CHUNK_SIZE  = 250;
	public const BULK_MAX_REFS    = 1000;
	public const EMAIL_CHUNK_SIZE = 10;

	private const DEFAULT_PER_PAGE = 50;
	private const PER_PAGE_OPTIONS = array( 10, 25, 50, 100, 250 );
	private const PER_PAGE_MIN     = 1;
	private const PER_PAGE_MAX     = 250;
	private const EXPORT_CSV       = 'csv';
	private const EXPORT_JSON      = 'json';
	private const HIDDEN_COLS_META = 'iftp_ld_entries_hidden_cols';
	private const COL_ORDER_META   = 'iftp_ld_entries_col_order';
	private const PER_PAGE_META    = 'iftp_ld_entries_per_page';

	// Default order. Reference and the checkbox column always stay.
	public const HIDEABLE_COLUMNS = array( 'student', 'product', 'method', 'amount', 'status', 'order', 'created' );

	// Column => store orderby. Not ref: references are random, sorting by them tells nothing.
	private const SORTABLE = array(
		'student' => 'customer_name',
		'amount'  => 'amount',
		'status'  => 'status',
		'created' => 'created_at',
	);

	/**
	 * Attempts.
	 *
	 * @var TransactionStore
	 */
	private $store;

	/**
	 * Settings.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Shared cell markup.
	 *
	 * @var EntryCells
	 */
	private $cells;

	/**
	 * The payment email editor.
	 *
	 * @var PaymentEmailSettings
	 */
	private $email;

	/**
	 * The separate entry page.
	 *
	 * @var EntryPage
	 */
	private $entry;

	/**
	 * The request's view state.
	 *
	 * @var array<string, mixed>|null
	 * @phpstan-var View|null
	 */
	private $filters = null;

	/**
	 * Column order, read once.
	 *
	 * @var array<int, string>|null
	 */
	private $column_order = null;

	/**
	 * Constructor.
	 *
	 * @param TransactionStore     $store    Attempts.
	 * @param SettingsRepository   $settings Settings.
	 * @param EntryCells           $cells    Shared cell markup.
	 * @param PaymentEmailSettings $email    Payment email editor.
	 * @param EntryPage            $entry    Separate entry page.
	 */
	public function __construct( TransactionStore $store, SettingsRepository $settings, EntryCells $cells, PaymentEmailSettings $email, EntryPage $entry ) {
		$this->store    = $store;
		$this->settings = $settings;
		$this->cells    = $cells;
		$this->email    = $email;
		$this->entry    = $entry;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * `load-{hook}`: the entry page's guard redirect and the per-page preference, before any output.
	 *
	 * @return void
	 */
	public function load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- EntryPage::load() verifies its own view nonce.
		if ( isset( $_GET['entry_id'] ) ) {
			$this->entry->load();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- per-user display preference from this screen's GET view, like core's list-table mode.
		if ( ! isset( $_GET['per_page'] ) ) {
			return;
		}

		$per_page = $this->current_filters()['per_page'];

		if ( self::DEFAULT_PER_PAGE === $per_page ) {
			delete_user_meta( get_current_user_id(), self::PER_PAGE_META );
		} elseif ( $this->saved_per_page() !== $per_page ) {
			update_user_meta( get_current_user_id(), self::PER_PAGE_META, $per_page );
		}
	}

	/**
	 * The "doesn't exist anymore" notice after a bad entry link.
	 *
	 * @return void
	 */
	public function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( AdminMenu::is_current_screen() && isset( $_GET['iftp_ld_missing'] ) ) {
			wp_admin_notice(
				esc_html__( 'That payment doesn’t exist anymore.', 'ifthenpay-payments-for-learndash' ),
				array(
					'type'        => 'error',
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * Assets, on our screen only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! AdminMenu::is_current_screen() ) {
			return;
		}

		wp_enqueue_style( 'wp-jquery-ui-dialog' );
		wp_enqueue_style( 'dashicons' );

		$deps = array( 'wp-jquery-ui-dialog', 'dashicons' );

		if ( wp_style_is( 'learndash-admin', 'registered' ) ) {
			$deps[] = 'learndash-admin';
		}

		if ( ! wp_style_is( 'iftp-ld-tokens', 'registered' ) ) {
			wp_register_style( 'iftp-ld-tokens', IFTP_LD_URL . 'assets/css/tokens.css', $deps, Plugin::asset_version( 'assets/css/tokens.css' ) );
		}

		wp_enqueue_style( 'iftp-ld-entries', IFTP_LD_URL . 'assets/css/entries.css', array( 'iftp-ld-tokens' ), Plugin::asset_version( 'assets/css/entries.css' ) );

		$is_entry = $this->entry->is_requested();
		$js_deps  = array( 'jquery', 'jquery-ui-dialog' );
		$email    = array();

		if ( ! $is_entry ) {
			$email = $this->email->enqueue();

			if ( false !== $email['editor'] ) {
				$js_deps[] = 'code-editor';
			}

			// Head, not footer: it may redirect to the saved view before the default one paints.
			wp_enqueue_script( 'iftp-ld-entries-restore', IFTP_LD_URL . 'assets/js/entries-restore.js', array(), Plugin::asset_version( 'assets/js/entries-restore.js' ), false );
			wp_localize_script(
				'iftp-ld-entries-restore',
				'iftpLdEntriesStore',
				array(
					// Per site and per user, so a shared browser doesn't mix admins' views.
					'key'      => 'iftp_ld_entries:' . get_current_blog_id() . ':' . get_current_user_id(),
					'pageSlug' => AdminMenu::PAGE_SLUG,
					// Per-page lives in user meta, so the saved view leaves it out.
					'view'     => array_diff_key( $this->url_args( $this->current_filters() ), array( 'per_page' => true ) ),
				)
			);
		}

		wp_enqueue_script( 'iftp-ld-entries', IFTP_LD_URL . 'assets/js/entries.js', $js_deps, Plugin::asset_version( 'assets/js/entries.js' ), true );

		global $wp_locale;

		wp_localize_script(
			'iftp-ld-entries',
			'iftpLdEntries',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( self::NONCE_ACTION ),
				'exportUrl'          => admin_url( 'admin-post.php' ),
				'exportNonce'        => wp_create_nonce( self::EXPORT_ACTION ),
				'listUrl'            => AdminMenu::url(),
				'currentFilters'     => $this->current_filters(),
				'statusLabels'       => EntryCells::status_labels(),
				'defaultColumnOrder' => self::HIDEABLE_COLUMNS,
				'bulkChunkSize'      => self::BULK_CHUNK_SIZE,
				'emailChunkSize'     => self::EMAIL_CHUNK_SIZE,
				'emailSettings'      => $email,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by the entry page's delete.
				'flash'              => isset( $_GET['iftp_ld_deleted'] ) ? __( 'Payment deleted', 'ifthenpay-payments-for-learndash' ) : '',
				// Same separator as number_format_i18n(), decoded since some locales store "&nbsp;".
				'numberFormat'       => array(
					'thousands' => html_entity_decode( $wp_locale instanceof \WP_Locale ? (string) $wp_locale->number_format['thousands_sep'] : ',', ENT_QUOTES, 'UTF-8' ),
				),
				'i18n'               => $this->js_strings(),
			)
		);
	}

	/**
	 * Every string entries.js shows.
	 *
	 * @return array<string, string>
	 */
	private function js_strings(): array {
		return array(
			'cancel'             => __( 'Cancel', 'ifthenpay-payments-for-learndash' ),
			'ok'                 => __( 'OK', 'ifthenpay-payments-for-learndash' ),
			'copied'             => __( 'Copied', 'ifthenpay-payments-for-learndash' ),
			'linkCopied'         => __( 'Link copied', 'ifthenpay-payments-for-learndash' ),
			'copyError'          => __( 'Couldn’t copy. Select the text and copy it yourself.', 'ifthenpay-payments-for-learndash' ),
			'view'               => __( 'View', 'ifthenpay-payments-for-learndash' ),
			'hide'               => __( 'Hide', 'ifthenpay-payments-for-learndash' ),
			'detailsError'       => __( 'We couldn’t load this payment.', 'ifthenpay-payments-for-learndash' ),
			'tryAgain'           => __( 'Try again', 'ifthenpay-payments-for-learndash' ),
			'deleteTitle'        => __( 'Delete this payment?', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: payment reference. */
			'deleteMessage'      => __( 'This removes %s from Payments. ifthenpay keeps its own record.', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of payments. */
			'bulkDeleteTitle'    => __( 'Delete %s payments?', 'ifthenpay-payments-for-learndash' ),
			'bulkDeleteMessage'  => __( 'This removes them from Payments. ifthenpay keeps its own record.', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of paid payments in the selection. */
			'deleteKeepsPaid'    => __( '%s paid payments stay. Paid payments can’t be deleted.', 'ifthenpay-payments-for-learndash' ),
			'delete'             => __( 'Delete', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: payment reference. */
			'markPaidOneTitle'   => __( 'Mark %s as paid?', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: student name, 2: course or group title. */
			'markPaidOneMessage' => __( 'This enrols %1$s in %2$s and creates a LearnDash order, which sends LearnDash’s invoice and purchase emails. Only do this if the student really paid.', 'ifthenpay-payments-for-learndash' ),
			'markPaidAdHoc'      => __( 'This is an ad hoc payment, so nobody is enrolled and no LearnDash order is created.', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of payments. */
			'markPaidTitle'      => __( 'Mark %s payments as paid?', 'ifthenpay-payments-for-learndash' ),
			'markPaidMessage'    => __( 'Each checkout payment enrols its student and creates a LearnDash order, which sends LearnDash’s invoice and purchase emails. Ad hoc payments are only marked. Only do this if they really paid.', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: number of payments, 2: status label. */
			'markTitle'          => __( 'Mark %1$s payments as %2$s?', 'ifthenpay-payments-for-learndash' ),
			'markMessage'        => __( 'Moving a paid payment off Paid doesn’t remove the access already given, and its LearnDash order stays.', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: status label. */
			'markButton'         => __( 'Mark as %s', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: payments done so far, 2: payments selected. */
			'bulkUpdating'       => __( 'Updating… %1$s of %2$s', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: payments done so far, 2: payments selected. */
			'bulkDeleting'       => __( 'Deleting… %1$s of %2$s', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of payments. */
			'updated'            => __( 'Updated %s payments', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of payments. */
			'deleted'            => __( 'Deleted %s payments', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of payments. */
			'notRecorded'        => __( 'LearnDash couldn’t record %s of them. Mark them as paid again to retry.', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: payments done before the error, 2: payments selected. */
			'bulkPartialError'   => __( 'Stopped after %1$s of %2$s. The rest are still selected.', 'ifthenpay-payments-for-learndash' ),
			'saveError'          => __( 'Couldn’t save. Check your connection and try again.', 'ifthenpay-payments-for-learndash' ),
			'bulkLeaveWarning'   => __( 'A bulk action is still running. Leave anyway?', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: number of selected payments, 2: total number of payments. */
			'selectedOfTotal'    => __( '%1$s selected out of %2$s', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of selected payments. */
			'bulkSelectedCount'  => __( '%s selected', 'ifthenpay-payments-for-learndash' ),
			'exportNothing'      => __( 'Select at least one payment to export.', 'ifthenpay-payments-for-learndash' ),
			'newPayment'         => __( 'New payment', 'ifthenpay-payments-for-learndash' ),
			'createPayment'      => __( 'Create payment', 'ifthenpay-payments-for-learndash' ),
			'creating'           => __( 'Creating…', 'ifthenpay-payments-for-learndash' ),
			'paymentCreated'     => __( 'Payment recorded', 'ifthenpay-payments-for-learndash' ),
			'amountRequired'     => __( 'Enter an amount of at least 0,01 €.', 'ifthenpay-payments-for-learndash' ),
			'emailInvalid'       => __( 'Enter a valid email, or leave it blank.', 'ifthenpay-payments-for-learndash' ),
			'emailRequired'      => __( 'Enter the student’s email to send the payment email.', 'ifthenpay-payments-for-learndash' ),
			'sendEmailTitle'     => __( 'Send payment email?', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: student email address. */
			'sendEmailMessage'   => __( '%s will get an email with the payment button. If the payment has no live link yet, one is created first.', 'ifthenpay-payments-for-learndash' ),
			'sendEmail'          => __( 'Send email', 'ifthenpay-payments-for-learndash' ),
			'sending'            => __( 'Sending…', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: email address. */
			'emailSent'          => __( 'Email sent to %s', 'ifthenpay-payments-for-learndash' ),
			'sendError'          => __( 'Couldn’t send the payment email. Try again.', 'ifthenpay-payments-for-learndash' ),
			'bulkEmailTitle'     => __( 'Send payment emails?', 'ifthenpay-payments-for-learndash' ),
			/* translators: %s: number of selected payments. */
			'bulkEmailMessage'   => __( 'The students of the %s selected pending payments each get an email with their payment button. Payments without a student email are skipped.', 'ifthenpay-payments-for-learndash' ),
			'sendEmails'         => __( 'Send emails', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: payments done so far, 2: payments selected. */
			'bulkEmailing'       => __( 'Sending… %1$s of %2$s', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: sent, 2: skipped, 3: failed. */
			'bulkEmailDone'      => __( 'Sent %1$s, skipped %2$s, failed %3$s', 'ifthenpay-payments-for-learndash' ),
			'columnsSaveError'   => __( 'Couldn’t save your column choice.', 'ifthenpay-payments-for-learndash' ),
			/* translators: 1: column name, 2: new position, 3: number of movable columns. */
			'columnMoved'        => __( '%1$s moved to position %2$s of %3$s', 'ifthenpay-payments-for-learndash' ),
			'columnsReset'       => __( 'Columns reset to the default order, all shown.', 'ifthenpay-payments-for-learndash' ),
			'paymentEmail'       => __( 'Payment email', 'ifthenpay-payments-for-learndash' ),
			'saveTemplate'       => __( 'Save template', 'ifthenpay-payments-for-learndash' ),
			'saving'             => __( 'Saving…', 'ifthenpay-payments-for-learndash' ),
			'previewError'       => __( 'Couldn’t refresh the preview.', 'ifthenpay-payments-for-learndash' ),
			'emailResetDone'     => __( 'Default template restored. Save to keep it.', 'ifthenpay-payments-for-learndash' ),
			'discardTitle'       => __( 'Discard your changes?', 'ifthenpay-payments-for-learndash' ),
			'discardMessage'     => __( 'The payment email has unsaved edits.', 'ifthenpay-payments-for-learndash' ),
			'discard'            => __( 'Discard', 'ifthenpay-payments-for-learndash' ),
			'mediaTitle'         => __( 'Choose an image for the email', 'ifthenpay-payments-for-learndash' ),
			'mediaButton'        => __( 'Insert image', 'ifthenpay-payments-for-learndash' ),
			'loading'            => __( 'Loading', 'ifthenpay-payments-for-learndash' ),
		);
	}

	/**
	 * Renders the screen: the entry page when one is asked for, the list otherwise.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Host::can_manage() ) {
			return;
		}

		if ( $this->entry->is_requested() ) {
			$this->entry->render();
			return;
		}

		$filters = $this->current_filters();
		$shared  = $this->store_filters( $filters );
		$result  = $this->store->query(
			$shared + array(
				'status'   => $filters['status'],
				'orderby'  => $filters['orderby'],
				'order'    => $filters['order'],
				'per_page' => $filters['per_page'],
				'paged'    => $filters['paged'],
			)
		);
		$summary = $this->store->status_summary( $shared );

		$hidden_cols = $this->hidden_columns();
		$col_order   = $this->column_order();
		$chips       = $this->active_filter_chips( $filters );
		$view_args   = $this->url_args( $filters );
		$per_page    = $filters['per_page'];
		$is_custom   = ! in_array( $per_page, self::PER_PAGE_OPTIONS, true );
		$first_run   = ! $this->settings->is_connected() && 0 === array_sum( array_column( $summary, 'count' ) ) && array() === $chips && '' === $filters['s'];
		?>
		<div id="iftp-ld-entries" class="wrap iftp-ld-entries iftp-ld-scope" data-iftp-hidden-cols="<?php echo esc_attr( implode( ' ', $hidden_cols ) ); ?>" data-iftp-col-order="<?php echo esc_attr( implode( ' ', $col_order ) ); ?>">
			<hr class="wp-header-end" />
			<h2 id="iftp-ld-entries-title" class="screen-reader-text"><?php esc_html_e( 'Payments', 'ifthenpay-payments-for-learndash' ); ?></h2>
			<p class="iftp-ld-entries__intro"><?php esc_html_e( 'Every ifthenpay payment attempt, paid or not. LearnDash creates an order only when a payment is paid.', 'ifthenpay-payments-for-learndash' ); ?></p>

			<?php if ( $first_run ) : ?>
				<?php $this->render_onboarding(); ?>
			<?php else : ?>
				<section class="iftp-ld-summary" aria-labelledby="iftp-ld-summary-title">
					<h3 id="iftp-ld-summary-title" class="screen-reader-text"><?php esc_html_e( 'Totals by status', 'ifthenpay-payments-for-learndash' ); ?></h3>
					<div data-iftp-summary><?php $this->print_summary( $summary ); ?></div>
				</section>

				<section class="iftp-ld-card" aria-labelledby="iftp-ld-entries-title">
					<div class="iftp-ld-toolbar">
						<nav class="iftp-ld-toolbar__tabs" aria-label="<?php esc_attr_e( 'Filter by status', 'ifthenpay-payments-for-learndash' ); ?>">
							<ul class="iftp-ld-tabs">
								<?php // No counts: the summary cards above show them, and are filters too. ?>
								<?php foreach ( $this->status_filters() as $slug => $label ) : ?>
									<?php $is_current = $slug === $filters['status']; ?>
									<li><a class="iftp-ld-tabs__tab<?php echo $is_current ? ' iftp-ld-tabs__tab--current' : ''; ?>" href="
									<?php
									echo esc_url(
										$this->filtered_url(
											array(
												'status' => $slug,
												'paged'  => null,
											)
										)
									);
									?>
																	"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						</nav>

						<div class="iftp-ld-bulk" data-iftp-bulk hidden>
							<strong class="iftp-ld-bulk__count" data-iftp-bulk-count></strong>
							<label class="screen-reader-text" for="iftp-ld-bulk-select"><?php esc_html_e( 'Bulk action', 'ifthenpay-payments-for-learndash' ); ?></label>
							<select id="iftp-ld-bulk-select" data-iftp-bulk-select>
								<option value=""><?php esc_html_e( 'Bulk actions', 'ifthenpay-payments-for-learndash' ); ?></option>
								<?php foreach ( EntryCells::status_labels() as $slug => $label ) : ?>
									<?php /* translators: %s: status label. */ ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( sprintf( __( 'Mark as %s', 'ifthenpay-payments-for-learndash' ), strtolower( $label ) ) ); ?></option>
								<?php endforeach; ?>
								<?php // entries.js lists it only while every selected payment is pending. ?>
								<option value="email" data-iftp-bulk-email hidden disabled><?php esc_html_e( 'Send payment email', 'ifthenpay-payments-for-learndash' ); ?></option>
								<option value="export-csv"><?php esc_html_e( 'Export selected as CSV', 'ifthenpay-payments-for-learndash' ); ?></option>
								<option value="export-json"><?php esc_html_e( 'Export selected as JSON', 'ifthenpay-payments-for-learndash' ); ?></option>
								<?php // entries.js disables it while every selected payment is paid. ?>
								<option value="delete" data-iftp-bulk-delete><?php esc_html_e( 'Delete', 'ifthenpay-payments-for-learndash' ); ?></option>
							</select>
							<button type="button" class="button" data-iftp-bulk-apply><?php esc_html_e( 'Apply', 'ifthenpay-payments-for-learndash' ); ?></button>
							<button type="button" class="button-link" data-iftp-selection-cancel><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-learndash' ); ?></button>
						</div>

						<div class="iftp-ld-toolbar__tools">
							<form class="iftp-ld-search" role="search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
								<?php $this->hidden_view_inputs( $view_args, array( 's', 'paged' ) ); ?>
								<label class="screen-reader-text" for="iftp-ld-search-input"><?php esc_html_e( 'Search payments', 'ifthenpay-payments-for-learndash' ); ?></label>
								<input type="search" id="iftp-ld-search-input" class="iftp-ld-search__input" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search reference, student or email', 'ifthenpay-payments-for-learndash' ); ?>" title="<?php esc_attr_e( 'Search by reference, student, email, method, request ID or order number', 'ifthenpay-payments-for-learndash' ); ?>" />
							</form>

							<button type="button" class="button iftp-ld-tool iftp-ld-tool--filters" data-iftp-filters-toggle aria-expanded="false" aria-controls="iftp-ld-filters">
								<span class="dashicons dashicons-filter" aria-hidden="true"></span>
								<?php esc_html_e( 'Filters', 'ifthenpay-payments-for-learndash' ); ?>
								<?php if ( array() !== $chips ) : ?>
									<span class="iftp-ld-badge" aria-hidden="true"><?php echo esc_html( number_format_i18n( count( $chips ) ) ); ?></span>
									<?php /* translators: %s: number of active filters. */ ?>
									<span class="screen-reader-text"><?php echo esc_html( sprintf( _n( '(%s active)', '(%s active)', count( $chips ), 'ifthenpay-payments-for-learndash' ), number_format_i18n( count( $chips ) ) ) ); ?></span>
								<?php endif; ?>
							</button>

							<div class="iftp-ld-tool-wrap iftp-ld-tool-wrap--columns">
								<button type="button" class="button iftp-ld-tool iftp-ld-tool--columns" data-iftp-columns-trigger aria-expanded="false" aria-controls="iftp-ld-columns-menu">
									<span class="dashicons dashicons-columns" aria-hidden="true"></span>
									<?php esc_html_e( 'Columns', 'ifthenpay-payments-for-learndash' ); ?>
								</button>
								<?php $this->render_columns_menu( $col_order, $hidden_cols ); ?>
								<p class="screen-reader-text" data-iftp-col-status role="status"></p>
							</div>

							<div class="iftp-ld-tool-wrap">
								<button type="button" class="button iftp-ld-tool iftp-ld-tool--export" data-iftp-export-trigger aria-expanded="false" aria-controls="iftp-ld-export-menu">
									<span class="dashicons dashicons-download" aria-hidden="true"></span>
									<?php esc_html_e( 'Export', 'ifthenpay-payments-for-learndash' ); ?>
								</button>
								<div id="iftp-ld-export-menu" class="iftp-ld-popover iftp-ld-popover--export" data-iftp-export-menu hidden>
									<ul class="iftp-ld-popover__list" role="menu" aria-label="<?php esc_attr_e( 'Export', 'ifthenpay-payments-for-learndash' ); ?>">
										<li role="none"><a role="menuitem" class="iftp-ld-popover__item" href="<?php echo esc_url( $this->export_url( $filters, self::EXPORT_CSV ) ); ?>" data-iftp-export-option><?php esc_html_e( 'Export view as CSV', 'ifthenpay-payments-for-learndash' ); ?></a></li>
										<li role="none"><a role="menuitem" class="iftp-ld-popover__item" href="<?php echo esc_url( $this->export_url( $filters, self::EXPORT_JSON ) ); ?>" data-iftp-export-option><?php esc_html_e( 'Export view as JSON', 'ifthenpay-payments-for-learndash' ); ?></a></li>
									</ul>
								</div>
							</div>
						</div>
					</div>

					<?php $this->render_filter_panel( $filters, $view_args, $chips ); ?>

					<?php if ( array() !== $chips ) : ?>
						<div class="iftp-ld-chips-row">
							<ul class="iftp-ld-chips" aria-label="<?php esc_attr_e( 'Active filters', 'ifthenpay-payments-for-learndash' ); ?>">
								<?php foreach ( $chips as $chip ) : ?>
									<li>
										<a class="iftp-ld-chip" href="<?php echo esc_url( $chip['url'] ); ?>">
											<span class="screen-reader-text"><?php esc_html_e( 'Remove filter:', 'ifthenpay-payments-for-learndash' ); ?></span>
											<span class="iftp-ld-chip__key"><?php echo esc_html( $chip['key'] ); ?></span>
											<span class="iftp-ld-chip__value"><?php echo esc_html( $chip['value'] ); ?></span>
											<span class="iftp-ld-chip__remove" aria-hidden="true">&times;</span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
							<a class="button-link" href="<?php echo esc_url( $this->clear_filters_url() ); ?>"><?php esc_html_e( 'Clear filters', 'ifthenpay-payments-for-learndash' ); ?></a>
						</div>
					<?php endif; ?>

					<div class="iftp-ld-stage">
						<div class="iftp-ld-table-wrap" role="region" aria-labelledby="iftp-ld-entries-title" tabindex="0">
							<table class="widefat iftp-ld-table" id="iftp-ld-table">
								<thead>
									<tr>
										<td class="manage-column column-cb check-column"><input type="checkbox" id="iftp-ld-select-all" data-iftp-select-all /><label class="screen-reader-text" for="iftp-ld-select-all"><?php esc_html_e( 'Select all on this page', 'ifthenpay-payments-for-learndash' ); ?></label></td>
										<th scope="col" class="manage-column column-ref" data-col="ref"><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-learndash' ); ?></th>
										<?php foreach ( $col_order as $slug ) : ?>
											<?php $this->render_head_cell( $slug, $filters['orderby'], $filters['order'] ); ?>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody data-iftp-body>
									<?php $this->print_rows( $result['items'], array() !== $chips || '' !== $filters['s'] || '' !== $filters['status'] ); ?>
								</tbody>
							</table>
						</div>

						<?php // Same mark as the empty state, so a load that ends empty settles straight into it. ?>
						<div class="iftp-ld-loader" aria-hidden="true">
							<?php $this->print_mark( true ); ?>
						</div>
					</div>

					<div class="iftp-ld-footer">
						<div class="iftp-ld-footer__status" aria-live="polite">
							<span class="iftp-ld-footer__range" data-iftp-total-count data-iftp-total="<?php echo esc_attr( (string) $result['total'] ); ?>"><?php echo esc_html( $this->range_label( $result['paged'], $per_page, $result['total'] ) ); ?></span>
							<span class="iftp-ld-footer__selection" data-iftp-selection-summary hidden>
								<span data-iftp-selection-text></span> &middot;
								<button type="button" class="button-link" data-iftp-selection-cancel><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-learndash' ); ?></button>
							</span>
						</div>

						<div class="iftp-ld-footer__nav">
							<form class="iftp-ld-per-page" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" data-iftp-per-page-form>
								<?php $this->hidden_view_inputs( $view_args, array( 'per_page', 'paged' ) ); ?>
								<label for="iftp-ld-per-page"><?php esc_html_e( 'Payments per page', 'ifthenpay-payments-for-learndash' ); ?></label>
								<select id="iftp-ld-per-page" name="per_page" data-iftp-per-page>
									<?php foreach ( self::PER_PAGE_OPTIONS as $option ) : ?>
										<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( number_format_i18n( $option ) ); ?></option>
									<?php endforeach; ?>
									<option value="custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Custom…', 'ifthenpay-payments-for-learndash' ); ?></option>
								</select>
								<span class="iftp-ld-per-page__custom" data-iftp-per-page-custom<?php echo $is_custom ? '' : ' hidden'; ?>>
									<label class="screen-reader-text" for="iftp-ld-per-page-custom"><?php esc_html_e( 'Custom payments per page', 'ifthenpay-payments-for-learndash' ); ?></label>
									<input type="number" id="iftp-ld-per-page-custom" name="per_page_custom" min="<?php echo esc_attr( (string) self::PER_PAGE_MIN ); ?>" max="<?php echo esc_attr( (string) self::PER_PAGE_MAX ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) $per_page ); ?>" />
								</span>
								<button type="submit" class="button" data-iftp-per-page-submit><?php esc_html_e( 'Go', 'ifthenpay-payments-for-learndash' ); ?></button>
							</form>

							<div data-iftp-pagination><?php $this->render_pagination( $result['paged'], $result['pages'] ); ?></div>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php $this->render_dialogs(); ?>
			<div class="iftp-ld-toast iftp-ld-scope" data-iftp-toast role="status" aria-live="polite" hidden><span class="iftp-ld-toast__icon" aria-hidden="true"></span><span data-iftp-toast-message></span></div>
			<button type="button" class="iftp-ld-scroll-top" data-iftp-scroll-top aria-label="<?php esc_attr_e( 'Back to top', 'ifthenpay-payments-for-learndash' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
		</div>
		<?php
	}

	/**
	 * LearnDash's own onboarding notice, while there is nothing to list and no account yet.
	 *
	 * @return void
	 */
	private function render_onboarding(): void {
		?>
		<section class="ld-onboarding-screen ld-orders-onboarding iftp-ld-onboarding">
			<div class="notice ld-notice ld-notice--info inline">
				<h2><?php esc_html_e( 'How to start taking ifthenpay payments', 'ifthenpay-payments-for-learndash' ); ?></h2>
				<ol>
					<li>
						<?php
						printf(
							/* translators: %s: link to the ifthenpay settings. */
							esc_html__( 'Connect your account in %s.', 'ifthenpay-payments-for-learndash' ),
							'<a href="' . esc_url( PaymentsSection::url() ) . '">' . esc_html__( 'Settings › Payments › ifthenpay', 'ifthenpay-payments-for-learndash' ) . '</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Turn on the payment methods you want to offer and pick a default.', 'ifthenpay-payments-for-learndash' ); ?></li>
					<li><?php esc_html_e( 'Set a course or group’s access mode to Buy now.', 'ifthenpay-payments-for-learndash' ); ?></li>
					<li><?php esc_html_e( 'Payment attempts show up here as students pay.', 'ifthenpay-payments-for-learndash' ); ?></li>
				</ol>
			</div>
		</section>
		<?php
	}

	/**
	 * The filter panel: Course/Group, Method, Amount, Created with presets.
	 *
	 * @param array<string, mixed> $filters   current_filters().
	 * @param array<string, mixed> $view_args url_args().
	 * @param array<int, mixed>    $chips     Active chips.
	 * @phpstan-param View $filters
	 * @return void
	 */
	private function render_filter_panel( array $filters, array $view_args, array $chips ): void {
		?>
		<div id="iftp-ld-filters" class="iftp-ld-filters" data-iftp-filter-panel hidden>
			<form id="iftp-ld-filter-form" class="iftp-ld-filters__form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<?php $this->hidden_view_inputs( $view_args, array( 'product_id', 'pay_method', 'amount_min', 'amount_max', 'date_from', 'date_to', 'paged' ) ); ?>

				<div class="iftp-ld-filters__field">
					<label class="iftp-ld-filters__label" for="iftp-ld-product-filter"><?php esc_html_e( 'Course or group', 'ifthenpay-payments-for-learndash' ); ?></label>
					<select id="iftp-ld-product-filter" name="product_id">
						<option value=""><?php esc_html_e( 'All courses and groups', 'ifthenpay-payments-for-learndash' ); ?></option>
						<?php $this->print_product_options( $this->filter_products(), $filters['product_id'] ); ?>
					</select>
				</div>

				<div class="iftp-ld-filters__field">
					<label class="iftp-ld-filters__label" for="iftp-ld-method-filter"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-learndash' ); ?></label>
					<select id="iftp-ld-method-filter" name="pay_method">
						<option value=""><?php esc_html_e( 'All methods', 'ifthenpay-payments-for-learndash' ); ?></option>
						<?php foreach ( $this->store->distinct_methods() as $method ) : ?>
							<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $filters['pay_method'], $method ); ?>><?php echo esc_html( $this->cells->method_label( $method ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="iftp-ld-filters__field" role="group" aria-labelledby="iftp-ld-amount-label">
					<span id="iftp-ld-amount-label" class="iftp-ld-filters__label"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-learndash' ); ?></span>
					<div class="iftp-ld-filters__range">
						<label class="screen-reader-text" for="iftp-ld-amount-min"><?php esc_html_e( 'From', 'ifthenpay-payments-for-learndash' ); ?></label>
						<span class="iftp-ld-affix"><input type="number" id="iftp-ld-amount-min" name="amount_min" step="0.01" min="0" inputmode="decimal" value="<?php echo esc_attr( $filters['amount_min'] ); ?>" placeholder="<?php esc_attr_e( 'From', 'ifthenpay-payments-for-learndash' ); ?>" /><span class="iftp-ld-affix__suffix" aria-hidden="true">€</span></span>
						<span aria-hidden="true">&ndash;</span>
						<label class="screen-reader-text" for="iftp-ld-amount-max"><?php esc_html_e( 'To', 'ifthenpay-payments-for-learndash' ); ?></label>
						<span class="iftp-ld-affix"><input type="number" id="iftp-ld-amount-max" name="amount_max" step="0.01" min="0" inputmode="decimal" value="<?php echo esc_attr( $filters['amount_max'] ); ?>" placeholder="<?php esc_attr_e( 'To', 'ifthenpay-payments-for-learndash' ); ?>" /><span class="iftp-ld-affix__suffix" aria-hidden="true">€</span></span>
					</div>
				</div>

				<div class="iftp-ld-filters__field iftp-ld-filters__field--created" role="group" aria-labelledby="iftp-ld-created-label">
					<span id="iftp-ld-created-label" class="iftp-ld-filters__label"><?php esc_html_e( 'Created', 'ifthenpay-payments-for-learndash' ); ?></span>
					<div class="iftp-ld-filters__range">
						<label class="screen-reader-text" for="iftp-ld-date-from"><?php esc_html_e( 'From date', 'ifthenpay-payments-for-learndash' ); ?></label>
						<input type="date" id="iftp-ld-date-from" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
						<span aria-hidden="true">&ndash;</span>
						<label class="screen-reader-text" for="iftp-ld-date-to"><?php esc_html_e( 'To date', 'ifthenpay-payments-for-learndash' ); ?></label>
						<input type="date" id="iftp-ld-date-to" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
					</div>
					<ul class="iftp-ld-presets" aria-label="<?php esc_attr_e( 'Quick date ranges', 'ifthenpay-payments-for-learndash' ); ?>">
						<?php foreach ( $this->date_presets() as $preset ) : ?>
							<?php $is_current = $preset['from'] === $filters['date_from'] && $preset['to'] === $filters['date_to']; ?>
							<li><a class="button button-small<?php echo $is_current ? ' iftp-ld-presets__item--current' : ''; ?>" href="
							<?php
							echo esc_url(
								$this->filtered_url(
									array(
										'date_from' => $preset['from'],
										'date_to'   => $preset['to'],
										'paged'     => null,
									)
								)
							);
							?>
																" data-iftp-date-preset data-iftp-date-from="<?php echo esc_attr( $preset['from'] ); ?>" data-iftp-date-to="<?php echo esc_attr( $preset['to'] ); ?>"<?php echo $is_current ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $preset['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="iftp-ld-filters__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply filters', 'ifthenpay-payments-for-learndash' ); ?></button>
					<?php if ( array() !== $chips ) : ?>
						<a class="button-link" href="<?php echo esc_url( $this->clear_filters_url() ); ?>"><?php esc_html_e( 'Clear filters', 'ifthenpay-payments-for-learndash' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * The dialogs' content. entries.js turns each into a jQuery UI dialog inside #iftp-ld-entries.
	 *
	 * @return void
	 */
	private function render_dialogs(): void {
		?>
		<div id="iftp-ld-confirm-dialog" hidden>
			<p class="iftp-ld-dialog__message" data-iftp-confirm-message></p>
			<p class="iftp-ld-dialog__note" data-iftp-confirm-note hidden></p>
		</div>

		<div id="iftp-ld-new-payment-dialog" title="<?php esc_attr_e( 'New payment', 'ifthenpay-payments-for-learndash' ); ?>" hidden>
			<form class="iftp-ld-dialog__form iftp-ld-new-payment" data-iftp-new-payment-form novalidate>
				<p class="iftp-ld-dialog__intro"><?php esc_html_e( 'Record a payment taken outside checkout. It’s filed as ad hoc: nobody is enrolled and no LearnDash order is created.', 'ifthenpay-payments-for-learndash' ); ?></p>
				<p class="iftp-ld-dialog__error" data-iftp-new-payment-error role="alert" hidden><span class="dashicons dashicons-warning" aria-hidden="true"></span><span data-iftp-error-text></span></p>

				<div class="iftp-ld-new-payment__row">
					<label class="iftp-ld-dialog__label" for="iftp-ld-np-name"><?php esc_html_e( 'Student name', 'ifthenpay-payments-for-learndash' ); ?></label>
					<input type="text" id="iftp-ld-np-name" class="iftp-ld-dialog__input" name="customer_name" autocomplete="off" />
				</div>
				<div class="iftp-ld-new-payment__row">
					<label class="iftp-ld-dialog__label" for="iftp-ld-np-email"><?php esc_html_e( 'Student email', 'ifthenpay-payments-for-learndash' ); ?></label>
					<input type="email" id="iftp-ld-np-email" class="iftp-ld-dialog__input" name="customer_email" autocomplete="off" aria-describedby="iftp-ld-np-email-error" />
					<p id="iftp-ld-np-email-error" class="iftp-ld-dialog__field-error" hidden></p>
				</div>
				<div class="iftp-ld-new-payment__row">
					<label class="iftp-ld-dialog__label" for="iftp-ld-np-product"><?php esc_html_e( 'Course or group', 'ifthenpay-payments-for-learndash' ); ?></label>
					<select id="iftp-ld-np-product" class="iftp-ld-dialog__input" name="product_id" data-iftp-np-product>
						<option value=""><?php esc_html_e( 'None', 'ifthenpay-payments-for-learndash' ); ?></option>
						<?php $this->print_product_options( $this->all_products(), 0, true ); ?>
					</select>
				</div>
				<div class="iftp-ld-new-payment__row">
					<label class="iftp-ld-dialog__label" for="iftp-ld-np-amount"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-learndash' ); ?> <span aria-hidden="true">*</span></label>
					<span class="iftp-ld-affix"><input type="number" id="iftp-ld-np-amount" name="amount" step="0.01" min="0.01" inputmode="decimal" required aria-describedby="iftp-ld-np-amount-error" /><span class="iftp-ld-affix__suffix" aria-hidden="true">€</span></span>
					<p id="iftp-ld-np-amount-error" class="iftp-ld-dialog__field-error" hidden></p>
				</div>
				<div class="iftp-ld-new-payment__row">
					<label class="iftp-ld-dialog__label" for="iftp-ld-np-status"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-learndash' ); ?></label>
					<select id="iftp-ld-np-status" class="iftp-ld-dialog__input" name="status" data-iftp-np-status>
						<?php foreach ( EntryCells::status_labels() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="iftp-ld-new-payment__row" data-iftp-np-send-row>
					<label><input type="checkbox" name="send_email" value="1" data-iftp-np-send /> <?php esc_html_e( 'Send the payment email now', 'ifthenpay-payments-for-learndash' ); ?></label>
				</div>
			</form>
		</div>

		<?php $this->email->render_dialog(); ?>
		<?php
	}

	/**
	 * The Columns popover: show/hide and reorder.
	 *
	 * @param array<int, string> $col_order   column_order().
	 * @param array<int, string> $hidden_cols hidden_columns().
	 * @return void
	 */
	private function render_columns_menu( array $col_order, array $hidden_cols ): void {
		$labels = $this->column_labels();
		$last   = count( $col_order ) - 1;
		?>
		<div id="iftp-ld-columns-menu" class="iftp-ld-popover iftp-ld-popover--columns" data-iftp-columns-menu role="group" aria-labelledby="iftp-ld-columns-title" hidden>
			<p id="iftp-ld-columns-title" class="iftp-ld-popover__title"><?php esc_html_e( 'Choose and order columns', 'ifthenpay-payments-for-learndash' ); ?></p>
			<div class="iftp-ld-columns__locked">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-learndash' ); ?></span>
				<span class="iftp-ld-columns__hint"><?php esc_html_e( 'Always shown', 'ifthenpay-payments-for-learndash' ); ?></span>
			</div>
			<ul class="iftp-ld-columns__list" data-iftp-col-list>
				<?php foreach ( $col_order as $i => $slug ) : ?>
					<?php $label = $labels[ $slug ] ?? $slug; ?>
					<li class="iftp-ld-columns__item" data-iftp-col-item="<?php echo esc_attr( $slug ); ?>">
						<span class="iftp-ld-columns__handle dashicons dashicons-menu" data-iftp-col-drag draggable="true" aria-hidden="true"></span>
						<label class="iftp-ld-columns__option">
							<input type="checkbox" data-iftp-col-toggle="<?php echo esc_attr( $slug ); ?>" <?php checked( ! in_array( $slug, $hidden_cols, true ) ); ?> />
							<span class="iftp-ld-columns__label"><?php echo esc_html( $label ); ?></span>
						</label>
						<?php /* translators: %s: column name. */ ?>
						<button type="button" class="iftp-ld-columns__move" data-iftp-col-move="up" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s up', 'ifthenpay-payments-for-learndash' ), $label ) ); ?>"<?php echo 0 === $i ? ' aria-disabled="true"' : ''; ?>><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
						<?php /* translators: %s: column name. */ ?>
						<button type="button" class="iftp-ld-columns__move" data-iftp-col-move="down" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s down', 'ifthenpay-payments-for-learndash' ), $label ) ); ?>"<?php echo $last === $i ? ' aria-disabled="true"' : ''; ?>><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="iftp-ld-popover__footer"><button type="button" class="button-link" data-iftp-col-reset><?php esc_html_e( 'Reset columns', 'ifthenpay-payments-for-learndash' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Prints the logo mark: the empty-state icon at rest, the spinner while busy.
	 *
	 * @param bool $busy Start as the spinner.
	 * @return void
	 */
	private function print_mark( bool $busy ): void {
		?>
		<span class="iftp-ld-mark<?php echo $busy ? ' iftp-ld-mark--busy' : ' iftp-ld-empty__icon'; ?>" aria-hidden="true">
			<span class="iftp-ld-mark__disc"><img class="iftp-ld-mark__logo" src="<?php echo esc_url( IFTP_LD_URL . 'assets/img/icon-white.svg' ); ?>" alt="" width="28" height="28" /></span>
			<span class="iftp-ld-mark__orbit"></span>
		</span>
		<?php
	}

	/**
	 * The tbody contents for a page.
	 *
	 * @param array<int, array<string, mixed>> $rows     The page's rows.
	 * @param bool                             $filtered Whether a filter, search or tab narrows the view.
	 * @return void
	 */
	private function print_rows( array $rows, bool $filtered ): void {
		if ( array() === $rows ) {
			?>
			<tr class="iftp-ld-table__empty-row">
				<td colspan="<?php echo esc_attr( (string) ( count( self::HIDEABLE_COLUMNS ) + 2 ) ); ?>">
					<div class="iftp-ld-empty">
						<?php $this->print_mark( false ); ?>
						<?php if ( $filtered ) : ?>
							<h3 class="iftp-ld-empty__title"><?php esc_html_e( 'No payments match these filters', 'ifthenpay-payments-for-learndash' ); ?></h3>
							<p class="iftp-ld-empty__text"><?php esc_html_e( 'Try other filters, or clear them to see everything.', 'ifthenpay-payments-for-learndash' ); ?></p>
							<p><a class="button" href="<?php echo esc_url( AdminMenu::url( array( 'status' => '' ) ) ); ?>" data-iftp-clear-view><?php esc_html_e( 'Clear filters', 'ifthenpay-payments-for-learndash' ); ?></a></p>
						<?php else : ?>
							<h3 class="iftp-ld-empty__title"><?php esc_html_e( 'No payments yet', 'ifthenpay-payments-for-learndash' ); ?></h3>
							<p class="iftp-ld-empty__text"><?php esc_html_e( 'When students pay with ifthenpay, every attempt shows up here.', 'ifthenpay-payments-for-learndash' ); ?></p>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<?php
			return;
		}

		// One query each for every course and student on the page.
		_prime_post_caches( array_unique( array_filter( array_map( 'intval', array_column( $rows, 'product_id' ) ) ) ), false, false );
		cache_users( array_unique( array_filter( array_map( 'intval', array_column( $rows, 'user_id' ) ) ) ) );

		foreach ( $rows as $row ) {
			/**
			 * Store rows are hydrated.
			 *
			 * @phpstan-var Transaction $row
			 */
			$this->render_row( $row );
		}
	}

	/**
	 * One payment as a row, plus its empty details row that entries.js fills on first open.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	private function render_row( array $row ): void {
		$ref        = $row['ref'];
		$details_id = 'iftp-ld-details-' . sanitize_html_class( $ref );
		$is_paid    = TransactionStore::STATUS_PAID === $row['status'];
		$labels     = $this->column_labels();
		$data       = array(
			'ref'      => $ref,
			'status'   => $row['status'],
			'student'  => '' !== $row['customer_name'] ? $row['customer_name'] : $row['customer_email'],
			'email'    => $row['customer_email'],
			'product'  => $row['product_id'] > 0 ? $this->cells->product_title( $row['product_id'] ) : '',
			'checkout' => $row['user_id'] > 0 && $row['product_id'] > 0,
		);
		?>
		<tr class="iftp-ld-table__row" data-iftp-ref="<?php echo esc_attr( $ref ); ?>" data-iftp-row="<?php echo esc_attr( rawurlencode( (string) wp_json_encode( $data ) ) ); ?>">
			<th scope="row" class="check-column">
				<input type="checkbox" id="iftp-ld-cb-<?php echo esc_attr( sanitize_html_class( $ref ) ); ?>" data-iftp-row-check data-iftp-status="<?php echo esc_attr( $row['status'] ); ?>" value="<?php echo esc_attr( $ref ); ?>" />
				<?php /* translators: %s: payment reference. */ ?>
				<label class="screen-reader-text" for="iftp-ld-cb-<?php echo esc_attr( sanitize_html_class( $ref ) ); ?>"><?php echo esc_html( sprintf( __( 'Select %s', 'ifthenpay-payments-for-learndash' ), $ref ) ); ?></label>
			</th>
			<td class="column-ref" data-col="ref">
				<span class="iftp-ld-ref-line">
					<button type="button" class="iftp-ld-ref" data-iftp-details-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( $details_id ); ?>"><?php echo esc_html( $ref ); ?></button>
					<?php self::print_copy_button( $ref, __( 'Copy reference', 'ifthenpay-payments-for-learndash' ) ); ?>
				</span>
				<div class="row-actions visible">
					<span class="view"><button type="button" class="button-link" data-iftp-details-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( $details_id ); ?>"><?php esc_html_e( 'View', 'ifthenpay-payments-for-learndash' ); ?></button></span>
					<?php $this->render_link_action( $row ); ?>
					<?php // Paid rows are payment history: no Delete, and the server refuses them too. ?>
					<?php if ( ! $is_paid ) : ?>
						| <span class="delete"><button type="button" class="button-link button-link-delete" data-iftp-row-delete="<?php echo esc_attr( $ref ); ?>"><?php esc_html_e( 'Delete', 'ifthenpay-payments-for-learndash' ); ?></button></span>
					<?php endif; ?>
				</div>
			</td>
			<?php foreach ( $this->column_order() as $slug ) : ?>
				<td class="column-<?php echo esc_attr( $slug ); ?>" data-col="<?php echo esc_attr( $slug ); ?>" data-label="<?php echo esc_attr( $labels[ $slug ] ); ?>"><?php $this->render_cell( $slug, $row ); ?></td>
			<?php endforeach; ?>
		</tr>
		<tr id="<?php echo esc_attr( $details_id ); ?>" class="iftp-ld-table__details" data-iftp-details-ref="<?php echo esc_attr( $ref ); ?>" hidden>
			<td colspan="<?php echo esc_attr( (string) ( count( self::HIDEABLE_COLUMNS ) + 2 ) ); ?>"></td>
		</tr>
		<?php
	}

	/**
	 * The row's link action: Copy link while payable, otherwise the reason, greyed out.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	private function render_link_action( array $row ): void {
		if ( EntryCells::has_live_link( $row ) ) {
			echo ' | <span class="copy-link"><button type="button" class="button-link" data-iftp-copy-link="' . esc_attr( $row['payment_url'] ) . '">' . esc_html__( 'Copy link', 'ifthenpay-payments-for-learndash' ) . '</button></span>';
			return;
		}

		if ( TransactionStore::STATUS_PENDING === $row['status'] && '' === $row['payment_url'] ) {
			$label = __( 'No link', 'ifthenpay-payments-for-learndash' );
			$hint  = __( 'No payment link yet. Sending the payment email creates one.', 'ifthenpay-payments-for-learndash' );
		} elseif ( '' !== $row['payment_url'] && in_array( $row['status'], array( TransactionStore::STATUS_PENDING, TransactionStore::STATUS_EXPIRED ), true ) ) {
			$label = __( 'Link expired', 'ifthenpay-payments-for-learndash' );
			$hint  = __( 'This link can no longer be paid.', 'ifthenpay-payments-for-learndash' );
		} else {
			return;
		}

		echo ' | <span class="iftp-ld-row-action--disabled" aria-disabled="true" title="' . esc_attr( $hint ) . '">' . esc_html( $label ) . '<span class="screen-reader-text"> (' . esc_html( $hint ) . ')</span></span>';
	}

	/**
	 * Prints LearnDash's copy-text button (its partial when available, the same markup otherwise).
	 *
	 * @param string $text    Text to copy.
	 * @param string $tooltip Tooltip.
	 * @return void
	 */
	public static function print_copy_button( string $text, string $tooltip ): void {
		if ( '' === $text ) {
			return;
		}

		$success = __( 'Copied', 'ifthenpay-payments-for-learndash' );

		if ( class_exists( '\LearnDash\Core\Template\Template' ) ) {
			echo wp_kses(
				\LearnDash\Core\Template\Template::get_admin_template(
					'common/copy-text',
					array(
						'text'            => $text,
						'tooltip_default' => $tooltip,
						'tooltip_success' => $success,
					)
				),
				array(
					'button' => array(
						'class'                => true,
						'data-tooltip'         => true,
						'data-tooltip-default' => true,
						'data-tooltip-success' => true,
						'data-text'            => true,
					),
					'span'   => array(
						'class'       => true,
						'aria-hidden' => true,
					),
				)
			);
			return;
		}

		printf(
			'<button class="learndash-copy-text" data-tooltip="%1$s" data-tooltip-default="%1$s" data-tooltip-success="%2$s" data-text="%3$s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="screen-reader-text">%4$s</span></button>',
			esc_attr( $tooltip ),
			esc_attr( $success ),
			esc_attr( $text ),
			esc_html( $tooltip )
		);
	}

	/**
	 * One header cell.
	 *
	 * @param string $slug    Column.
	 * @param string $orderby Current orderby.
	 * @param string $order   Current order.
	 * @return void
	 */
	private function render_head_cell( string $slug, string $orderby, string $order ): void {
		$label = $this->column_labels()[ $slug ];

		if ( ! isset( self::SORTABLE[ $slug ] ) ) {
			echo '<th scope="col" class="manage-column column-' . esc_attr( $slug ) . '" data-col="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</th>';
			return;
		}

		$key       = self::SORTABLE[ $slug ];
		$is_sorted = $key === $orderby;
		$next      = $is_sorted && 'asc' === $order ? 'desc' : 'asc';
		$class     = 'manage-column column-' . $slug . ( $is_sorted ? ' sorted ' . $order : ' sortable desc' );
		$aria      = $is_sorted ? ( 'asc' === $order ? 'ascending' : 'descending' ) : '';

		printf(
			'<th scope="col" class="%1$s" data-col="%2$s"%3$s><a href="%4$s"><span>%5$s</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>',
			esc_attr( $class ),
			esc_attr( $slug ),
			'' !== $aria ? ' aria-sort="' . esc_attr( $aria ) . '"' : '',
			esc_url(
				$this->filtered_url(
					array(
						'orderby' => $key,
						'order'   => $next,
						'paged'   => null,
					)
				)
			),
			esc_html( $label )
		);
	}

	/**
	 * One body cell.
	 *
	 * @param string               $slug Column.
	 * @param array<string, mixed> $row  Transaction row.
	 * @phpstan-param Transaction $row
	 * @return void
	 */
	private function render_cell( string $slug, array $row ): void {
		switch ( $slug ) {
			case 'student':
				$this->cells->print_student( $row );
				break;
			case 'product':
				$this->cells->print_product( $row );
				break;
			case 'method':
				$this->cells->print_method( $row['pay_method'], $row['status'] );
				break;
			case 'amount':
				echo esc_html( EntryCells::format_amount( $row['amount'] ) );
				break;
			case 'status':
				$this->cells->print_status( $row['status'] );
				break;
			case 'order':
				$this->cells->print_order( $row );
				break;
			case 'created':
				$this->cells->print_created( $row['created_at'] );
				break;
		}
	}

	/**
	 * The inline details panel, in LearnDash's order-details anatomy.
	 *
	 * @param array<string, mixed> $row Transaction row.
	 * @phpstan-param Transaction $row
	 * @return string
	 */
	public function details_html( array $row ): string {
		ob_start();
		?>
		<div class="iftp-ld-details">
			<div class="ld-order-details__container">
				<?php $this->entry->print_payment_table( $row ); ?>
				<?php $this->entry->print_student_table( $row ); ?>
				<?php $this->entry->print_product_table( $row ); ?>
			</div>
			<div class="iftp-ld-details__actions">
				<?php if ( EntryCells::can_email( $row ) ) : ?>
					<button type="button" class="button" data-iftp-send-email="<?php echo esc_attr( $row['ref'] ); ?>" data-iftp-send-to="<?php echo esc_attr( $row['customer_email'] ); ?>"><?php esc_html_e( 'Send payment email', 'ifthenpay-payments-for-learndash' ); ?></button>
				<?php endif; ?>
				<a class="button-link" href="<?php echo esc_url( EntryPage::url( $row['ref'] ) ); ?>"><?php esc_html_e( 'Open full page', 'ifthenpay-payments-for-learndash' ); ?> <span aria-hidden="true">&rarr;</span></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Re-queries and renders the tbody, pagination and summary for a view.
	 *
	 * @param array<string, mixed> $view View state.
	 * @phpstan-param View $view
	 * @return array{rowsHtml: string, paginationHtml: string, summaryHtml: string, paged: int, total: int, totalLabel: string}
	 */
	public function rendered_view( array $view ): array {
		$shared = $this->store_filters( $view );
		$result = $this->store->query(
			$shared + array(
				'status'   => $view['status'],
				'orderby'  => $view['orderby'],
				'order'    => $view['order'],
				'per_page' => $view['per_page'],
				'paged'    => $view['paged'],
			)
		);

		$base = $this->url_args( array_merge( $view, array( 'paged' => $result['paged'] ) ) );

		ob_start();
		$this->print_rows( $result['items'], '' !== $view['s'] || '' !== $view['status'] || array() !== $this->active_filter_chips( $view, $base ) );
		$rows = (string) ob_get_clean();

		ob_start();
		$this->render_pagination( $result['paged'], $result['pages'], $base );
		$pagination = (string) ob_get_clean();

		ob_start();
		$this->print_summary( $this->store->status_summary( $shared ), $base );
		$summary = (string) ob_get_clean();

		return array(
			'rowsHtml'       => $rows,
			'paginationHtml' => $pagination,
			'summaryHtml'    => $summary,
			'paged'          => $result['paged'],
			'total'          => $result['total'],
			'totalLabel'     => $this->range_label( $result['paged'], $view['per_page'], $result['total'] ),
		);
	}

	/**
	 * The summary cards, each a status filter; the current one toggles back to All.
	 *
	 * @param array<string, array{count: int, amount: float}> $summary status_summary().
	 * @param array<string, mixed>|null                       $base    url_args() of the view (AJAX has no $_GET).
	 * @return void
	 */
	private function print_summary( array $summary, ?array $base = null ): void {
		$base  = $base ?? $this->url_args( $this->current_filters() );
		$icons = array(
			TransactionStore::STATUS_PAID    => 'yes',
			TransactionStore::STATUS_PENDING => 'clock',
			TransactionStore::STATUS_FAILED  => 'no-alt',
			TransactionStore::STATUS_EXPIRED => 'hourglass',
		);
		?>
		<ul class="iftp-ld-summary__list">
			<?php foreach ( $icons as $slug => $icon ) : ?>
				<?php
				$count      = (int) ( $summary[ $slug ]['count'] ?? 0 );
				$amount     = (float) ( $summary[ $slug ]['amount'] ?? 0 );
				$is_current = $slug === $base['status'];
				$url        = $this->filtered_url(
					array(
						'status' => $is_current ? '' : $slug,
						'paged'  => null,
					),
					$base
				);
				?>
				<li class="iftp-ld-summary__item iftp-ld-summary__item--<?php echo esc_attr( $slug ); ?><?php echo $is_current ? ' iftp-ld-summary__item--current' : ''; ?>">
					<a class="iftp-ld-summary__link" href="<?php echo esc_url( $url ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>>
						<span class="iftp-ld-summary__label"><?php echo esc_html( TransactionStore::STATUS_FAILED === $slug ? __( 'Failed or cancelled', 'ifthenpay-payments-for-learndash' ) : EntryCells::status_label( $slug ) ); ?></span>
						<span class="iftp-ld-summary__amount"><?php echo esc_html( EntryCells::format_amount( $amount ) ); ?></span>
						<?php /* translators: %s: number of payments. */ ?>
						<span class="iftp-ld-summary__count"><?php echo esc_html( sprintf( _n( '%s payment', '%s payments', $count, 'ifthenpay-payments-for-learndash' ), number_format_i18n( $count ) ) ); ?></span>
						<?php if ( $is_current ) : ?>
							<span class="screen-reader-text"><?php esc_html_e( 'Selected. Activate to show all payments.', 'ifthenpay-payments-for-learndash' ); ?></span>
						<?php endif; ?>
						<span class="iftp-ld-summary__icon" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Pagination, windowed around the current page, with page-jump GET forms for the gaps.
	 *
	 * @param int                       $paged Current page.
	 * @param int                       $pages Total pages.
	 * @param array<string, mixed>|null $base  View args (AJAX has no $_GET).
	 * @return void
	 */
	private function render_pagination( int $paged, int $pages, ?array $base = null ): void {
		if ( $pages <= 1 ) {
			return;
		}

		$args  = $base ?? $this->url_args( $this->current_filters() );
		$start = max( 1, $paged - 2 );
		$end   = min( $paged + 2, $pages );
		?>
		<nav class="iftp-ld-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'ifthenpay-payments-for-learndash' ); ?>">
			<?php if ( $paged > 1 ) : ?>
				<a class="button iftp-ld-pagination__item" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $paged - 1 ), $args ) ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'ifthenpay-payments-for-learndash' ); ?>">&lsaquo;</a>
			<?php endif; ?>
			<?php if ( $start > 1 ) : ?>
				<a class="button iftp-ld-pagination__item" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => 1 ), $args ) ); ?>">1</a>
				<?php if ( $start > 2 ) : ?>
					<?php $this->render_page_jump( $pages, $args ); ?>
				<?php endif; ?>
			<?php endif; ?>
			<?php for ( $page = $start; $page <= $end; $page++ ) : ?>
				<?php if ( $page === $paged ) : ?>
					<span class="button iftp-ld-pagination__item iftp-ld-pagination__item--current" aria-current="page"><?php echo esc_html( number_format_i18n( $page ) ); ?></span>
				<?php else : ?>
					<a class="button iftp-ld-pagination__item" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $page ), $args ) ); ?>"><?php echo esc_html( number_format_i18n( $page ) ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>
			<?php if ( $end < $pages ) : ?>
				<?php if ( $end < $pages - 1 ) : ?>
					<?php $this->render_page_jump( $pages, $args ); ?>
				<?php endif; ?>
				<a class="button iftp-ld-pagination__item" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $pages ), $args ) ); ?>"><?php echo esc_html( number_format_i18n( $pages ) ); ?></a>
			<?php endif; ?>
			<?php if ( $paged < $pages ) : ?>
				<a class="button iftp-ld-pagination__item" href="<?php echo esc_url( $this->filtered_url( array( 'paged' => $paged + 1 ), $args ) ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'ifthenpay-payments-for-learndash' ); ?>">&rsaquo;</a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * A page-jump box in place of an ellipsis, its own GET form so it works without JS.
	 *
	 * @param int                  $pages Total pages.
	 * @param array<string, mixed> $args  View args.
	 * @return void
	 */
	private function render_page_jump( int $pages, array $args ): void {
		?>
		<form class="iftp-ld-pagination__jump" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<?php $this->hidden_view_inputs( $args, array( 'paged' ) ); ?>
			<input type="number" name="paged" min="1" max="<?php echo esc_attr( (string) $pages ); ?>" step="1" inputmode="numeric" required autocomplete="off" placeholder="&hellip;" aria-label="<?php esc_attr_e( 'Go to page', 'ifthenpay-payments-for-learndash' ); ?>" />
		</form>
		<?php
	}

	/**
	 * Admin-post: streams the current view (or the selected refs) as CSV or JSON.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! Host::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-learndash' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() verified the nonce; filters_from() sanitizes every key.
		$view = $this->filters_from( $_REQUEST );
		$refs = self::refs_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() verified the nonce.
		$format = isset( $_REQUEST['format'] ) && self::EXPORT_JSON === sanitize_key( wp_unslash( $_REQUEST['format'] ) ) ? self::EXPORT_JSON : self::EXPORT_CSV;
		$args   = $this->store_filters( $view ) + array(
			'status'  => $view['status'],
			'orderby' => $view['orderby'],
			'order'   => $view['order'],
		);

		if ( array() !== $refs ) {
			$args['refs'] = $refs;
		}

		nocache_headers();
		header( 'Content-Type: ' . ( self::EXPORT_JSON === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ifthenpay-payments-' . wp_date( 'Y-m-d' ) . '.' . $format . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the response body; WP_Filesystem has no stream API.
		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		if ( self::EXPORT_JSON === $format ) {
			$this->stream_json( $out, $args );
		} else {
			$this->stream_csv( $out, $args );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen() above.
		fclose( $out );
		exit;
	}

	/**
	 * Writes the rows as CSV, with a BOM for Excel and a header row.
	 *
	 * @param resource             $out  php://output.
	 * @param array<string, mixed> $args iterate() filters.
	 * @return void
	 */
	private function stream_csv( $out, array $args ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
		fwrite( $out, "\xEF\xBB\xBF" );

		self::put_csv_row(
			$out,
			array(
				__( 'Reference', 'ifthenpay-payments-for-learndash' ),
				__( 'Course or group', 'ifthenpay-payments-for-learndash' ),
				__( 'Type', 'ifthenpay-payments-for-learndash' ),
				__( 'Student name', 'ifthenpay-payments-for-learndash' ),
				__( 'Student email', 'ifthenpay-payments-for-learndash' ),
				__( 'User ID', 'ifthenpay-payments-for-learndash' ),
				__( 'Method', 'ifthenpay-payments-for-learndash' ),
				__( 'Amount', 'ifthenpay-payments-for-learndash' ),
				__( 'Status', 'ifthenpay-payments-for-learndash' ),
				__( 'LearnDash order', 'ifthenpay-payments-for-learndash' ),
				__( 'Created', 'ifthenpay-payments-for-learndash' ),
				__( 'Last updated', 'ifthenpay-payments-for-learndash' ),
				__( 'Expires', 'ifthenpay-payments-for-learndash' ),
				__( 'Request ID', 'ifthenpay-payments-for-learndash' ),
				__( 'Transaction ID', 'ifthenpay-payments-for-learndash' ),
				__( 'Payment link', 'ifthenpay-payments-for-learndash' ),
			)
		);

		foreach ( $this->store->iterate( $args ) as $row ) {
			self::put_csv_row(
				$out,
				array(
					(string) $row['ref'],
					$this->export_product( (int) $row['product_id'] ),
					(int) $row['product_id'] > 0 ? (string) $row['product_type'] : '',
					(string) $row['customer_name'],
					(string) $row['customer_email'],
					(int) $row['user_id'] > 0 ? (string) $row['user_id'] : '',
					(string) $row['pay_method'],
					number_format( (float) $row['amount'], 2, '.', '' ),
					EntryCells::status_label( (string) $row['status'] ),
					(int) $row['order_id'] > 0 ? (string) $row['order_id'] : '',
					(string) wp_date( 'Y-m-d H:i:s', (int) $row['created_at'] ),
					(string) wp_date( 'Y-m-d H:i:s', (int) $row['updated_at'] ),
					(int) $row['expires_at'] > 0 ? (string) wp_date( 'Y-m-d H:i:s', (int) $row['expires_at'] ) : '',
					(string) $row['request_id'],
					(string) $row['transaction_id'],
					(string) $row['payment_url'],
				)
			);
		}
	}

	/**
	 * Writes the rows as a JSON array, one object per line, so big exports never sit in memory.
	 *
	 * @param resource             $out  php://output.
	 * @param array<string, mixed> $args iterate() filters.
	 * @return void
	 */
	private function stream_json( $out, array $args ): void {
		$first = true;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
		fwrite( $out, '[' );

		foreach ( $this->store->iterate( $args ) as $row ) {
			// Stable English keys and raw values (status slug, ISO dates): this is for machines.
			$json = wp_json_encode(
				array(
					'reference'      => (string) $row['ref'],
					'product_id'     => (int) $row['product_id'],
					'product_type'   => (string) $row['product_type'],
					'product'        => $this->export_product( (int) $row['product_id'] ),
					'user_id'        => (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
					'customer_name'  => (string) $row['customer_name'],
					'customer_email' => (string) $row['customer_email'],
					'method'         => (string) $row['pay_method'],
					'amount'         => round( (float) $row['amount'], 2 ),
					'status'         => (string) $row['status'],
					'status_label'   => EntryCells::status_label( (string) $row['status'] ),
					'order_id'       => (int) $row['order_id'] > 0 ? (int) $row['order_id'] : null,
					'created_at'     => (string) wp_date( 'c', (int) $row['created_at'] ),
					'updated_at'     => (string) wp_date( 'c', (int) $row['updated_at'] ),
					'expires_at'     => (int) $row['expires_at'] > 0 ? (string) wp_date( 'c', (int) $row['expires_at'] ) : null,
					'request_id'     => (string) $row['request_id'],
					'transaction_id' => (string) $row['transaction_id'],
					'payment_url'    => (string) $row['payment_url'],
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			);

			if ( false === $json ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
			fwrite( $out, ( $first ? '' : ',' ) . "\n  " . $json );
			$first = false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see handle_export()'s fopen().
		fwrite( $out, ( $first ? '' : "\n" ) . "]\n" );
	}

	/**
	 * Plain-text product name for the export, or "Ad Hoc".
	 *
	 * @param int $product_id Course or group ID.
	 * @return string
	 */
	private function export_product( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return __( 'Ad Hoc', 'ifthenpay-payments-for-learndash' );
		}

		return html_entity_decode( wp_strip_all_tags( $this->cells->product_title( $product_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * One CSV line, every cell guarded against spreadsheet formula injection.
	 *
	 * @param resource           $out   php://output.
	 * @param array<int, string> $cells Cells.
	 * @return void
	 */
	private static function put_csv_row( $out, array $cells ): void {
		$safe = array_map(
			static function ( string $cell ): string {
				// A leading quote makes Excel/Sheets treat the cell as text.
				return ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) ? "'" . $cell : $cell;
			},
			$cells
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- see handle_export()'s fopen().
		fputcsv( $out, $safe, ',', '"', '' );
	}

	/**
	 * The selected refs, sent as one JSON field (refs[] would hit max_input_vars).
	 *
	 * @return array<int, string>
	 */
	public static function refs_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- callers verify the nonce; each decoded ref is sanitized below, since sanitizing the JSON could corrupt it.
		$raw  = isset( $_POST['refs'] ) && is_string( $_POST['refs'] ) ? wp_unslash( $_POST['refs'] ) : '';
		$refs = json_decode( $raw, true );

		if ( ! is_array( $refs ) ) {
			return array();
		}

		$refs = array_map( 'sanitize_text_field', array_map( 'strval', array_filter( $refs, 'is_scalar' ) ) );

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
	 * The browser's view state from $_POST, for re-rendering after a mutation.
	 *
	 * @param string $prefix '' or 'view_' (create uses view_* so the view can't clash with the payment's fields).
	 * @return array<string, mixed>
	 * @phpstan-return View
	 */
	public function view_state_from_request( string $prefix ): array {
		$source = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the AJAX guard verified the nonce; filters_from() unslashes and sanitizes every key.
		foreach ( array( 's', 'product_id', 'date_from', 'date_to', 'pay_method', 'amount_min', 'amount_max', 'orderby', 'order', 'per_page', 'per_page_custom', 'paged' ) as $key ) {
			if ( isset( $_POST[ $prefix . $key ] ) && is_string( $_POST[ $prefix . $key ] ) ) {
				$source[ $key ] = $_POST[ $prefix . $key ];
			}
		}

		// Always view_status: a bare status is the target status of a bulk change.
		$source['status'] = isset( $_POST['view_status'] ) && is_string( $_POST['view_status'] ) ? $_POST['view_status'] : '';
		// phpcs:enable

		if ( ! isset( $source['per_page'] ) ) {
			$source['per_page'] = (string) self::DEFAULT_PER_PAGE;
		}

		return $this->filters_from( $source );
	}

	/**
	 * Course/Group filter options: every course or group with a payment.
	 *
	 * @return array<int, int>
	 */
	private function filter_products(): array {
		$ids = $this->store->distinct_product_ids();

		_prime_post_caches( $ids, false, false );

		return $ids;
	}

	/**
	 * Every course and group, for the New payment dialog.
	 *
	 * @return array<int, int>
	 */
	private function all_products(): array {
		$ids = get_posts(
			array(
				'post_type'        => array( 'sfwd-courses', 'groups' ),
				'post_status'      => array( 'publish', 'private', 'draft' ),
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- ids only, once, when the screen opens.
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$ids = array_map( 'intval', $ids );

		_prime_post_caches( $ids, false, true );

		return $ids;
	}

	/**
	 * Prints the product <option>s grouped as Courses / Groups.
	 *
	 * @param array<int, int> $ids        Post IDs.
	 * @param int             $selected   Selected ID.
	 * @param bool            $with_price Add data-iftp-price for the New payment prefill.
	 * @return void
	 */
	private function print_product_options( array $ids, int $selected, bool $with_price = false ): void {
		$groups = array(
			'course' => array(),
			'group'  => array(),
		);

		foreach ( $ids as $id ) {
			$groups[ TransactionStore::product_type( $id ) ][ $id ] = $this->cells->product_title( $id );
		}

		$labels = array(
			'course' => class_exists( '\LearnDash_Custom_Label' ) ? (string) \LearnDash_Custom_Label::get_label( 'courses' ) : __( 'Courses', 'ifthenpay-payments-for-learndash' ),
			'group'  => class_exists( '\LearnDash_Custom_Label' ) ? (string) \LearnDash_Custom_Label::get_label( 'groups' ) : __( 'Groups', 'ifthenpay-payments-for-learndash' ),
		);

		foreach ( $groups as $type => $options ) {
			if ( array() === $options ) {
				continue;
			}

			asort( $options );

			echo '<optgroup label="' . esc_attr( $labels[ $type ] ) . '">';

			foreach ( $options as $id => $title ) {
				$price = '';

				if ( $with_price ) {
					$product = Host::product( $id );
					$price   = null !== $product && $product->get_price() > 0 ? number_format( $product->get_price(), 2, '.', '' ) : '';
				}

				echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $selected, $id, false ) . ( '' !== $price ? ' data-iftp-price="' . esc_attr( $price ) . '"' : '' ) . '>' . esc_html( $title ) . '</option>';
			}

			echo '</optgroup>';
		}
	}

	/**
	 * Column slug => label.
	 *
	 * @return array<string, string>
	 */
	private function column_labels(): array {
		return array(
			'student' => __( 'Student', 'ifthenpay-payments-for-learndash' ),
			'product' => __( 'Course/Group', 'ifthenpay-payments-for-learndash' ),
			'method'  => __( 'Method', 'ifthenpay-payments-for-learndash' ),
			'amount'  => __( 'Amount', 'ifthenpay-payments-for-learndash' ),
			'status'  => __( 'Status', 'ifthenpay-payments-for-learndash' ),
			'order'   => __( 'Order', 'ifthenpay-payments-for-learndash' ),
			'created' => __( 'Created', 'ifthenpay-payments-for-learndash' ),
		);
	}

	/**
	 * Saves the hidden columns and their order for the current admin.
	 *
	 * @param array<int, string>      $hidden Hidden slugs.
	 * @param array<int, string>|null $order  Order, or null to leave it.
	 * @return array{hidden: array<int, string>, order?: array<int, string>}
	 */
	public function save_columns( array $hidden, ?array $order ): array {
		$hidden = array_values( array_intersect( self::HIDEABLE_COLUMNS, $hidden ) );

		update_user_meta( get_current_user_id(), self::HIDDEN_COLS_META, $hidden );

		$response = array( 'hidden' => $hidden );

		if ( null !== $order ) {
			$order = self::normalize_column_order( $order );
			update_user_meta( get_current_user_id(), self::COL_ORDER_META, $order );
			$response['order'] = $order;
		}

		return $response;
	}

	/**
	 * The current admin's hidden columns.
	 *
	 * @return array<int, string>
	 */
	private function hidden_columns(): array {
		$stored = get_user_meta( get_current_user_id(), self::HIDDEN_COLS_META, true );

		return is_array( $stored ) ? array_values( array_intersect( self::HIDEABLE_COLUMNS, $stored ) ) : array();
	}

	/**
	 * The current admin's column order: every hideable slug, once.
	 *
	 * @return array<int, string>
	 */
	private function column_order(): array {
		if ( null === $this->column_order ) {
			$this->column_order = self::normalize_column_order( get_user_meta( get_current_user_id(), self::COL_ORDER_META, true ) );
		}

		return $this->column_order;
	}

	/**
	 * Known slugs in the given order, missing ones appended.
	 *
	 * @param mixed $requested Stored meta or posted order.
	 * @return array<int, string>
	 */
	private static function normalize_column_order( $requested ): array {
		$order = is_array( $requested ) ? array_values( array_unique( array_intersect( array_filter( $requested, 'is_string' ), self::HIDEABLE_COLUMNS ) ) ) : array();

		return array_merge( $order, array_values( array_diff( self::HIDEABLE_COLUMNS, $order ) ) );
	}

	/**
	 * Quick date ranges in the site's timezone.
	 *
	 * @return array<int, array{label: string, from: string, to: string}>
	 */
	private function date_presets(): array {
		$today = current_datetime();

		return array(
			array(
				'label' => __( 'Today', 'ifthenpay-payments-for-learndash' ),
				'from'  => $today->format( 'Y-m-d' ),
				'to'    => $today->format( 'Y-m-d' ),
			),
			array(
				'label' => __( 'Last 7 days', 'ifthenpay-payments-for-learndash' ),
				'from'  => $today->modify( '-6 days' )->format( 'Y-m-d' ),
				'to'    => $today->format( 'Y-m-d' ),
			),
			array(
				'label' => __( 'This month', 'ifthenpay-payments-for-learndash' ),
				'from'  => $today->modify( 'first day of this month' )->format( 'Y-m-d' ),
				'to'    => $today->modify( 'last day of this month' )->format( 'Y-m-d' ),
			),
			array(
				'label' => __( 'Last month', 'ifthenpay-payments-for-learndash' ),
				'from'  => $today->modify( 'first day of last month' )->format( 'Y-m-d' ),
				'to'    => $today->modify( 'last day of last month' )->format( 'Y-m-d' ),
			),
		);
	}

	/**
	 * One chip per applied panel filter, each linking to the view without it.
	 *
	 * @param array<string, mixed>      $view View state.
	 * @param array<string, mixed>|null $base View args to build links from.
	 * @phpstan-param View $view
	 * @return array<int, array{key: string, value: string, url: string}>
	 */
	private function active_filter_chips( array $view, ?array $base = null ): array {
		$chips = array();

		if ( $view['product_id'] > 0 ) {
			$type    = TransactionStore::product_type( $view['product_id'] );
			$chips[] = array(
				/* translators: %s: "Course" or "Group" label. */
				'key'   => sprintf( __( '%s:', 'ifthenpay-payments-for-learndash' ), $this->cells->product_type_label( $type ) ),
				'value' => $this->cells->product_title( $view['product_id'] ),
				'url'   => $this->filtered_url(
					array(
						'product_id' => null,
						'paged'      => null,
					),
					$base
				),
			);
		}

		if ( '' !== $view['pay_method'] ) {
			$chips[] = array(
				'key'   => __( 'Method:', 'ifthenpay-payments-for-learndash' ),
				'value' => $this->cells->method_label( $view['pay_method'] ),
				'url'   => $this->filtered_url(
					array(
						'pay_method' => null,
						'paged'      => null,
					),
					$base
				),
			);
		}

		$amount = $this->range_text(
			'' !== $view['amount_min'] ? EntryCells::format_amount( (float) $view['amount_min'] ) : '',
			'' !== $view['amount_max'] ? EntryCells::format_amount( (float) $view['amount_max'] ) : '',
			/* translators: %s: an amount. */
			__( 'up to %s', 'ifthenpay-payments-for-learndash' )
		);

		if ( '' !== $amount ) {
			$chips[] = array(
				'key'   => __( 'Amount:', 'ifthenpay-payments-for-learndash' ),
				'value' => $amount,
				'url'   => $this->filtered_url(
					array(
						'amount_min' => null,
						'amount_max' => null,
						'paged'      => null,
					),
					$base
				),
			);
		}

		$created = '';

		// A range that matches a preset reads better as its label.
		foreach ( $this->date_presets() as $preset ) {
			if ( '' !== $view['date_from'] && $preset['from'] === $view['date_from'] && $preset['to'] === $view['date_to'] ) {
				$created = $preset['label'];
				break;
			}
		}

		if ( '' === $created ) {
			$created = $this->range_text(
				'' !== $view['date_from'] ? (string) wp_date( (string) get_option( 'date_format' ), self::day_bound( $view['date_from'], '12:00:00' ) ) : '',
				'' !== $view['date_to'] ? (string) wp_date( (string) get_option( 'date_format' ), self::day_bound( $view['date_to'], '12:00:00' ) ) : '',
				/* translators: %s: a date. */
				__( 'until %s', 'ifthenpay-payments-for-learndash' )
			);
		}

		if ( '' !== $created ) {
			$chips[] = array(
				'key'   => __( 'Created:', 'ifthenpay-payments-for-learndash' ),
				'value' => $created,
				'url'   => $this->filtered_url(
					array(
						'date_from' => null,
						'date_to'   => null,
						'paged'     => null,
					),
					$base
				),
			);
		}

		return $chips;
	}

	/**
	 * "A – B", "from A", or the upper-only wording.
	 *
	 * @param string $low   Lower bound text.
	 * @param string $high  Upper bound text.
	 * @param string $up_to Upper-only format with one %s.
	 * @return string
	 */
	private function range_text( string $low, string $high, string $up_to ): string {
		if ( '' !== $low && '' !== $high ) {
			/* translators: 1: lower bound, 2: upper bound. */
			return sprintf( __( '%1$s – %2$s', 'ifthenpay-payments-for-learndash' ), $low, $high );
		}

		if ( '' !== $low ) {
			/* translators: %s: an amount or a date. */
			return sprintf( __( 'from %s', 'ifthenpay-payments-for-learndash' ), $low );
		}

		return '' !== $high ? sprintf( $up_to, $high ) : '';
	}

	/**
	 * "1–50 of 1,234", blank when empty (the empty state says it).
	 *
	 * @param int $paged    Page.
	 * @param int $per_page Per page.
	 * @param int $total    Total rows.
	 * @return string
	 */
	private function range_label( int $paged, int $per_page, int $total ): string {
		if ( $total <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: 1: first payment shown, 2: last payment shown, 3: total payments. */
			__( '%1$s–%2$s of %3$s', 'ifthenpay-payments-for-learndash' ),
			number_format_i18n( ( $paged - 1 ) * $per_page + 1 ),
			number_format_i18n( min( $paged * $per_page, $total ) ),
			number_format_i18n( $total )
		);
	}

	/**
	 * The "Clear filters" link: drops the panel filters, keeps search, status, sort and per-page.
	 *
	 * @return string
	 */
	private function clear_filters_url(): string {
		return $this->filtered_url(
			array(
				'product_id' => null,
				'pay_method' => null,
				'amount_min' => null,
				'amount_max' => null,
				'date_from'  => null,
				'date_to'    => null,
				'paged'      => null,
			)
		);
	}

	/**
	 * The export link for a view, without pagination.
	 *
	 * @param array<string, mixed> $view   View state.
	 * @param string               $format EXPORT_*.
	 * @phpstan-param View $view
	 * @return string
	 */
	private function export_url( array $view, string $format ): string {
		$args = $this->url_args( $view );

		unset( $args['per_page'], $args['paged'] );

		return add_query_arg(
			self::query_values(
				array(
					'action'   => self::EXPORT_ACTION,
					'_wpnonce' => wp_create_nonce( self::EXPORT_ACTION ),
					'format'   => $format,
				) + $args
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The current URL with some params overridden (null removes one).
	 *
	 * @param array<string, mixed>      $overrides Params.
	 * @param array<string, mixed>|null $base      View args to start from (AJAX has no $_GET).
	 * @return string
	 */
	private function filtered_url( array $overrides, ?array $base = null ): string {
		$args = array( 'page' => AdminMenu::PAGE_SLUG ) + ( $base ?? $this->url_args( $this->current_filters() ) );

		foreach ( $overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $args[ $key ] );
			} else {
				$args[ $key ] = $value;
			}
		}

		if ( isset( $args['per_page'] ) && self::DEFAULT_PER_PAGE === (int) $args['per_page'] ) {
			unset( $args['per_page'] );
		}

		if ( isset( $args['paged'] ) && 1 === (int) $args['paged'] ) {
			unset( $args['paged'] );
		}

		$values = self::query_values( $args );

		// A bare URL would bring the saved view back (entries-restore.js), so "All" keeps an empty status.
		if ( 1 === count( $values ) ) {
			$values['status'] = '';
		}

		return add_query_arg( $values, admin_url( 'admin.php' ) );
	}

	/**
	 * Hidden inputs that carry a view into a plain GET form.
	 *
	 * @param array<string, mixed> $args url_args().
	 * @param array<int, string>   $omit Keys the form sets itself.
	 * @return void
	 */
	private function hidden_view_inputs( array $args, array $omit ): void {
		foreach ( array( 'page' => AdminMenu::PAGE_SLUG ) + $args as $key => $value ) {
			if ( in_array( $key, $omit, true ) || '' === $value || null === $value ) {
				continue;
			}

			if ( ( 'per_page' === $key && self::DEFAULT_PER_PAGE === (int) $value ) || ( 'paged' === $key && 1 === (int) $value ) ) {
				continue;
			}

			echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
	}

	/**
	 * Drops blank params and URL-encodes the rest (add_query_arg() doesn't encode values).
	 *
	 * @param array<string, mixed> $args Params.
	 * @return array<string, string>
	 */
	private static function query_values( array $args ): array {
		$args = array_filter(
			$args,
			static function ( $value ): bool {
				return '' !== $value && null !== $value;
			}
		);

		return array_map(
			static function ( $value ): string {
				return rawurlencode( (string) $value );
			},
			$args
		);
	}

	/**
	 * A view as URL params (no `page`).
	 *
	 * @param array<string, mixed> $view View state.
	 * @return array<string, mixed>
	 */
	private function url_args( array $view ): array {
		return array(
			's'          => $view['s'],
			'status'     => $view['status'],
			'product_id' => $view['product_id'] > 0 ? (string) $view['product_id'] : '',
			'date_from'  => $view['date_from'],
			'date_to'    => $view['date_to'],
			'pay_method' => $view['pay_method'],
			'amount_min' => $view['amount_min'],
			'amount_max' => $view['amount_max'],
			'orderby'    => $view['orderby'],
			'order'      => $view['order'],
			'per_page'   => $view['per_page'],
			'paged'      => $view['paged'],
		);
	}

	/**
	 * The store's where-args: everything but status, sort and pagination.
	 *
	 * @param array<string, mixed> $view View state.
	 * @return array<string, mixed>
	 */
	private function store_filters( array $view ): array {
		return array(
			'product_id' => $view['product_id'],
			'date_from'  => self::day_bound( $view['date_from'], '00:00:00' ),
			'date_to'    => self::day_bound( $view['date_to'], '23:59:59' ),
			'search'     => $view['s'],
			'pay_method' => $view['pay_method'],
			'amount_min' => '' !== $view['amount_min'] ? (float) $view['amount_min'] : null,
			'amount_max' => '' !== $view['amount_max'] ? (float) $view['amount_max'] : null,
		);
	}

	/**
	 * The request's view state, read once.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return View
	 */
	private function current_filters(): array {
		if ( null === $this->filters ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state; filters_from() sanitizes every key.
			$this->filters = $this->filters_from( $_GET );
		}

		return $this->filters;
	}

	/**
	 * Reads and sanitizes a view from a raw (slashed) request array.
	 *
	 * @param array<string, mixed> $source Raw request.
	 * @return array<string, mixed>
	 * @phpstan-return View
	 */
	private function filters_from( array $source ): array {
		$text = static function ( string $key ) use ( $source ): string {
			return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? sanitize_text_field( wp_unslash( $source[ $key ] ) ) : '';
		};

		$orderby = in_array( $text( 'orderby' ), self::SORTABLE, true ) ? $text( 'orderby' ) : '';
		$status  = in_array( $text( 'status' ), TransactionStore::ALL_STATUSES, true ) ? $text( 'status' ) : '';
		$min     = self::sanitize_amount( $text( 'amount_min' ) );
		$max     = self::sanitize_amount( $text( 'amount_max' ) );

		if ( '' !== $min && '' !== $max && (float) $min > (float) $max ) {
			list( $min, $max ) = array( $max, $min );
		}

		$method = $text( 'pay_method' );

		return array(
			's'          => $text( 's' ),
			'status'     => $status,
			'product_id' => absint( $text( 'product_id' ) ),
			'date_from'  => self::sanitize_date( $text( 'date_from' ) ),
			'date_to'    => self::sanitize_date( $text( 'date_to' ) ),
			'pay_method' => '' !== $method && in_array( $method, $this->store->distinct_methods(), true ) ? $method : '',
			'amount_min' => $min,
			'amount_max' => $max,
			'per_page'   => isset( $source['per_page'] ) ? self::sanitize_per_page( 'custom' === $text( 'per_page' ) ? $text( 'per_page_custom' ) : $text( 'per_page' ) ) : $this->saved_per_page(),
			'paged'      => max( 1, absint( $text( 'paged' ) ) ),
			'orderby'    => $orderby,
			'order'      => '' !== $orderby ? ( 'asc' === strtolower( $text( 'order' ) ) ? 'asc' : 'desc' ) : '',
		);
	}

	/**
	 * The current admin's saved per-page.
	 *
	 * @return int
	 */
	private function saved_per_page(): int {
		return self::sanitize_per_page( (string) get_user_meta( get_current_user_id(), self::PER_PAGE_META, true ) );
	}

	/**
	 * Clamps per-page; non-numeric means the default.
	 *
	 * @param string $raw Value.
	 * @return int
	 */
	private static function sanitize_per_page( string $raw ): int {
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return self::DEFAULT_PER_PAGE;
		}

		return max( self::PER_PAGE_MIN, min( self::PER_PAGE_MAX, (int) $raw ) );
	}

	/**
	 * A dot-decimal amount, or '' for no bound.
	 *
	 * @param string $raw Value.
	 * @return string
	 */
	private static function sanitize_amount( string $raw ): string {
		$value = str_replace( ',', '.', trim( $raw ) );

		return 1 === preg_match( '/^\d{1,12}(?:\.\d+)?$/', $value ) ? $value : '';
	}

	/**
	 * A valid Y-m-d date, or ''.
	 *
	 * @param string $raw Value.
	 * @return string
	 */
	private static function sanitize_date( string $raw ): string {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $parts ) ) {
			return '';
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $raw : '';
	}

	/**
	 * A filter date as a timestamp in the site's timezone (not strtotime(), which reads UTC), or 0.
	 *
	 * @param string $date Y-m-d or ''.
	 * @param string $time Time of day.
	 * @return int
	 */
	private static function day_bound( string $date, string $time ): int {
		if ( '' === $date ) {
			return 0;
		}

		try {
			return ( new \DateTimeImmutable( $date . ' ' . $time, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Status tabs. Cancelled sits inside Failed, as the store's summary does.
	 *
	 * @return array<string, string>
	 */
	private function status_filters(): array {
		return array(
			''                               => __( 'All', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_PENDING => __( 'Pending', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_PAID    => __( 'Paid', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_FAILED  => __( 'Failed', 'ifthenpay-payments-for-learndash' ),
			TransactionStore::STATUS_EXPIRED => __( 'Expired', 'ifthenpay-payments-for-learndash' ),
		);
	}
}
