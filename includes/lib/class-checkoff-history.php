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
 * in which anything was entered becomes **one post of the type `cko_round`,
 * a child of its checklist** (`post_parent`, since 2.3.0; before, a meta row
 * `_cko_h_<number>` on the checklist, moved over once by
 * {@see self::maybe_migrate()}). Its date is when the round was closed; its
 * meta hold the number, the dates, the progress and — in `_cko_round_data` —
 * the values with the time each was set and the structure at closing, so a
 * later renamed entry, a reordered answer or a changed unit does not falsify
 * the past. The page pages through them round by round and shows each as
 * the list itself ({@see self::round_view()}); wp-admin lists them under
 * CheckOff → Rounds and shows each read-only.
 *
 * Rules, all in this class:
 *
 * - **Off by default** (`checkoff_history_enabled`). The values may be
 *   personal — a weight, a medication tracker. Switching it off deletes
 *   every stored round at once.
 * - **Limited** to `checkoff_history_days` (default 90) after a round was
 *   closed: expired rounds are skipped on reading and deleted by a daily
 *   cron (`checkoff_history_prune`), found by their post date.
 * - **Edit access only** on the website and the API.
 * - The owner can delete the history of a checklist at any time; deleting
 *   the checklist deletes its rounds, and a round moved to the trash is
 *   deleted for good.
 */
class Checkoff_History {

	const POST_TYPE     = 'cko_round';
	const META_NUMBER   = '_cko_round_number';
	const META_START    = '_cko_round_start';
	const META_END      = '_cko_round_end';
	const META_DONE     = '_cko_round_done';
	const META_TOTAL    = '_cko_round_total';
	const META_DATA     = '_cko_round_data';
	const LEGACY_PREFIX = '_cko_h_';
	const LEGACY_OLDEST = '_cko_h_oldest';
	const OPT_VERSION   = 'checkoff_rounds_version';
	const OPT_ENABLED   = 'checkoff_history_enabled';
	const OPT_DAYS      = 'checkoff_history_days';
	const DEFAULT_DAYS  = 90;
	const MAX_DAYS      = 3650;
	const CRON_HOOK     = 'checkoff_history_prune';

	/**
	 * Hooks in.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 20 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune_all' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'update_option_' . self::OPT_ENABLED, array( __CLASS__, 'on_toggle' ), 10, 2 );

		// A round does not outlive its checklist, nor a trash can.
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_checklist' ) );
		add_action( 'trashed_post', array( __CLASS__, 'delete_trashed' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'add_meta_boxes_' . Checkoff_Post_Types::POST_TYPE, array( __CLASS__, 'add_checklist_box' ) );
	}

	/**
	 * The post type of a kept round: not public, listed under CheckOff for
	 * administrators, never created by hand.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => _x( 'Rounds', 'Post type general name', 'checkoff' ),
					'singular_name' => _x( 'Round', 'Post type singular name', 'checkoff' ),
					'all_items'     => __( 'Rounds', 'checkoff' ),
					'edit_item'     => __( 'Round', 'checkoff' ),
					'search_items'  => __( 'Search rounds', 'checkoff' ),
					'not_found'     => __( 'No rounds kept.', 'checkoff' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Checkoff_Post_Types::POST_TYPE,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
				'capabilities'        => Checkoff_Post_Types::admin_capabilities(),
			)
		);
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
	 * @param array              $times     When each value was set (entry ID => unix time).
	 * @return void
	 */
	public static function record( Checkoff_Checklist $checklist, int $index, array $values, int $closed, array $times = array() ): void {

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

		self::store(
			$checklist->get_post_id(),
			array(
				'number'    => $index + 1,
				'start'     => $start->format( 'Y-m-d' ),
				'end'       => $last_day->format( 'Y-m-d' ),
				'closed'    => $closed,
				'done'      => $progress['done'],
				'total'     => $progress['total'],
				'values'    => $values,
				'times'     => array_map( 'intval', array_intersect_key( $times, $values ) ),
				'structure' => $structure,
			)
		);
	}

