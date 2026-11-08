<?php
/**
 * Checklist model.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One checklist: its structure (what is on it) and its values (what has been
 * ticked, rated, counted or written) — the one class the rest of the plugin
 * talks to about a list.
 *
 * Storage (3.0.0, {@see Checkoff_Schema}): the list is a `cko_checklist`
 * post with its ID, access level, password hash and directory mark as post
 * meta; the structure is a row per revision in `checkoff_versions`
 * ({@see Checkoff_Versions}); the values are a row per entry and round in
 * `checkoff_values` ({@see Checkoff_Values}). Kept apart on purpose:
 * ticking is frequent and touches one entry, editing is rare and replaces
 * the whole tree. A tick never has to send — or can overwrite — the
 * structure, and an edit names the revision it is based on, so two open
 * tabs cannot silently overwrite each other's changes.
 *
 * Structure: a container
 *   { id, kind: "checklist", title, description, recurrence, view?, stages?, items: [] }
 * whose items are building blocks of any type, stacked one after the other
 * and shown as sections. Every block is
 *   { id, kind: "block", type, title, description, settings, items: [] }
 * and every entry of a block's `items` is either a leaf
 *   { id, kind: "item", label, data? }
 * or — where the block type supports children — another block (nested).
 *
 * Values: per entry of the round in force, validated by the block type the
 * entry belongs to. Rounds are
 * computed from the repetition rule and the clock ({@see self::round_index()}):
 * a new round has no rows yet, so it starts empty without anything being
 * written.
 */
class Checkoff_Checklist {

	const META_PUBLIC_ID   = '_cko_public_id';
	const META_ACCESS      = '_cko_access';   // open | protected | private.
	const META_PASSWORD    = '_cko_password'; // Hash; only with protected or private.
	const META_LISTED      = '_cko_listed';   // '1': shown in the public directory (protected only).
	const META_LAST_ACCESS = '_cko_last_access';

	/**
	 * How many open entries the summary of a finished round keeps by name.
	 */
	const LAST_ROUND_OPEN_MAX = 30;

	/**
	 * What a visitor may do with the list as opened. The address gives what
	 * the list's level allows ({@see self::get_level()}); the password gives
	 * the rest.
	 */
	const ACCESS_EDIT   = 'edit';
	const ACCESS_LOCKED = 'locked'; // A protected list without its password: view and tick.
	const ACCESS_SEALED = 'sealed'; // A private list without its password: nothing but the password prompt.

	/**
	 * How far the address alone opens the list.
	 *
	 * - open:      view, tick and edit (no password)
	 * - protected: view and tick; editing needs the password
	 * - private:   everything needs the password
	 */
	const LEVEL_OPEN      = 'open';
	const LEVEL_PROTECTED = 'protected';
	const LEVEL_PRIVATE   = 'private';

	/**
	 * The board (2.5.0): a checklist may be shown as a Kanban board, its
	 * sections as lanes and three columns — open, in progress, done. Where a
	 * card stands follows from its value (`stage_of()`); an entry with
	 * nothing done yet can be marked "in progress" by a value of its own,
	 * stored as the stage of its value row ({@see Checkoff_Values}).
	 */
	const VIEW_LIST     = 'list';
	const VIEW_BOARD    = 'board';
	const STAGE_OPEN    = 'open';
	const STAGE_DOING   = 'doing';
	const STAGE_DONE    = 'done';
	const MAX_STAGE_LEN = 40;

	/**
	 * How far back a late tick's own time is believed (offline browsers).
	 */
	const LATE_TICK_MAX = 7 * DAY_IN_SECONDS;

	const PASSWORD_MIN = 6;
	const PASSWORD_MAX = 200;

	/**
	 * Kind of the top level of a structure.
	 */
	const KIND_CHECKLIST = 'checklist';

	/**
	 * Query parameter carrying the public ID, e.g. /checklist/?cko=k7f3-q9xm-2ab4.
	 */
	const QUERY_VAR = 'cko';

	/**
	 * Defaults and hard limits of the size settings.
	 */
	const DEFAULT_MAX_DEPTH = 5;
	const LIMIT_MAX_DEPTH   = 10;
	const DEFAULT_MAX_NODES = 300;
	const LIMIT_MAX_NODES   = 2000;

	/**
	 * Text lengths.
	 */
	const MAX_TITLE       = 200;
	const MAX_DESCRIPTION = 1000;
	const MAX_LABEL       = 300;

	/**
	 * How often the last access is written at most.
	 */
	const ACCESS_RESOLUTION = HOUR_IN_SECONDS;

	/**
	 * Post ID. Internal only — never sent to a visitor.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Memoised newest version: {revision, structure, saved_at}.
	 *
	 * @var array|null
	 */
	private ?array $version = null;

	/**
	 * What the visitor may do, by the ID the checklist was found with.
	 * Server-side code (cron, admin) works with full access.
	 *
	 * @var string
	 */
	private string $access;

