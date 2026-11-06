<?php
/**
 * Scheduled cleanup.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes checklists nobody has opened for a configurable number of days.
 *
 * Visitors create checklists without an account, and a list whose ID has
 * been lost can never be found again. Without a cleanup those stay forever.
 * Off by default: deleting somebody's list is not a decision to make for the
 * site owner.
 */
class Checkoff_Cleanup {

	const CRON_HOOK   = 'checkoff_daily_cleanup';
	const BATCH_LIMIT = 200;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Days after which an unused checklist is deleted; 0 = never.
	 *
	 * @return int
	 */
	public static function inactive_days(): int {
		return max( 0, (int) get_option( 'checkoff_cleanup_inactive_days', 0 ) );
	}

	/**
	 * Keeps the cron event in step with the setting.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {

		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( self::inactive_days() > 0 && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( 0 === self::inactive_days() && $scheduled ) {
			self::unschedule();
		}
	}

	/**
	 * Removes the cron event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Deletes one batch of unused checklists.
	 *
	 * @return int Number deleted.
	 */
	public function run(): int {

		$days = self::inactive_days();

		if ( 0 === $days ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'        => Checkoff_Post_Types::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => self::BATCH_LIMIT,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					// Templates are the site's, not a visitor's: never cleaned up.
					array(
						'key'     => Checkoff_Templates::META_FLAG,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Checkoff_Checklist::META_LAST_ACCESS,
						'value'   => time() - $days * DAY_IN_SECONDS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}

		return count( $ids );
	}
}
