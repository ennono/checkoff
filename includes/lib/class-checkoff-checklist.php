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
 * ticked, rated, counted or written).
 *
 * Both are post meta of a `cko_checklist` post, kept apart on purpose:
 * ticking is frequent and touches one entry, editing is rare and replaces the
 * whole tree. A tick therefore never has to send — or can overwrite — the
 * structure, and an edit carries a revision number so two open tabs cannot
 * silently overwrite each other's changes.
 *
 * Structure: a checklist has no type of its own. It is a container
 *   { id, kind: "checklist", title, description, recurrence, items: [] }
 * whose items are building blocks of any type, stacked one after the other
 * and shown as sections. Every block is
 *   { id, kind: "block", type, title, description, settings, items: [] }
 * and every entry of a block's `items` is either a leaf
 *   { id, kind: "item", label }
 * or — where the block type supports children — another block (nested).
 * Before 1.3.0 the top level was a block itself; upgrade_legacy() turns such
 * a tree into a container on read.
 *
 * Values: a flat map of leaf ID => value, validated by the block type the
 * leaf belongs to.
 */
class Checkoff_Checklist {

	const META_PUBLIC_ID    = '_cko_public_id';
	const META_TICK_ID      = '_cko_tick_id';
	const META_PASSWORD     = '_cko_password';
	const META_STRUCTURE    = '_cko_structure';
	const META_VALUES       = '_cko_values'; // Before 1.2.0: all values in one row; migrated on read.
	const META_VALUE_PREFIX = '_cko_v_';
	const META_REVISION     = '_cko_revision';
	const META_LAST_ACCESS  = '_cko_last_access';
	const META_VALUES_ROUND = '_cko_values_round';
	const META_LAST_ROUND   = '_cko_last_round';

	/**
	 * How many open entries the summary of a finished round keeps by name.
	 */
	const LAST_ROUND_OPEN_MAX = 30;

