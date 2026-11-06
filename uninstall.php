<?php
/**
 * CheckOff uninstall routine.
 *
 * Always removes the plugin's options, its transients (rate limits) and the
 * scheduled cleanup. The checklists themselves are only deleted when the
 * site owner opted in via "Delete checklists on uninstall" — they are the
 * visitors' data, and an accidental uninstall should not destroy them.
 *
 * @package CheckOff
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Performs the uninstall. Wrapped in a function so its variables stay local.
 *
 * @return void
 */
function checkoff_run_uninstall(): void {

	global $wpdb;

	$delete_checklists = ( 'on' === get_option( 'checkoff_delete_data_on_uninstall', '' ) );

	// 1. Scheduled events.
	wp_clear_scheduled_hook( 'checkoff_daily_cleanup' );

	// 2. Checklists (opt-in only).
	if ( $delete_checklists ) {
		do {
			$ids = get_posts(
				array(
					'post_type'        => 'cko_checklist',
					'post_status'      => 'any',
					'posts_per_page'   => 200,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);

			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} while ( ! empty( $ids ) );
	}

	// 3. Options and transients.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'checkoff_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_checkoff_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_checkoff_' ) . '%' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

checkoff_run_uninstall();