	/**
	 * Constructor.
	 *
	 * @param int    $post_id Post ID of a cko_checklist post.
	 * @param string $access  One of the ACCESS_* constants.
	 */
	public function __construct( int $post_id, string $access = self::ACCESS_EDIT ) {
		$this->post_id = $post_id;
		$this->access  = in_array( $access, array( self::ACCESS_LOCKED, self::ACCESS_SEALED ), true ) ? $access : self::ACCESS_EDIT;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Finding and creating
	 * ------------------------------------------------------------------
	 */

	/**
	 * Finds a checklist by its ID — the only way a visitor can. The access
	 * it opens with is then narrowed by its level ({@see Checkoff_Password::apply()}).
	 *
	 * @param mixed $public_id Raw ID (any case, with or without dashes).
	 * @return self|null
	 */
	public static function find( mixed $public_id ): ?self {

		$public_id = Checkoff_Public_Id::normalize( $public_id );

		if ( '' === $public_id ) {
			return null;
		}

		$post_id = self::post_id_by_key( $public_id, 'publish' );

		return 0 === $post_id ? null : new self( $post_id );
	}

	/**
	 * The checklist carrying an ID, or 0.
	 *
	 * @param string $key    Normalised ID.
	 * @param string $status Post status to look in.
	 * @return int
	 */
	private static function post_id_by_key( string $key, string $status ): int {

		$ids = get_posts(
			array(
				'post_type'        => Checkoff_Post_Types::POST_TYPE,
				'post_status'      => $status,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_PUBLIC_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Creates a checklist.
	 *
	 * @param string $title Title.
	 * @param string $type  Block type of the top level.
	 * @return self|WP_Error
	 */
	public static function create( string $title, string $type = Checkoff_Block_Types::FALLBACK ): self|WP_Error {

		$title = self::clean_text( $title, self::MAX_TITLE );

		if ( '' === $title ) {
			$title = __( 'My checklist', 'checkoff' );
		}

		if ( ! Checkoff_Block_Types::is_enabled( $type ) ) {
			$type = Checkoff_Block_Types::FALLBACK;
		}

		$public_id = self::unique_public_id();

		if ( '' === $public_id ) {
			return new WP_Error( 'checkoff_id', __( 'No free ID could be found. Please try again.', 'checkoff' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Checkoff_Post_Types::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$checklist = new self( (int) $post_id );

		update_post_meta( $post_id, self::META_PUBLIC_ID, $public_id );
		update_post_meta( $post_id, self::META_ACCESS, self::LEVEL_OPEN );
		update_post_meta( $post_id, self::META_LAST_ACCESS, time() );
		Checkoff_Versions::add( (int) $post_id, 1, self::sanitize_structure( self::new_structure( $type, $title ) ) );

		/**
		 * Fires after a checklist was created.
		 *
		 * @param Checkoff_Checklist $checklist The new checklist.
		 */
		do_action( 'checkoff_checklist_created', $checklist );

		return $checklist;
	}

	/**
	 * A public ID no checklist carries yet, or '' after too many collisions.
	 *
	 * @return string
	 */
	public static function unique_public_id(): string {

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {

			$candidate = Checkoff_Public_Id::generate();

			if ( 0 === self::post_id_by_key( $candidate, 'any' ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * A new checklist structure: the container with one empty block.
	 *
	 * @param string $type  Block key of the first block.
	 * @param string $title Title of the checklist.
	 * @return array
	 */
	public static function new_structure( string $type, string $title = '' ): array {
		return array(
			'id'          => self::generate_node_id(),
			'kind'        => self::KIND_CHECKLIST,
			'title'       => $title,
			'description' => '',
			'items'       => array( self::new_block( $type ) ),
			'recurrence'  => Checkoff_Recurrence::none(),
		);
	}

	/**
	 * A new, empty block of a type.
	 *
	 * @param string $type  Block key.
	 * @param string $title Title.
	 * @return array
	 */
	public static function new_block( string $type, string $title = '' ): array {
		return array(
			'id'          => self::generate_node_id(),
			'kind'        => 'block',
			'type'        => Checkoff_Block_Types::get( $type )->key(),
			'title'       => $title,
			'description' => '',
			'settings'    => Checkoff_Block_Types::get( $type )->default_settings(),
			'items'       => array(),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Accessors
	 * ------------------------------------------------------------------
	 */

	/**
	 * Post ID (internal).
	 *
	 * @return int
	 */
	public function get_post_id(): int {
		return $this->post_id;
	}

	/**
	 * The public ID in stored form.
	 *
	 * @return string
	 */
	public function get_public_id(): string {
		return (string) get_post_meta( $this->post_id, self::META_PUBLIC_ID, true );
	}

	/**
	 * What the checklist was opened for.
	 *
	 * @return string One of the ACCESS_* constants.
	 */
	public function get_access(): string {
		return $this->access;
	}

	/**
	 * Whether the structure may be changed, the ticks cleared and the
	 * checklist deleted — only with the public ID, and on a protected list
	 * only once unlocked.
	 *
	 * @return bool
	 */
	public function can_edit(): bool {
		return self::ACCESS_EDIT === $this->access;
	}

	/**
	 * The ID this checklist was opened with, in stored form.
	 *
	 * @return string
	 */
	public function get_current_id(): string {
		return $this->get_public_id();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Password
	 * ------------------------------------------------------------------
	 */

	/**
	 * Whether the checklist has a password (level protected or private).
	 *
	 * @return bool
	 */
	public function is_protected(): bool {
		return '' !== $this->password_hash();
	}

	/**
	 * How far the address alone opens the list: open without a password,
	 * else protected, or private where the password guards viewing too.
	 *
	 * @return string One of the LEVEL_* constants.
	 */
	public function get_level(): string {

		$level = (string) get_post_meta( $this->post_id, self::META_ACCESS, true );

		// Protected or private without a password would lock out everybody.
		if ( ! in_array( $level, array( self::LEVEL_PROTECTED, self::LEVEL_PRIVATE ), true ) || ! $this->is_protected() ) {
			return self::LEVEL_OPEN;
		}

		return $level;
	}

	/**
	 * Sets the level, with the password where it needs one: a new password,
	 * or '' to keep the current one. Open removes the password; private
	 * takes the list out of the directory.
	 *
	 * @param string $level    One of the LEVEL_* constants.
	 * @param string $password New password, or '' to keep the current one.
	 * @return true|WP_Error
	 */
	public function set_level( string $level, string $password = '' ): bool|WP_Error {

		if ( ! in_array( $level, array( self::LEVEL_OPEN, self::LEVEL_PROTECTED, self::LEVEL_PRIVATE ), true ) ) {
			return new WP_Error( 'checkoff_level', __( 'Unknown access level.', 'checkoff' ), array( 'status' => 400 ) );
		}

		if ( self::LEVEL_OPEN === $level ) {
			update_post_meta( $this->post_id, self::META_ACCESS, self::LEVEL_OPEN );
			delete_post_meta( $this->post_id, self::META_LISTED );
			return $this->set_password( '' );
		}

		if ( '' === $password && ! $this->is_protected() ) {
			return new WP_Error( 'checkoff_password_length', __( 'Please enter a password.', 'checkoff' ), array( 'status' => 400 ) );
		}

		if ( '' !== $password ) {
			$result = $this->set_password( $password );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		update_post_meta( $this->post_id, self::META_ACCESS, $level );

		if ( self::LEVEL_PRIVATE === $level ) {
			delete_post_meta( $this->post_id, self::META_LISTED );
		}

		return true;
	}

	/**
	 * Whether the list is shown in the public directory: marked so by its
	 * owner, protected (an open list there could be changed and deleted by
	 * anybody passing by), and the directory switched on.
	 *
	 * @return bool
	 */
	public function is_listed(): bool {
		return Checkoff_Directory::is_enabled()
			&& self::LEVEL_PROTECTED === $this->get_level()
			&& '1' === (string) get_post_meta( $this->post_id, self::META_LISTED, true );
	}

	/**
	 * Shows the list in the directory, or no longer.
	 *
	 * @param bool $listed Show it.
	 * @return true|WP_Error
	 */
	public function set_listed( bool $listed ): bool|WP_Error {

		if ( ! $listed ) {
			delete_post_meta( $this->post_id, self::META_LISTED );
			return true;
		}

		if ( ! Checkoff_Directory::is_enabled() ) {
			return new WP_Error( 'checkoff_directory_off', __( 'Public checklists are not available on this site.', 'checkoff' ), array( 'status' => 403 ) );
		}

		if ( self::LEVEL_PROTECTED !== $this->get_level() ) {
			return new WP_Error( 'checkoff_listed_level', __( 'Only a protected checklist can be shown publicly: everybody may view and tick it, but changing it needs the password.', 'checkoff' ), array( 'status' => 400 ) );
		}

		update_post_meta( $this->post_id, self::META_LISTED, '1' );

		return true;
	}

	/**
	 * The stored password hash ('' without protection). Only a hash is
	 * stored — WordPress's own, as for user passwords.
	 *
	 * @return string
	 */
	public function password_hash(): string {
		return (string) get_post_meta( $this->post_id, self::META_PASSWORD, true );
	}

	/**
	 * Whether a password is the checklist's.
	 *
	 * @param string $password Plain password.
	 * @return bool
	 */
	public function check_password( string $password ): bool {

		$hash = $this->password_hash();

		return '' !== $hash && '' !== $password && wp_check_password( $password, $hash );
	}

	/**
	 * Sets the password, or removes the protection with ''.
	 *
	 * @param string $password Plain password.
	 * @return true|WP_Error
	 */
	public function set_password( string $password ): bool|WP_Error {

		if ( '' === $password ) {
			delete_post_meta( $this->post_id, self::META_PASSWORD );
			return true;
		}

		$length = mb_strlen( $password );

		if ( $length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX ) {
			return new WP_Error(
				'checkoff_password_length',
				/* translators: %s: minimum number of characters. */
				sprintf( __( 'The password must have at least %s characters.', 'checkoff' ), number_format_i18n( self::PASSWORD_MIN ) ),
				array( 'status' => 400 )
			);
		}

		update_post_meta( $this->post_id, self::META_PASSWORD, wp_hash_password( $password ) );

		return true;
	}

	/**
	 * Whether this view may learn when values were set and page through the
	 * kept rounds: whoever may edit, while the site keeps a history.
	 *
	 * @return bool
	 */
	public function shows_times(): bool {
		return $this->can_edit() && Checkoff_History::is_enabled();
	}

	/**
	 * Turns an edit view of a protected list into a locked one.
	 *
	 * @return void
	 */
	public function lock(): void {
		if ( self::ACCESS_EDIT === $this->access && $this->is_protected() ) {
			$this->access = self::ACCESS_LOCKED;
		}
	}

	/**
	 * Turns any view of a private list into a sealed one.
	 *
	 * @return void
	 */
	public function seal(): void {
		if ( self::LEVEL_PRIVATE === $this->get_level() ) {
			$this->access = self::ACCESS_SEALED;
		}
	}

	/**
	 * Gives the checklist a new public ID; the old one stops working at once.
	 *
	 * @return string The new ID, or '' if none could be found.
	 */
	public function regenerate_public_id(): string {

		$public_id = self::unique_public_id();

		if ( '' !== $public_id ) {
			update_post_meta( $this->post_id, self::META_PUBLIC_ID, $public_id );
		}

		return $public_id;
	}

	/**
	 * Title (the title of the top-level block).
	 *
	 * @return string
	 */
	public function get_title(): string {
		return (string) get_the_title( $this->post_id );
	}

	/**
	 * Revision of the structure: the newest version's number.
	 *
	 * @return int
	 */
	public function get_revision(): int {
		return $this->version()['revision'];
	}

	/**
	 * Unix time the checklist was last opened or changed.
	 *
	 * @return int
	 */
	public function get_last_access(): int {
		return (int) get_post_meta( $this->post_id, self::META_LAST_ACCESS, true );
	}

	/**
	 * Notes an access; written at most once per hour, so merely viewing a
	 * list does not write to the database on every page load.
	 *
	 * @param bool $force Write regardless of the resolution (on changes).
	 * @return void
	 */
	public function touch( bool $force = false ): void {

		if ( $force || time() - $this->get_last_access() >= self::ACCESS_RESOLUTION ) {
			update_post_meta( $this->post_id, self::META_LAST_ACCESS, time() );
		}
	}

	/**
	 * The newest version: {revision, structure, saved_at}. A list without
	 * one (not to happen) reads as an empty list of revision 1.
	 *
	 * @return array{revision:int, structure:array, saved_at:int}
	 */
	private function version(): array {

		if ( null === $this->version ) {
			$latest        = Checkoff_Versions::latest( $this->post_id );
			$this->version = null !== $latest ? $latest : array(
				'revision'  => 1,
				'structure' => self::new_structure( Checkoff_Block_Types::FALLBACK, $this->get_title() ),
				'saved_at'  => time(),
			);
			// Validated on read as well as on write: a row written past this
			// class cannot put an unknown block type on a page.
			$this->version['structure'] = self::sanitize_structure( $this->version['structure'] );
		}

		return $this->version;
	}

	/**
	 * The structure as it is.
	 *
	 * @return array
	 */
	public function get_structure(): array {
		return $this->version()['structure'];
	}

	/**
	 * Saves the structure as the next revision. The revision is the
	 * conflict check: only one save based on a revision can insert the next.
	 *
	 * @param mixed $raw           Raw structure (decoded JSON).
	 * @param int   $base_revision The revision the client edited.
	 * @return array|WP_Error The stored structure and revision, or an error
	 *                        (code checkoff_conflict when the base is stale).
	 */
	public function set_structure( mixed $raw, int $base_revision ): array|WP_Error {

		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'checkoff_invalid', __( 'The checklist could not be read.', 'checkoff' ) );
		}

		$conflict = new WP_Error( 'checkoff_conflict', __( 'This checklist was changed somewhere else.', 'checkoff' ) );

		if ( $base_revision !== $this->get_revision() ) {
			return $conflict;
		}

		$old_round = $this->round_index();
		$old_rule  = $this->get_structure()['recurrence'];
		$structure = self::sanitize_structure( $raw );
		$revision  = $base_revision + 1;

		if ( ! Checkoff_Versions::add( $this->post_id, $revision, $structure ) ) {
			return $conflict;
		}

		$this->version = array(
			'revision'  => $revision,
			'structure' => $structure,
			'saved_at'  => time(),
		);

		// A changed rule renumbers the rounds: what is ticked moves to the
		// round in force under the new rule, and the past of the old rule
		// (numbered by it) goes.
		if ( $old_rule !== $structure['recurrence'] ) {
			Checkoff_Values::move( $this->post_id, $old_round, $this->round_index() );
			Checkoff_Values::clear_except( $this->post_id, $this->round_index() );
		}

		// Values of removed entries go, the others are checked against the
		// new settings (a scale may have become shorter).
		Checkoff_Values::revalidate( $this->post_id, $this->round_index(), $this->revalidated( $this->raw_values() ), array_keys( self::leaf_map( $structure ) ) );

		// Without a history, older versions are of no use.
		if ( ! Checkoff_History::is_enabled() ) {
			Checkoff_Versions::prune( $this->post_id, time() + 1 );
		}

		$title = '' !== $structure['title'] ? $structure['title'] : __( 'My checklist', 'checkoff' );

		if ( $title !== $this->get_title() ) {
			wp_update_post(
				array(
					'ID'         => $this->post_id,
					'post_title' => $title,
				)
			);
		}

		$this->touch( true );

		return array(
			'structure' => $structure,
			'revision'  => $revision,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Values
	 * ------------------------------------------------------------------
	 */

	/**
	 * The index of the round in force (0 for a list that does not repeat) —
	 * the round the values are read from and written to.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return int
	 */
	public function round_index( ?int $now = null ): int {
		$recurrence = $this->recurrence();
		return $recurrence->repeats() ? max( 0, (int) $recurrence->current( $now )['index'] ) : 0;
	}

	/**
	 * The stored values of the round in force, unvalidated.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return array<string, mixed>
	 */
	private function raw_values( ?int $now = null ): array {

		$values = array();

		foreach ( Checkoff_Values::rows( $this->post_id, $this->round_index( $now ) ) as $id => $row ) {
			if ( null !== $row['v'] ) {
				$values[ $id ] = $row['v'];
			}
		}

		return $values;
	}

	/**
	 * Values as they are to be shown: of the round in force, only for
	 * entries that exist, without those that have expired (weekly reset).
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return array<string, mixed>
	 */
	public function get_values( ?int $now = null ): array {
		return $this->displayable( $this->raw_values( $now ) );
	}

	/**
	 * The board's stages of the round in force: entry ID => 'doing'.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return array<string, string>
	 */
	public function get_stages( ?int $now = null ): array {

		$leaves = self::leaf_map( $this->get_structure() );
		$stages = array();

		foreach ( Checkoff_Values::rows( $this->post_id, $this->round_index( $now ) ) as $id => $row ) {
			if ( null !== $row['s'] && isset( $leaves[ $id ] ) ) {
				$stages[ $id ] = $row['s'];
			}
		}

		return $stages;
	}

	/**
	 * When each value or stage shown was set: entry ID => unix time.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return array<string, int>
	 */
	public function get_value_times( ?int $now = null ): array {

		$shown = $this->get_values( $now ) + $this->get_stages( $now );
		$times = array();

		foreach ( Checkoff_Values::rows( $this->post_id, $this->round_index( $now ) ) as $id => $row ) {
			if ( array_key_exists( $id, $shown ) ) {
				$times[ $id ] = $row['t'];
			}
		}

		return $times;
	}

	/**
	 * Checks a moment a change was made at (a tick sent late by a browser
	 * that was offline): believed up to LATE_TICK_MAX back, never ahead,
	 * and refused when it lies before the round in force began — it would
	 * otherwise land in the next round.
	 *
	 * @param int|null $at Unix time, or null for now.
	 * @return int|WP_Error
	 */
	private function moment( ?int $at ): int|WP_Error {

		$now = time();
		$at  = null === $at ? $now : min( $at, $now );

		if ( $at < $now ) {
			$recurrence = $this->recurrence();
			$current    = $recurrence->current( $now );
			if ( $recurrence->repeats() && $at < (int) $current['start'] && ! $current['before_start'] ) {
				return new WP_Error( 'checkoff_round_over', __( 'This tick was made in a round that has ended since; it was not saved.', 'checkoff' ), array( 'status' => 409 ) );
			}
			$at = max( $at, $now - self::LATE_TICK_MAX );
		}

		return $at;
	}

	/**
	 * Sets the value of one entry.
	 *
	 * @param string   $item_id Entry ID.
	 * @param mixed    $raw     Raw value; null or empty clears the entry.
	 * @param int|null $at      Unix time the tick was made, now by default.
	 * @return mixed|WP_Error The stored value (null when cleared).
	 */
	public function set_value( string $item_id, mixed $raw, ?int $at = null ): mixed {

		$leaves = self::leaf_map( $this->get_structure() );

		if ( ! isset( $leaves[ $item_id ] ) ) {
			return new WP_Error( 'checkoff_unknown_item', __( 'This entry no longer exists. Reload the page.', 'checkoff' ) );
		}

		$at = $this->moment( $at );

		if ( is_wp_error( $at ) ) {
			return $at;
		}

		$block = $leaves[ $item_id ];
		$value = Checkoff_Block_Types::get( $block['type'] )->sanitize_value( $raw, $block['settings'] );

		Checkoff_Values::set_value( $this->post_id, $this->round_index(), $item_id, $value, $at );
		$this->touch( true );
		Checkoff_History::keep_to_retention( $this );

		return $value;
	}

	/**
	 * Sets the board's stage of one entry: 'doing' or null.
	 *
	 * @param string      $item_id Entry ID.
	 * @param string|null $stage   'doing' or null.
	 * @param int|null    $at      Unix time, now by default.
	 * @return string|null|WP_Error The stored stage.
	 */
	public function set_stage( string $item_id, ?string $stage, ?int $at = null ): string|null|WP_Error {

		if ( ! isset( self::leaf_map( $this->get_structure() )[ $item_id ] ) ) {
			return new WP_Error( 'checkoff_unknown_item', __( 'This entry no longer exists. Reload the page.', 'checkoff' ) );
		}

		$at = $this->moment( $at );

		if ( is_wp_error( $at ) ) {
			return $at;
		}

		$stage = Checkoff_Values::STAGE_DOING === $stage ? Checkoff_Values::STAGE_DOING : null;

		Checkoff_Values::set_stage( $this->post_id, $this->round_index(), $item_id, $stage, $at );
		$this->touch( true );

		return $stage;
	}

	/**
	 * Clears every value and stage of the round in force.
	 *
	 * @return void
	 */
	public function reset_values(): void {
		Checkoff_Values::clear( $this->post_id, $this->round_index() );
		$this->touch( true );
	}

	/**
	 * Values re-checked against the structure: those of removed entries go,
	 * the rest are checked against their (possibly changed) settings.
	 *
	 * @param array $values Stored values.
	 * @return array
	 */
	private function revalidated( array $values ): array {

		$leaves = self::leaf_map( $this->get_structure() );
		$clean  = array();

		foreach ( $values as $id => $value ) {

			if ( ! isset( $leaves[ $id ] ) ) {
				continue;
			}

			$block = $leaves[ $id ];
			$value = Checkoff_Block_Types::get( $block['type'] )->revalidate_value( $value, $block['settings'] );

			if ( null !== $value ) {
				$clean[ $id ] = $value;
			}
		}

		return $clean;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Rounds
	 * ------------------------------------------------------------------
	 */

	/**
	 * The repetition rule of the checklist.
	 *
	 * @return Checkoff_Recurrence
	 */
	public function recurrence(): Checkoff_Recurrence {
		return new Checkoff_Recurrence( $this->get_structure()['recurrence'] );
	}

	/**
	 * How the round before the one in force went — what mails and the page
	 * say about "last time". Computed from its rows and the structure in
	 * force when it ended; null for a list that does not repeat, or before
	 * its second round.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return array{number:int, done:int, total:int, open:array, more:int, closed:int}|null
	 */
	public function get_last_round( ?int $now = null ): ?array {

		$recurrence = $this->recurrence();
		$index      = $this->round_index( $now );

		if ( ! $recurrence->repeats() || $index < 1 ) {
			return null;
		}

		$closed    = $recurrence->round_start( $index )->getTimestamp();
		$version   = Checkoff_Versions::at( $this->post_id, $closed );
		$structure = self::sanitize_structure( null !== $version ? $version['structure'] : $this->get_structure() );
		$values    = array();

		foreach ( Checkoff_Values::rows( $this->post_id, $index - 1 ) as $id => $row ) {
			if ( null !== $row['v'] ) {
				$values[ $id ] = $row['v'];
			}
		}

		$progress = self::progress_of( $structure, $values );
		$open     = self::open_items_of( $structure, $values, $now );

		return array(
			'number' => $index,
			'done'   => $progress['done'],
			'total'  => $progress['total'],
			'open'   => array_slice( $open, 0, self::LAST_ROUND_OPEN_MAX ),
			'more'   => max( 0, count( $open ) - self::LAST_ROUND_OPEN_MAX ),
			'closed' => $closed,
		);
	}

	/**
	 * Stored values reduced to entries that exist and are shown now.
	 *
	 * @param array $raw Stored values.
	 * @return array
	 */
	private function displayable( array $raw ): array {

		$leaves = self::leaf_map( $this->get_structure() );
		$values = array();

		foreach ( $raw as $id => $value ) {
			if ( isset( $leaves[ $id ] ) ) {
				$value = Checkoff_Block_Types::get( $leaves[ $id ]['type'] )->value_for_display( $value, $leaves[ $id ]['settings'] );
				if ( null !== $value ) {
					$values[ $id ] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Progress of the whole checklist, by the block types' own rules.
	 *
	 * @param array|null $values Values, the current ones by default.
	 * @param int|null   $now    Unix time, now by default.
	 * @return array{done:int, total:int, percent:int}
	 */
	public function progress( ?array $values = null, ?int $now = null ): array {
		return self::progress_of( $this->get_structure(), $values ?? $this->get_values( $now ) );
	}

	/**
	 * Progress of any structure with any values.
	 *
	 * @param array $structure Structure.
	 * @param array $values    Values as shown.
	 * @return array{done:int, total:int, percent:int}
	 */
	public static function progress_of( array $structure, array $values ): array {

		$done  = 0;
		$total = 0;

		foreach ( self::leaf_map( $structure ) as $id => $block ) {
			$part   = Checkoff_Block_Types::get( $block['type'] )->progress( $values[ $id ] ?? null, $block['settings'] );
			$done  += $part['done'];
			$total += $part['total'];
		}

		return array(
			'done'    => $done,
			'total'   => $total,
			'percent' => $total > 0 ? (int) floor( $done / $total * 100 ) : 0,
		);
	}

	/**
	 * Entries not yet done, with the titles of the lists they are in.
	 *
	 * @param array|null $values Values, the current ones by default.
	 * @return array<int, array{label:string, path:string}>
	 */
	public function open_items( ?array $values = null ): array {
		return self::open_items_of( $this->get_structure(), $values ?? $this->get_values(), null, null === $values ? $this->get_stages() : array() );
	}

	/**
	 * Open entries of any structure with any values.
	 *
	 * @param array    $structure Structure.
	 * @param array    $values    Values as shown.
	 * @param int|null $now       Unix time, now by default (for due dates).
	 * @param array    $stages    The board's stages (entry ID => 'doing').
	 * @return array<int, array{label:string, path:string, note:string}>
	 */
	public static function open_items_of( array $structure, array $values, ?int $now = null, array $stages = array() ): array {

		$open  = array();
		$board = self::VIEW_BOARD === ( $structure['view'] ?? '' ) ? self::stage_names( $structure ) : null;

		$walk = static function ( array $block, array $path ) use ( &$walk, &$open, $values, $now, $board, $stages ): void {

			$type = Checkoff_Block_Types::get( (string) ( $block['type'] ?? '' ) );

			if ( '' !== $block['title'] ) {
				$path[] = $block['title'];
			}

			foreach ( $block['items'] as $item ) {

				if ( 'block' === $item['kind'] ) {
					$walk( $item, $path );
					continue;
				}

				$part = $type->progress( $values[ $item['id'] ] ?? null, $block['settings'] );

				if ( $part['total'] > 0 && $part['done'] < $part['total'] ) {
					$note = $type->describe_item( $item, $values[ $item['id'] ] ?? null, $block['settings'], $now );

					// On a board, a card in progress says so.
					if ( null !== $board && self::STAGE_DOING === self::stage_of( $block, $item, $values, $stages ) ) {
						$note = '' === $note ? $board[ self::STAGE_DOING ] : $board[ self::STAGE_DOING ] . ' · ' . $note;
					}

					$open[] = array(
						'label' => '' !== $item['label'] ? $item['label'] : '—',
						'path'  => implode( ' › ', array_slice( $path, 1 ) ),
						'note'  => $note,
					);
				}
			}
		};

		$walk( $structure, array() );

		return $open;
	}

	/**
	 * The round in force, ready for display.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return array
	 */
	public function round_info( ?int $now = null ): array {

		$recurrence = $this->recurrence();
		$round      = $recurrence->current( $now );

		if ( ! $recurrence->repeats() ) {
			return array( 'repeats' => false );
		}

		return array(
			'repeats'      => true,
			'number'       => $round['number'],
			'total'        => $round['total'],
			'start'        => Checkoff_Recurrence::format_date( $round['start'] ),
			// The last day of the round, not the start of the next.
			'end'          => Checkoff_Recurrence::format_date( (int) $round['end'] - DAY_IN_SECONDS / 2 ),
			'before_start' => $round['before_start'],
			'finished'     => $round['finished'],
			'description'  => $recurrence->describe(),
		);
	}

	/**
	 * Deletes the checklist for good. It belongs to whoever holds the ID, and
	 * a list they deleted should not linger in a trash they cannot see.
	 *
	 * @return bool
	 */
	public function delete(): bool {
		return (bool) wp_delete_post( $this->post_id, true );
	}

	/**
	 * Address of the checklist: the configured checklist page, else the given
	 * fallback, with the public ID as query parameter.
	 *
	 * @param string $fallback Base URL when no checklist page is configured.
	 * @return string
	 */
	public function url( string $fallback = '' ): string {
		return self::url_for( $this->get_current_id(), $fallback );
	}

	/**
	 * Address of a checklist by public ID.
	 *
	 * @param string $public_id Stored public ID.
	 * @param string $fallback  Base URL when no checklist page is configured.
	 * @return string
	 */
	public static function url_for( string $public_id, string $fallback = '' ): string {

		$base = self::page_url();

		if ( '' === $base ) {
			$base = '' !== $fallback ? $fallback : home_url( '/' );
		}

		return add_query_arg( self::QUERY_VAR, Checkoff_Public_Id::format( $public_id ), $base );
	}

	/**
	 * URL of the page checklists are shown on — the chosen one, else the one
	 * found with the shortcode — or '' ({@see Checkoff_Checklist_Page}).
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return Checkoff_Checklist_Page::url();
	}

	/**
	 * Everything the frontend needs — and nothing it must not see: the post
	 * ID stays on the server.
	 *
	 * @param string $fallback_url Base URL when no checklist page is configured.
	 * @return array
	 */
	public function to_client( string $fallback_url = '' ): array {

		// A private list without its password: the prompt, and nothing of the list.
		if ( self::ACCESS_SEALED === $this->access ) {
			return array(
				'id'        => Checkoff_Public_Id::format( $this->get_current_id() ),
				'access'    => $this->access,
				'level'     => self::LEVEL_PRIVATE,
				'protected' => true,
				'url'       => $this->url( $fallback_url ),
			);
		}

		$sync = $this->sync_state();

		$data = array(
			'id'        => Checkoff_Public_Id::format( $this->get_current_id() ),
			'access'    => $this->access,
			'protected' => $this->is_protected(),
			'level'     => $this->get_level(),
			'title'     => $this->get_title(),
			'structure' => $this->get_structure(),
			'values'    => (object) $sync['values'],
			'stages'    => (object) $sync['stages'],
			'revision'  => $this->get_revision(),
			'url'       => $this->url( $fallback_url ),
			'round'     => $sync['round'],
			'last'      => $sync['last'],
			'state'     => $sync['state'],
		);

		if ( $this->can_edit() ) {
			$data['listed'] = $this->is_listed();

			// The past and the times of the ticks are the owner's: other views
			// learn not even that they exist.
			if ( $this->shows_times() ) {
				$index           = Checkoff_History::index( $this );
				$data['times']   = (object) $sync['times'];
				$data['history'] = array(
					'rounds' => count( $index ),
					'index'  => $index,
					'days'   => Checkoff_History::days(),
				);
			}
		}

		return $data;
	}

	/**
	 * What an open page shows of the checklist besides its structure, and a
	 * fingerprint of it all — the live update compares fingerprints and
	 * sends data only when they differ. One method for the page and the
	 * poll, so the two cannot compute different fingerprints for one state.
	 *
	 * @return array{state:string, values:array, stages:array, times:array, round:array, last:array|null}
	 */
	public function sync_state(): array {

		$values = $this->get_values();
		$stages = $this->get_stages();
		$times  = $this->get_value_times();
		$round  = $this->round_info();
		$last   = $this->get_last_round();
		$last   = null === $last ? null : array(
			'number' => $last['number'],
			'done'   => $last['done'],
			'total'  => $last['total'],
		);

		return array(
			'state'  => md5( (string) wp_json_encode( array( $this->get_revision(), $values, $stages, $times, $round, $last ) ) ),
			'values' => $values,
			'stages' => $stages,
			'times'  => $times,
			'round'  => $round,
			'last'   => $last,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Structure validation
	 * ------------------------------------------------------------------
	 */

	/**
	 * Maximum nesting depth, the top level counting as 1.
	 *
	 * @return int
	 */
	public static function max_depth(): int {
		$depth = (int) get_option( 'checkoff_max_depth', self::DEFAULT_MAX_DEPTH );
		return max( 1, min( self::LIMIT_MAX_DEPTH, $depth ) );
	}

	/**
	 * Maximum number of blocks and entries in one checklist.
	 *
	 * @return int
	 */
	public static function max_nodes(): int {
		$nodes = (int) get_option( 'checkoff_max_nodes', self::DEFAULT_MAX_NODES );
		return max( 10, min( self::LIMIT_MAX_NODES, $nodes ) );
	}

	/**
	 * Validates a whole tree: known block types only, children only where the
	 * type allows them, unique IDs, the configured depth and size, and clean
	 * texts. What does not fit is dropped rather than refused, so a client
	 * that is out of step with the settings loses the excess and not the list.
	 *
	 * @param array $raw Raw top-level block.
	 * @return array
	 */
	public static function sanitize_structure( array $raw ): array {

		$count = 1;
		$seen  = array();

		$structure = array(
			'id'          => self::claim_id( $raw['id'] ?? '', $seen ),
			'kind'        => self::KIND_CHECKLIST,
			'title'       => self::clean_text( $raw['title'] ?? '', self::MAX_TITLE ),
			'description' => self::clean_text( $raw['description'] ?? '', self::MAX_DESCRIPTION, true ),
			'items'       => array(),
		);

		// The top level holds blocks only; an entry needs a block to say
		// what kind of entry it is.
		foreach ( is_array( $raw['items'] ?? null ) ? $raw['items'] : array() as $item ) {

			if ( $count >= self::max_nodes() ) {
				break;
			}

			if ( is_array( $item ) && 'block' === ( $item['kind'] ?? '' ) ) {
				$structure['items'][] = self::sanitize_block( $item, 1, $count, $seen );
			}
		}

		// Only the top level carries the repetition: a checklist repeats as
		// a whole.
		$structure['recurrence'] = Checkoff_Recurrence::sanitize( $raw['recurrence'] ?? null );

		// The board view and the names of its three columns, top level only;
		// absent means the list and the default names.
		if ( self::VIEW_BOARD === ( $raw['view'] ?? '' ) ) {
			$structure['view'] = self::VIEW_BOARD;
		}

		$stages = is_array( $raw['stages'] ?? null ) ? array_values( $raw['stages'] ) : array();
		$stages = array_map( static fn( $name ) => self::clean_text( is_string( $name ) ? $name : '', self::MAX_STAGE_LEN ), array_slice( array_pad( $stages, 3, '' ), 0, 3 ) );

		if ( array_filter( $stages, 'strlen' ) ) {
			$structure['stages'] = $stages;
		}

		return $structure;
	}

	/**
	 * Validates one block and, recursively, its entries.
	 *
	 * @param array $raw   Raw block.
	 * @param int   $depth Depth of this block (top level = 1).
	 * @param int   $count Nodes so far (by reference).
	 * @param array $seen  IDs so far (by reference).
	 * @return array
	 */
	private static function sanitize_block( array $raw, int $depth, int &$count, array &$seen ): array {

		$type_key = is_string( $raw['type'] ?? null ) ? $raw['type'] : '';
		$type     = Checkoff_Block_Types::get( $type_key );

		++$count;

		$block = array(
			'id'          => self::claim_id( $raw['id'] ?? '', $seen ),
			'kind'        => 'block',
			'type'        => $type->key(),
			'title'       => self::clean_text( $raw['title'] ?? '', self::MAX_TITLE ),
			'description' => self::clean_text( $raw['description'] ?? '', self::MAX_DESCRIPTION, true ),
			'settings'    => $type->sanitize_settings( $raw['settings'] ?? array() ),
			'items'       => array(),
		);

		$items = is_array( $raw['items'] ?? null ) ? $raw['items'] : array();

		foreach ( $items as $item ) {

			if ( $count >= self::max_nodes() ) {
				break;
			}

			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( 'block' === ( $item['kind'] ?? '' ) ) {

				if ( $type->supports_children() && $depth < self::max_depth() ) {
					$block['items'][] = self::sanitize_block( $item, $depth + 1, $count, $seen );
				}

				continue;
			}

			++$count;

			$leaf = array(
				'id'    => self::claim_id( $item['id'] ?? '', $seen ),
				'kind'  => 'item',
				'label' => self::clean_text( $item['label'] ?? '', self::MAX_LABEL ),
			);

			// Only blocks that know entry data keep it (see sanitize_item_data()).
			$data = $type->sanitize_item_data( $item['data'] ?? array(), $block['settings'] );

			if ( ! empty( $data ) ) {
				$leaf['data'] = $data;
			}

			$block['items'][] = $leaf;
		}

		return $block;
	}

	/**
	 * Keeps a valid, unused node ID or hands out a new one.
	 *
	 * @param mixed $id   Raw ID.
	 * @param array $seen IDs so far (by reference).
	 * @return string
	 */
	private static function claim_id( mixed $id, array &$seen ): string {

		$id = is_string( $id ) ? $id : '';

		if ( ! self::is_node_id( $id ) || isset( $seen[ $id ] ) ) {
			do {
				$id = self::generate_node_id();
			} while ( isset( $seen[ $id ] ) );
		}

		$seen[ $id ] = true;

		return $id;
	}

	/**
	 * Whether a string is a valid node ID.
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	public static function is_node_id( string $id ): bool {
		return (bool) preg_match( '/^[a-z0-9]{6,16}$/', $id );
	}

	/**
	 * Where a card stands on the board: done by the block's own rule (an
	 * entry without such a rule — a note — or one marked not needed counts
	 * done once anything is entered), in progress when something is entered
	 * or it is marked so, else open. The browser mirrors this in stageOf().
	 *
	 * @param array $block  The entry's block.
	 * @param array $item   The entry.
	 * @param array $values Values.
	 * @param array $stages Stages (entry ID => 'doing').
	 * @return string One of the STAGE_* constants.
	 */
	public static function stage_of( array $block, array $item, array $values, array $stages = array() ): string {

		$value = $values[ $item['id'] ] ?? null;

		if ( null !== $value ) {
			$part = Checkoff_Block_Types::get( (string) $block['type'] )->progress( $value, (array) $block['settings'] );
			return ( 0 === $part['total'] || $part['done'] >= $part['total'] ) ? self::STAGE_DONE : self::STAGE_DOING;
		}

		return empty( $stages[ $item['id'] ] ) ? self::STAGE_OPEN : self::STAGE_DOING;
	}

	/**
	 * The names of the board's three columns: the list's own, else the
	 * defaults.
	 *
	 * @param array $structure Structure.
	 * @return array{open:string, doing:string, done:string}
	 */
	public static function stage_names( array $structure ): array {

		$own = (array) ( $structure['stages'] ?? array() );

		return array(
			self::STAGE_OPEN  => '' !== (string) ( $own[0] ?? '' ) ? (string) $own[0] : _x( 'Open', 'board column', 'checkoff' ),
			self::STAGE_DOING => '' !== (string) ( $own[1] ?? '' ) ? (string) $own[1] : __( 'In progress', 'checkoff' ),
			self::STAGE_DONE  => '' !== (string) ( $own[2] ?? '' ) ? (string) $own[2] : _x( 'Done', 'board column', 'checkoff' ),
		);
	}

	/**
	 * A new node ID. Only unique within one checklist, which is all it has to be.
	 *
	 * @return string
	 */
	public static function generate_node_id(): string {

		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id    = '';

		for ( $i = 0; $i < 8; $i++ ) {
			$id .= $chars[ random_int( 0, 35 ) ];
		}

		return $id;
	}

	/**
	 * Every entry, by ID, with the type and settings of its block — what
	 * validates its value.
	 *
	 * @param array $block A block or the container.
	 * @return array<string, array{type:string, settings:array}>
	 */
	public static function leaf_map( array $block ): array {

		$map = array();

		foreach ( $block['items'] as $item ) {

			if ( 'block' === $item['kind'] ) {
				$map += self::leaf_map( $item );
				continue;
			}

			$map[ $item['id'] ] = array(
				'type'     => $block['type'],
				'settings' => $block['settings'],
			);
		}

		return $map;
	}

	/**
	 * Number of blocks and entries in a tree.
	 *
	 * @param array $block Top-level block.
	 * @return array{blocks:int, items:int}
	 */
	public static function count_nodes( array $block ): array {

		$counts = array(
			// The container is not a list of its own.
			'blocks' => self::KIND_CHECKLIST === ( $block['kind'] ?? '' ) ? 0 : 1,
			'items'  => 0,
		);

		foreach ( $block['items'] as $item ) {

			if ( 'block' === $item['kind'] ) {
				$sub               = self::count_nodes( $item );
				$counts['blocks'] += $sub['blocks'];
				$counts['items']  += $sub['items'];
				continue;
			}

			++$counts['items'];
		}

		return $counts;
	}

	/**
	 * Plain text of limited length.
	 *
	 * @param mixed $text      Raw text.
	 * @param int   $max       Maximum length in characters.
	 * @param bool  $multiline Keep line breaks.
	 * @return string
	 */
	private static function clean_text( mixed $text, int $max, bool $multiline = false ): string {

		if ( ! is_scalar( $text ) ) {
			return '';
		}

		$text = $multiline ? sanitize_textarea_field( (string) $text ) : sanitize_text_field( (string) $text );

		return trim( mb_substr( $text, 0, $max ) );
	}
}
