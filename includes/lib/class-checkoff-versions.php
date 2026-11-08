<?php
/**
 * The structure of a checklist, one row per revision.
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
 * Table `{prefix}checkoff_versions`: (checklist_id, revision) → structure
 * (JSON), saved_at (UTC).
 *
 * The newest row is the list as it is. Saving inserts the next revision; the
 * primary key makes that the conflict check: two saves based on the same
 * revision cannot both insert revision + 1, so the second gets 409.
 * Older rows are what past rounds were ticked against — a round is drawn
 * with the version in force when it ended ({@see self::at()}). Versions no
 * kept round needs are pruned ({@see self::prune()}).
 */
class Checkoff_Versions {

	/**
	 * Per request: checklist ID => newest version.
	 *
	 * @var array<int, array|null>
	 */
	private static array $latest = array();

	/**
	 * The table's name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'checkoff_versions';
	}

	/**
	 * The newest version of a list, or null.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @return array{revision:int, structure:array, saved_at:int}|null
	 */
	public static function latest( int $checklist_id ): ?array {

		if ( array_key_exists( $checklist_id, self::$latest ) ) {
			return self::$latest[ $checklist_id ];
		}

		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT revision, structure, saved_at FROM ' . self::table() . ' WHERE checklist_id = %d ORDER BY revision DESC LIMIT 1', $checklist_id ) );

		self::$latest[ $checklist_id ] = $row ? self::from_row( $row ) : null;

		return self::$latest[ $checklist_id ];
	}

	/**
	 * The version in force at a moment: the newest saved at or before it —
	 * else (the list did not exist yet, which only a rule starting in the
	 * past can make look otherwise) the newest there is.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @param int $time         Unix time.
	 * @return array{revision:int, structure:array, saved_at:int}|null
	 */
	public static function at( int $checklist_id, int $time ): ?array {

		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT revision, structure, saved_at FROM {$table} WHERE checklist_id = %d AND saved_at <= %s ORDER BY revision DESC LIMIT 1", $checklist_id, gmdate( 'Y-m-d H:i:s', $time ) ) );

		return $row ? self::from_row( $row ) : self::latest( $checklist_id );
	}

	/**
	 * Inserts a revision. False when it exists already — somebody else saved
	 * first.
	 *
	 * @param int   $checklist_id Checklist post ID.
	 * @param int   $revision     Revision number.
	 * @param array $structure    Sanitized structure.
	 * @return bool
	 */
	public static function add( int $checklist_id, int $revision, array $structure ): bool {

		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'checklist_id' => $checklist_id,
				'revision'     => $revision,
				'structure'    => (string) wp_json_encode( $structure ),
				'saved_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		$wpdb->suppress_errors( $suppress );
		unset( self::$latest[ $checklist_id ] );

		return 1 === $inserted;
	}

	/**
	 * Deletes the versions older than the one in force at a moment; the
	 * newest always stays.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @param int $keep_from    Unix time from which versions are needed.
	 * @return void
	 */
	public static function prune( int $checklist_id, int $keep_from ): void {

		$needed = self::at( $checklist_id, $keep_from );
		$latest = self::latest( $checklist_id );

		if ( null === $needed || null === $latest ) {
			return;
		}

		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE checklist_id = %d AND revision < %d', $checklist_id, min( $needed['revision'], $latest['revision'] ) ) );
	}

	/**
	 * Deletes every version of a list.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @return void
	 */
	public static function clear( int $checklist_id ): void {

		global $wpdb;

		$wpdb->delete( self::table(), array( 'checklist_id' => $checklist_id ), array( '%d' ) );
		unset( self::$latest[ $checklist_id ] );
	}

	/**
	 * Forgets what this request read (tests, long-running jobs).
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$latest = array();
	}

	/**
	 * A row as an array.
	 *
	 * @param object $row Row.
	 * @return array{revision:int, structure:array, saved_at:int}
	 */
	private static function from_row( object $row ): array {

		$structure = json_decode( (string) $row->structure, true );

		return array(
			'revision'  => (int) $row->revision,
			'structure' => is_array( $structure ) ? $structure : array(),
			'saved_at'  => (int) strtotime( $row->saved_at . ' UTC' ),
		);
	}
}
// phpcs:enable
