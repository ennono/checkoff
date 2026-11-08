<?php
/**
 * The plugin's own tables and their version.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates CheckOff's tables (`dbDelta()`), and deletes what
 * belongs to a checklist when the checklist is deleted.
 *
 * The data model of 3.0.0:
 *
 * - `cko_checklist` posts: the list — its public ID, access level, password
 *   hash, directory mark, last access, template marks (post meta).
 * - `{prefix}checkoff_versions`: the structure of a list, one row per saved
 *   revision ({@see Checkoff_Versions}). The newest is the list as it is;
 *   older ones are what past rounds were ticked against.
 * - `{prefix}checkoff_values`: one row per entry and round
 *   ({@see Checkoff_Values}): the value, the board's stage, when it was set.
 *   Past rounds are these rows of an earlier round number — there is no
 *   copy and no post per round ({@see Checkoff_History}).
 * - `cko_subscription` posts: the e-mail subscriptions.
 *
 * The option `checkoff_schema` holds the version the tables have; a plugin
 * update with a newer one runs {@see self::install()} on the next request.
 * Data from before 3.0.0 is not carried over: the update removes it
 * ({@see self::remove_before_3()}), keeping only each list's structure.
 */
class Checkoff_Schema {

	const VERSION = 3;
	const OPTION  = 'checkoff_schema';

	/**
	 * Hooks in.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_checklist' ) );
	}

	/**
	 * Creates or updates the tables when the stored version is older.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {

		$stored = (int) get_option( self::OPTION, 0 );

		if ( self::VERSION === $stored ) {
			return;
		}

		self::install();

		if ( $stored < 3 ) {
			self::remove_before_3();
		}

		update_option( self::OPTION, self::VERSION, true );
	}

	/**
	 * Creates the tables, or brings them up to date.
	 *
	 * @return void
	 */
	public static function install(): void {

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$values  = Checkoff_Values::table();
		$history = Checkoff_Versions::table();

		// dbDelta wants two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$values} (
  checklist_id bigint(20) unsigned NOT NULL,
  round int(10) unsigned NOT NULL DEFAULT 0,
  entry_id varchar(16) NOT NULL,
  value longtext NULL,
  stage varchar(8) NULL,
  set_at datetime NOT NULL,
  PRIMARY KEY  (checklist_id,round,entry_id),
  KEY set_at (set_at)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$history} (
  checklist_id bigint(20) unsigned NOT NULL,
  revision int(10) unsigned NOT NULL,
  structure longtext NOT NULL,
  saved_at datetime NOT NULL,
  PRIMARY KEY  (checklist_id,revision)
) {$charset};"
		);
	}

	/**
	 * Removes what the versions before 3.0.0 stored. Each list keeps its
	 * structure (as revision 1) and, where it had a password, the level
	 * "protected"; values, rounds, tick IDs and the rest go.
	 *
	 * @return void
	 */
	private static function remove_before_3(): void {

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cko_structure' ) );

		foreach ( (array) $rows as $row ) {
			$structure = maybe_unserialize( $row->meta_value );
			if ( is_array( $structure ) && null === Checkoff_Versions::latest( (int) $row->post_id ) ) {
				Checkoff_Versions::add( (int) $row->post_id, 1, Checkoff_Checklist::sanitize_structure( $structure ) );
			}
		}

		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Checkoff_Checklist::META_PASSWORD ) ) as $post_id ) {
			$private = get_post_meta( (int) $post_id, '_cko_private', true );
			update_post_meta( (int) $post_id, Checkoff_Checklist::META_ACCESS, $private ? Checkoff_Checklist::LEVEL_PRIVATE : Checkoff_Checklist::LEVEL_PROTECTED );
		}

		foreach ( array( '_cko_v_', '_cko_h_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}

		foreach ( array( '_cko_structure', '_cko_revision', '_cko_values', '_cko_values_round', '_cko_last_round', '_cko_tick_id', '_cko_private', '_cko_sub_tick_only' ) as $key ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $key ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		foreach ( get_posts(
			array(
				'post_type'        => 'cko_round',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		) as $round_id ) {
			wp_delete_post( (int) $round_id, true );
		}

		delete_option( 'checkoff_rounds_version' );
		wp_cache_flush();
	}

	/**
	 * Deleting a checklist deletes its rows in both tables.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function delete_for_checklist( int $post_id ): void {
		if ( Checkoff_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			Checkoff_Values::clear( $post_id );
			Checkoff_Versions::clear( $post_id );
		}
	}

	/**
	 * Drops both tables (uninstall).
	 *
	 * @return void
	 */
	public static function drop(): void {

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own tables.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Checkoff_Values::table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Checkoff_Versions::table() );
		// phpcs:enable

		delete_option( self::OPTION );
	}
}