	/**
	 * What the ID a checklist was opened with allows. The public ID is the
	 * key to everything (edit, delete); the tick ID only to ticking — the
	 * link to hand to children, flatmates, a cleaning crew.
	 */
	const ACCESS_EDIT   = 'edit';
	const ACCESS_TICK   = 'tick';
	const ACCESS_LOCKED = 'locked'; // The public ID of a password-protected list, not unlocked.

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
	 * Memoised structure.
	 *
	 * @var array|null
	 */
	private ?array $structure = null;

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
	 * @param string $access  ACCESS_EDIT, ACCESS_TICK or ACCESS_LOCKED.
	 */
	public function __construct( int $post_id, string $access = self::ACCESS_EDIT ) {
		$this->post_id = $post_id;
		$this->access  = in_array( $access, array( self::ACCESS_TICK, self::ACCESS_LOCKED ), true ) ? $access : self::ACCESS_EDIT;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Finding and creating
	 * ------------------------------------------------------------------
	 */

	/**
	 * Finds a checklist by one of its IDs — the only way a visitor can. The
	 * public ID gives full access, the tick ID ticking only.
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

		if ( 0 === $post_id ) {
			return null;
		}

		$access = get_post_meta( $post_id, self::META_TICK_ID, true ) === $public_id ? self::ACCESS_TICK : self::ACCESS_EDIT;

		return new self( $post_id, $access );
	}

	/**
	 * The checklist carrying an ID as public or tick ID, or 0.
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
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => self::META_PUBLIC_ID,
						'value' => $key,
					),
					array(
						'key'   => self::META_TICK_ID,
						'value' => $key,
					),
				),
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
		update_post_meta( $post_id, self::META_TICK_ID, self::unique_public_id() );
		update_post_meta( $post_id, self::META_STRUCTURE, self::new_structure( $type, $title ) );
		update_post_meta( $post_id, self::META_REVISION, 1 );
		update_post_meta( $post_id, self::META_LAST_ACCESS, time() );

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

			// Unique across both kinds: an ID must never open two lists.
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
	 * The tick ID in stored form; checklists from before 1.2.0 get one on
	 * first request.
	 *
	 * @return string
	 */
	public function get_tick_id(): string {

		$tick_id = (string) get_post_meta( $this->post_id, self::META_TICK_ID, true );

		if ( '' === $tick_id ) {
			$tick_id = $this->regenerate_tick_id();
		}

		return $tick_id;
	}

	/**
	 * Gives the checklist a new tick ID; the old tick link stops working.
	 *
	 * @return string The new ID, or '' if none could be found.
	 */
	public function regenerate_tick_id(): string {

		$tick_id = self::unique_public_id();

		if ( '' !== $tick_id ) {
			update_post_meta( $this->post_id, self::META_TICK_ID, $tick_id );
		}

		return $tick_id;
	}

	/**
	 * What the checklist was opened for.
	 *
	 * @return string ACCESS_EDIT, ACCESS_TICK or ACCESS_LOCKED.
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
		return self::ACCESS_TICK === $this->access ? $this->get_tick_id() : $this->get_public_id();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Password
	 * ------------------------------------------------------------------
	 */

	/**
	 * Whether the checklist is protected by a password.
	 *
	 * @return bool
	 */
	public function is_protected(): bool {
		return '' !== $this->password_hash();
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
	 * Revision of the structure, counted up on every save.
	 *
	 * @return int
	 */
	public function get_revision(): int {
		return max( 1, (int) get_post_meta( $this->post_id, self::META_REVISION, true ) );
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
	 * The structure, validated on read as well as on write — a row written
	 * past this class cannot put an unknown block type on a page.
	 *
	 * @return array
	 */
	public function get_structure(): array {

		if ( null === $this->structure ) {

			$raw = get_post_meta( $this->post_id, self::META_STRUCTURE, true );

			$this->structure = is_array( $raw ) && ! empty( $raw )
				? self::sanitize_structure( $raw )
				: self::sanitize_structure( self::new_structure( Checkoff_Block_Types::FALLBACK, $this->get_title() ) );
		}

		return $this->structure;
	}

	/**
	 * Replaces the structure.
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

		if ( $base_revision !== $this->get_revision() ) {
			return new WP_Error( 'checkoff_conflict', __( 'This checklist was changed somewhere else.', 'checkoff' ) );
		}

		// A round left over from the old rule is closed first, so its summary
		// is not lost to a changed rule.
		$this->rollover();

		$old_rule  = $this->get_structure()['recurrence'];
		$structure = self::sanitize_structure( $raw );
		$revision  = $this->get_revision() + 1;

		update_post_meta( $this->post_id, self::META_STRUCTURE, $structure );
		update_post_meta( $this->post_id, self::META_REVISION, $revision );

		$this->structure = $structure;

		// A changed rule renumbers the rounds; what is ticked belongs to the
		// round in force under the new one.
		if ( $old_rule !== $structure['recurrence'] ) {
			update_post_meta( $this->post_id, self::META_VALUES_ROUND, $this->recurrence()->current()['index'] );
		}

		// Values of removed entries go, the others are checked against the
		// new settings (a scale may have become shorter).
		$this->store_values( $this->revalidate_values( $this->get_raw_values() ) );

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

	/**
	 * Values as stored, unvalidated: one meta row per entry
	 * (`_cko_v_<entry ID>`), holding array( 'v' => value ) — wrapped, because
	 * WordPress stores a bare scalar as text and true would come back as "1".
	 *
	 * One row each, so that ticking writes exactly that entry's row. When all
	 * values lived in one array, two people ticking at the same moment each
	 * read the array, added their tick and wrote it back — and the second
	 * write erased the first tick.
	 *
	 * @return array
	 */
	private function get_raw_values(): array {

		$this->migrate_values();

		$values = array();
		$length = strlen( self::META_VALUE_PREFIX );

		foreach ( (array) get_post_meta( $this->post_id ) as $key => $rows ) {
			if ( str_starts_with( (string) $key, self::META_VALUE_PREFIX ) && isset( $rows[0] ) ) {
				$row = maybe_unserialize( $rows[0] );
				if ( is_array( $row ) && array_key_exists( 'v', $row ) ) {
					$values[ substr( (string) $key, $length ) ] = $row['v'];
				}
			}
		}

		return $values;
	}

	/**
	 * Moves the values of a checklist from before 1.2.0 (one array in
	 * `_cko_values`) into one row per entry, once.
	 *
	 * @return void
	 */
	private function migrate_values(): void {

		$legacy = get_post_meta( $this->post_id, self::META_VALUES, true );

		if ( '' === $legacy ) {
			return;
		}

		delete_post_meta( $this->post_id, self::META_VALUES );

		foreach ( is_array( $legacy ) ? $legacy : array() as $id => $value ) {
			if ( self::is_node_id( (string) $id ) ) {
				update_post_meta( $this->post_id, self::META_VALUE_PREFIX . $id, array( 'v' => $value ) );
			}
		}
	}

	/**
	 * Values as they are to be shown: only for entries that exist, and
	 * without those that have expired (weekly reset).
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return array<string, mixed>
	 */
	public function get_values( ?int $now = null ): array {

		if ( $this->values_are_stale( $now ) ) {
			return array();
		}

		$leaves = self::leaf_map( $this->get_structure() );
		$values = array();

		foreach ( $this->get_raw_values() as $id => $value ) {

			if ( ! isset( $leaves[ $id ] ) ) {
				continue;
			}

			$block = $leaves[ $id ];
			$value = Checkoff_Block_Types::get( $block['type'] )->value_for_display( $value, $block['settings'] );

			if ( null !== $value ) {
				$values[ $id ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Sets the value of one entry.
	 *
	 * @param string $item_id Leaf ID.
	 * @param mixed  $raw     Raw value; null or empty clears the entry.
	 * @return mixed|WP_Error The stored value (null when cleared).
	 */
	public function set_value( string $item_id, mixed $raw ): mixed {

		$leaves = self::leaf_map( $this->get_structure() );

		if ( ! isset( $leaves[ $item_id ] ) ) {
			return new WP_Error( 'checkoff_unknown_item', __( 'This entry no longer exists. Reload the page.', 'checkoff' ) );
		}

		$this->rollover();

		$this->migrate_values();

		$block = $leaves[ $item_id ];
		$value = Checkoff_Block_Types::get( $block['type'] )->sanitize_value( $raw, $block['settings'] );

		// Only this entry's row: no read-modify-write of the other entries.
		if ( null === $value ) {
			delete_post_meta( $this->post_id, self::META_VALUE_PREFIX . $item_id );
		} else {
			update_post_meta( $this->post_id, self::META_VALUE_PREFIX . $item_id, array( 'v' => $value ) );
		}

		$this->touch( true );

		return $value;
	}

	/**
	 * Clears every value.
	 *
	 * @return void
	 */
	public function reset_values(): void {
		$this->rollover();
		$this->store_values( array() );
		$this->touch( true );
	}

	/**
	 * Replaces all values: rows not in $values are deleted, changed ones
	 * written, unchanged ones left alone.
	 *
	 * @param array $values Values (entry ID => value).
	 * @return void
	 */
	private function store_values( array $values ): void {

		foreach ( $this->get_raw_values() as $id => $old ) {
			if ( ! array_key_exists( $id, $values ) ) {
				delete_post_meta( $this->post_id, self::META_VALUE_PREFIX . $id );
			} elseif ( $old === $values[ $id ] ) {
				unset( $values[ $id ] );
			}
		}

		foreach ( $values as $id => $value ) {
			if ( self::is_node_id( (string) $id ) ) {
				update_post_meta( $this->post_id, self::META_VALUE_PREFIX . $id, array( 'v' => $value ) );
			}
		}
	}

	/**
	 * Drops values of entries that no longer exist and re-checks the rest.
	 *
	 * @param array $values Stored values.
	 * @return array
	 */
	private function revalidate_values( array $values ): array {

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
	 * The round the stored values belong to (the current one when unknown).
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return int
	 */
	private function values_round( ?int $now = null ): int {

		$stored = get_post_meta( $this->post_id, self::META_VALUES_ROUND, true );

		return '' === $stored ? $this->recurrence()->current( $now )['index'] : (int) $stored;
	}

	/**
	 * Whether the stored values belong to an earlier round than the one in
	 * force — then they read as empty, and the next write closes that round.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return bool
	 */
	public function values_are_stale( ?int $now = null ): bool {

		$recurrence = $this->recurrence();

		return $recurrence->repeats() && $this->values_round( $now ) !== $recurrence->current( $now )['index'];
	}

	/**
	 * Closes a round that has ended: keeps a summary of it — how much was
	 * done and which entries stayed open — and empties the values for the
	 * round now in force. Nothing else of the old round is kept.
	 *
	 * Called before every write, before the checklist is shown and by the
	 * mail dispatch, so the summary exists whichever of them comes first.
	 *
	 * @param int|null $now Unix time, now by default.
	 * @return bool Whether a round was closed.
	 */
	public function rollover( ?int $now = null ): bool {

		$recurrence = $this->recurrence();

		if ( ! $recurrence->repeats() ) {
			return false;
		}

		$current = $recurrence->current( $now )['index'];
		$stored  = get_post_meta( $this->post_id, self::META_VALUES_ROUND, true );

		if ( '' === $stored ) {
			update_post_meta( $this->post_id, self::META_VALUES_ROUND, $current );
			return false;
		}

		if ( (int) $stored === $current ) {
			return false;
		}

		$values   = $this->displayable( $this->get_raw_values() );
		$progress = $this->progress( $values );
		$open     = self::open_items_of( $this->get_structure(), $values, $now );

		update_post_meta(
			$this->post_id,
			self::META_LAST_ROUND,
			array(
				'number' => (int) $stored + 1,
				'done'   => $progress['done'],
				'total'  => $progress['total'],
				'open'   => array_slice( $open, 0, self::LAST_ROUND_OPEN_MAX ),
				'more'   => max( 0, count( $open ) - self::LAST_ROUND_OPEN_MAX ),
				'closed' => $now ?? time(),
			)
		);

		// The round itself, for a limited time, where the site keeps a history.
		Checkoff_History::record( $this, (int) $stored, $values, $now ?? time() );

		$this->store_values( array() );
		update_post_meta( $this->post_id, self::META_VALUES_ROUND, $current );

		/**
		 * Fires after a round of a repeating checklist was closed.
		 *
		 * @param Checkoff_Checklist $checklist The checklist.
		 * @param int                $round     Number of the closed round (1-based).
		 */
		do_action( 'checkoff_round_closed', $this, (int) $stored + 1 );

		return true;
	}

	/**
	 * Summary of the last closed round, or null.
	 *
	 * @return array{number:int, done:int, total:int, open:array, more:int, closed:int}|null
	 */
	public function get_last_round(): ?array {

		$last = get_post_meta( $this->post_id, self::META_LAST_ROUND, true );

		return is_array( $last ) && isset( $last['number'] ) ? $last : null;
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

		foreach ( self::leaf_map( $structure, $values ) as $id => $block ) {
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
		return self::open_items_of( $this->get_structure(), $values ?? $this->get_values() );
	}

	/**
	 * Open entries of any structure with any values.
	 *
	 * @param array    $structure Structure.
	 * @param array    $values    Values as shown.
	 * @param int|null $now       Unix time, now by default (for due dates).
	 * @return array<int, array{label:string, path:string, note:string}>
	 */
	public static function open_items_of( array $structure, array $values, ?int $now = null ): array {

		$open = array();

		$walk = static function ( array $block, array $path ) use ( &$walk, &$open, $values, $now ): void {

			$type = Checkoff_Block_Types::get( (string) ( $block['type'] ?? '' ) );

			if ( '' !== $block['title'] ) {
				$path[] = $block['title'];
			}

			// Of a switch, only the variant in force has open entries.
			foreach ( self::children_in_force( $block, $values ) as $item ) {

				if ( 'block' === $item['kind'] ) {
					$walk( $item, $path );
					continue;
				}

				$part = $type->progress( $values[ $item['id'] ] ?? null, $block['settings'] );

				if ( $part['total'] > 0 && $part['done'] < $part['total'] ) {
					$open[] = array(
						'label' => '' !== $item['label'] ? $item['label'] : '—',
						'path'  => implode( ' › ', array_slice( $path, 1 ) ),
						'note'  => $type->describe_item( $item, $values[ $item['id'] ] ?? null, $block['settings'], $now ),
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
	 * Address that opens the checklist for ticking only.
	 *
	 * @param string $fallback Base URL when no checklist page is configured.
	 * @return string
	 */
	public function tick_url( string $fallback = '' ): string {
		return self::url_for( $this->get_tick_id(), $fallback );
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

		$this->rollover();

		$sync = $this->sync_state();

		$data = array(
			'id'        => Checkoff_Public_Id::format( $this->get_current_id() ),
			'access'    => $this->access,
			// Only for the holder of the public ID: a tick view need not know.
			'protected' => self::ACCESS_TICK !== $this->access && $this->is_protected(),
			'title'     => $this->get_title(),
			'structure' => $this->get_structure(),
			'values'    => (object) $sync['values'],
			'revision'  => $this->get_revision(),
			'url'       => $this->url( $fallback_url ),
			'round'     => $sync['round'],
			'last'      => $sync['last'],
			'state'     => $sync['state'],
		);

		// The tick link is for the owner to hand out; somebody holding only
		// the tick link never learns the ID that edits.
		if ( $this->can_edit() ) {
			$data['tick_id']  = Checkoff_Public_Id::format( $this->get_tick_id() );
			$data['tick_url'] = $this->tick_url( $fallback_url );

			// The past is the owner's: a tick view learns not even that it exists.
			if ( Checkoff_History::is_enabled() ) {
				$data['history'] = array(
					'rounds' => Checkoff_History::count( $this->post_id ),
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
	 * @return array{state:string, values:array, round:array, last:array|null}
	 */
	public function sync_state(): array {

		$values = $this->get_values();
		$round  = $this->round_info();
		$last   = $this->get_last_round();
		$last   = null === $last ? null : array(
			'number' => $last['number'],
			'done'   => $last['done'],
			'total'  => $last['total'],
		);

		return array(
			'state'  => md5( (string) wp_json_encode( array( $this->get_revision(), $values, $round, $last ) ) ),
			'values' => $values,
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

		if ( self::KIND_CHECKLIST !== ( $raw['kind'] ?? '' ) ) {
			$raw = self::upgrade_legacy( $raw );
		}

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

		return $structure;
	}

	/**
	 * Turns a tree from before 1.3.0, whose top level was a block, into a
	 * container of blocks.
	 *
	 * A top-level simple checklist gives up its sub-lists as stacked blocks;
	 * entries standing between them are gathered, in order, into simple
	 * blocks of their own. Any other top-level type becomes the one block of
	 * the container. Title, description and repetition move up to the
	 * container; entry IDs stay, so ticks, IDs and subscriptions are
	 * untouched. The gathering blocks get IDs derived from their first
	 * entry, so the same old tree always upgrades to the same new one —
	 * whether or not anybody saves it in between.
	 *
	 * @param array $raw Old top-level block.
	 * @return array Container (not yet validated).
	 */
	private static function upgrade_legacy( array $raw ): array {

		$container = array(
			'id'          => $raw['id'] ?? '',
			'kind'        => self::KIND_CHECKLIST,
			'title'       => $raw['title'] ?? '',
			'description' => $raw['description'] ?? '',
			'recurrence'  => $raw['recurrence'] ?? null,
			'items'       => array(),
		);

		$items = is_array( $raw['items'] ?? null ) ? $raw['items'] : array();
		$type  = is_string( $raw['type'] ?? null ) ? $raw['type'] : '';

		if ( Checkoff_Block_Types::FALLBACK !== Checkoff_Block_Types::get( $type )->key() ) {
			$container['items'][] = array(
				'id'       => 'b' . substr( (string) ( $raw['id'] ?? '' ), 0, 15 ),
				'kind'     => 'block',
				'type'     => $type,
				'title'    => '',
				'settings' => $raw['settings'] ?? array(),
				'items'    => $items,
			);
			return $container;
		}

		$group = null;

		foreach ( $items as $item ) {

			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( 'block' === ( $item['kind'] ?? '' ) ) {
				if ( null !== $group ) {
					$container['items'][] = $group;
					$group                = null;
				}
				$container['items'][] = $item;
				continue;
			}

			if ( null === $group ) {
				$group = array(
					'id'    => 'g' . substr( (string) ( $item['id'] ?? '' ), 0, 15 ),
					'kind'  => 'block',
					'type'  => Checkoff_Block_Types::FALLBACK,
					'title' => '',
					'items' => array(),
				);
			}

			$group['items'][] = $item;
		}

		if ( null !== $group ) {
			$container['items'][] = $group;
		}

		return $container;
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

		// A block that is nothing without sub-blocks (the switch) cannot sit
		// where the depth limit leaves no room for them.
		if ( $type->needs_child_level() && $depth >= self::max_depth() ) {
			$type = Checkoff_Block_Types::get( Checkoff_Block_Types::FALLBACK );
		}

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
		$items = $type->prepare_items( $items, $block['id'] );

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
	 * Everything that holds a value, by ID: every entry, and every block that
	 * holds one itself (the switch) — each with the type and settings that
	 * validate its value (a value-holding block's settings carry the number
	 * of its children as `_variants`).
	 *
	 * Given the values, only what is in force is mapped: of a switch, only
	 * the active variant. That is the map progress is counted on; without
	 * values (validation, cleaning up), everything.
	 *
	 * @param array      $block  A block or the container.
	 * @param array|null $values Values, to follow only the variants in force.
	 * @return array<string, array{type:string, settings:array}>
	 */
	public static function leaf_map( array $block, ?array $values = null ): array {

		$map = array();

		if ( 'block' === ( $block['kind'] ?? '' ) && Checkoff_Block_Types::get( (string) $block['type'] )->holds_value() ) {
			$children                     = self::child_blocks( $block );
			$map[ (string) $block['id'] ] = array(
				'type'     => $block['type'],
				'settings' => (array) $block['settings'] + array( '_variants' => count( $children ) ),
			);
		}

		foreach ( self::children_in_force( $block, $values ) as $item ) {

			if ( 'block' === $item['kind'] ) {
				$map += self::leaf_map( $item, $values );
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
	 * A block's items as they apply: all of them — or, for a block holding a
	 * value (the switch) when values are given, only the variant in force.
	 * Progress, open entries, mails and the history all walk this.
	 *
	 * @param array      $block  A block or the container.
	 * @param array|null $values Values, or null for everything.
	 * @return array
	 */
	public static function children_in_force( array $block, ?array $values = null ): array {

		if ( null === $values || 'block' !== ( $block['kind'] ?? '' ) ) {
			return $block['items'];
		}

		$type = Checkoff_Block_Types::get( (string) $block['type'] );

		if ( ! $type->holds_value() ) {
			return $block['items'];
		}

		$children = self::child_blocks( $block );

		if ( ! $children ) {
			return array();
		}

		return array( $children[ $type->active_child( $values[ $block['id'] ] ?? null, count( $children ) ) ] );
	}

	/**
	 * A block's sub-blocks, in order.
	 *
	 * @param array $block Block.
	 * @return array
	 */
	private static function child_blocks( array $block ): array {
		return array_values(
			array_filter(
				$block['items'],
				static function ( $item ): bool {
					return 'block' === ( $item['kind'] ?? '' );
				}
			)
		);
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
