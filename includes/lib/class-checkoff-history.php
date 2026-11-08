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
 * The past rounds of repeating checklists — virtual: a past round is the
 * rows of an earlier round number in `checkoff_values`, drawn with the
 * structure in force when it ended (`checkoff_versions`, the version saved
 * last before its end). Nothing is copied and no post is made per round;
 * number, dates and progress are computed.
 *
 * Rules, all in this class:
 *
 * - **Off by default** (`checkoff_history_enabled`): only the round in force
 *   and the one before it are kept (the latter for "last time" in mails and
 *   on the page). Switching the history off deletes every older round.
 * - **On:** past rounds are kept for `checkoff_history_days` (default 90)
 *   after they ended; the daily cron (`checkoff_history_prune`) deletes
 *   older ones and the versions no kept round needs.
 * - **Edit access only** on the website and the API.
 * - The owner can delete the history of a checklist at any time.
 */
class Checkoff_History {

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
		add_action( 'add_meta_boxes_' . Checkoff_Post_Types::POST_TYPE, array( __CLASS__, 'add_checklist_box' ) );
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
	 * Schedules the daily pruning — always: without a history it removes
	 * the rounds before the last one of lists nobody opened.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
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
	 * Keeping and deleting
	 * ------------------------------------------------------------------
	 */

	/**
	 * After a write: without a history, rounds before the last one go.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return void
	 */
	public static function keep_to_retention( Checkoff_Checklist $checklist ): void {
		if ( ! self::is_enabled() && $checklist->recurrence()->repeats() ) {
			Checkoff_Values::clear_before( $checklist->get_post_id(), $checklist->round_index() - 1 );
		}
	}

	/**
	 * Deletes the history of one checklist: every round but the one in force.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return void
	 */
	public static function clear( Checkoff_Checklist $checklist ): void {
		Checkoff_Values::clear_except( $checklist->get_post_id(), $checklist->round_index() );
		Checkoff_Versions::prune( $checklist->get_post_id(), time() + 1 );
	}

	/**
	 * Deletes what one checklist keeps beyond the rules.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int|null           $now       Unix time, now by default.
	 * @return int Rounds deleted.
	 */
	public static function prune( Checkoff_Checklist $checklist, ?int $now = null ): int {

		$now        = $now ?? time();
		$id         = $checklist->get_post_id();
		$recurrence = $checklist->recurrence();

		if ( ! $recurrence->repeats() ) {
			return 0;
		}

		$index   = $checklist->round_index( $now );
		$cutoff  = $now - self::days() * DAY_IN_SECONDS;
		$deleted = 0;
		$oldest  = null;

		foreach ( array_keys( Checkoff_Values::rounds( $id ) ) as $round ) {

			if ( $round >= $index - 1 ) {
				$oldest = null === $oldest ? $round : min( $oldest, $round );
				continue; // The round in force and "last time" always stay.
			}

			$ended = $recurrence->round_start( $round + 1 )->getTimestamp();

			if ( ! self::is_enabled() || $ended < $cutoff ) {
				Checkoff_Values::clear( $id, $round );
				++$deleted;
			} else {
				$oldest = null === $oldest ? $round : min( $oldest, $round );
			}
		}

		// Versions older than the oldest kept round needs are of no use.
		Checkoff_Versions::prune( $id, null === $oldest ? $now : $recurrence->round_start( $oldest + 1 )->getTimestamp() );

		return $deleted;
	}

	/**
	 * Daily cron: prunes every checklist with more than one round.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return int Rounds deleted.
	 */
	public static function prune_all( ?int $now = null ): int {

		$deleted = 0;

		foreach ( Checkoff_Values::lists_with_history() as $id ) {
			if ( Checkoff_Post_Types::POST_TYPE === get_post_type( $id ) ) {
				$deleted += self::prune( new Checkoff_Checklist( $id ), $now );
			}
		}

		return $deleted;
	}

	/**
	 * Deletes every round before the last one of every checklist (the
	 * history switched off).
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		foreach ( Checkoff_Values::lists_with_history() as $id ) {
			if ( Checkoff_Post_Types::POST_TYPE === get_post_type( $id ) ) {
				$checklist = new Checkoff_Checklist( $id );
				Checkoff_Values::clear_before( $id, $checklist->round_index() - 1 );
				Checkoff_Versions::prune( $id, time() + 1 );
			}
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------
	 */

	/**
	 * The kept past rounds to page through, oldest first: number and dates.
	 * Empty while the history is off.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int|null           $now       Unix time, now by default.
	 * @return array<int, array{number:int, start:string, end:string}>
	 */
	public static function index( Checkoff_Checklist $checklist, ?int $now = null ): array {

		$index = array();

		foreach ( self::kept( $checklist, $now ) as $round ) {
			$index[] = array(
				'number' => $round['number'],
				'start'  => Checkoff_Recurrence::format_date( $round['start'] ),
				'end'    => Checkoff_Recurrence::format_date( $round['end'] ),
			);
		}

		return $index;
	}

