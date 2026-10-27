<?php
/**
 * The Payments menu entry and LearnDash Orders tab.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash\Admin;

use Ifthenpay\LearnDash\Host;

defined( 'ABSPATH' ) || exit;

/**
 * Puts "Payments" under LearnDash LMS, right after Orders, and in LearnDash's
 * Orders tab set (Customer Orders | Test Orders | Payments) on both screens.
 */
final class AdminMenu {

	public const PAGE_SLUG = 'iftp-ld-payments';

	/**
	 * Our screen ID (the hook suffix), '' until registered.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * The entries screen.
	 *
	 * @var EntriesPage
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param EntriesPage $page The entries screen.
	 */
	public function __construct( EntriesPage $page ) {
		$this->page = $page;

		add_action( 'admin_menu', array( $this, 'add_page' ), 10 );
		add_action( 'admin_menu', array( $this, 'position' ), 1000 );
		add_action( 'in_admin_header', array( $this, 'borrow_parent_file' ), 1 );
		add_action( 'in_admin_header', array( $this, 'restore_parent_file' ), 21 );
		add_action( 'learndash_admin_tabs_set', array( $this, 'add_tab' ), 10, 2 );
		add_filter( 'learndash_header_buttons', array( $this, 'header_buttons' ), 20 );
		add_filter( 'learndash_load_admin_assets', array( $this, 'load_learndash_assets' ) );
		add_action( 'admin_footer', array( $this, 'current_menu_link' ), 9 );
	}

	/**
	 * The list URL.
	 *
	 * @param array<string, string> $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether the current admin screen is ours (list or entry page).
	 *
	 * @return bool
	 */
	public static function is_current_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return '' !== self::$hook && null !== $screen && self::$hook === $screen->id;
	}

	/**
	 * Registers the submenu page.
	 *
	 * @return void
	 */
	public function add_page(): void {
		$title = __( 'Payments', 'ifthenpay-payments-for-learndash' );
		$hook  = add_submenu_page( 'learndash-lms', $title, $title, Host::admin_cap(), self::PAGE_SLUG, array( $this->page, 'render' ) );

		if ( is_string( $hook ) && '' !== $hook ) {
			self::$hook = $hook;
			add_action( 'load-' . $hook, array( $this->page, 'load' ) );
		}
	}

	/**
	 * Moves our entry right after LearnDash's Orders.
	 *
	 * @return void
	 */
	public function position(): void {
		global $submenu;

		if ( ! isset( $submenu['learndash-lms'] ) || ! is_array( $submenu['learndash-lms'] ) ) {
			return;
		}

		$items = array_values( $submenu['learndash-lms'] );
		$ours  = null;

		foreach ( $items as $index => $item ) {
			if ( self::PAGE_SLUG === ( $item[2] ?? '' ) ) {
				$ours = $item;
				unset( $items[ $index ] );
				break;
			}
		}

		if ( null === $ours ) {
			return;
		}

		$items  = array_values( $items );
		$orders = array_search( Host::orders_url(), array_column( $items, 2 ), true );
		$at     = false === $orders ? count( $items ) : (int) $orders + 1;

		array_splice( $items, $at, 0, array( $ours ) );

		$submenu['learndash-lms'] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering LearnDash's submenu, as LearnDash itself does.
	}

	/**
	 * Hands LearnDash's tab code the Orders parent on our screen. The WP menu is already printed by now.
	 *
	 * @return void
	 */
	public function borrow_parent_file(): void {
		global $parent_file;

		if ( self::is_current_screen() ) {
			$parent_file = Host::orders_url(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- read by LearnDash's tabs at in_admin_header 20, put back at 21.
		}
	}

	/**
	 * Puts the parent back once LearnDash drew its tabs.
	 *
	 * @return void
	 */
	public function restore_parent_file(): void {
		global $parent_file;

		if ( self::is_current_screen() ) {
			$parent_file = 'learndash-lms'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- see borrow_parent_file().
		}
	}

	/**
	 * Adds our tab to LearnDash's Orders tab set.
	 *
	 * @param string $parent_file LearnDash's current tab set key.
	 * @param object $tabs        Learndash_Admin_Menus_Tabs.
	 * @return void
	 */
	public function add_tab( $parent_file, $tabs ): void {
		if ( Host::orders_url() !== $parent_file || '' === self::$hook || ! is_object( $tabs ) || ! method_exists( $tabs, 'add_admin_tab_item' ) ) {
			return;
		}

		$tabs->add_admin_tab_item(
			Host::orders_url(),
			array(
				'link' => 'admin.php?page=' . self::PAGE_SLUG,
				'name' => __( 'Customer Payments', 'ifthenpay-payments-for-learndash' ),
				'id'   => self::$hook,
				'cap'  => Host::admin_cap(),
			),
			90
		);
	}

	/**
	 * "New payment" and "Payment email" in LearnDash's header split button, on the list only.
	 *
	 * @param mixed $buttons Header buttons.
	 * @return mixed
	 */
	public function header_buttons( $buttons ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which header to show.
		if ( ! is_array( $buttons ) || ! self::is_current_screen() || isset( $_GET['entry_id'] ) || ! Host::can_manage() ) {
			return $buttons;
		}

		$buttons[] = array(
			'text'  => __( 'New payment', 'ifthenpay-payments-for-learndash' ),
			'class' => 'iftp-ld-header-new-payment',
		);
		$buttons[] = array(
			'text'  => __( 'Payment email', 'ifthenpay-payments-for-learndash' ),
			'class' => 'iftp-ld-header-email',
			'icon'  => false,
		);

		return $buttons;
	}

	/**
	 * LearnDash's admin assets (copy-text, select2) on our screen too.
	 *
	 * @param mixed $load Whether LearnDash loads them.
	 * @return mixed
	 */
	public function load_learndash_assets( $load ) {
		return self::is_current_screen() ? true : $load;
	}

	/**
	 * LearnDash marks the tab set's link (Orders) current in the menu; on our screen it should be ours.
	 *
	 * @return void
	 */
	public function current_menu_link(): void {
		global $learndash_current_page_link;

		if ( self::is_current_screen() ) {
			$learndash_current_page_link = 'admin.php?page=' . self::PAGE_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- LearnDash's own menu-highlight global.
		}
	}
}
