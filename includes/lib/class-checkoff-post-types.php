<?php
/**
 * Post types file.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the checklist post type and its admin list.
 *
 * A checklist is a post for storage only. It has no public page of its own —
 * it is shown wherever a shortcode asks for it by its public ID — and so the
 * type is not publicly queryable, has no permalink and no archive, and the
 * post ID never reaches the frontend.
 */
class Checkoff_Post_Types {

	const POST_TYPE = 'cko_checklist';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'register_checklist_post_type' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'admin_column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_last_access' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );

		// A page showing a checklist carries its secret in the address, so it
		// must never end up in a search index, a cache or a referrer.
		add_filter( 'wp_robots', array( $this, 'noindex_checklist_views' ) );
		add_action( 'template_redirect', array( $this, 'protect_checklist_views' ) );
	}

	/**
	 * The capability that opens CheckOff in wp-admin: checklists (with their
	 * secret IDs), subscriptions (with e-mail addresses) and settings.
	 * Administrators only by default — the default capabilities of posts
	 * would let every editor read every visitor's IDs.
	 *
	 * @return string
	 */
	public static function admin_capability(): string {

		/**
		 * Filters the capability that opens CheckOff in wp-admin.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'checkoff_admin_capability', 'manage_options' );
	}

	/**
	 * Capabilities of the plugin's post types: every one is the admin
	 * capability, except creating — checklists and subscriptions are made
	 * on the frontend; one made in wp-admin would have no ID and no
	 * structure.
	 *
	 * @return array<string, string>
	 */
	public static function admin_capabilities(): array {

		$cap  = self::admin_capability();
		$caps = array( 'create_posts' => 'do_not_allow' );

		foreach ( array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts' ) as $primitive ) {
			$caps[ $primitive ] = $cap;
		}

		return $caps;
	}

	/**
	 * Headers of a page showing a checklist: the address carries the ID, so
	 * the page must not be kept by any cache (page caches, proxies, the
	 * browser's back-forward cache across users of one device), not be
	 * indexed, and not pass its address on to sites it links to.
	 *
	 * @return void
	 */
	public function protect_checklist_views(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		if ( ! isset( $_GET[ Checkoff_Checklist::QUERY_VAR ] ) || headers_sent() ) {
			return;
		}

		// Understood by the common caching plugins.
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCDN' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the names the caching plugins read.
			}
		}

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * Registers the checklist post type.
	 *
	 * @return void
	 */
	public function register_checklist_post_type(): void {

		$labels = array(
			'name'               => _x( 'Checklists', 'Post type general name', 'checkoff' ),
			'singular_name'      => _x( 'Checklist', 'Post type singular name', 'checkoff' ),
			'menu_name'          => _x( 'CheckOff', 'Admin Menu text', 'checkoff' ),
			'all_items'          => __( 'All Checklists', 'checkoff' ),
			'edit_item'          => __( 'Edit Checklist', 'checkoff' ),
			'view_item'          => __( 'View Checklist', 'checkoff' ),
			'search_items'       => __( 'Search Checklists', 'checkoff' ),
			'not_found'          => __( 'No checklists found.', 'checkoff' ),
			'not_found_in_trash' => __( 'No checklists found in Trash.', 'checkoff' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'menu_position'       => 30,
				'menu_icon'           => 'dashicons-yes-alt',
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::admin_capabilities(),
			)
		);
	}

	/**
	 * Columns of the admin list.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public function admin_columns( array $columns ): array {

		$new = array();

		foreach ( $columns as $key => $label ) {

			if ( 'date' === $key ) {
				$new['cko_public_id']   = __( 'Checklist ID', 'checkoff' );
				$new['cko_size']        = __( 'Size', 'checkoff' );
				$new['cko_last_access'] = __( 'Last used', 'checkoff' );
			}

			$new[ $key ] = $label;
		}

		return $new;
	}

	/**
	 * Content of the plugin's admin columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function admin_column_content( string $column, int $post_id ): void {

		$checklist = new Checkoff_Checklist( $post_id );

		switch ( $column ) {

			case 'cko_public_id':
				echo '<code>' . esc_html( Checkoff_Public_Id::format( $checklist->get_public_id() ) ) . '</code>';
				if ( Checkoff_Templates::is_template( $post_id ) ) {
					echo ' <span class="cko-admin-template">' . esc_html__( 'Template', 'checkoff' ) . '</span>';
				}
				if ( $checklist->is_protected() ) {
					$label = Checkoff_Post_Meta::level_label( $checklist->get_level() );
					echo ' <span class="dashicons dashicons-' . ( Checkoff_Checklist::LEVEL_PRIVATE === $checklist->get_level() ? 'hidden' : 'lock' ) . '" title="' . esc_attr( $label ) . '"></span><span class="screen-reader-text">' . esc_html( $label ) . '</span>';
				}
				if ( $checklist->is_listed() ) {
					echo ' <span class="cko-admin-template">' . esc_html__( 'Public', 'checkoff' ) . '</span>';
				}
				break;

			case 'cko_size':
				$counts = Checkoff_Checklist::count_nodes( $checklist->get_structure() );
				echo esc_html(
					sprintf(
						/* translators: 1: number of entries, 2: number of lists. */
						__( '%1$s entries in %2$s lists', 'checkoff' ),
						number_format_i18n( $counts['items'] ),
						number_format_i18n( $counts['blocks'] )
					)
				);
				break;

			case 'cko_last_access':
				$time = $checklist->get_last_access();
				echo $time > 0
					? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) )
					: '—';
				break;
		}
	}

	/**
	 * Makes the last-used column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( array $columns ): array {
		$columns['cko_last_access'] = 'cko_last_access';
		return $columns;
	}

	/**
	 * Sorts the admin list by last use when asked to.
	 *
	 * @param WP_Query $query Query about to run.
	 * @return void
	 */
	public function sort_by_last_access( WP_Query $query ): void {

		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'cko_last_access' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', Checkoff_Checklist::META_LAST_ACCESS );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Row actions: no "View" (there is no page of its own), and "Quick Edit"
	 * would only rename the post behind the top-level block's back.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( array $actions, WP_Post $post ): array {

		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		unset( $actions['view'], $actions['inline hide-if-no-js'] );

		$checklist = new Checkoff_Checklist( (int) $post->ID );

		if ( '' !== Checkoff_Checklist::page_url() && '' !== $checklist->get_public_id() ) {
			$actions['cko_open'] = '<a href="' . esc_url( $checklist->url() ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Open on the website', 'checkoff' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Adds noindex, nofollow to any page opened with a checklist ID.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function noindex_checklist_views( array $robots ): array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		if ( isset( $_GET[ Checkoff_Checklist::QUERY_VAR ] ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'] );
		}

		return $robots;
	}
}
