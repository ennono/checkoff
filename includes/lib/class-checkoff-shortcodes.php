<?php
/**
 * Shortcodes and AJAX handlers for the CheckOff plugin.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all frontend shortcodes and AJAX handlers.
 *
 * The shortcodes are components: they print markup with `cko-*` classes and
 * leave page structure and look to the theme. The checklist itself is drawn
 * by assets/js/frontend.js from the data the shortcode embeds; every change a
 * visitor makes goes back through one of the AJAX handlers below, which find
 * the checklist by its public ID and let Checkoff_Checklist validate it.
 */
class Checkoff_Shortcodes {

	/**
	 * Nonce action of all frontend requests.
	 */
	const NONCE_ACTION = 'checkoff_frontend';

	/**
	 * Largest structure payload accepted, in bytes.
	 */
	const MAX_PAYLOAD = 512000;

	/**
	 * Registers all shortcodes and AJAX action hooks.
	 */
	public function __construct() {

		add_shortcode( 'cko_app', array( $this, 'cko_app_callback' ) );
		add_shortcode( 'cko_checklist', array( $this, 'cko_checklist_callback' ) );
		add_shortcode( 'cko_create', array( $this, 'cko_create_callback' ) );
		add_shortcode( 'cko_open', array( $this, 'cko_open_callback' ) );
		add_shortcode( 'cko_recent', array( $this, 'cko_recent_callback' ) );
		add_shortcode( 'cko_templates', array( $this, 'cko_templates_callback' ) );
		add_shortcode( 'cko_lists', array( $this, 'cko_lists_callback' ) );

		$this->register_ajax();
	}

