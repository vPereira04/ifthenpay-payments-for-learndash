<?php
/**
 * Plugin Name:       ifthenpay | Payments for LearnDash
 * Plugin URI:        https://ifthenpay.com/
 * Description:       ifthenpay Pay by Link for LearnDash: students pay for courses and groups on ifthenpay's hosted page with Multibanco, MB WAY, cards, Apple Pay, Google Pay and more.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  sfwd-lms
 * Author:            ifthenpay
 * Author URI:        https://ifthenpay.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ifthenpay-payments-for-learndash
 * Domain Path:       /languages
 *
 * @package Ifthenpay\LearnDash
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'IFTP_LD_VERSION', '1.0.0' );
define( 'IFTP_LD_FILE', __FILE__ );
define( 'IFTP_LD_PATH', plugin_dir_path( __FILE__ ) );
define( 'IFTP_LD_URL', plugin_dir_url( __FILE__ ) );

// A build without vendor/ shouldn't take the site down: say so and stay inert.
if ( ! is_readable( IFTP_LD_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				wp_admin_notice(
					esc_html__( 'ifthenpay | Payments for LearnDash is missing its vendor/autoload.php file. Run "composer install" in the plugin folder, or reinstall the plugin.', 'ifthenpay-payments-for-learndash' ),
					array( 'type' => 'error' )
				);
			}
		},
		10,
		0
	);
	return;
}

require_once IFTP_LD_PATH . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \Ifthenpay\LearnDash\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Ifthenpay\LearnDash\Cron\DailyCron::class, 'unschedule' ) );

// LearnDash loads its classes on plugins_loaded priority 0 and then fires this; our folder sorts before
// sfwd-lms, so nothing that extends a LearnDash class may load before it.
add_action(
	'learndash_files_included',
	static function (): void {
		if ( class_exists( \Ifthenpay\LearnDash\Plugin::class ) ) {
			\Ifthenpay\LearnDash\Plugin::instance()->boot();
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( \Ifthenpay\LearnDash\Host::is_active() ) {
			return;
		}

		add_action(
			'admin_notices',
			static function (): void {
				if ( current_user_can( 'activate_plugins' ) ) {
					wp_admin_notice(
						esc_html__( 'ifthenpay | Payments for LearnDash needs LearnDash LMS to be installed and active.', 'ifthenpay-payments-for-learndash' ),
						array( 'type' => 'warning' )
					);
				}
			},
			10,
			0
		);
	},
	20
);
