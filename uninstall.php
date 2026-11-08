<?php
/**
 * CheckOff uninstall routine.
 *
 * Always removes the plugin's options (send log included), its transients
 * (rate limits), the scheduled events, every e-mail subscription and the
 * history of closed rounds (the tables, see step 4). The checklists themselves are only deleted when the
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

	// 4. The tables: gone with the checklists; otherwise only the history
	// goes — the newest round and the newest structure of every list stay —
	// since without the plugin nothing would prune it after its days.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$values   = $wpdb->prefix . 'checkoff_values';
	$versions = $wpdb->prefix . 'checkoff_versions';

	if ( $delete_checklists ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$values}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$versions}" );
	} else {
		foreach ( (array) $wpdb->get_results( "SELECT checklist_id, MAX(round) AS newest FROM {$values} GROUP BY checklist_id" ) as $row ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$values} WHERE checklist_id = %d AND round < %d", $row->checklist_id, $row->newest ) );
		}
		foreach ( (array) $wpdb->get_results( "SELECT checklist_id, MAX(revision) AS newest FROM {$versions} GROUP BY checklist_id" ) as $row ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$versions} WHERE checklist_id = %d AND revision < %d", $row->checklist_id, $row->newest ) );
		}
	}

	// 5. Options (send log included) and transients.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'checkoff_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_checkoff_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_checkoff_' ) . '%' ) );
	// phpcs:enable
}

checkoff_run_uninstall();
