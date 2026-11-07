<?php
/**
 * The page checklists are shown on.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which page links to checklists lead to.
 *
 * Every link the plugin makes — in the REST answers, the mails, the share
 * menu, the forms — is that page plus `?cko=<ID>`. The page is the one
 * chosen under Settings → General; without a choice the links used to lead
 * to the front page, where nothing showed a checklist as soon as it was an
 * Elementor page without the shortcode.
 *
 * So, without a choice, the first published page that can show a checklist
 * is used: one containing `[cko_app]`, else `[cko_checklist]` without a
 * fixed `id` — in its content or in its Elementor data — or using a page
 * template that adds `[cko_app]` itself (filter
 * `checkoff_checklist_page_templates`; the CheckOff child theme's by
 * default). The result is cached for a day and forgotten whenever a page is
 * saved, trashed or deleted. While nothing is chosen, administrators see a
 * notice saying where the links lead and how to choose the page.
 */
class Checkoff_Checklist_Page {

	const OPTION    = 'checkoff_checklist_page';
	const TRANSIENT = 'checkoff_detected_page';

	/**
	 * Hooks in.
	 */
	public function __construct() {
		add_action( 'save_post_page', array( __CLASS__, 'forget' ) );
		add_action( 'trashed_post', array( __CLASS__, 'forget' ) );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The page chosen in the settings, if it is published; else 0.
	 *
	 * @return int
	 */
	public static function chosen_id(): int {

		$page_id = (int) get_option( self::OPTION, 0 );

		return ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) ? $page_id : 0;
	}

	/**
	 * The page links lead to: the chosen one, else the one found; 0 if none.
	 *
	 * @return int
	 */
	public static function id(): int {
		$chosen = self::chosen_id();
		return $chosen > 0 ? $chosen : self::detected_id();
	}

	/**
	 * Address of that page, or '' when there is none.
	 *
	 * @return string
	 */
	public static function url(): string {
		$page_id = self::id();
		return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * The first published page that can show a checklist (cached); 0 if none.
	 *
	 * @return int
	 */
	public static function detected_id(): int {

		$cached = get_transient( self::TRANSIENT );

		if ( false !== $cached && ( 0 === (int) $cached || 'publish' === get_post_status( (int) $cached ) ) ) {
			return (int) $cached;
		}

		$page_id = self::detect();
		set_transient( self::TRANSIENT, $page_id, DAY_IN_SECONDS );

		return $page_id;
	}

	/**
	 * Forgets the page found, so the next link looks again.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Looks for the page: `[cko_app]` first, then `[cko_checklist]` without
	 * a fixed ID, in the content or the Elementor data, or a page template
	 * that adds `[cko_app]`. Lowest menu order, then oldest, wins.
	 *
	 * @return int
	 */
	public static function detect(): int {

		global $wpdb;

		/**
		 * Page templates that show `[cko_app]` by themselves.
		 *
		 * @param string[] $templates Template file names, as stored in _wp_page_template.
		 */
		$templates = array_filter( (array) apply_filters( 'checkoff_checklist_page_templates', array( 'page-templates/checkoff-app.php' ) ), 'is_string' );

		$template_clause = '';
		if ( $templates ) {
			$template_clause = ' OR t.meta_value IN (' . implode( ',', array_fill( 0, count( $templates ), '%s' ) ) . ')';
		}

		$like_app  = '%' . $wpdb->esc_like( '[cko_app' ) . '%';
		$like_list = '%' . $wpdb->esc_like( '[cko_checklist' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- one search, cached above; the template placeholders are built from the list's length.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_content, e.meta_value AS elementor, t.meta_value AS template
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} e ON ( e.post_id = p.ID AND e.meta_key = '_elementor_data' )
				LEFT JOIN {$wpdb->postmeta} t ON ( t.post_id = p.ID AND t.meta_key = '_wp_page_template' )
				WHERE p.post_type = 'page' AND p.post_status = 'publish'
				AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR e.meta_value LIKE %s OR e.meta_value LIKE %s{$template_clause} )
				ORDER BY p.menu_order ASC, p.ID ASC
				LIMIT 50",
				array_merge( array( $like_app, $like_list, $like_app, $like_list ), $templates )
			)
		);
		// phpcs:enable

		$fallback = 0;

		foreach ( (array) $rows as $row ) {

			$text = (string) $row->post_content . ' ' . (string) $row->elementor;

			if ( str_contains( $text, '[cko_app' ) || in_array( (string) $row->template, $templates, true ) ) {
				return (int) $row->ID;
			}

			// A checklist shortcode with a fixed ID shows that list only.
			if ( 0 === $fallback && preg_match( '/\[cko_checklist(?![^\]]*\bid\s*=)/', $text ) ) {
				$fallback = (int) $row->ID;
			}
		}

		return $fallback;
	}

	/**
	 * Tells administrators while no checklist page is chosen — on the
	 * dashboard, the plugins screen and CheckOff's own screens.
	 *
	 * @return void
	 */
	public function notice(): void {

		if ( self::chosen_id() > 0 || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! ( in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || Checkoff_Post_Types::POST_TYPE === $screen->post_type || str_contains( (string) $screen->id, 'checkoff' ) ) ) {
			return;
		}

		$settings = admin_url( 'edit.php?post_type=' . Checkoff_Post_Types::POST_TYPE . '&page=checkoff_settings' );
		$found    = self::detected_id();

		$text = $found > 0
			? sprintf(
				/* translators: %s: page title. */
				__( 'No checklist page is chosen. Links to checklists currently lead to "%s", the page found with the CheckOff shortcode. Choose the page in the settings so that this stays so.', 'checkoff' ),
				get_the_title( $found )
			)
			: __( 'No checklist page is chosen, and no page with [cko_app] was found: links to checklists lead to the front page, where no checklist appears. Create a page with [cko_app] and choose it in the settings.', 'checkoff' );

		printf(
			'<div class="notice notice-warning"><p><strong>CheckOff:</strong> %1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $text ),
			esc_url( $settings ),
			esc_html__( 'Choose the checklist page', 'checkoff' )
		);
	}
}
