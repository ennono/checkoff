<?php
/**
 * CheckOff uninstall routine.
 *
 * Always removes the plugin's options (send log included), its transients
 * (rate limits), the scheduled events, every e-mail subscription and the
 * history of closed rounds. The checklists themselves are only deleted when the
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
	wp_clear_scheduled_hook( 'checkoff_mail_dispatch' );
	wp_clear_scheduled_hook( 'checkoff_history_prune' );

	// 2. Subscriptions — always: they are e-mail addresses, and without the
	// plugin nobody could unsubscribe any more.
	do {
		$sub_ids = get_posts(
			array(
				'post_type'        => 'cko_subscription',
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		foreach ( $sub_ids as $sub_id ) {
			wp_delete_post( (int) $sub_id, true );
		}
	} while ( ! empty( $sub_ids ) );

	// 3. Checklists (opt-in only).
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

	// 4. Options (send log included), transients and the history of closed
	// rounds — always: without the plugin nothing prunes it after its days.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_cko_h_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'checkoff_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_checkoff_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_checkoff_' ) . '%' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

checkoff_run_uninstall();
