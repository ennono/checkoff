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

		$this->register_ajax();
	}

	/**
	 * AJAX endpoints, for visitors and logged-in users alike.
	 *
	 * @return void
	 */
	private function register_ajax(): void {

		$endpoints = array(
			'cko_create'         => 'ajax_create',
			'cko_save_structure' => 'ajax_save_structure',
			'cko_set_value'      => 'ajax_set_value',
			'cko_reset_values'   => 'ajax_reset_values',
			'cko_delete'         => 'ajax_delete',
		);

		foreach ( $endpoints as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
		}
	}

	/*
	---------------------------------------------------------------------
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
		return max( 0, (int) get_option( 'checkoff_create_per_hour', 10 ) );
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
	---------------------------------------------------------------------
	 * Shortcodes
	 * ------------------------------------------------------------------
	 */

	/**
	 * [cko_app] — everything a checklist page needs in one shortcode: the
	 * checklist when the address carries an ID, otherwise the forms to create
	 * and open one and the checklists recently opened on this device.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_app_callback( $atts = array() ): string {

		if ( '' !== $this->requested_id() ) {
			return $this->cko_checklist_callback( array() );
		}

		return '<div class="cko-app">'
			. $this->cko_create_callback( array() )
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

		$checklist = $this->lookup( $raw_id );

		if ( is_wp_error( $checklist ) ) {
			return '<div class="cko-app cko-app--missing">'
				. '<p class="cko-message cko-message--error" role="alert">' . esc_html( $checklist->get_error_message() ) . '</p>'
				. $this->cko_open_callback( array() )
				. '</div>';
		}

		$checklist->touch();

		$data = $checklist->to_client( $this->current_url() );

		$html  = '<div class="cko-app cko-checklist" data-cko-checklist>';
		$html .= '<script type="application/json" class="cko-data">' . self::json( $data ) . '</script>';
		$html .= '<h2 class="cko-checklist__title">' . esc_html( $data['title'] ) . '</h2>';
		$html .= '<noscript><p class="cko-message">' . esc_html__( 'Please enable JavaScript to use this checklist.', 'checkoff' ) . '</p></noscript>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * [cko_create title="" button=""] — the form that starts a new checklist.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function cko_create_callback( $atts = array() ): string {

		$atts = shortcode_atts(
			array(
				'title'  => __( 'New checklist', 'checkoff' ),
				'button' => __( 'Create checklist', 'checkoff' ),
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
		$html .= '<input type="text" id="' . esc_attr( $uid ) . '-title" name="title" maxlength="' . esc_attr( (string) Checkoff_Checklist::MAX_TITLE ) . '" placeholder="' . esc_attr__( 'e.g. Moving house', 'checkoff' ) . '" required />';
		$html .= '</p>';

		$enabled = Checkoff_Block_Types::enabled_keys();

		if ( count( $enabled ) > 1 ) {
			$html .= '<fieldset class="cko-create__types">';
			$html .= '<legend>' . esc_html__( 'Start with', 'checkoff' ) . '</legend>';

			foreach ( $enabled as $index => $key ) {
				$type  = Checkoff_Block_Types::get( $key );
				$html .= '<label class="cko-create__type">';
				$html .= '<input type="radio" name="type" value="' . esc_attr( $key ) . '"' . checked( 0, $index, false ) . ' /> ';
				$html .= '<span class="cko-create__type-icon" aria-hidden="true">' . esc_html( $type->icon() ) . '</span> ';
				$html .= '<span class="cko-create__type-label">' . esc_html( $type->label() ) . '</span>';
				$html .= '<span class="cko-create__type-desc">' . esc_html( $type->description() ) . '</span>';
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

	/*
	---------------------------------------------------------------------
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

		if ( ! self::can_create() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to create a checklist.', 'checkoff' ) ), 403 );
		}

		if ( Checkoff_Rate_Limit::is_exceeded( 'create', self::create_limit() ) ) {
			wp_send_json_error( array( 'message' => __( 'You have created many checklists in a short time. Please try again later.', 'checkoff' ) ), 429 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : Checkoff_Block_Types::FALLBACK;
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$checklist = Checkoff_Checklist::create( $title, $type );

		if ( is_wp_error( $checklist ) ) {
			wp_send_json_error( array( 'message' => $checklist->get_error_message() ), 500 );
		}

		Checkoff_Rate_Limit::hit( 'create' );

		// Only an address on this site may serve as the base of the link.
		$fallback = wp_validate_redirect( remove_query_arg( Checkoff_Checklist::QUERY_VAR, $page_url ), '' );

		wp_send_json_success(
			array(
				'id'  => Checkoff_Public_Id::format( $checklist->get_public_id() ),
				'url' => $checklist->url( $fallback ),
			)
		);
	}

	/**
	 * Saves the structure of a checklist.
	 *
	 * @return void
	 */
	public function ajax_save_structure(): void {

		$checklist = $this->ajax_checklist();

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
			)
		);
	}

	/**
	 * Sets the value of one entry.
	 *
	 * @return void
	 */
	public function ajax_set_value(): void {

		$checklist = $this->ajax_checklist();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_checklist().
		$item_id = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		$raw     = isset( $_POST['value'] ) ? json_decode( (string) wp_unslash( $_POST['value'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated by the block type.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$value = $checklist->set_value( $item_id, $raw );

		if ( is_wp_error( $value ) ) {
			wp_send_json_error( array( 'message' => $value->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'item'  => $item_id,
				'value' => $value,
			)
		);
	}

	/**
	 * Clears every value of a checklist.
	 *
	 * @return void
	 */
	public function ajax_reset_values(): void {

		$checklist = $this->ajax_checklist();
		$checklist->reset_values();

		wp_send_json_success( array( 'values' => new stdClass() ) );
	}

	/**
	 * Deletes a checklist.
	 *
	 * @return void
	 */
	public function ajax_delete(): void {

		$checklist = $this->ajax_checklist();

		if ( ! $checklist->delete() ) {
			wp_send_json_error( array( 'message' => __( 'The checklist could not be deleted.', 'checkoff' ) ), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * Checks the nonce and finds the checklist a request names, or ends the
	 * request with an error.
	 *
	 * @return Checkoff_Checklist
	 */
	private function ajax_checklist(): Checkoff_Checklist {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$raw_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$checklist = $this->lookup( $raw_id );

		if ( is_wp_error( $checklist ) ) {
			wp_send_json_error( array( 'message' => $checklist->get_error_message() ), 404 );
		}

		return $checklist;
	}

	/*
	---------------------------------------------------------------------
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
	private function lookup( string $raw_id ): Checkoff_Checklist|WP_Error {

		if ( Checkoff_Rate_Limit::is_exceeded( 'lookup', self::lookup_limit() ) ) {
			return new WP_Error( 'checkoff_limited', __( 'Too many unknown IDs were tried. Please wait a while and try again.', 'checkoff' ) );
		}

		$checklist = Checkoff_Checklist::find( $raw_id );

		if ( null === $checklist ) {
			Checkoff_Rate_Limit::hit( 'lookup' );
			return new WP_Error( 'checkoff_not_found', __( 'No checklist was found for this ID. Please check it and try again.', 'checkoff' ) );
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