	/**
	 * Writes one round as a child post of its checklist — the one post of
	 * that number, replaced if it exists.
	 *
	 * @param int   $checklist_id Checklist post ID.
	 * @param array $round        {number, start, end, closed, done, total, values, times, structure}.
	 * @return int The round's post ID, 0 on failure.
	 */
	private static function store( int $checklist_id, array $round ): int {

		$number   = (int) $round['number'];
		$existing = self::round_id( $checklist_id, $number );
		$closed   = min( (int) $round['closed'], time() ); // A future date would make WordPress schedule the post.

		$postarr = array(
			'post_type'     => self::POST_TYPE,
			'post_status'   => 'publish',
			'post_parent'   => $checklist_id,
			'post_author'   => 0,
			/* translators: 1: checklist title, 2: round number. */
			'post_title'    => sprintf( __( '%1$s – round %2$s', 'checkoff' ), get_the_title( $checklist_id ), number_format_i18n( $number ) ),
			'post_date'     => wp_date( 'Y-m-d H:i:s', $closed ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $closed ),
			'meta_input'    => array(
				self::META_NUMBER => $number,
				self::META_START  => (string) $round['start'],
				self::META_END    => (string) $round['end'],
				self::META_DONE   => $round['done'],
				self::META_TOTAL  => $round['total'],
				self::META_DATA   => array(
					'values'    => (array) $round['values'],
					'times'     => (array) ( $round['times'] ?? array() ),
					'structure' => (array) $round['structure'],
				),
			),
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;
		}

		$id = wp_insert_post( wp_slash( $postarr ), true );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Deletes the history of one checklist.
	 *
	 * @param int $post_id Checklist post ID.
	 * @return void
	 */
	public static function clear( int $post_id ): void {
		foreach ( self::round_ids( $post_id ) as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Deletes expired rounds of one checklist.
	 *
	 * @param int      $post_id Checklist post ID.
	 * @param int|null $now     Unix time, now by default.
	 * @return int Rounds deleted.
	 */
	public static function prune( int $post_id, ?int $now = null ): int {
		return self::delete_expired( $now, $post_id );
	}

	/**
	 * Daily cron: deletes every expired round.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return int Rounds deleted.
	 */
	public static function prune_all( ?int $now = null ): int {
		return self::delete_expired( $now, 0 );
	}

	/**
	 * Deletes rounds closed before the retention, of one checklist or all.
	 *
	 * @param int|null $now          Unix time, now by default.
	 * @param int      $checklist_id Checklist post ID, 0 for all.
	 * @return int Rounds deleted.
	 */
	private static function delete_expired( ?int $now, int $checklist_id ): int {

		$cutoff  = gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - self::days() * DAY_IN_SECONDS );
		$deleted = 0;

		do {
			$args = array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'before' => $cutoff,
					),
				),
			);
			if ( $checklist_id > 0 ) {
				$args['post_parent'] = $checklist_id;
			}

			$ids = get_posts( $args );

			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
				++$deleted;
			}
		} while ( ! empty( $ids ) );

		return $deleted;
	}

	/**
	 * Deletes every stored round of every checklist.
	 *
	 * @return void
	 */
	public static function delete_all(): void {

		do {
			$ids = get_posts(
				array(
					'post_type'        => self::POST_TYPE,
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

	/**
	 * Deleting a checklist deletes its rounds.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function delete_for_checklist( int $post_id ): void {
		if ( Checkoff_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			self::clear( $post_id );
		}
	}

	/**
	 * A round moved to the trash is deleted for good: the retention would
	 * not reach it there.
	 *
	 * @param int $post_id Trashed post.
	 * @return void
	 */
	public static function delete_trashed( int $post_id ): void {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Moves rounds kept as meta rows before 2.3.0 (`_cko_h_<number>` on the
	 * checklist) into child posts, once.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {

		if ( '1' === (string) get_option( self::OPT_VERSION, '' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-off move.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::LEGACY_PREFIX ) . '%' ) );

		foreach ( (array) $rows as $row ) {

			$post_id = (int) $row->post_id;
			$key     = (string) $row->meta_key;

			if ( preg_match( '/^' . preg_quote( self::LEGACY_PREFIX, '/' ) . '\d+$/', $key ) ) {
				$round = maybe_unserialize( $row->meta_value );
				if ( is_array( $round ) && isset( $round['number'] ) && Checkoff_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
					self::store( $post_id, $round + array( 'times' => array() ) );
				}
			}

			delete_post_meta( $post_id, $key );
		}

		update_option( self::OPT_VERSION, '1', false );
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

		$rounds = array();

		foreach ( self::kept( $post_id, $now ) as $round ) {
			$rounds[] = self::to_client( $round, $now );
		}

		return array_reverse( $rounds );
	}

	/**
	 * The kept rounds to page through, oldest first: number and dates.
	 *
	 * @param int      $post_id Checklist post ID.
	 * @param int|null $now     Unix time, now by default.
	 * @return array<int, array{number:int, start:string, end:string}>
	 */
	public static function index( int $post_id, ?int $now = null ): array {

		return array_map(
			static fn( array $round ): array => array(
				'number' => (int) $round['number'],
				'start'  => self::date_text( (string) $round['start'] ),
				'end'    => self::date_text( (string) $round['end'] ),
			),
			self::kept( $post_id, $now )
		);
	}

	/**
	 * One kept round as it was, for showing it as the list itself: the
	 * structure at closing, the values and when each was set.
	 *
	 * @param int      $post_id Checklist post ID.
	 * @param int      $number  Round number (1-based).
	 * @param int|null $now     Unix time, now by default.
	 * @return array|null Null when it is not kept (or expired).
	 */
	public static function round_view( int $post_id, int $number, ?int $now = null ): ?array {

		$id    = self::round_id( $post_id, $number );
		$round = $id ? self::load( $id ) : null;

		if ( null === $round || $round['closed'] < ( $now ?? time() ) - self::days() * DAY_IN_SECONDS ) {
			return null;
		}

		$structure = Checkoff_Checklist::sanitize_structure( $round['structure'] );

		return array(
			'number'    => $round['number'],
			'start'     => self::date_text( $round['start'] ),
			'end'       => self::date_text( $round['end'] ),
			'closed'    => gmdate( 'c', $round['closed'] ),
			'structure' => $structure,
			'values'    => (object) $round['values'],
			'times'     => (object) array_map( 'intval', $round['times'] ),
			'progress'  => Checkoff_Checklist::progress_of( $structure, $round['values'] ),
		);
	}

	/**
	 * Number of kept rounds (expired ones included until the next pruning).
	 *
	 * @param int $post_id Checklist post ID.
	 * @return int
	 */
	public static function count( int $post_id ): int {
		return count( self::round_ids( $post_id ) );
	}

	/**
	 * The unexpired rounds of a checklist, oldest first.
	 *
	 * @param int      $post_id Checklist post ID.
	 * @param int|null $now     Unix time, now by default.
	 * @return array<int, array>
	 */
	private static function kept( int $post_id, ?int $now ): array {

		$cutoff = ( $now ?? time() ) - self::days() * DAY_IN_SECONDS;
		$rounds = array();

		foreach ( self::round_ids( $post_id ) as $id ) {
			$round = self::load( $id );
			if ( null !== $round && $round['closed'] >= $cutoff ) {
				$rounds[] = $round;
			}
		}

		usort( $rounds, static fn( array $a, array $b ): int => $a['number'] <=> $b['number'] );

		return $rounds;
	}

	/**
	 * A round post as {number, start, end, closed, done, total, values, times, structure}.
	 *
	 * @param int $round_id Round post ID.
	 * @return array|null
	 */
	public static function load( int $round_id ): ?array {

		$post = get_post( $round_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$data = get_post_meta( $round_id, self::META_DATA, true );
		$data = is_array( $data ) ? $data : array();

		return array(
			'number'    => (int) get_post_meta( $round_id, self::META_NUMBER, true ),
			'start'     => (string) get_post_meta( $round_id, self::META_START, true ),
			'end'       => (string) get_post_meta( $round_id, self::META_END, true ),
			'closed'    => (int) strtotime( $post->post_date_gmt . ' UTC' ),
			'done'      => (int) get_post_meta( $round_id, self::META_DONE, true ),
			'total'     => (int) get_post_meta( $round_id, self::META_TOTAL, true ),
			'values'    => (array) ( $data['values'] ?? array() ),
			'times'     => (array) ( $data['times'] ?? array() ),
			'structure' => (array) ( $data['structure'] ?? array() ),
		);
	}

	/**
	 * The post IDs of a checklist's rounds.
	 *
	 * @param int $post_id Checklist post ID.
	 * @return int[]
	 */
	public static function round_ids( int $post_id ): array {

		if ( $post_id <= 0 ) {
			return array();
		}

		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'any',
					'post_parent'      => $post_id,
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			)
		);
	}

	/**
	 * The post ID of one round of a checklist, or 0.
	 *
	 * @param int $post_id Checklist post ID.
	 * @param int $number  Round number.
	 * @return int
	 */
	private static function round_id( int $post_id, int $number ): int {

		if ( $post_id <= 0 || $number <= 0 ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'post_parent'      => $post_id,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- among one checklist's rounds.
				'meta_query'       => array(
					array(
						'key'   => self::META_NUMBER,
						'value' => $number,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * A stored Y-m-d in the site's date format.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function date_text( string $ymd ): string {

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );

		return $date ? Checkoff_Recurrence::format_date( $date->getTimestamp() + DAY_IN_SECONDS / 2 ) : $ymd;
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

			foreach ( Checkoff_Checklist::children_in_force( $block, $values ) as $item ) {
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

	/*
	 * ---------------------------------------------------------------------
	 * wp-admin
	 * ------------------------------------------------------------------
	 */

	/**
	 * Columns of the rounds list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function admin_columns( array $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '',
			'title'         => __( 'Round', 'checkoff' ),
			'cko_checklist' => __( 'Checklist', 'checkoff' ),
			'cko_period'    => __( 'Period', 'checkoff' ),
			'cko_done'      => _x( 'Done', 'rounds column', 'checkoff' ),
			'date'          => __( 'Closed', 'checkoff' ),
		);
	}

	/**
	 * Content of the rounds list's columns.
	 *
	 * @param string $column   Column.
	 * @param int    $round_id Round post ID.
	 * @return void
	 */
	public static function admin_column_content( string $column, int $round_id ): void {

		$round = self::load( $round_id );

		if ( null === $round ) {
			return;
		}

		switch ( $column ) {
			case 'cko_checklist':
				$parent = (int) wp_get_post_parent_id( $round_id );
				if ( $parent ) {
					echo '<a href="' . esc_url( (string) get_edit_post_link( $parent ) ) . '">' . esc_html( get_the_title( $parent ) ) . '</a>';
				}
				break;
			case 'cko_period':
				$start = self::date_text( $round['start'] );
				$end   = self::date_text( $round['end'] );
				echo esc_html( $start === $end ? $start : $start . ' – ' . $end );
				break;
			case 'cko_done':
				echo esc_html(
					sprintf(
						/* translators: 1: entries done, 2: entries in all. */
						__( '%1$s of %2$s', 'checkoff' ),
						number_format_i18n( $round['done'] ),
						number_format_i18n( $round['total'] )
					)
				);
				break;
		}
	}

	/**
	 * Rows of the rounds list: view and delete, nothing to edit.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {

		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$keep = array();

		if ( isset( $actions['edit'] ) ) {
			$keep['edit'] = '<a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'View', 'checkoff' ) . '</a>';
		}

		if ( isset( $actions['trash'] ) ) {
			$keep['trash'] = str_replace( '>' . __( 'Trash' ) . '<', '>' . esc_html__( 'Delete', 'checkoff' ) . '<', $actions['trash'] ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core string.
		}

		return $keep;
	}

	/**
	 * The screen of one round: read only — its entries as they were, no
	 * title field to change and no publish box.
	 *
	 * @return void
	 */
	public static function add_meta_boxes(): void {

		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );
		remove_post_type_support( self::POST_TYPE, 'title' );

		add_meta_box(
			'checkoff-round',
			__( 'Round', 'checkoff' ),
			array( __CLASS__, 'render_round_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * One round, read only.
	 *
	 * @param WP_Post $post Round post.
	 * @return void
	 */
	public static function render_round_box( WP_Post $post ): void {

		$round = self::load( (int) $post->ID );

		if ( null === $round ) {
			return;
		}

		$parent = (int) $post->post_parent;
		$start  = self::date_text( $round['start'] );
		$end    = self::date_text( $round['end'] );

		echo '<h2 style="padding:0;margin:0 0 .5em;font-size:1.3em">' . esc_html( get_the_title( $post ) ) . '</h2>';
		echo '<p>' . esc_html( $start === $end ? $start : $start . ' – ' . $end );
		echo ' · ' . esc_html(
			sprintf(
				/* translators: 1: entries done, 2: entries in all. */
				__( '%1$s of %2$s done', 'checkoff' ),
				number_format_i18n( $round['done'] ),
				number_format_i18n( $round['total'] )
			)
		);
		if ( $parent ) {
			echo ' · <a href="' . esc_url( (string) get_edit_post_link( $parent ) ) . '">' . esc_html__( 'To the checklist', 'checkoff' ) . '</a>';
		}
		echo '</p>';

		echo '<div class="cko-admin-outline">';
		echo Checkoff_Post_Meta::outline_html( Checkoff_Checklist::sanitize_structure( $round['structure'] ), $round['values'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '</div>';
	}

	/**
	 * On a checklist's screen: its kept rounds, linked.
	 *
	 * @param WP_Post $post Checklist post.
	 * @return void
	 */
	public static function add_checklist_box( WP_Post $post ): void {

		if ( ! self::count( (int) $post->ID ) ) {
			return;
		}

		add_meta_box(
			'checkoff-checklist-rounds',
			__( 'Kept rounds', 'checkoff' ),
			array( __CLASS__, 'render_checklist_box' ),
			Checkoff_Post_Types::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * The list of a checklist's kept rounds, newest first.
	 *
	 * @param WP_Post $post Checklist post.
	 * @return void
	 */
	public static function render_checklist_box( WP_Post $post ): void {

		$rounds = array_reverse( self::kept( (int) $post->ID, null ) );

		echo '<ul style="margin:0">';
		foreach ( $rounds as $round ) {
			$id    = self::round_id( (int) $post->ID, $round['number'] );
			$start = self::date_text( $round['start'] );
			echo '<li><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">'
				. esc_html(
					sprintf(
						/* translators: %s: round number. */
						__( 'Round %s', 'checkoff' ),
						number_format_i18n( $round['number'] )
					)
				)
				. '</a> · ' . esc_html( $start ) . ' · ' . esc_html( number_format_i18n( $round['done'] ) . '/' . number_format_i18n( $round['total'] ) ) . '</li>';
		}
		echo '</ul>';
	}
}
