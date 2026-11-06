<?php
/**
 * History of closed rounds.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the ticks and values of the closed rounds of repeating checklists,
 * for a limited time — off unless the site switches it on.
 *
 * Without it a closed round leaves only its summary (`_cko_last_round`);
 * with it, every round closed by {@see Checkoff_Checklist::rollover()}
 * in which anything was entered is stored as one meta row `_cko_h_<round number>`: the values as they
 * were shown, the structure at that moment (so a later renamed entry, a
 * reordered answer or a changed unit does not falsify the past), the
 * round's dates and its progress.
 *
 * Rules, all in this class:
 *
 * - **Off by default** (`checkoff_history_enabled`). The values may be
 *   personal — a weight, a medication tracker — and so far they vanished
 *   with every round. Switching it off deletes every stored round at once.
 * - **Limited** to `checkoff_history_days` (default 90) after a round was
 *   closed: expired rounds are skipped on reading and deleted by a daily
 *   cron (`checkoff_history_prune`), found through `_cko_h_oldest`.
 * - **Edit ID only.** The tick link is handed to others; the past is the
 *   owner's (`cko_history` / REST `rounds` refuse a tick view with 403).
 * - The owner can delete the history of a checklist at any time.
 */
class Checkoff_History {

	const META_PREFIX  = '_cko_h_';
	const META_OLDEST  = '_cko_h_oldest';
	const OPT_ENABLED  = 'checkoff_history_enabled';
	const OPT_DAYS     = 'checkoff_history_days';
	const DEFAULT_DAYS = 90;
	const MAX_DAYS     = 3650;
	const CRON_HOOK    = 'checkoff_history_prune';

	/**
	 * Hooks in.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune_all' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'update_option_' . self::OPT_ENABLED, array( __CLASS__, 'on_toggle' ), 10, 2 );
	}

	/**
	 * Whether closed rounds are kept.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'on' === (string) get_option( self::OPT_ENABLED, '' );
	}

	/**
	 * Days a closed round is kept.
	 *
	 * @return int
	 */
	public static function days(): int {
		$days = (int) get_option( self::OPT_DAYS, self::DEFAULT_DAYS );
		return $days > 0 ? min( self::MAX_DAYS, $days ) : self::DEFAULT_DAYS;
	}

	/**
	 * Schedules the daily pruning while the history is on, and removes it
	 * while it is off.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {

		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( self::is_enabled() && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( ! self::is_enabled() && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Switching the history off deletes what it kept.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function on_toggle( mixed $old_value, mixed $value ): void {
		if ( 'on' !== (string) $value ) {
			self::delete_all();
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------
	 */

	/**
	 * Keeps a round that is being closed. Called by rollover() before the
	 * values are emptied; does nothing while the history is off.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int                $index     Index (0-based) of the closed round.
	 * @param array              $values    Its values as shown.
	 * @param int                $closed    Unix time of closing.
	 * @return void
	 */
	public static function record( Checkoff_Checklist $checklist, int $index, array $values, int $closed ): void {

		// A round in which nothing was entered is not kept: rounds nobody
		// opened are never closed one by one either, so keeping the one that
		// happened to be closed would make some empty days visible and not
		// others.
		if ( ! self::is_enabled() || empty( $values ) ) {
			return;
		}

		$recurrence = $checklist->recurrence();
		$structure  = $checklist->get_structure();
		$progress   = Checkoff_Checklist::progress_of( $structure, $values );
		$start      = $recurrence->round_start( $index );
		$last_day   = $recurrence->round_start( $index + 1 )->modify( '-1 day' );

		update_post_meta(
			$checklist->get_post_id(),
			self::META_PREFIX . ( $index + 1 ),
			array(
				'number'    => $index + 1,
				'start'     => $start->format( 'Y-m-d' ),
				'end'       => $last_day->format( 'Y-m-d' ),
				'closed'    => $closed,
				'done'      => $progress['done'],
				'total'     => $progress['total'],
				'values'    => $values,
				'structure' => $structure,
			)
		);

		if ( '' === get_post_meta( $checklist->get_post_id(), self::META_OLDEST, true ) ) {
			update_post_meta( $checklist->get_post_id(), self::META_OLDEST, $closed );
		}
	}

	/**
	 * Deletes the history of one checklist.
	 *
	 * @param int $post_id Checklist post ID.
	 * @return void
	 */
	public static function clear( int $post_id ): void {

		foreach ( self::meta_keys( $post_id ) as $key ) {
			delete_post_meta( $post_id, $key );
		}

		delete_post_meta( $post_id, self::META_OLDEST );
	}

	/**
	 * Deletes expired rounds of one checklist.
	 *
	 * @param int      $post_id Checklist post ID.
	 * @param int|null $now     Unix time, now by default.
	 * @return int Rounds deleted.
	 */
	public static function prune( int $post_id, ?int $now = null ): int {

		$cutoff  = ( $now ?? time() ) - self::days() * DAY_IN_SECONDS;
		$deleted = 0;
		$oldest  = null;

		foreach ( self::meta_keys( $post_id ) as $key ) {

			$round  = get_post_meta( $post_id, $key, true );
			$closed = is_array( $round ) ? (int) ( $round['closed'] ?? 0 ) : 0;

			if ( $closed < $cutoff ) {
				delete_post_meta( $post_id, $key );
				++$deleted;
				continue;
			}

			$oldest = null === $oldest ? $closed : min( $oldest, $closed );
		}

		if ( null === $oldest ) {
			delete_post_meta( $post_id, self::META_OLDEST );
		} else {
			update_post_meta( $post_id, self::META_OLDEST, $oldest );
		}

		return $deleted;
	}