	/**
	 * AJAX endpoints, for visitors and logged-in users alike.
	 *
	 * @return void
	 */
	private function register_ajax(): void {

		$endpoints = array(
			'cko_create'          => 'ajax_create',
			'cko_save_structure'  => 'ajax_save_structure',
			'cko_set_value'       => 'ajax_set_value',
			'cko_reset_values'    => 'ajax_reset_values',
			'cko_delete'          => 'ajax_delete',
			'cko_poll'            => 'ajax_poll',
			'cko_history'         => 'ajax_history',
			'cko_history_delete'  => 'ajax_history_delete',
			'cko_history_round'   => 'ajax_history_round',
			'cko_upcoming_round'  => 'ajax_upcoming_round',
			'cko_unlock'          => 'ajax_unlock',
			'cko_lock'            => 'ajax_lock',
			'cko_set_password'    => 'ajax_set_password',
			'cko_remove_password' => 'ajax_remove_password',
			'cko_set_access'      => 'ajax_set_access',
			'cko_set_listed'      => 'ajax_set_listed',
		);

		foreach ( $endpoints as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings read by the shortcodes and handlers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Whether the current visitor may create checklists.
	 *
	 * @return bool
	 */
	public static function can_create(): bool {
		return is_user_logged_in() || 'on' === (string) get_option( 'checkoff_guests_can_create', 'on' );
	}

	/**
	 * Checklists one visitor may create per hour (0 = unlimited).
	 *
	 * @return int
	 */
	public static function create_limit(): int {
		return Checkoff_Quota::limit( Checkoff_Quota::CREATE );
	}

	/**
	 * Seconds between two live updates of an open checklist; 0 = off.
	 *
	 * @return int
	 */
	public static function sync_interval(): int {
		$seconds = (int) get_option( 'checkoff_sync_interval', 20 );
		return $seconds <= 0 ? 0 : max( 5, min( 300, $seconds ) );
	}

	/**
	 * Unknown IDs one visitor may try per hour before lookups are refused.
	 *
	 * @return int
	 */
	public static function lookup_limit(): int {
		return max( 0, (int) get_option( 'checkoff_failed_lookups_per_hour', 20 ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------
	 */

	/**
	 * [cko_app templates=""] — everything a checklist page needs in one
	 * shortcode: the checklist when the address carries an ID, otherwise the
	 * forms to create and open one and the checklists recently opened on
	 * this device. `templates` is passed on to the create form.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_app_callback( $atts = array() ): string {

		$atts = shortcode_atts( array( 'templates' => '' ), $atts, 'cko_app' );

		if ( '' !== $this->requested_id() ) {
			return $this->cko_checklist_callback( array() );
		}

		return '<div class="cko-app">'
			. $this->cko_create_callback( array( 'templates' => $atts['templates'] ) )
			. $this->cko_open_callback( array() )
			. $this->cko_recent_callback( array() )
			. '</div>';
	}

	/**
	 * [cko_checklist id=""] — one checklist, by the attribute or by the
	 * address (?cko=…). The attribute is for a theme that embeds a fixed list.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_checklist_callback( $atts = array() ): string {

		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'cko_checklist' );

		$raw_id = '' !== (string) $atts['id'] ? (string) $atts['id'] : $this->requested_id();

		if ( '' === $raw_id ) {
			return '';
		}

		checkoff()->enqueue_frontend_assets();

		$checklist = self::lookup( $raw_id );

		if ( is_wp_error( $checklist ) ) {
			return '<div class="cko-app cko-app--missing">'
				. '<p class="cko-message cko-message--error" role="alert">' . esc_html( $checklist->get_error_message() ) . '</p>'
				. $this->cko_open_callback( array() )
				. '</div>';
		}

		$checklist->touch();

		$data  = $checklist->to_client( $this->current_url() );
		$title = $data['title'] ?? __( 'Private checklist', 'checkoff' );

		$html  = '<div class="cko-app cko-checklist" data-cko-checklist>';
		$html .= '<script type="application/json" class="cko-data">' . self::json( $data ) . '</script>';
		$html .= '<h2 class="cko-checklist__title">' . esc_html( $title ) . '</h2>';
		$html .= '<noscript><p class="cko-message">' . esc_html__( 'Please enable JavaScript to use this checklist.', 'checkoff' ) . '</p></noscript>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * [cko_create title="" button="" templates="" template=""] — the form that
	 * starts a new checklist.
	 *
	 * - `templates="no"` hides the site's templates (default: shown).
	 * - `template="<key>"` preselects one; with `templates="no"` the form
	 *   offers no choice and always starts from that template.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_create_callback( $atts = array() ): string {

		$atts = shortcode_atts(
			array(
				'title'     => __( 'New checklist', 'checkoff' ),
				'button'    => __( 'Create checklist', 'checkoff' ),
				'template'  => '',
				'templates' => '',
			),
			$atts,
			'cko_create'
		);

		checkoff()->enqueue_frontend_assets();

		$html = '<section class="cko-create">';

		if ( '' !== $atts['title'] ) {
			$html .= '<h2 class="cko-create__title">' . esc_html( $atts['title'] ) . '</h2>';
		}

		if ( ! self::can_create() ) {
			$html .= '<p class="cko-message">' . wp_kses(
				sprintf(
					/* translators: %s: login URL. */
					__( 'Please <a href="%s">log in</a> to create a checklist.', 'checkoff' ),
					esc_url( wp_login_url( $this->current_url() ) )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';

			return $html . '</section>';
		}

		$uid = wp_unique_id( 'cko-create-' );

		$html .= '<form class="cko-create__form" data-cko-create>';
		$html .= '<p class="cko-field">';
		$html .= '<label for="' . esc_attr( $uid ) . '-title">' . esc_html__( 'Name', 'checkoff' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( $uid ) . '-title" name="title" maxlength="' . esc_attr( (string) Checkoff_Checklist::MAX_TITLE ) . '" placeholder="' . esc_attr__( 'e.g. Moving house', 'checkoff' ) . '" />';
		$html .= '</p>';

		// A checklist has no type: it starts as one empty simple block and
		// gets its building blocks while editing. The only choice left is a
		// template, offered when the site has any and the shortcode allows.
		$offered   = self::attribute_flag( (string) $atts['templates'], Checkoff_Templates::shown_by_default() );
		$all       = Checkoff_Templates::all();
		$templates = $offered ? $all : array();
		$preselect = sanitize_key( (string) $atts['template'] );
		$preselect = in_array( $preselect, array_column( $all, 'key' ), true ) ? $preselect : '';

		if ( ! $offered && '' !== $preselect ) {
			// No choice shown, one template named: the form starts from it.
			$html .= '<input type="hidden" name="type" value="' . esc_attr( Checkoff_Templates::CHOICE_PREFIX . $preselect ) . '" />';
		} elseif ( ! empty( $templates ) ) {
			$html .= '<fieldset class="cko-create__types cko-create__templates">';
			$html .= '<legend>' . esc_html__( 'Template (optional)', 'checkoff' ) . '</legend>';

			$html .= '<label class="cko-create__type cko-create__type--empty">';
			$html .= '<input type="radio" name="type" value="' . esc_attr( Checkoff_Block_Types::FALLBACK ) . '"' . checked( $preselect, '', false ) . ' /> ';
			$html .= '<span class="cko-create__type-icon" aria-hidden="true">+</span> ';
			$html .= '<span class="cko-create__type-label">' . esc_html__( 'Empty checklist', 'checkoff' ) . '</span>';
			$html .= '<span class="cko-create__type-desc">' . esc_html__( 'Add the building blocks yourself.', 'checkoff' ) . '</span>';
			$html .= '</label>';

			foreach ( $templates as $template ) {
				$html .= '<label class="cko-create__type cko-create__type--template">';
				$html .= '<input type="radio" name="type" value="' . esc_attr( Checkoff_Templates::CHOICE_PREFIX . $template['key'] ) . '"' . checked( $preselect, $template['key'], false ) . ' data-title="' . esc_attr( $template['title'] ) . '" /> ';
				$html .= '<span class="cko-create__type-icon" aria-hidden="true">📋</span> ';
				$html .= '<span class="cko-create__type-label">' . esc_html( $template['title'] ) . '</span>';
				$html .= '<span class="cko-create__type-desc">' . esc_html( self::template_summary( $template ) ) . '</span>';
				$html .= '</label>';
			}

			$html .= '</fieldset>';
		} else {
			$html .= '<input type="hidden" name="type" value="' . esc_attr( Checkoff_Block_Types::FALLBACK ) . '" />';
		}

		$html .= '<p class="cko-create__actions"><button type="submit" class="cko-button cko-button--primary">' . esc_html( $atts['button'] ) . '</button></p>';
		$html .= '<p class="cko-message" data-cko-message role="status" aria-live="polite"></p>';
		$html .= '</form>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * [cko_open title="" button=""] — the form that opens a checklist by its
	 * ID. A plain GET form: it works without JavaScript and leads to the
	 * checklist page (or this page, when none is configured).
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_open_callback( $atts = array() ): string {

		$atts = shortcode_atts(
			array(
				'title'  => __( 'Open a checklist', 'checkoff' ),
				'button' => __( 'Open', 'checkoff' ),
			),
			$atts,
			'cko_open'
		);

		checkoff()->enqueue_frontend_assets();

		$action = Checkoff_Checklist::page_url();
		$action = '' !== $action ? $action : remove_query_arg( Checkoff_Checklist::QUERY_VAR, $this->current_url() );
		$uid    = wp_unique_id( 'cko-open-' );

		$html = '<section class="cko-open">';

		if ( '' !== $atts['title'] ) {
			$html .= '<h2 class="cko-open__title">' . esc_html( $atts['title'] ) . '</h2>';
		}

		$html .= '<form class="cko-open__form" method="get" action="' . esc_url( $action ) . '">';
		$html .= $this->hidden_query_fields( $action );
		$html .= '<p class="cko-field">';
		$html .= '<label for="' . esc_attr( $uid ) . '">' . esc_html__( 'Checklist ID', 'checkoff' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( $uid ) . '" name="' . esc_attr( Checkoff_Checklist::QUERY_VAR ) . '" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="' . esc_attr( 'abcd-efgh-jkmn' ) . '" required />';
		$html .= '</p>';
		$html .= '<p class="cko-open__actions"><button type="submit" class="cko-button">' . esc_html( $atts['button'] ) . '</button></p>';
		$html .= '</form>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * [cko_templates title="" button=""] — the site's templates as cards,
	 * each with a button that creates the visitor's own copy.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_templates_callback( $atts = array() ): string {

		$atts = shortcode_atts(
			array(
				'title'  => __( 'Templates', 'checkoff' ),
				'button' => __( 'Use this template', 'checkoff' ),
			),
			$atts,
			'cko_templates'
		);

		$templates = Checkoff_Templates::all();

		if ( empty( $templates ) ) {
			return '';
		}

		checkoff()->enqueue_frontend_assets();

		$html = '<section class="cko-templates">';

		if ( '' !== $atts['title'] ) {
			$html .= '<h2 class="cko-templates__title">' . esc_html( $atts['title'] ) . '</h2>';
		}

		if ( ! self::can_create() ) {
			$html .= '<p class="cko-message">' . esc_html__( 'Please log in to create a checklist.', 'checkoff' ) . '</p>';
		}

		$html .= '<ul class="cko-templates__list">';

		foreach ( $templates as $template ) {
			$html .= '<li class="cko-template">';
			$html .= '<h3 class="cko-template__title">' . esc_html( $template['title'] ) . '</h3>';

			if ( '' !== $template['description'] ) {
				$html .= '<p class="cko-template__desc">' . nl2br( esc_html( $template['description'] ) ) . '</p>';
			}

			$html .= '<p class="cko-template__meta">' . esc_html( self::template_summary( $template, false ) ) . '</p>';

			if ( self::can_create() ) {
				$html .= '<form class="cko-template__form" data-cko-create>'
					. '<input type="hidden" name="type" value="' . esc_attr( Checkoff_Templates::CHOICE_PREFIX . $template['key'] ) . '" />'
					. '<button type="submit" class="cko-button cko-button--primary">' . esc_html( $atts['button'] ) . '</button>'
					. '<span class="cko-message" data-cko-message role="status" aria-live="polite"></span>'
					. '</form>';
			}

			$html .= '</li>';
		}

		$html .= '</ul></section>';

		return $html;
	}

	/**
	 * A template in one line: description (optional), size, repetition.
	 *
	 * @param array $template       Template (see Checkoff_Templates::all()).
	 * @param bool  $with_description Lead with the description.
	 * @return string
	 */
	private static function template_summary( array $template, bool $with_description = true ): string {

		$parts = array();

		if ( $with_description && '' !== $template['description'] ) {
			$parts[] = wp_trim_words( $template['description'], 18 );
		}

		$parts[] = sprintf(
			/* translators: %s: number of entries. */
			_n( '%s entry', '%s entries', $template['items'], 'checkoff' ),
			number_format_i18n( $template['items'] )
		);

		if ( '' !== $template['repetition'] ) {
			$parts[] = $template['repetition'];
		}

		return implode( ' · ', $parts );
	}

	/**
	 * [cko_recent title=""] — the checklists opened on this device. Kept in
	 * the browser's localStorage only; the server never learns of the list.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_recent_callback( $atts = array() ): string {

		$atts = shortcode_atts(
			array( 'title' => __( 'Recently opened', 'checkoff' ) ),
			$atts,
			'cko_recent'
		);

		checkoff()->enqueue_frontend_assets();

		$base = Checkoff_Checklist::page_url();
		$base = '' !== $base ? $base : remove_query_arg( Checkoff_Checklist::QUERY_VAR, $this->current_url() );

		$html = '<section class="cko-recent" data-cko-recent data-base-url="' . esc_url( $base ) . '" hidden>';

		if ( '' !== $atts['title'] ) {
			$html .= '<h2 class="cko-recent__title">' . esc_html( $atts['title'] ) . '</h2>';
		}

		$html .= '<ul class="cko-recent__list"></ul>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * [cko_lists title="" limit="20" empty=""] — the checklists their owners
	 * show publicly ({@see Checkoff_Directory}), most recently used first.
	 * Each links to the list: everybody may view and tick it there, editing
	 * needs its password.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_lists_callback( $atts = array() ): string {

		$atts = shortcode_atts(
			array(
				'title' => __( 'Public checklists', 'checkoff' ),
				'limit' => 20,
				'empty' => __( 'No checklist is shown publicly yet.', 'checkoff' ),
			),
			$atts,
			'cko_lists'
		);

		if ( ! Checkoff_Directory::is_enabled() ) {
			return '';
		}

		checkoff()->enqueue_frontend_assets();

		$fallback = remove_query_arg( Checkoff_Checklist::QUERY_VAR, $this->current_url() );
		$lists    = Checkoff_Directory::lists( (int) $atts['limit'] );

		$html = '<section class="cko-lists">';

		if ( '' !== $atts['title'] ) {
			$html .= '<h2 class="cko-lists__title">' . esc_html( $atts['title'] ) . '</h2>';
		}

		if ( empty( $lists ) ) {
			return $html . ( '' !== $atts['empty'] ? '<p class="cko-message">' . esc_html( $atts['empty'] ) . '</p>' : '' ) . '</section>';
		}

		$html .= '<ul class="cko-lists__list">';

		foreach ( $lists as $checklist ) {
			$entry = Checkoff_Directory::entry( $checklist, $fallback );
			$done  = $entry['progress']['done'];
			$total = $entry['progress']['total'];

			$html .= '<li class="cko-listing">';
			$html .= '<a class="cko-listing__link" href="' . esc_url( $entry['url'] ) . '">' . esc_html( $entry['title'] ) . '</a>';

			if ( '' !== $entry['description'] ) {
				$html .= '<p class="cko-listing__desc">' . esc_html( wp_trim_words( $entry['description'], 30 ) ) . '</p>';
			}

			$html .= '<p class="cko-listing__meta">';
			$html .= '<span class="cko-listing__progress">' . esc_html(
				sprintf(
					/* translators: 1: entries done, 2: entries in all. */
					__( '%1$s of %2$s done', 'checkoff' ),
					number_format_i18n( $done ),
					number_format_i18n( $total )
				)
			) . '</span>';

			if ( $entry['repeats'] ) {
				$html .= ' · <span class="cko-listing__repeats">' . esc_html__( 'repeats', 'checkoff' ) . '</span>';
			}

			$html .= '</p>';
			$html .= '<progress class="cko-listing__bar" max="' . esc_attr( (string) max( 1, $total ) ) . '" value="' . esc_attr( (string) $done ) . '"></progress>';
			$html .= '</li>';
		}

		$html .= '</ul></section>';

		return $html;
	}

	/*
	 * ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------
	 */

	/**
	 * Creates a checklist and answers with its address.
	 *
	 * @return void
	 */
	public function ajax_create(): void {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : Checkoff_Block_Types::FALLBACK;
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$checklist = self::create_checklist( $title, $type );

		if ( is_wp_error( $checklist ) ) {
			self::send_retry_after( $checklist );
			wp_send_json_error( array( 'message' => $checklist->get_error_message() ), self::error_status( $checklist ) );
		}

		// Only an address on this site may serve as the base of the link.
		$fallback = wp_validate_redirect( remove_query_arg( Checkoff_Checklist::QUERY_VAR, $page_url ), '' );

		wp_send_json_success(
			array(
				'id'   => Checkoff_Public_Id::format( $checklist->get_public_id() ),
				'url'  => $checklist->url( $fallback ),
				// A copy of a template is ready to use; an empty list needs
				// building first.
				'edit' => ! str_starts_with( $type, Checkoff_Templates::CHOICE_PREFIX ),
			)
		);
	}

	/**
	 * Creates a checklist for the current visitor — the one place that
	 * decides whether they may (login setting, hourly limit), shared by the
	 * create form and the REST API.
	 *
	 * @param string $title  Title; empty for the default (or the template's).
	 * @param string $choice A block key for an empty list, or tpl:<key> for a
	 *                       copy of a template.
	 * @return Checkoff_Checklist|WP_Error The error carries an HTTP status.
	 */
	public static function create_checklist( string $title, string $choice = Checkoff_Block_Types::FALLBACK ): Checkoff_Checklist|WP_Error {

		if ( ! self::can_create() ) {
			return new WP_Error( 'checkoff_login_required', __( 'Please log in to create a checklist.', 'checkoff' ), array( 'status' => 403 ) );
		}

		$quota = Checkoff_Quota::take( Checkoff_Quota::CREATE );

		if ( is_wp_error( $quota ) ) {
			return $quota;
		}

		$checklist = str_starts_with( $choice, Checkoff_Templates::CHOICE_PREFIX )
			? Checkoff_Templates::create_from( substr( $choice, strlen( Checkoff_Templates::CHOICE_PREFIX ) ), $title )
			: Checkoff_Checklist::create( $title, sanitize_key( $choice ) );

		if ( is_wp_error( $checklist ) ) {
			$data = $checklist->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$checklist->add_data( array( 'status' => 'checkoff_template' === $checklist->get_error_code() ? 404 : 500 ) );
			}
			return $checklist;
		}

		return $checklist;
	}

	/**
	 * A yes/no shortcode attribute: "no", "false", "0", "off" (and "nein")
	 * are no, anything else yes, an empty one the default.
	 *
	 * @param string $value   Attribute value.
	 * @param bool   $default Value when the attribute is not given.
	 * @return bool
	 */
	public static function attribute_flag( string $value, bool $default ): bool {

		$value = strtolower( trim( $value ) );

		if ( '' === $value ) {
			return $default;
		}

		return ! in_array( $value, array( 'no', 'false', '0', 'off', 'nein' ), true );
	}

	/**
	 * The HTTP status an error carries, 400 when it carries none.
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	public static function error_status( WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
	}

	/**
	 * Saves the structure of a checklist.
	 *
	 * @return void
	 */
	public function ajax_save_structure(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_checklist().
		$raw      = isset( $_POST['structure'] ) ? (string) wp_unslash( $_POST['structure'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated by Checkoff_Checklist::sanitize_structure().
		$revision = isset( $_POST['revision'] ) ? absint( $_POST['revision'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $raw || strlen( $raw ) > self::MAX_PAYLOAD ) {
			wp_send_json_error( array( 'message' => __( 'The checklist could not be read.', 'checkoff' ) ), 400 );
		}

		$result = $checklist->set_structure( json_decode( $raw, true ), $revision );

		if ( is_wp_error( $result ) ) {

			if ( 'checkoff_conflict' === $result->get_error_code() ) {
				wp_send_json_error(
					array(
						'message'  => $result->get_error_message(),
						'conflict' => true,
					),
					409
				);
			}

			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'structure' => $result['structure'],
				'revision'  => $result['revision'],
				'values'    => (object) $checklist->get_values(),
				'stages'    => (object) $checklist->get_stages(),
				'round'     => $checklist->round_info(),
			)
		);
	}

	/**
	 * Sets the value of one entry — or, with `stage`, its stage on the board
	 * ('doing' or '').
	 *
	 * @return void
	 */
	public function ajax_set_value(): void {

		$checklist = $this->ajax_checklist( false, Checkoff_Quota::TICK );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_checklist().
		$item_id = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		$at      = ! empty( $_POST['at'] ) ? absint( $_POST['at'] ) : null; // A tick sent late, by a browser that was offline.
		$round   = ! empty( $_POST['round'] ) ? absint( $_POST['round'] ) : null; // A round ahead.

		if ( isset( $_POST['stage'] ) ) {
			$stage = sanitize_key( wp_unslash( $_POST['stage'] ) );
			$value = $checklist->set_stage( $item_id, '' === $stage ? null : $stage, $at, $round );
			$key   = 'stage';
		} else {
			$raw   = isset( $_POST['value'] ) ? json_decode( (string) wp_unslash( $_POST['value'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated by the block type.
			$value = $checklist->set_value( $item_id, $raw, $at, $round );
			$key   = 'value';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $value ) ) {
			wp_send_json_error(
				array(
					'message' => $value->get_error_message(),
					'code'    => $value->get_error_code(),
				),
				in_array( $value->get_error_code(), array( 'checkoff_round_over', 'checkoff_round_not_open' ), true ) ? 409 : 400
			);
		}

		wp_send_json_success(
			array(
				'item' => $item_id,
				$key   => $value,
			)
		);
	}

	/**
	 * Live update: what changed since the client last asked.
	 *
	 * The client sends the fingerprint of what it shows; while nothing has
	 * changed the answer is that fingerprint and nothing else. Only reads —
	 * a poll never closes a round or counts as a visit, so an open tab does
	 * not keep a checklist from the cleanup.
	 *
	 * @return void
	 */
	public function ajax_poll(): void {

		$checklist = $this->ajax_checklist();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_checklist().
		$known    = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$revision = isset( $_POST['revision'] ) ? absint( $_POST['revision'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$sync  = $checklist->sync_state();
		$state = $sync['state'];

		if ( $state === $known ) {
			wp_send_json_success(
				array(
					'state'   => $state,
					'changed' => false,
				)
			);
		}

		$data = array(
			'state'    => $state,
			'changed'  => true,
			'revision' => $checklist->get_revision(),
			'values'   => (object) $sync['values'],
			'stages'   => (object) $sync['stages'],
			'round'    => $sync['round'],
			'last'     => $sync['last'],
		);

		if ( $checklist->shows_times() ) {
			$data['times'] = (object) $sync['times'];
		}

		if ( $revision !== $checklist->get_revision() ) {
			$data['structure'] = $checklist->get_structure();
		}

		wp_send_json_success( $data );
	}

	/**
	 * Clears every value of a checklist.
	 *
	 * @return void
	 */
	public function ajax_reset_values(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );
		$checklist->reset_values();

		wp_send_json_success( array( 'values' => new stdClass() ) );
	}

	/**
	 * Deletes a checklist.
	 *
	 * @return void
	 */
	public function ajax_delete(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );

		if ( ! $checklist->delete() ) {
			wp_send_json_error( array( 'message' => __( 'The checklist could not be deleted.', 'checkoff' ) ), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * The kept rounds of a checklist — edit ID only.
	 *
	 * @return void
	 */
	public function ajax_history(): void {

		$checklist = $this->ajax_checklist( true );

		wp_send_json_success( self::history_data( $checklist ) );
	}

	/**
	 * One kept round as it was — edit ID only.
	 *
	 * @return void
	 */
	public function ajax_history_round(): void {

		$checklist = $this->ajax_checklist( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_checklist().
		$round = self::history_round( $checklist, isset( $_POST['number'] ) ? absint( $_POST['number'] ) : 0 );

		if ( is_wp_error( $round ) ) {
			wp_send_json_error( array( 'message' => $round->get_error_message() ), self::error_status( $round ) );
		}

		wp_send_json_success( $round );
	}

	/**
	 * One round ahead, to tick in advance — whoever may tick.
	 *
	 * @return void
	 */
	public function ajax_upcoming_round(): void {

		$checklist = $this->ajax_checklist();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_checklist().
		$round = self::upcoming_round( $checklist, isset( $_POST['number'] ) ? absint( $_POST['number'] ) : 0 );

		if ( is_wp_error( $round ) ) {
			wp_send_json_error( array( 'message' => $round->get_error_message() ), self::error_status( $round ) );
		}

		wp_send_json_success( $round );
	}

	/**
	 * One round ahead, or the error both channels answer with.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int                $number    Round number.
	 * @return array|WP_Error
	 */
	public static function upcoming_round( Checkoff_Checklist $checklist, int $number ): array|WP_Error {

		$round = $checklist->upcoming_view( $number );

		return null === $round
			? new WP_Error( 'checkoff_round_not_open', __( 'This round cannot be ticked (any more). Reload the page.', 'checkoff' ), array( 'status' => 404 ) )
			: $round;
	}

	/**
	 * Deletes the kept rounds of a checklist — edit ID only.
	 *
	 * @return void
	 */
	public function ajax_history_delete(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );

		Checkoff_History::clear( $checklist );

		wp_send_json_success( self::history_data( $checklist ) );
	}

	/**
	 * Unlocks a protected list with its password for this browser.
	 *
	 * @return void
	 */
	public function ajax_unlock(): void {

		$checklist = $this->ajax_checklist( false, '', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in ajax_checklist(); a password is compared, not shown.
		$password = Checkoff_Password::from_request( isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '' );
		$result   = Checkoff_Password::unlock( $checklist, $password );

		if ( is_wp_error( $result ) ) {
			self::send_retry_after( $result );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), self::error_status( $result ) );
		}

		wp_send_json_success();
	}

	/**
	 * Locks a protected list again in this browser.
	 *
	 * @return void
	 */
	public function ajax_lock(): void {

		$checklist = $this->ajax_checklist( false, '', true );
		Checkoff_Password::forget( $checklist );

		wp_send_json_success();
	}

	/**
	 * Sets or changes the password — edit access only.
	 *
	 * @return void
	 */
	public function ajax_set_password(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in ajax_checklist(); a password is hashed, not shown.
		$password = Checkoff_Password::from_request( isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '' );

		// Before 2.0.0 a password meant "protected"; a private list stays private.
		$level  = Checkoff_Checklist::LEVEL_PRIVATE === $checklist->get_level() ? Checkoff_Checklist::LEVEL_PRIVATE : Checkoff_Checklist::LEVEL_PROTECTED;
		$result = '' === $password
			? new WP_Error( 'checkoff_password_length', __( 'Please enter a password.', 'checkoff' ), array( 'status' => 400 ) )
			: Checkoff_Password::change( $checklist, $level, $password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), self::error_status( $result ) );
		}

		wp_send_json_success( self::access_data( $checklist ) );
	}

	/**
	 * Removes the password — edit access only.
	 *
	 * @return void
	 */
	public function ajax_remove_password(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );
		Checkoff_Password::change( $checklist, Checkoff_Checklist::LEVEL_OPEN );

		wp_send_json_success( self::access_data( $checklist ) );
	}

	/**
	 * Sets the level — open, protected, private — with a new password or
	 * the current one; edit access only.
	 *
	 * @return void
	 */
	public function ajax_set_access(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in ajax_checklist(); a password is hashed, not shown.
		$level    = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';
		$password = Checkoff_Password::from_request( isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = Checkoff_Password::change( $checklist, $level, $password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), self::error_status( $result ) );
		}

		wp_send_json_success( self::access_data( $checklist ) );
	}

	/**
	 * Shows the list in the public directory, or no longer; edit access only.
	 *
	 * @return void
	 */
	public function ajax_set_listed(): void {

		$checklist = $this->ajax_checklist( true, Checkoff_Quota::EDIT );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_checklist().
		$result = $checklist->set_listed( ! empty( $_POST['listed'] ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), self::error_status( $result ) );
		}

		wp_send_json_success( self::access_data( $checklist ) );
	}

	/**
	 * The protection of a list as the page and the REST API report it.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return array{level:string, protected:bool, listed:bool}
	 */
	public static function access_data( Checkoff_Checklist $checklist ): array {
		return array(
			'level'     => $checklist->get_level(),
			'protected' => $checklist->is_protected(),
			'listed'    => $checklist->is_listed(),
		);
	}

	/**
	 * The history as page and REST API answer with it.
	 *
	 * @param Checkoff_Checklist $checklist Checklist (edit access checked by the caller).
	 * @return array{enabled:bool, days:int, rounds:array}
	 */
	public static function history_data( Checkoff_Checklist $checklist ): array {

		$enabled = Checkoff_History::is_enabled();

		return array(
			'enabled' => $enabled,
			'days'    => Checkoff_History::days(),
			'index'   => Checkoff_History::index( $checklist ),
			'rounds'  => Checkoff_History::rounds( $checklist ),
		);
	}

	/**
	 * One kept round for the page and the REST API (edit access checked by
	 * the caller).
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int                $number    Round number.
	 * @return array|WP_Error 404 when the round is not kept.
	 */
	public static function history_round( Checkoff_Checklist $checklist, int $number ): array|WP_Error {

		$round = Checkoff_History::round_view( $checklist, $number );

		if ( null === $round ) {
			return new WP_Error( 'checkoff_round_not_kept', __( 'This round is not kept.', 'checkoff' ), array( 'status' => 404 ) );
		}

		return $round;
	}

	/**
	 * Checks the nonce and finds the checklist a request names, or ends the
	 * request with an error.
	 *
	 * @param bool   $edit  Whether the request changes more than a value.
	 * @param string $quota The quota the request takes (Checkoff_Quota), or ''.
	 * @param bool   $sealed_ok Whether a private list without its password will do (unlocking).
	 * @return Checkoff_Checklist
	 */
	private function ajax_checklist( bool $edit = false, string $quota = '', bool $sealed_ok = false ): Checkoff_Checklist {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$raw_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$checklist = self::request_checklist( $raw_id, $edit, $quota, $sealed_ok );

		if ( is_wp_error( $checklist ) ) {
			self::send_retry_after( $checklist );
			wp_send_json_error( array( 'message' => $checklist->get_error_message() ), 'checkoff_not_found' === $checklist->get_error_code() ? 404 : self::error_status( $checklist ) );
		}

		return $checklist;
	}

	/**
	 * The checklist a request names, with the checks every request makes —
	 * shared by the page's handlers and the REST API: the rate-limited
	 * lookup, edit access where needed, and the request's quota.
	 *
	 * @param string $raw_id Raw ID.
	 * @param bool   $edit   Whether the request changes more than a value.
	 * @param string $quota  The quota the request takes, or ''.
	 * @param bool   $sealed_ok Whether a private list without its password will do (unlocking).
	 * @return Checkoff_Checklist|WP_Error The error carries an HTTP status.
	 */
	public static function request_checklist( string $raw_id, bool $edit = false, string $quota = '', bool $sealed_ok = false ): Checkoff_Checklist|WP_Error {

		$checklist = self::lookup( $raw_id );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		if ( ! $sealed_ok ) {
			$sealed = self::refuse_sealed( $checklist );
			if ( is_wp_error( $sealed ) ) {
				return $sealed;
			}
		}

		if ( $edit && Checkoff_Checklist::ACCESS_LOCKED === $checklist->get_access() ) {
			return new WP_Error( 'checkoff_locked', __( 'This checklist is protected by a password. Enter it to edit the checklist.', 'checkoff' ), array( 'status' => 403 ) );
		}

		if ( '' !== $quota ) {
			$taken = Checkoff_Quota::take( $quota );
			if ( is_wp_error( $taken ) ) {
				return $taken;
			}
		}

		return $checklist;
	}

	/**
	 * The refusal for a private list opened without its password — for
	 * everything but the prompt itself.
	 *
	 * @param Checkoff_Checklist $checklist Checklist as looked up.
	 * @return true|WP_Error 403 checkoff_private.
	 */
	public static function refuse_sealed( Checkoff_Checklist $checklist ): bool|WP_Error {

		if ( Checkoff_Checklist::ACCESS_SEALED === $checklist->get_access() ) {
			return new WP_Error( 'checkoff_private', __( 'This checklist is private. Enter its password to open it.', 'checkoff' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Sends Retry-After for a refusal that carries one.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	public static function send_retry_after( WP_Error $error ): void {

		$data = $error->get_error_data();

		if ( is_array( $data ) && ! empty( $data['retry_after'] ) && ! headers_sent() ) {
			header( 'Retry-After: ' . (int) $data['retry_after'] );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Finds a checklist by public ID, counting misses against the visitor.
	 *
	 * Once a visitor has tried too many unknown IDs, every lookup is refused
	 * for the rest of the hour — including correct ones, or the refusal would
	 * itself tell a guesser when they hit.
	 *
	 * @param string $raw_id Raw public ID.
	 * @return Checkoff_Checklist|WP_Error
	 */
	public static function lookup( string $raw_id ): Checkoff_Checklist|WP_Error {

		if ( Checkoff_Rate_Limit::is_exceeded( 'lookup', self::lookup_limit() ) ) {
			return new WP_Error(
				'checkoff_limited',
				__( 'Too many unknown IDs were tried. Please wait a while and try again.', 'checkoff' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, Checkoff_Rate_Limit::retry_after( 'lookup' ) ),
				)
			);
		}

		$checklist = Checkoff_Checklist::find( $raw_id );

		if ( null !== $checklist ) {
			// A protected list stays locked without its unlock.
			Checkoff_Password::apply( $checklist );
		}

		if ( null === $checklist ) {
			Checkoff_Rate_Limit::hit( 'lookup' );
			return new WP_Error( 'checkoff_not_found', __( 'No checklist was found for this ID. Please check it and try again.', 'checkoff' ), array( 'status' => 404 ) );
		}

		return $checklist;
	}

	/**
	 * The public ID in the address, unvalidated.
	 *
	 * @return string
	 */
	private function requested_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup, rate limited.
		return isset( $_GET[ Checkoff_Checklist::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ Checkoff_Checklist::QUERY_VAR ] ) ) : '';
	}

	/**
	 * Address of the page being rendered.
	 *
	 * @return string
	 */
	private function current_url(): string {

		$id = get_queried_object_id();

		if ( $id > 0 && is_singular() ) {
			$url = (string) get_permalink( $id );
		} else {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$url  = home_url( $path );
		}

		return remove_query_arg( Checkoff_Checklist::QUERY_VAR, $url );
	}

	/**
	 * Hidden fields for the query arguments an action URL already carries —
	 * a GET form replaces the query string, so a site with plain permalinks
	 * (?page_id=12) would otherwise lose the page.
	 *
	 * @param string $action Form action.
	 * @return string
	 */
	private function hidden_query_fields( string $action ): string {

		$query = (string) wp_parse_url( $action, PHP_URL_QUERY );

		if ( '' === $query ) {
			return '';
		}

		wp_parse_str( $query, $args );

		$html = '';

		foreach ( $args as $key => $value ) {
			if ( is_scalar( $value ) && Checkoff_Checklist::QUERY_VAR !== $key ) {
				$html .= '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
			}
		}

		return $html;
	}

	/**
	 * JSON safe to put inside a <script> element.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	private static function json( mixed $data ): string {
		return (string) wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
	}
}
