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
 * Structure: a tree of blocks. Every block is
 *   { id, kind: "block", type, title, description, settings, items: [] }
 * and every entry of `items` is either a leaf
 *   { id, kind: "item", label }
 * or — where the block type supports children — another block.
 *
 * Values: a flat map of leaf ID => value, validated by the block type the
 * leaf belongs to.
 */
class Checkoff_Checklist {

	const META_PUBLIC_ID   = '_cko_public_id';
	const META_STRUCTURE   = '_cko_structure';
	const META_VALUES      = '_cko_values';
	const META_REVISION    = '_cko_revision';
	const META_LAST_ACCESS = '_cko_last_access';

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
	 * Constructor.
	 *
	 * @param int $post_id Post ID of a cko_checklist post.
	 */
	public function __construct( int $post_id ) {
		$this->post_id = $post_id;
	}

	/*
	---------------------------------------------------------------------
	 * Finding and creating
	 * ------------------------------------------------------------------
	 */

	/**
	 * Finds a checklist by its public ID — the only way a visitor can.
	 *
	 * @param mixed $public_id Raw public ID (any case, with or without dashes).
	 * @return self|null
	 */
	public static function find( mixed $public_id ): ?self {

		$public_id = Checkoff_Public_Id::normalize( $public_id );

		if ( '' === $public_id ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'        => Checkoff_Post_Types::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_PUBLIC_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $public_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return empty( $ids ) ? null : new self( (int) $ids[0] );
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
		update_post_meta( $post_id, self::META_STRUCTURE, self::new_block( $type, $title ) );
		update_post_meta( $post_id, self::META_VALUES, array() );
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

			$taken = get_posts(
				array(
					'post_type'        => Checkoff_Post_Types::POST_TYPE,
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => self::META_PUBLIC_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $candidate, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( empty( $taken ) ) {
				return $candidate;
			}
		}

		return '';
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
	---------------------------------------------------------------------
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
				: self::new_block( Checkoff_Block_Types::FALLBACK, $this->get_title() );
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

		$structure = self::sanitize_structure( $raw );
		$revision  = $this->get_revision() + 1;

		update_post_meta( $this->post_id, self::META_STRUCTURE, $structure );
		update_post_meta( $this->post_id, self::META_REVISION, $revision );

		$this->structure = $structure;

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
	 * Values as stored, unvalidated.
	 *
	 * @return array
	 */
	private function get_raw_values(): array {

		$values = get_post_meta( $this->post_id, self::META_VALUES, true );

		return is_array( $values ) ? $values : array();
	}

	/**
	 * Values as they are to be shown: only for entries that exist, and
	 * without those that have expired (weekly reset).
	 *
	 * @return array<string, mixed>
	 */
	public function get_values(): array {

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

		$block  = $leaves[ $item_id ];
		$value  = Checkoff_Block_Types::get( $block['type'] )->sanitize_value( $raw, $block['settings'] );
		$values = $this->get_raw_values();

		if ( null === $value ) {
			unset( $values[ $item_id ] );
		} else {
			$values[ $item_id ] = $value;
		}

		$this->store_values( $values );
		$this->touch( true );

		return $value;
	}

	/**
	 * Clears every value.
	 *
	 * @return void
	 */
	public function reset_values(): void {
		$this->store_values( array() );
		$this->touch( true );
	}

	/**
	 * Writes the values.
	 *
	 * @param array $values Values.
	 * @return void
	 */
	private function store_values( array $values ): void {
		update_post_meta( $this->post_id, self::META_VALUES, $values );
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
		return self::url_for( $this->get_public_id(), $fallback );
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
	 * URL of the page configured as the checklist page, or ''.
	 *
	 * @return string
	 */
	public static function page_url(): string {

		$page_id = (int) get_option( 'checkoff_checklist_page', 0 );

		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		return (string) get_permalink( $page_id );
	}

	/**
	 * Everything the frontend needs — and nothing it must not see: the post
	 * ID stays on the server.
	 *
	 * @param string $fallback_url Base URL when no checklist page is configured.
	 * @return array
	 */
	public function to_client( string $fallback_url = '' ): array {
		return array(
			'id'        => Checkoff_Public_Id::format( $this->get_public_id() ),
			'title'     => $this->get_title(),
			'structure' => $this->get_structure(),
			'values'    => (object) $this->get_values(),
			'revision'  => $this->get_revision(),
			'url'       => $this->url( $fallback_url ),
		);
	}

	/*
	---------------------------------------------------------------------
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

		$count = 0;
		$seen  = array();

		return self::sanitize_block( $raw, 1, $count, $seen );
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

			$block['items'][] = array(
				'id'    => self::claim_id( $item['id'] ?? '', $seen ),
				'kind'  => 'item',
				'label' => self::clean_text( $item['label'] ?? '', self::MAX_LABEL ),
			);
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
	 * Every leaf of a tree with the block it belongs to.
	 *
	 * @param array $block Top-level block.
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
			'blocks' => 1,
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
