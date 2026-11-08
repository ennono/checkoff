<?php
/**
 * The values of the checklists, one row per entry and round.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The table names come from $wpdb->prefix and cannot be placeholders; every
// value goes through prepare() or the wpdb helpers. Own tables, no object cache.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

/**
 * Table `{prefix}checkoff_values`: (checklist_id, round, entry_id) →
 * value (JSON, NULL = nothing entered), stage (NULL | 'doing', the board's
 * "in progress" mark), set_at (UTC, the last change).
 *
 * A tick writes exactly its row, so two people ticking at once never erase
 * each other's ticks. A row with neither value nor stage is deleted. The
 * round is the index of the round in force (0 for a list that does not
 * repeat): a new round simply has no rows yet, and the rows of earlier
 * rounds are the history ({@see Checkoff_History}).
 *
 * This class is the only place that reads or writes the table.
 */
class Checkoff_Values {

	const STAGE_DOING = 'doing';

	/**
	 * The table's name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'checkoff_values';
	}

	/**
	 * The rows of one round: entry ID => {v, s, t}.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @param int $round        Round index.
	 * @return array<string, array{v:mixed, s:string|null, t:int}>
	 */
	public static function rows( int $checklist_id, int $round ): array {

		global $wpdb;

		$result = $wpdb->get_results( $wpdb->prepare( 'SELECT entry_id, value, stage, set_at FROM ' . self::table() . ' WHERE checklist_id = %d AND round = %d', $checklist_id, $round ) );
		$rows   = array();

		foreach ( (array) $result as $row ) {
			$rows[ (string) $row->entry_id ] = array(
				'v' => null === $row->value ? null : json_decode( (string) $row->value, true ),
				's' => self::STAGE_DOING === $row->stage ? self::STAGE_DOING : null,
				't' => (int) strtotime( $row->set_at . ' UTC' ),
			);
		}

		return $rows;
	}

	/**
	 * Writes the value of an entry, keeping its stage.
	 *
	 * @param int      $checklist_id Checklist post ID.
	 * @param int      $round        Round index.
	 * @param string   $entry_id     Entry ID.
	 * @param mixed    $value        Value; null clears it.
	 * @param int|null $at           Unix time, now by default.
	 * @return void
	 */
	public static function set_value( int $checklist_id, int $round, string $entry_id, mixed $value, ?int $at = null ): void {
		$row = self::rows_of_entry( $checklist_id, $round, $entry_id );
		self::put( $checklist_id, $round, $entry_id, $value, $row['s'] ?? null, $at );
	}

	/**
	 * Writes the stage of an entry, keeping its value.
	 *
	 * @param int         $checklist_id Checklist post ID.
	 * @param int         $round        Round index.
	 * @param string      $entry_id     Entry ID.
	 * @param string|null $stage        'doing' or null.
	 * @param int|null    $at           Unix time, now by default.
	 * @return void
	 */
	public static function set_stage( int $checklist_id, int $round, string $entry_id, ?string $stage, ?int $at = null ): void {
		$row = self::rows_of_entry( $checklist_id, $round, $entry_id );
		self::put( $checklist_id, $round, $entry_id, $row['v'] ?? null, self::STAGE_DOING === $stage ? self::STAGE_DOING : null, $at );
	}

	/**
	 * Replaces the values of a round after a structure change: rows not in
	 * $values lose their value, changed values are written, their times
	 * kept; stages of entries that are gone go with them.
	 *
	 * @param int   $checklist_id Checklist post ID.
	 * @param int   $round        Round index.
	 * @param array $values       Entry ID => value, as they are to stay.
	 * @param array $entries      Entry IDs that exist (for the stages).
	 * @return void
	 */
	public static function revalidate( int $checklist_id, int $round, array $values, array $entries ): void {

		foreach ( self::rows( $checklist_id, $round ) as $entry_id => $row ) {

			$value = array_key_exists( $entry_id, $values ) ? $values[ $entry_id ] : null;
			$stage = in_array( $entry_id, $entries, true ) ? $row['s'] : null;

			if ( $value !== $row['v'] || $stage !== $row['s'] ) {
				self::put( $checklist_id, $round, (string) $entry_id, $value, $stage, $row['t'] );
			}
		}
	}