	/**
	 * Daily cron: prunes every checklist holding an expired round.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return int Rounds deleted.
	 */
	public static function prune_all( ?int $now = null ): int {

		$now = $now ?? time();
		$ids = get_posts(
			array(
				'post_type'        => Checkoff_Post_Types::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_OLDEST,
						'value'   => $now - self::days() * DAY_IN_SECONDS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$deleted = 0;

		foreach ( $ids as $id ) {
			$deleted += self::prune( (int) $id, $now );
		}

		return $deleted;
	}

	/**
	 * Deletes every stored round of every checklist.
	 *
	 * @return void
	 */
	public static function delete_all(): void {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bulk delete; the meta cache is flushed below.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::META_PREFIX ) . '%' ) );

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
		} else {
			wp_cache_flush();
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------
	 */

	/**
	 * The kept rounds of a checklist, newest first, ready for a client:
	 * every entry with its label, value, wording and state as they were.
	 *
	 * @param int      $post_id Checklist post ID.
	 * @param int|null $now     Unix time, now by default.
	 * @return array<int, array>
	 */
	public static function rounds( int $post_id, ?int $now = null ): array {

		$cutoff = ( $now ?? time() ) - self::days() * DAY_IN_SECONDS;
		$rounds = array();

		foreach ( self::meta_keys( $post_id ) as $key ) {

			$round = get_post_meta( $post_id, $key, true );

			if ( ! is_array( $round ) || (int) ( $round['closed'] ?? 0 ) < $cutoff ) {
				continue;
			}

			$rounds[] = self::to_client( $round, $now );
		}

		usort(
			$rounds,
			static function ( array $a, array $b ): int {
				return $b['number'] <=> $a['number'];
			}
		);

		return $rounds;
	}

	/**
	 * Number of kept rounds (expired ones included until the next pruning).
	 *
	 * @param int $post_id Checklist post ID.
	 * @return int
	 */
	public static function count( int $post_id ): int {
		return count( self::meta_keys( $post_id ) );
	}

	/**
	 * One stored round for a client.
	 *
	 * @param array    $round Stored round.
	 * @param int|null $now   Unix time, for the wording of due dates.
	 * @return array
	 */
	private static function to_client( array $round, ?int $now ): array {

		$values    = (array) ( $round['values'] ?? array() );
		$structure = Checkoff_Checklist::sanitize_structure( (array) ( $round['structure'] ?? array() ) );
		$sections  = array();

		$walk = static function ( array $block, string $path ) use ( &$walk, &$sections, $values, $now ): void {

			$type    = Checkoff_Block_Types::get( (string) $block['type'] );
			$title   = '' !== $block['title'] ? $block['title'] : '';
			$path    = '' === $path ? $title : ( '' === $title ? $path : $path . ' › ' . $title );
			$entries = array();

			foreach ( $block['items'] as $item ) {

				if ( 'block' === $item['kind'] ) {
					continue;
				}

				$value = $values[ $item['id'] ] ?? null;
				$part  = $type->progress( $value, $block['settings'] );

				$entries[] = array(
					'id'      => $item['id'],
					'label'   => $item['label'],
					'value'   => $value,
					'text'    => $type->describe_item( $item, $value, $block['settings'], $now ),
					'done'    => $part['total'] > 0 && $part['done'] >= $part['total'],
					'skipped' => $type->counts_progress() && 0 === $part['total'],
				);
			}

			if ( $entries ) {
				$sections[] = array(
					'title'   => $path,
					'type'    => $type->key(),
					'unit'    => (string) ( $block['settings']['unit'] ?? '' ),
					'entries' => $entries,
				);
			}

			foreach ( $block['items'] as $item ) {
				if ( 'block' === $item['kind'] ) {
					$walk( $item, $path );
				}
			}
		};

		foreach ( $structure['items'] as $block ) {
			$walk( $block, '' );
		}

		$total = (int) ( $round['total'] ?? 0 );
		$done  = (int) ( $round['done'] ?? 0 );

		return array(
			'number'   => (int) $round['number'],
			'start'    => (string) ( $round['start'] ?? '' ),
			'end'      => (string) ( $round['end'] ?? '' ),
			'closed'   => gmdate( 'c', (int) ( $round['closed'] ?? 0 ) ),
			'done'     => $done,
			'total'    => $total,
			'percent'  => $total > 0 ? (int) floor( $done / $total * 100 ) : 0,
			'sections' => $sections,
		);
	}

	/**
	 * Meta keys of the stored rounds of a checklist.
	 *
	 * @param int $post_id Checklist post ID.
	 * @return string[]
	 */
	private static function meta_keys( int $post_id ): array {

		$keys = array();

		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			if ( preg_match( '/^' . preg_quote( self::META_PREFIX, '/' ) . '\d+$/', (string) $key ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}
}