	/**
	 * One kept round as it was, for showing it as the list itself: the
	 * structure in force when it ended, the values and when each was set.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int                $number    Round number (1-based).
	 * @param int|null           $now       Unix time, now by default.
	 * @return array|null Null when it is not kept.
	 */
	public static function round_view( Checkoff_Checklist $checklist, int $number, ?int $now = null ): ?array {

		foreach ( self::kept( $checklist, $now ) as $round ) {

			if ( $round['number'] !== $number ) {
				continue;
			}

			$data = self::load( $checklist, $round );

			return array(
				'number'    => $number,
				'start'     => Checkoff_Recurrence::format_date( $round['start'] ),
				'end'       => Checkoff_Recurrence::format_date( $round['end'] ),
				'closed'    => gmdate( 'c', $round['closed'] ),
				'structure' => $data['structure'],
				'values'    => (object) $data['values'],
				'stages'    => (object) $data['stages'],
				'times'     => (object) $data['times'],
				'progress'  => Checkoff_Checklist::progress_of( $data['structure'], $data['values'] ),
			);
		}

		return null;
	}

	/**
	 * The kept rounds, newest first, worded: every entry with its label,
	 * value, wording and state as they were (REST `GET …/rounds`).
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int|null           $now       Unix time, now by default.
	 * @return array<int, array>
	 */
	public static function rounds( Checkoff_Checklist $checklist, ?int $now = null ): array {

		$rounds = array();

		foreach ( array_reverse( self::kept( $checklist, $now ) ) as $round ) {
			$data     = self::load( $checklist, $round );
			$progress = Checkoff_Checklist::progress_of( $data['structure'], $data['values'] );
			$rounds[] = self::to_client(
				array(
					'number'    => $round['number'],
					'start'     => wp_date( 'Y-m-d', $round['start'] ),
					'end'       => wp_date( 'Y-m-d', $round['end'] ),
					'closed'    => $round['closed'],
					'done'      => $progress['done'],
					'total'     => $progress['total'],
					'values'    => $data['values'],
					'structure' => $data['structure'],
				),
				$now
			);
		}

		return $rounds;
	}

	/**
	 * The past rounds of a list that have rows and are within the history:
	 * {index, number, start, end (last day, unix), closed (unix)}, oldest first.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int|null           $now       Unix time, now by default.
	 * @return array<int, array{index:int, number:int, start:int, end:int, closed:int}>
	 */
	private static function kept( Checkoff_Checklist $checklist, ?int $now ): array {

		$recurrence = $checklist->recurrence();

		if ( ! self::is_enabled() || ! $recurrence->repeats() ) {
			return array();
		}

		$now     = $now ?? time();
		$current = $checklist->round_index( $now );
		$cutoff  = $now - self::days() * DAY_IN_SECONDS;
		$kept    = array();

		foreach ( array_keys( Checkoff_Values::rounds( $checklist->get_post_id() ) ) as $round ) {

			if ( $round >= $current ) {
				continue;
			}

			$closed = $recurrence->round_start( $round + 1 )->getTimestamp();

			if ( $closed < $cutoff ) {
				continue;
			}

			$kept[] = array(
				'index'  => $round,
				'number' => $round + 1,
				'start'  => $recurrence->round_start( $round )->getTimestamp(),
				'end'    => $closed - DAY_IN_SECONDS / 2,
				'closed' => $closed,
			);
		}

		return $kept;
	}

	/**
	 * The rows of a round with the structure in force when it ended.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param array              $round     From kept().
	 * @return array{structure:array, values:array, stages:array, times:array}
	 */
	private static function load( Checkoff_Checklist $checklist, array $round ): array {

		$version   = Checkoff_Versions::at( $checklist->get_post_id(), $round['closed'] );
		$structure = Checkoff_Checklist::sanitize_structure( null !== $version ? $version['structure'] : $checklist->get_structure() );
		$values    = array();
		$stages    = array();
		$times     = array();

		foreach ( Checkoff_Values::rows( $checklist->get_post_id(), $round['index'] ) as $id => $row ) {
			if ( null !== $row['v'] ) {
				$values[ $id ] = $row['v'];
			}
			if ( null !== $row['s'] ) {
				$stages[ $id ] = $row['s'];
			}
			$times[ $id ] = $row['t'];
		}

		return array(
			'structure' => $structure,
			'values'    => $values,
			'stages'    => $stages,
			'times'     => $times,
		);
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

	/*
	 * ---------------------------------------------------------------------
	 * wp-admin
	 * ------------------------------------------------------------------
	 */

	/**
	 * On a checklist's screen: its kept rounds.
	 *
	 * @param WP_Post $post Checklist post.
	 * @return void
	 */
	public static function add_checklist_box( WP_Post $post ): void {

		if ( ! self::index( new Checkoff_Checklist( (int) $post->ID ) ) ) {
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

		$checklist = new Checkoff_Checklist( (int) $post->ID );

		echo '<ul style="margin:0">';
		foreach ( array_reverse( self::kept( $checklist, null ) ) as $round ) {
			$data     = self::load( $checklist, $round );
			$progress = Checkoff_Checklist::progress_of( $data['structure'], $data['values'] );
			echo '<li>' . esc_html(
				sprintf(
					/* translators: %s: round number. */
					__( 'Round %s', 'checkoff' ),
					number_format_i18n( $round['number'] )
				)
			) . ' · ' . esc_html( Checkoff_Recurrence::format_date( $round['start'] ) ) . ' · ' . esc_html( number_format_i18n( $progress['done'] ) . '/' . number_format_i18n( $progress['total'] ) ) . '</li>';
		}
		echo '</ul>';
	}
}
