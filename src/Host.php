<?php
/**
 * Guarded access to LearnDash's helpers.
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

namespace Ifthenpay\LearnDash;

defined( 'ABSPATH' ) || exit;

/**
 * One place that knows LearnDash's function, class and post type names.
 *
 * I keep every call here behind function_exists()/class_exists() so a LearnDash
 * rename (or a request where it didn't boot) degrades to "not available" instead
 * of a fatal.
 */
final class Host {

	public const ORDERS_POST_TYPE = 'sfwd-transactions';

	/**
	 * Whether LearnDash booted in this request.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'LEARNDASH_VERSION' ) && class_exists( '\Learndash_Payment_Gateway' );
	}

	/**
	 * LearnDash version.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'LEARNDASH_VERSION' ) ? (string) LEARNDASH_VERSION : '';
	}

	/**
	 * The capability LearnDash uses for its own admin screens.
	 *
	 * @return string
	 */
	public static function admin_cap(): string {
		return defined( 'LEARNDASH_ADMIN_CAPABILITY_CHECK' ) ? (string) LEARNDASH_ADMIN_CAPABILITY_CHECK : 'manage_options';
	}

	/**
	 * Whether the current user may manage payments.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::admin_cap() ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- LearnDash's own admin capability.
	}

	/**
	 * LearnDash's currency code.
	 *
	 * @return string
	 */
	public static function currency(): string {
		return function_exists( 'learndash_get_currency_code' ) ? strtoupper( (string) learndash_get_currency_code() ) : '';
	}

	/**
	 * A course or group as LearnDash's product model.
	 *
	 * @param int $post_id Course or group ID.
	 * @return \LearnDash\Core\Models\Product|null
	 */
	public static function product( int $post_id ) {
		if ( $post_id <= 0 || ! class_exists( '\LearnDash\Core\Models\Product' ) ) {
			return null;
		}

		$product = \LearnDash\Core\Models\Product::find( $post_id );

		return $product instanceof \LearnDash\Core\Models\Product ? $product : null;
	}

	/**
	 * "Course" or "Group", with LearnDash's custom labels.
	 *
	 * @param int $post_id Course or group ID.
	 * @return string
	 */
	public static function product_type_label( int $post_id ): string {
		$product = self::product( $post_id );

		return null === $product ? '' : $product->get_type_label();
	}

	/**
	 * Orders list URL (LearnDash LMS › Orders).
	 *
	 * @return string
	 */
	public static function orders_url(): string {
		return 'edit.php?post_type=' . self::orders_post_type();
	}

	/**
	 * Orders post type slug, through LearnDash's own mapper when available.
	 *
	 * @return string
	 */
	public static function orders_post_type(): string {
		if ( function_exists( 'learndash_get_post_type_slug' ) && class_exists( '\LDLMS_Post_Types' ) ) {
			return (string) learndash_get_post_type_slug( \LDLMS_Post_Types::TRANSACTION );
		}

		return self::ORDERS_POST_TYPE;
	}

	/**
	 * Edit link of a LearnDash order, or '' when it's gone.
	 *
	 * @param int $order_id Parent order ID.
	 * @return string
	 */
	public static function order_edit_url( int $order_id ): string {
		if ( $order_id <= 0 || self::orders_post_type() !== get_post_type( $order_id ) ) {
			return '';
		}

		return (string) get_edit_post_link( $order_id, 'raw' );
	}

	/**
	 * The "Orders" label (LearnDash lets sites rename it).
	 *
	 * @return string
	 */
	public static function orders_label(): string {
		if ( class_exists( '\LearnDash_Custom_Label' ) ) {
			return (string) \LearnDash_Custom_Label::get_label( 'orders' );
		}

		return __( 'Orders', 'ifthenpay-payments-for-learndash' );
	}

	/**
	 * Whether the modern LD30 checkout/registration design is on.
	 *
	 * @return bool
	 */
	public static function is_modern_registration(): bool {
		if ( ! function_exists( 'learndash_registration_variation' ) || ! class_exists( '\LearnDash_Theme_Register_LD30' ) ) {
			return false;
		}

		return learndash_registration_variation() !== \LearnDash_Theme_Register_LD30::$variation_classic;
	}

	/**
	 * Whether the active LearnDash theme is the modern LD30 course design.
	 *
	 * @param int $post_id Course or group ID.
	 * @return bool
	 */
	public static function is_modern_course_page( int $post_id ): bool {
		if ( ! class_exists( '\LearnDash\Core\Themes\LD30\Modern\Features' ) || ! class_exists( '\LearnDash_Theme_Register' ) || ! class_exists( '\LDLMS_Post_Types' ) ) {
			return false;
		}

		if ( 'ld30' !== \LearnDash_Theme_Register::get_active_theme_key() ) {
			return false;
		}

		$view = 'groups' === get_post_type( $post_id ) ? \LDLMS_Post_Types::GROUP : \LDLMS_Post_Types::COURSE;

		return \LearnDash\Core\Themes\LD30\Modern\Features::enabled_for_view( $view );
	}

	/**
	 * A coupon attached to a product for a user (the transient LearnDash keeps for a day).
	 *
	 * @param int $post_id Course or group ID.
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function attached_coupon( int $post_id, int $user_id ): array {
		if ( ! function_exists( 'learndash_get_attached_coupon_data' ) ) {
			return array();
		}

		$coupon = learndash_get_attached_coupon_data( $post_id, $user_id );

		return null === $coupon ? array() : (array) $coupon->to_array();
	}

	/**
	 * Puts a coupon snapshot back as the attached coupon, so LearnDash records and redeems it with the order.
	 *
	 * @param int                  $post_id Course or group ID.
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $coupon  Coupon DTO array.
	 * @return void
	 */
	public static function reattach_coupon( int $post_id, int $user_id, array $coupon ): void {
		if ( array() === $coupon || ! function_exists( 'learndash_map_coupon_transient_key' ) || ! function_exists( 'learndash_post_has_attached_coupon' ) ) {
			return;
		}

		if ( learndash_post_has_attached_coupon( $post_id, $user_id ) ) {
			return;
		}

		set_transient( learndash_map_coupon_transient_key( $post_id, $user_id ), $coupon, HOUR_IN_SECONDS );
	}
}
