<?php
/**
 * Plugin Name: CheckOff
 * Version: 1.6.0
 * Plugin URI:
 * Description: Let visitors build their own multi-level checklists from building blocks — simple lists, lists with due dates or "not needed", weekday lists, ratings, measurements, choices, counters and notes — and tick them off.
 * Author: Timo Fricke
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Tested up to: 7.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Text Domain: checkoff
 * Domain Path: /lang/
 *
 * @package CheckOff
 * @author  Timo Fricke
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load plugin class files.
require_once 'includes/class-checkoff.php';
require_once 'includes/class-checkoff-settings.php';

// Load plugin libraries.
require_once 'includes/lib/class-checkoff-admin-api.php';
require_once 'includes/lib/blocks/class-checkoff-block-type.php';
require_once 'includes/lib/blocks/class-checkoff-block-simple.php';
require_once 'includes/lib/blocks/class-checkoff-block-status.php';
require_once 'includes/lib/blocks/class-checkoff-block-deadline.php';
require_once 'includes/lib/blocks/class-checkoff-block-weekdays.php';
require_once 'includes/lib/blocks/class-checkoff-block-color-scale.php';
require_once 'includes/lib/blocks/class-checkoff-block-number-scale.php';
require_once 'includes/lib/blocks/class-checkoff-block-measurement.php';
require_once 'includes/lib/blocks/class-checkoff-block-choice.php';
require_once 'includes/lib/blocks/class-checkoff-block-counter.php';
require_once 'includes/lib/blocks/class-checkoff-block-note.php';
require_once 'includes/lib/class-checkoff-block-types.php';
require_once 'includes/lib/class-checkoff-public-id.php';
require_once 'includes/lib/class-checkoff-recurrence.php';
require_once 'includes/lib/class-checkoff-rate-limit.php';
require_once 'includes/lib/class-checkoff-checklist.php';
require_once 'includes/lib/class-checkoff-templates.php';
require_once 'includes/lib/class-checkoff-post-types.php';
require_once 'includes/lib/class-checkoff-post-meta.php';
require_once 'includes/lib/class-checkoff-shortcodes.php';
require_once 'includes/lib/class-checkoff-cleanup.php';
require_once 'includes/lib/class-checkoff-mail-log.php';
require_once 'includes/lib/class-checkoff-mailer.php';
require_once 'includes/lib/class-checkoff-subscriptions.php';
require_once 'includes/lib/class-checkoff-privacy.php';
require_once 'includes/lib/class-checkoff-rest-api.php';


/**
 * Writes a message to the PHP error log when WP_DEBUG and WP_DEBUG_LOG are both enabled.
 *
 * @param mixed $message String, array, or object to log.
 * @return void
 */
function checkoff_write_to_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true && defined( 'WP_DEBUG_LOG' )
		&& WP_DEBUG_LOG === true ) {
		if ( is_array( $message ) || is_object( $message ) ) {
			error_log( print_r( $message, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
		} else {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}


/**
 * Returns the main instance of CheckOff to prevent the need to use globals.
 *
 * @return Checkoff
 */
function checkoff() {

	// Must match the Version header above: this string is the cache buster on
	// every enqueued script and stylesheet.
	$instance = Checkoff::instance( __FILE__, '1.6.0' );

	if ( is_null( $instance->settings ) ) {
		$instance->settings = Checkoff_Settings::instance( $instance );
	}

	return $instance;
}

checkoff();
checkoff()->add_post_types();
checkoff()->add_post_meta();
checkoff()->add_shortcodes();
checkoff()->add_cleanup();
checkoff()->add_subscriptions();
checkoff()->add_privacy();
checkoff()->add_rest_api();