	/**
	 * Deletes the rows of a list: all, or of one round.
	 *
	 * @param int      $checklist_id Checklist post ID.
	 * @param int|null $round        Round index, null for all.
	 * @return void
	 */
	public static function clear( int $checklist_id, ?int $round = null ): void {

		global $wpdb;

		$where  = array( 'checklist_id' => $checklist_id );
		$format = array( '%d' );

		if ( null !== $round ) {
			$where['round'] = $round;
			$format[]       = '%d';
		}

		$wpdb->delete( self::table(), $where, $format );
	}

	/**
	 * Deletes the rows of the rounds before one.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @param int $round        Round index; earlier rounds go.
	 * @return int Rows deleted.
	 */
	public static function clear_before( int $checklist_id, int $round ): int {

		global $wpdb;

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE checklist_id = %d AND round < %d', $checklist_id, $round ) );
	}

	/**
	 * Deletes the rows of every round but one.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @param int $round        Round index to keep.
	 * @return void
	 */
	public static function clear_except( int $checklist_id, int $round ): void {

		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE checklist_id = %d AND round <> %d', $checklist_id, $round ) );
	}

	/**
	 * Moves the rows of one round to another (a changed repetition rule).
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @param int $from         Round index.
	 * @param int $to           Round index.
	 * @return void
	 */
	public static function move( int $checklist_id, int $from, int $to ): void {

		if ( $from === $to ) {
			return;
		}

		global $wpdb;

		self::clear( $checklist_id, $to );

		$wpdb->update(
			self::table(),
			array( 'round' => $to ),
			array(
				'checklist_id' => $checklist_id,
				'round'        => $from,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * The rounds of a list that have rows: round index => {entries, last}.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @return array<int, array{entries:int, last:int}>
	 */
	public static function rounds( int $checklist_id ): array {

		global $wpdb;

		$result = $wpdb->get_results( $wpdb->prepare( 'SELECT round, COUNT(*) AS entries, MAX(set_at) AS last FROM ' . self::table() . ' WHERE checklist_id = %d GROUP BY round ORDER BY round', $checklist_id ) );
		$rounds = array();

		foreach ( (array) $result as $row ) {
			$rounds[ (int) $row->round ] = array(
				'entries' => (int) $row->entries,
				'last'    => (int) strtotime( $row->last . ' UTC' ),
			);
		}

		return $rounds;
	}

	/**
	 * The lists that have rows in more than one round — the candidates for
	 * pruning the history.
	 *
	 * @return int[]
	 */
	public static function lists_with_history(): array {

		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT checklist_id FROM ' . self::table() . ' GROUP BY checklist_id HAVING COUNT(DISTINCT round) > 1' ) );
	}

	/**
	 * The row of one entry.
	 *
	 * @param int    $checklist_id Checklist post ID.
	 * @param int    $round        Round index.
	 * @param string $entry_id     Entry ID.
	 * @return array{v:mixed, s:string|null, t:int}|null
	 */
	private static function rows_of_entry( int $checklist_id, int $round, string $entry_id ): ?array {

		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT value, stage, set_at FROM ' . self::table() . ' WHERE checklist_id = %d AND round = %d AND entry_id = %s', $checklist_id, $round, $entry_id ) );

		return $row ? array(
			'v' => null === $row->value ? null : json_decode( (string) $row->value, true ),
			's' => self::STAGE_DOING === $row->stage ? self::STAGE_DOING : null,
			't' => (int) strtotime( $row->set_at . ' UTC' ),
		) : null;
	}

	/**
	 * Writes one row, or deletes it when it holds nothing.
	 *
	 * @param int         $checklist_id Checklist post ID.
	 * @param int         $round        Round index.
	 * @param string      $entry_id     Entry ID.
	 * @param mixed       $value        Value or null.
	 * @param string|null $stage        Stage or null.
	 * @param int|null    $at           Unix time, now by default.
	 * @return void
	 */
	private static function put( int $checklist_id, int $round, string $entry_id, mixed $value, ?string $stage, ?int $at ): void {

		global $wpdb;

		$key = array(
			'checklist_id' => $checklist_id,
			'round'        => $round,
			'entry_id'     => $entry_id,
		);

		if ( null === $value && null === $stage ) {
			$wpdb->delete( self::table(), $key, array( '%d', '%d', '%s' ) );
			return;
		}

		$wpdb->replace(
			self::table(),
			$key + array(
				'value'  => null === $value ? null : (string) wp_json_encode( $value ),
				'stage'  => $stage,
				'set_at' => gmdate( 'Y-m-d H:i:s', $at ?? time() ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
// phpcs:enable
