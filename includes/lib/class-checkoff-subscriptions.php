<?php
/**
 * E-mail subscriptions of checklists.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets visitors subscribe to a checklist with an e-mail address and sends
 * the mails the sending rules ask for — built like the subscriptions of
 * MixMyMeals: double opt-in, rules of trigger + time + subject + template,
 * a 15-minute cron, a test button per rule.
 *
 * As little personal data as possible:
 *  - Stored per subscription: the address, the checklist and the time of
 *    confirmation (the proof of consent). No name, no IP address, no user
 *    agent, no account.
 *  - The links in the mails carry a token derived from the subscription with
 *    a keyed hash; no token is stored.
 *  - Unconfirmed subscriptions are deleted after a few hours; unsubscribing
 *    deletes the subscription at once, as does deleting the checklist. There
 *    is no suppression list, because it would be a list of addresses kept
 *    after their owners left — double opt-in already stops anybody being
 *    signed up by someone else.
 *  - wp-admin shows addresses masked only; the post title is the masked form.
 *  - The answer to a subscription request never says whether the address was
 *    subscribed already.
 *
 * Meta: _cko_sub_email, _cko_sub_checklist (post ID), _cko_sub_status
 * (pending|active), _cko_sub_confirmed (unix time), _cko_sub_sent (send key =>
 * unix time, so no occurrence is mailed twice).
 */
class Checkoff_Subscriptions {

	const POST_TYPE     = 'cko_subscription';
	const CRON_HOOK     = 'checkoff_mail_dispatch';
	const CRON_INTERVAL = 'checkoff_fifteen_minutes';
	const QUERY_ACTION  = 'cko_sub';

	const META_EMAIL     = '_cko_sub_email';
	const META_CHECKLIST = '_cko_sub_checklist';
	const META_STATUS    = '_cko_sub_status';
	const META_CONFIRMED = '_cko_sub_confirmed';
	const META_SENT      = '_cko_sub_sent';

	const STATUS_PENDING = 'pending';
	const STATUS_ACTIVE  = 'active';

	const OPT_ENABLED       = 'checkoff_subscriptions_enabled';
	const OPT_RULES         = 'checkoff_mail_rules';
	const OPT_PER_CHECKLIST = 'checkoff_subscriptions_per_checklist';
	const OPT_PENDING_HOURS = 'checkoff_subscription_pending_hours';
	const OPT_CONSENT       = 'checkoff_subscription_consent';
	const OPT_CONFIRM_SUBJ  = 'checkoff_confirm_subject';

	const DEFAULT_PER_CHECKLIST = 5;
	const DEFAULT_PENDING_HOURS = 48;
	const DISPATCH_BATCH        = 200;

	/**
	 * A mail is still sent this long after it was due (a cron that ran late),
	 * not later: after a site was down for a week nobody wants seven reminders.
	 */
	const GRACE = DAY_IN_SECONDS;

	/**
	 * How long "already sent" is remembered.
	 */
	const SENT_MEMORY = 400 * DAY_IN_SECONDS;

	/**
	 * Triggers that may be limited to "only while something is open".
	 */
	const OPEN_TRIGGERS = array( 'each_day', 'round_start', 'round_last_day' );

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 15 minutes.
		add_action( self::CRON_HOOK, array( $this, 'dispatch' ) );

		add_action( 'template_redirect', array( $this, 'handle_endpoint' ) );

		add_action( 'wp_ajax_cko_subscribe', array( $this, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_nopriv_cko_subscribe', array( $this, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_cko_test_rule', array( $this, 'ajax_test_rule' ) );
		add_action( 'wp_ajax_cko_clear_mail_log', array( $this, 'ajax_clear_mail_log' ) );

		// A subscription does not outlive its checklist, nor a trash can.
		add_action( 'before_delete_post', array( $this, 'delete_for_checklist' ) );
		add_action( 'trashed_post', array( $this, 'delete_trashed' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'admin_column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'get_edit_post_link', array( $this, 'no_edit_link' ), 10, 2 );

		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------
	 */

	/**
	 * Whether visitors may subscribe.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'on' === (string) get_option( self::OPT_ENABLED, '' );
	}

	/**
	 * Subscriptions one checklist may have (pending ones included).
	 *
	 * @return int
	 */
	public static function per_checklist(): int {
		return max( 1, min( 50, (int) get_option( self::OPT_PER_CHECKLIST, self::DEFAULT_PER_CHECKLIST ) ) );
	}

	/**
	 * Hours after which an unconfirmed subscription is deleted.
	 *
	 * @return int
	 */
	public static function pending_hours(): int {
		return max( 1, min( 168, (int) get_option( self::OPT_PENDING_HOURS, self::DEFAULT_PENDING_HOURS ) ) );
	}

	/**
	 * The sentence the visitor agrees to, as safe HTML.
	 *
	 * @return string
	 */
	public static function consent_html(): string {

		$text = trim( (string) get_option( self::OPT_CONSENT, '' ) );

		if ( '' === $text ) {
			$text = __( 'I would like to receive e-mails about this checklist. I can unsubscribe at any time with the link in every e-mail.', 'checkoff' );

			$privacy = get_privacy_policy_url();

			if ( '' !== $privacy ) {
				$text .= ' <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'checkoff' ) . '</a>';
			}
		}

		return wp_kses(
			$text,
			array(
				'a'      => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
				'strong' => array(),
				'em'     => array(),
			)
		);
	}

	/**
	 * Subject of the confirmation mail (may contain placeholders).
	 *
	 * @return string
	 */
	public static function confirm_subject(): string {
		$subject = trim( (string) get_option( self::OPT_CONFIRM_SUBJ, '' ) );
		return '' !== $subject ? $subject : __( 'Please confirm your subscription: {{ checklist }}', 'checkoff' );
	}

	/**
	 * The triggers a sending rule can use.
	 *
	 * @return array<string, string>
	 */
	public static function trigger_labels(): array {
		return array(
			'on_confirm'     => __( 'On confirmation (immediately)', 'checkoff' ),
			'each_day'       => __( 'Every day', 'checkoff' ),
			'round_start'    => __( 'When a round starts', 'checkoff' ),
			'round_last_day' => __( 'On the last day of a round', 'checkoff' ),
			'round_end'      => __( 'After a round has ended', 'checkoff' ),
			'series_end'     => __( 'After the last round', 'checkoff' ),
		);
	}

	/**
	 * Rules of a site that never saved any.
	 *
	 * @return array[]
	 */
	public static function default_rules(): array {
		return array(
			array(
				'trigger'   => 'on_confirm',
				'time'      => '',
				'subject'   => __( 'You are subscribed: {{ checklist }}', 'checkoff' ),
				'template'  => 'welcome.html',
				'only_open' => '',
			),
			array(
				'trigger'   => 'round_start',
				'time'      => '08:00',
				'subject'   => __( 'A new round starts: {{ checklist }}', 'checkoff' ),
				'template'  => 'round-start.html',
				'only_open' => '',
			),
			array(
				'trigger'   => 'round_last_day',
				'time'      => '18:00',
				'subject'   => __( 'Still open today: {{ checklist }}', 'checkoff' ),
				'template'  => 'reminder.html',
				'only_open' => 'on',
			),
			array(
				'trigger'   => 'series_end',
				'time'      => '09:00',
				'subject'   => __( 'Done: {{ checklist }}', 'checkoff' ),
				'template'  => 'series-end.html',
				'only_open' => '',
			),
		);
	}

	/**
	 * The sending rules in force, validated.
	 *
	 * @return array[]
	 */
	public static function get_rules(): array {

		$raw = get_option( self::OPT_RULES, false );

		if ( false === $raw ) {
			return self::default_rules();
		}

		return self::sanitize_rules( $raw );
	}

	/**
	 * Validates a list of rules (settings sanitizer and reader alike).
	 *
	 * @param mixed $raw Raw rows.
	 * @return array[]
	 */
	public static function sanitize_rules( mixed $raw ): array {

		$rules = array();

		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$rule = is_array( $row ) ? self::normalize_rule( $row ) : null;
			if ( null !== $rule ) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * One validated rule, or null when it cannot be used.
	 *
	 * @param array $row Raw row.
	 * @return array{trigger:string, time:string, subject:string, template:string, only_open:string}|null
	 */
	public static function normalize_rule( array $row ): ?array {

		$trigger = sanitize_key( (string) ( $row['trigger'] ?? '' ) );

		if ( ! isset( self::trigger_labels()[ $trigger ] ) ) {
			return null;
		}

		$template = sanitize_file_name( (string) ( $row['template'] ?? '' ) );

		if ( ! Checkoff_Mailer::is_template_name( $template ) || in_array( $template, array( 'layout.html', 'confirm.html' ), true ) ) {
			return null;
		}

		$time = trim( (string) ( $row['time'] ?? '' ) );

		if ( 'on_confirm' === $trigger ) {
			$time = '';
		} elseif ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
			return null;
		} else {
			$time = sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}

		$subject = sanitize_text_field( (string) ( $row['subject'] ?? '' ) );

		if ( '' === $subject ) {
			$subject = '{{ checklist }}';
		}

		return array(
			'trigger'   => $trigger,
			'time'      => $time,
			'subject'   => $subject,
			'template'  => $template,
			'only_open' => in_array( $trigger, self::OPEN_TRIGGERS, true ) && ! empty( $row['only_open'] ) ? 'on' : '',
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Post type
	 * ------------------------------------------------------------------
	 */

	/**
	 * Registers the subscription post type: storage plus a list in wp-admin.
	 *
	 * @return void
	 */
	public function register_post_type(): void {

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => _x( 'Subscriptions', 'Post type general name', 'checkoff' ),
					'singular_name' => _x( 'Subscription', 'Post type singular name', 'checkoff' ),
					'all_items'     => __( 'Subscriptions', 'checkoff' ),
					'search_items'  => __( 'Search Subscriptions', 'checkoff' ),
					'not_found'     => __( 'No subscriptions found.', 'checkoff' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Checkoff_Post_Types::POST_TYPE,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
				'capabilities'        => Checkoff_Post_Types::admin_capabilities(),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Subscribing
	 * ------------------------------------------------------------------
	 */

	/**
	 * Creates a pending subscription and sends the confirmation mail.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param string             $email     Address.
	 * @return true|WP_Error True also when the address was subscribed already —
	 *                       the caller must not be able to tell.
	 */
	public static function subscribe( Checkoff_Checklist $checklist, string $email ): bool|WP_Error {

		$email = strtolower( (string) sanitize_email( $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'checkoff_email', __( 'Please enter a valid e-mail address.', 'checkoff' ) );
		}

		$existing = self::find( $checklist->get_post_id(), $email );

		if ( $existing > 0 ) {
			// Already there: a pending one gets its confirmation mail again
			// (it may have been lost), an active one nothing.
			if ( self::STATUS_PENDING === get_post_meta( $existing, self::META_STATUS, true ) ) {
				self::send_confirmation( $existing );
			}
			return true;
		}

		if ( self::count_for_checklist( $checklist->get_post_id() ) >= self::per_checklist() ) {
			return new WP_Error( 'checkoff_full', __( 'This checklist cannot take any more subscriptions.', 'checkoff' ) );
		}

		$sub_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => Checkoff_Mailer::mask_email( $email ),
				'post_author' => 0,
			),
			true
		);

		if ( is_wp_error( $sub_id ) ) {
			return $sub_id;
		}

		update_post_meta( $sub_id, self::META_EMAIL, $email );
		update_post_meta( $sub_id, self::META_CHECKLIST, $checklist->get_post_id() );
		update_post_meta( $sub_id, self::META_STATUS, self::STATUS_PENDING );

		self::send_confirmation( (int) $sub_id );

		return true;
	}

	/**
	 * The subscription of an address to a checklist, or 0.
	 *
	 * @param int    $checklist_id Checklist post ID.
	 * @param string $email        Address.
	 * @return int
	 */
	public static function find( int $checklist_id, string $email ): int {

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_CHECKLIST,
						'value' => $checklist_id,
					),
					array(
						'key'   => self::META_EMAIL,
						'value' => strtolower( $email ),
					),
				),
			)
		);

		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Subscription IDs of a checklist.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @return int[]
	 */
	public static function ids_for_checklist( int $checklist_id ): array {

		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => self::META_CHECKLIST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $checklist_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Number of subscriptions of a checklist.
	 *
	 * @param int $checklist_id Checklist post ID.
	 * @return int
	 */
	public static function count_for_checklist( int $checklist_id ): int {
		return count( self::ids_for_checklist( $checklist_id ) );
	}

	/**
	 * AJAX: subscribe an address to a checklist.
	 *
	 * @return void
	 */
	public function ajax_subscribe(): void {

		check_ajax_referer( Checkoff_Shortcodes::NONCE_ACTION, 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$raw_id  = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$consent = ! empty( $_POST['consent'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = self::request( $raw_id, $email, $consent );

		if ( is_wp_error( $result ) ) {
			Checkoff_Shortcodes::send_retry_after( $result );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), Checkoff_Shortcodes::error_status( $result ) );
		}

		wp_send_json_success( array( 'message' => self::pending_message() ) );
	}

	/**
	 * Step 1 of the double opt-in for a visitor — the one place with its
	 * rules (switched on, consent given, hourly limit, ID lookup), shared by
	 * the checklist page and the REST API. Whether the address was
	 * subscribed already is never told.
	 *
	 * @param string $raw_id  Public or tick ID.
	 * @param string $email   Address.
	 * @param bool   $consent Whether the visitor ticked the consent.
	 * @return true|WP_Error The error carries an HTTP status.
	 */
	public static function request( string $raw_id, string $email, bool $consent ): bool|WP_Error {

		if ( ! self::is_enabled() ) {
			return new WP_Error( 'checkoff_subscriptions_off', __( 'Subscriptions are not available.', 'checkoff' ), array( 'status' => 403 ) );
		}

		$refused = Checkoff_Quota::refusal( Checkoff_Quota::SUBSCRIBE );

		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		if ( ! $consent ) {
			return new WP_Error( 'checkoff_consent', __( 'Please confirm that you would like to receive the e-mails.', 'checkoff' ), array( 'status' => 400 ) );
		}

		$checklist = Checkoff_Shortcodes::lookup( $raw_id );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		// A private list's content is only mailed to whoever may see it.
		$sealed = Checkoff_Shortcodes::refuse_sealed( $checklist );

		if ( is_wp_error( $sealed ) ) {
			return $sealed;
		}

		$taken = Checkoff_Quota::take( Checkoff_Quota::SUBSCRIBE );

		if ( is_wp_error( $taken ) ) {
			return $taken;
		}

		$result = self::subscribe( $checklist, $email );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return true;
	}

	/**
	 * What a visitor is told after step 1, whatever happened.
	 *
	 * @return string
	 */
	public static function pending_message(): string {
		return __( 'Almost done: please confirm with the link in the e-mail we have just sent you.', 'checkoff' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Links and tokens
	 * ------------------------------------------------------------------
	 */

	/**
	 * The token of a link: a keyed hash of the subscription and the action.
	 * Nothing is stored; a deleted subscription's links simply stop working.
	 *
	 * @param int    $sub_id Subscription ID.
	 * @param string $action confirm|unsubscribe.
	 * @return string
	 */
	public static function token( int $sub_id, string $action ): string {

		$created = (string) get_post_field( 'post_date_gmt', $sub_id );

		return substr( hash_hmac( 'sha256', $sub_id . '|' . $created . '|' . $action, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Address of a confirm or unsubscribe link.
	 *
	 * @param int    $sub_id Subscription ID.
	 * @param string $action confirm|unsubscribe.
	 * @return string
	 */
	public static function link( int $sub_id, string $action ): string {
		return add_query_arg(
			array(
				self::QUERY_ACTION => $action,
				's'                => $sub_id,
				't'                => self::token( $sub_id, $action ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Handles the links from the mails.
	 *
	 * A GET only shows a button: mail scanners open links on their own, and
	 * one of them must not confirm or cancel a subscription. The POST of
	 * that button — or a mail client's one-click unsubscribe (RFC 8058) —
	 * does it.
	 *
	 * @return void
	 */
	public function handle_endpoint(): void {

		// phpcs:disable WordPress.Security.NonceVerification -- authenticated by the token.
		$action = isset( $_REQUEST[ self::QUERY_ACTION ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::QUERY_ACTION ] ) ) : '';

		if ( ! in_array( $action, array( 'confirm', 'unsubscribe' ), true ) ) {
			return;
		}

		$sub_id  = isset( $_REQUEST['s'] ) ? absint( $_REQUEST['s'] ) : 0;
		$token   = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// phpcs:enable WordPress.Security.NonceVerification

		nocache_headers();

		$valid = $sub_id > 0
			&& self::POST_TYPE === get_post_type( $sub_id )
			&& hash_equals( self::token( $sub_id, $action ), $token );

		if ( ! $valid ) {
			$this->page(
				__( 'Link not valid', 'checkoff' ),
				'unsubscribe' === $action
					? __( 'This subscription no longer exists — you will not receive any more e-mails.', 'checkoff' )
					: __( 'This link is invalid or has expired. Please subscribe again.', 'checkoff' )
			);
		}

		if ( ! $is_post ) {
			$this->page(
				'confirm' === $action ? __( 'Confirm subscription', 'checkoff' ) : __( 'Unsubscribe', 'checkoff' ),
				'confirm' === $action
					? __( 'Please confirm that you would like to receive e-mails about this checklist.', 'checkoff' )
					: __( 'Do you no longer want to receive e-mails about this checklist?', 'checkoff' ),
				array(
					'action' => $action,
					's'      => $sub_id,
					't'      => $token,
					'label'  => 'confirm' === $action ? __( 'Confirm subscription', 'checkoff' ) : __( 'Unsubscribe', 'checkoff' ),
				)
			);
		}

		if ( 'confirm' === $action ) {
			self::confirm( $sub_id );
			$this->page( __( 'Subscription confirmed', 'checkoff' ), __( 'Thank you! Your subscription is confirmed.', 'checkoff' ) );
		}

		self::unsubscribe( $sub_id );
		$this->page( __( 'Unsubscribed', 'checkoff' ), __( 'You have been unsubscribed and your e-mail address has been deleted.', 'checkoff' ) );
	}

	/**
	 * Prints a minimal page and ends the request.
	 *
	 * @param string     $title   Title.
	 * @param string     $message Message.
	 * @param array|null $form    Button to show (action, s, t, label).
	 * @return void Ends the request.
	 */
	private function page( string $title, string $message, ?array $form = null ): void {

		$html = '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>';

		if ( null !== $form ) {
			$html .= '<form method="post" action="' . esc_url( home_url( '/' ) ) . '">'
				. '<input type="hidden" name="' . esc_attr( self::QUERY_ACTION ) . '" value="' . esc_attr( $form['action'] ) . '" />'
				. '<input type="hidden" name="s" value="' . esc_attr( (string) $form['s'] ) . '" />'
				. '<input type="hidden" name="t" value="' . esc_attr( $form['t'] ) . '" />'
				. '<p><button type="submit" class="button">' . esc_html( $form['label'] ) . '</button></p>'
				. '</form>';
		}

		$back  = Checkoff_Checklist::page_url();
		$html .= '<p><a href="' . esc_url( '' !== $back ? $back : home_url( '/' ) ) . '">' . esc_html( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ) . '</a></p>';

		wp_die( $html, esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}

	/**
	 * Confirms a subscription and sends the "on confirmation" mails.
	 *
	 * @param int      $sub_id Subscription ID.
	 * @param int|null $now    Unix time, now by default.
	 * @return void
	 */
	public static function confirm( int $sub_id, ?int $now = null ): void {

		if ( self::STATUS_ACTIVE === get_post_meta( $sub_id, self::META_STATUS, true ) ) {
			return;
		}

		$now = $now ?? time();

		update_post_meta( $sub_id, self::META_STATUS, self::STATUS_ACTIVE );
		update_post_meta( $sub_id, self::META_CONFIRMED, $now );

		$checklist = self::checklist_of( $sub_id );

		if ( null === $checklist ) {
			return;
		}

		$sent = array();

		foreach ( self::get_rules() as $rule ) {
			if ( 'on_confirm' === $rule['trigger'] && self::send_rule( $sub_id, $checklist, $rule, $now ) ) {
				$sent[ self::rule_key( $rule ) . '|confirm' ] = $now;
			}
		}

		update_post_meta( $sub_id, self::META_SENT, $sent );
	}

	/**
	 * Ends a subscription: the record, address included, is deleted.
	 *
	 * @param int $sub_id Subscription ID.
	 * @return void
	 */
	public static function unsubscribe( int $sub_id ): void {
		if ( self::POST_TYPE === get_post_type( $sub_id ) ) {
			wp_delete_post( $sub_id, true );
		}
	}

	/**
	 * The checklist of a subscription, or null when it is gone.
	 *
	 * @param int $sub_id Subscription ID.
	 * @return Checkoff_Checklist|null
	 */
	private static function checklist_of( int $sub_id ): ?Checkoff_Checklist {

		$checklist_id = (int) get_post_meta( $sub_id, self::META_CHECKLIST, true );

		if ( Checkoff_Post_Types::POST_TYPE !== get_post_type( $checklist_id ) ) {
			return null;
		}

		return new Checkoff_Checklist( $checklist_id );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------
	 */

	/**
	 * Sends the confirmation mail of a pending subscription.
	 *
	 * @param int $sub_id Subscription ID.
	 * @return bool
	 */
	public static function send_confirmation( int $sub_id ): bool {

		$checklist = self::checklist_of( $sub_id );

		if ( null === $checklist ) {
			return false;
		}

		$vars                  = self::mail_vars( self::checklist_data( $checklist ), '' );
		$vars['confirm_url']   = self::link( $sub_id, 'confirm' );
		$vars['pending_hours'] = (string) self::pending_hours();

		return Checkoff_Mailer::send_template(
			(string) get_post_meta( $sub_id, self::META_EMAIL, true ),
			self::confirm_subject(),
			'confirm.html',
			$vars,
			array(
				'layout' => 'layout.html',
				// The visitor has just asked for it.
				'force'  => true,
			)
		);
	}

	/**
	 * Sends the mail of one rule to one subscription.
	 *
	 * @param int                $sub_id    Subscription ID.
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param array              $rule      Rule.
	 * @param int|null           $now       Unix time, now by default.
	 * @return bool
	 */
	private static function send_rule( int $sub_id, Checkoff_Checklist $checklist, array $rule, ?int $now = null ): bool {

		$unsubscribe = self::link( $sub_id, 'unsubscribe' );

		return Checkoff_Mailer::send_template(
			(string) get_post_meta( $sub_id, self::META_EMAIL, true ),
			$rule['subject'],
			$rule['template'],
			self::mail_vars( self::checklist_data( $checklist, $now ), $unsubscribe ),
			array(
				'layout'          => 'layout.html',
				'unsubscribe_url' => $unsubscribe,
			)
		);
	}

	/**
	 * Identity of a rule in the "already sent" memory.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private static function rule_key( array $rule ): string {
		return $rule['trigger'] . '|' . $rule['time'] . '|' . $rule['template'];
	}

	/**
	 * The moments a rule asks for, as occurrence ID => unix time.
	 *
	 * Only the latest occurrence of each kind is listed; whether it is due
	 * (past, after the confirmation, within the grace) is decided by the
	 * caller.
	 *
	 * @param array               $rule       Rule.
	 * @param Checkoff_Recurrence $recurrence Repetition.
	 * @param int                 $now        Unix time.
	 * @return array<string, int>
	 */
	public static function occurrences( array $rule, Checkoff_Recurrence $recurrence, int $now ): array {

		$at = static function ( int $day_ts ) use ( $rule ): int {
			list( $h, $m ) = array_map( 'intval', explode( ':', $rule['time'] ) );
			return ( new DateTimeImmutable( '@' . $day_ts ) )->setTimezone( wp_timezone() )->setTime( $h, $m )->getTimestamp();
		};

		$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );

		if ( 'each_day' === $rule['trigger'] ) {
			return array( 'd' . $today->format( 'Ymd' ) => $at( $today->getTimestamp() ) );
		}

		if ( ! $recurrence->repeats() ) {
			return array();
		}

		$round = $recurrence->current( $now );
		$index = $round['index'];

		switch ( $rule['trigger'] ) {

			case 'round_start':
				return $round['finished'] ? array() : array( 'r' . $index => $at( $round['start'] ) );

			case 'round_last_day':
				return $round['finished'] ? array() : array( 'r' . $index => $at( (int) $round['end'] - DAY_IN_SECONDS / 2 ) );

			case 'round_end':
				if ( $round['finished'] ) {
					return array( 'r' . $index => $at( (int) $round['end'] ) );
				}
				return $index > 0 ? array( 'r' . ( $index - 1 ) => $at( $round['start'] ) ) : array();

			case 'series_end':
				return $round['finished'] ? array( 'end' => $at( (int) $round['end'] ) ) : array();
		}

		return array();
	}

	/**
	 * Cron: sends what is due, deletes unconfirmed subscriptions that expired.
	 *
	 * @param int|null $now Unix time, now by default (tests pass their own).
	 * @return int Number of mails sent.
	 */
	public function dispatch( ?int $now = null ): int {

		$now = $now ?? time();

		self::delete_expired_pending( $now );
		Checkoff_Mail_Log::prune();

		if ( ! self::is_enabled() ) {
			return 0;
		}

		$rules = array_filter( self::get_rules(), static fn( $rule ) => 'on_confirm' !== $rule['trigger'] );

		if ( empty( $rules ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => self::DISPATCH_BATCH,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => self::STATUS_ACTIVE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$count = 0;

		foreach ( $ids as $sub_id ) {
			$count += $this->dispatch_one( (int) $sub_id, $rules, $now );
		}

		return $count;
	}

	/**
	 * Sends the due mails of one subscription.
	 *
	 * @param int   $sub_id Subscription ID.
	 * @param array $rules  Rules (without on_confirm).
	 * @param int   $now    Unix time.
	 * @return int Number sent.
	 */
	private function dispatch_one( int $sub_id, array $rules, int $now ): int {

		$checklist = self::checklist_of( $sub_id );

		if ( null === $checklist ) {
			self::unsubscribe( $sub_id );
			return 0;
		}

		$confirmed = (int) get_post_meta( $sub_id, self::META_CONFIRMED, true );
		$sent      = get_post_meta( $sub_id, self::META_SENT, true );
		$sent      = is_array( $sent ) ? $sent : array();
		$before    = $sent;
		$count     = 0;
		$progress  = null;

		foreach ( $rules as $rule ) {

			foreach ( self::occurrences( $rule, $checklist->recurrence(), $now ) as $occurrence => $ts ) {

				$key = self::rule_key( $rule ) . '|' . $occurrence;

				if ( isset( $sent[ $key ] ) || $ts > $now || $ts < $confirmed || $now - $ts > self::GRACE ) {
					continue;
				}

				if ( 'on' === $rule['only_open'] ) {
					$progress = $progress ?? $checklist->progress( null, $now );
					if ( $progress['total'] > 0 && $progress['done'] >= $progress['total'] ) {
						$sent[ $key ] = $now; // Nothing open: settled without a mail.
						continue;
					}
				}

				if ( self::send_rule( $sub_id, $checklist, $rule, $now ) ) {
					$sent[ $key ] = $now;
					++$count;
				}
				// A failed send is tried again on the next run, within the grace.
			}
		}

		$sent = array_filter( $sent, static fn( $ts ) => (int) $ts > $now - self::SENT_MEMORY );

		if ( $sent !== $before ) {
			update_post_meta( $sub_id, self::META_SENT, $sent );
		}

		return $count;
	}

	/**
	 * Deletes unconfirmed subscriptions older than the configured hours.
	 *
	 * @param int $now Unix time.
	 * @return int Number deleted.
	 */
	public static function delete_expired_pending( int $now ): int {

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => self::DISPATCH_BATCH,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => self::STATUS_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', $now - self::pending_hours() * HOUR_IN_SECONDS ),
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}

		return count( $ids );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Placeholders
	 * ------------------------------------------------------------------
	 */

	/**
	 * What a mail needs to know about a checklist.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int|null           $now       Unix time, now by default.
	 * @return array
	 */
	public static function checklist_data( Checkoff_Checklist $checklist, ?int $now = null ): array {
		return array(
			'title'     => $checklist->get_title(),
			'url'       => $checklist->url(),
			'id'        => Checkoff_Public_Id::format( $checklist->get_current_id() ),
			'structure' => $checklist->get_structure(),
			'values'    => $checklist->get_values( $now ),
			'stages'    => $checklist->get_stages( $now ),
			'round'     => $checklist->round_info( $now ),
			'last'      => $checklist->get_last_round( $now ),
			'now'       => $now ?? time(),
		);
	}

	/**
	 * A made-up checklist for test mails, so no visitor's list ends up in an
	 * administrator's inbox.
	 *
	 * @return array
	 */
	public static function demo_data(): array {

		$structure = Checkoff_Checklist::sanitize_structure(
			array(
				'type'       => 'simple',
				'title'      => __( 'Example checklist', 'checkoff' ),
				'recurrence' => array(
					'freq'     => 'weekly',
					'interval' => 1,
					'start'    => (string) wp_date( 'Y-m-d', time() - 14 * DAY_IN_SECONDS ),
					'end'      => 'count',
					'count'    => 6,
				),
				'items'      => array(
					array(
						'id'    => 'demo0001',
						'kind'  => 'item',
						'label' => __( 'Water the plants', 'checkoff' ),
					),
					array(
						'id'    => 'demo0002',
						'kind'  => 'item',
						'label' => __( 'Take out the rubbish', 'checkoff' ),
					),
					array(
						'id'    => 'demo0003',
						'kind'  => 'item',
						'label' => __( 'Clean the bathroom', 'checkoff' ),
					),
				),
			)
		);

		$recurrence = new Checkoff_Recurrence( $structure['recurrence'] );
		$round      = $recurrence->current();

		return array(
			'title'     => $structure['title'],
			'url'       => Checkoff_Checklist::url_for( 'demodemodemo' ),
			'id'        => 'demo-demo-demo',
			'structure' => $structure,
			'values'    => array( 'demo0001' => true ),
			'round'     => array(
				'repeats'     => true,
				'number'      => $round['number'],
				'total'       => $round['total'],
				'start'       => Checkoff_Recurrence::format_date( $round['start'] ),
				'end'         => Checkoff_Recurrence::format_date( (int) $round['end'] - DAY_IN_SECONDS / 2 ),
				'finished'    => false,
				'description' => $recurrence->describe(),
			),
			'last'      => array(
				'number' => max( 1, $round['number'] - 1 ),
				'done'   => 2,
				'total'  => 3,
				'open'   => array(
					array(
						'label' => __( 'Clean the bathroom', 'checkoff' ),
						'path'  => '',
					),
				),
				'more'   => 0,
			),
		);
	}

	/**
	 * Every placeholder a mail template can use. All are always present; a
	 * template decides which it shows.
	 *
	 * @param array  $data            See checklist_data().
	 * @param string $unsubscribe_url Unsubscribe link ('' in the confirmation mail).
	 * @return array<string, string>
	 */
	public static function mail_vars( array $data, string $unsubscribe_url ): array {

		$progress = Checkoff_Checklist::progress_of( $data['structure'], $data['values'] );
		$now      = (int) ( $data['now'] ?? time() );
		$open     = Checkoff_Checklist::open_items_of( $data['structure'], $data['values'], $now, (array) ( $data['stages'] ?? array() ) );
		$round    = $data['round'];
		$last     = $data['last'];

		$round_label = '';

		if ( ! empty( $round['repeats'] ) ) {
			$round_label = null !== $round['total']
				/* translators: 1: number of the round, 2: number of rounds. */
				? sprintf( __( 'Round %1$s of %2$s', 'checkoff' ), number_format_i18n( $round['number'] ), number_format_i18n( $round['total'] ) )
				/* translators: %s: number of the round. */
				: sprintf( __( 'Round %s', 'checkoff' ), number_format_i18n( $round['number'] ) );
		}

		$last_label = '';
		$last_pct   = '';

		if ( is_array( $last ) ) {
			/* translators: %s: number of the round. */
			$last_label = sprintf( __( 'Round %s', 'checkoff' ), number_format_i18n( (int) $last['number'] ) );
			$last_pct   = (int) $last['total'] > 0 ? (string) (int) floor( $last['done'] / $last['total'] * 100 ) : '0';
		}

		$progress_summary = sprintf(
			/* translators: 1: entries done, 2: entries, 3: percent. */
			__( '%1$s of %2$s done (%3$s %%)', 'checkoff' ),
			number_format_i18n( $progress['done'] ),
			number_format_i18n( $progress['total'] ),
			number_format_i18n( $progress['percent'] )
		);

		if ( '' !== $round_label ) {
			$progress_summary = $round_label . ': ' . $progress_summary;
		}

		$last_summary = is_array( $last )
			? sprintf(
				/* translators: 1: "Round 3", 2: entries done, 3: entries, 4: percent. */
				__( '%1$s: %2$s of %3$s done (%4$s %%).', 'checkoff' ),
				$last_label,
				number_format_i18n( (int) $last['done'] ),
				number_format_i18n( (int) $last['total'] ),
				$last_pct
			)
			: '';

		return array(
			'checklist'            => (string) $data['title'],
			'checklist_url'        => (string) $data['url'],
			'checklist_id'         => (string) $data['id'],
			'repetition'           => (string) ( $round['description'] ?? '' ),
			'round_label'          => $round_label,
			'round_number'         => ! empty( $round['repeats'] ) ? (string) $round['number'] : '',
			'round_total'          => ! empty( $round['repeats'] ) && null !== $round['total'] ? (string) $round['total'] : '',
			'round_start'          => (string) ( $round['start'] ?? '' ),
			'round_end'            => (string) ( $round['end'] ?? '' ),
			'progress_done'        => (string) $progress['done'],
			'progress_total'       => (string) $progress['total'],
			'progress_percent'     => (string) $progress['percent'],
			'progress_summary'     => $progress_summary,
			'last_round_summary'   => $last_summary,
			'open_count'           => (string) count( $open ),
			'open_items_html'      => self::open_list_html( $open, 0 ),
			'items_html'           => self::items_html( $data['structure'], $data['values'], $now ),
			'last_round_label'     => $last_label,
			'last_round_done'      => is_array( $last ) ? (string) $last['done'] : '',
			'last_round_total'     => is_array( $last ) ? (string) $last['total'] : '',
			'last_round_percent'   => $last_pct,
			'last_round_open_html' => is_array( $last ) ? self::open_list_html( (array) $last['open'], (int) ( $last['more'] ?? 0 ) ) : '',
			'unsubscribe_url'      => $unsubscribe_url,
		);
	}

	/**
	 * Open entries as a list.
	 *
	 * @param array $open Entries (label, path).
	 * @param int   $more Entries left out.
	 * @return string
	 */
	private static function open_list_html( array $open, int $more ): string {

		if ( empty( $open ) ) {
			return '<p>' . esc_html__( 'Everything is done.', 'checkoff' ) . '</p>';
		}

		$html = '<ul>';

		foreach ( $open as $item ) {
			$html .= '<li>' . esc_html( $item['label'] );
			if ( '' !== ( $item['path'] ?? '' ) ) {
				$html .= ' <span style="color:#777;">(' . esc_html( $item['path'] ) . ')</span>';
			}
			if ( '' !== ( $item['note'] ?? '' ) ) {
				$html .= ' <span style="color:#777;">— ' . esc_html( $item['note'] ) . '</span>';
			}
			$html .= '</li>';
		}

		if ( $more > 0 ) {
			/* translators: %s: number of further open entries. */
			$html .= '<li>' . esc_html( sprintf( _n( '… and %s more', '… and %s more', $more, 'checkoff' ), number_format_i18n( $more ) ) ) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * The whole checklist with the state of every entry.
	 *
	 * @param array    $block  Block.
	 * @param array    $values Values.
	 * @param int|null $now    Unix time, now by default (for due dates).
	 * @return string
	 */
	private static function items_html( array $block, array $values, ?int $now = null ): string {

		// The container: its blocks as sections, each under its heading.
		if ( Checkoff_Checklist::KIND_CHECKLIST === ( $block['kind'] ?? '' ) ) {

			$html = '';

			foreach ( $block['items'] as $section ) {
				if ( '' !== $section['title'] ) {
					$html .= '<h3 style="font-size:17px;margin:18px 0 6px;">' . esc_html( $section['title'] ) . '</h3>';
				}
				$html .= self::items_html( $section, $values, $now );
			}

			return $html;
		}

		$type = Checkoff_Block_Types::get( $block['type'] );
		$html = '<ul style="list-style:none;padding-left:0;margin:0 0 0 0;">';

		foreach ( $block['items'] as $item ) {

			if ( 'block' === $item['kind'] ) {
				$html .= '<li style="margin:8px 0 4px;"><strong>' . esc_html( '' !== $item['title'] ? $item['title'] : Checkoff_Block_Types::get( $item['type'] )->label() ) . '</strong>'
					. '<div style="padding-left:16px;">' . self::items_html( $item, $values, $now ) . '</div></li>';
				continue;
			}

			$value = $values[ $item['id'] ] ?? null;
			$part  = $type->progress( $value, $block['settings'] );
			$done  = $part['total'] > 0 && $part['done'] >= $part['total'];
			$text  = $type->describe_item( $item, $value, $block['settings'], $now );
			// An entry that counts but has left the count (status "not needed").
			$mark = $done ? '☑ ' : ( $type->counts_progress() && 0 === $part['total'] ? '⊘ ' : '☐ ' );

			$html .= '<li style="margin:2px 0;">' . $mark . esc_html( '' !== $item['label'] ? $item['label'] : '—' );

			if ( '' !== $text && '✓' !== $text ) {
				$html .= ' <span style="color:#777;">— ' . esc_html( $text ) . '</span>';
			}

			$html .= '</li>';
		}

		return $html . '</ul>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------
	 */

	/**
	 * Adds the 15-minute interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (CheckOff)', 'checkoff' ),
		);
		return $schedules;
	}

	/**
	 * Keeps the cron event in step with the setting. It also runs while
	 * subscriptions are off, but only to delete expired pending ones — and
	 * only while there are any.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {

		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( self::is_enabled() && ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
		} elseif ( ! self::is_enabled() && $scheduled && 0 === (int) wp_count_posts( self::POST_TYPE )->publish ) {
			self::unschedule();
		}
	}

	/**
	 * Removes the cron event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Deletion
	 * ------------------------------------------------------------------
	 */

	/**
	 * Deletes the subscriptions of a checklist that is being deleted.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public function delete_for_checklist( int $post_id ): void {

		if ( Checkoff_Post_Types::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( self::ids_for_checklist( $post_id ) as $sub_id ) {
			wp_delete_post( $sub_id, true );
		}
	}

	/**
	 * A subscription moved to the trash is deleted for good: it holds an
	 * address, and a trash can is a place where it would linger.
	 *
	 * @param int $post_id Trashed post.
	 * @return void
	 */
	public function delete_trashed( int $post_id ): void {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Admin list
	 * ------------------------------------------------------------------
	 */

	/**
	 * Columns: masked address (the title), checklist, status, confirmed.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function admin_columns( array $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '',
			'title'         => __( 'E-mail (masked)', 'checkoff' ),
			'cko_checklist' => __( 'Checklist', 'checkoff' ),
			'cko_status'    => __( 'Status', 'checkoff' ),
			'cko_confirmed' => __( 'Confirmed', 'checkoff' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Subscription ID.
	 * @return void
	 */
	public function admin_column_content( string $column, int $post_id ): void {

		switch ( $column ) {

			case 'cko_checklist':
				$checklist_id = (int) get_post_meta( $post_id, self::META_CHECKLIST, true );
				echo $checklist_id > 0
					? '<a href="' . esc_url( (string) get_edit_post_link( $checklist_id ) ) . '">' . esc_html( get_the_title( $checklist_id ) ) . '</a>'
					: '—';
				break;

			case 'cko_status':
				echo self::STATUS_ACTIVE === get_post_meta( $post_id, self::META_STATUS, true )
					? esc_html__( 'Active', 'checkoff' )
					: esc_html__( 'Awaiting confirmation', 'checkoff' );
				break;

			case 'cko_confirmed':
				$time = (int) get_post_meta( $post_id, self::META_CONFIRMED, true );
				echo $time > 0 ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ) : '—';
				break;
		}
	}

	/**
	 * Row actions: deleting only.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( array $actions, WP_Post $post ): array {

		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$keep = array();

		if ( isset( $actions['trash'] ) ) {
			$keep['trash'] = str_replace( '>' . __( 'Trash' ) . '<', '>' . esc_html__( 'Delete', 'checkoff' ) . '<', $actions['trash'] ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core string.
		}

		return $keep;
	}

	/**
	 * No edit screen: there is nothing to edit.
	 *
	 * @param string $link    Link.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function no_edit_link( $link, $post_id ) {
		return self::POST_TYPE === get_post_type( $post_id ) ? '' : $link;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Admin AJAX
	 * ------------------------------------------------------------------
	 */

	/**
	 * Sends one rule's mail, filled with the example checklist, to an
	 * address of the administrator's choice.
	 *
	 * @return void
	 */
	public function ajax_test_rule(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'checkoff' ) ), 403 );
		}

		check_ajax_referer( 'checkoff_mail_admin', 'nonce' );

		$to   = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$to   = is_email( $to ) ? $to : (string) get_option( 'admin_email' );
		$rule = self::normalize_rule(
			array(
				'trigger'  => isset( $_POST['trigger'] ) ? sanitize_key( wp_unslash( $_POST['trigger'] ) ) : '',
				'time'     => isset( $_POST['time'] ) ? sanitize_text_field( wp_unslash( $_POST['time'] ) ) : '08:00',
				'subject'  => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'template' => isset( $_POST['template'] ) ? sanitize_file_name( wp_unslash( $_POST['template'] ) ) : '',
			)
		);

		$confirm = isset( $_POST['template'] ) && 'confirm.html' === $_POST['template'];

		if ( null === $rule && ! $confirm ) {
			wp_send_json_error( array( 'message' => __( 'Please check this row first: it needs a template and a time.', 'checkoff' ) ), 400 );
		}

		$vars = self::mail_vars( self::demo_data(), home_url( '/' ) );

		if ( $confirm ) {
			$vars['confirm_url']   = home_url( '/' );
			$vars['pending_hours'] = (string) self::pending_hours();
		}

		$sent = Checkoff_Mailer::send_template(
			$to,
			/* translators: %s: subject of the mail. */
			sprintf( __( '[Test] %s', 'checkoff' ), $confirm ? self::confirm_subject() : $rule['subject'] ),
			$confirm ? 'confirm.html' : $rule['template'],
			$vars,
			array(
				'layout'          => 'layout.html',
				'unsubscribe_url' => $confirm ? '' : home_url( '/' ),
				'force'           => true,
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The test e-mail could not be sent.', 'checkoff' ) ), 500 );
		}

		/* translators: %s: e-mail address. */
		wp_send_json_success( array( 'message' => sprintf( __( 'Test e-mail sent to %s.', 'checkoff' ), $to ) ) );
	}

	/**
	 * Empties the send log.
	 *
	 * @return void
	 */
	public function ajax_clear_mail_log(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		check_ajax_referer( 'checkoff_mail_admin', 'nonce' );

		Checkoff_Mail_Log::clear();

		wp_send_json_success();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Privacy tools
	 * ------------------------------------------------------------------
	 */

	/**
	 * Registers the exporter (Tools → Export Personal Data).
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['checkoff-subscriptions'] = array(
			'exporter_friendly_name' => __( 'CheckOff subscriptions', 'checkoff' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser (Tools → Erase Personal Data).
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['checkoff-subscriptions'] = array(
			'eraser_friendly_name' => __( 'CheckOff subscriptions', 'checkoff' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Subscription IDs of an address.
	 *
	 * @param string $email Address.
	 * @return int[]
	 */
	private static function ids_for_email( string $email ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => self::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => strtolower( $email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Exports the subscriptions of an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( string $email, int $page = 1 ): array {

		$items = array();

		foreach ( self::ids_for_email( $email ) as $sub_id ) {

			$confirmed = (int) get_post_meta( $sub_id, self::META_CONFIRMED, true );

			$items[] = array(
				'group_id'    => 'checkoff-subscriptions',
				'group_label' => __( 'CheckOff subscriptions', 'checkoff' ),
				'item_id'     => 'checkoff-subscription-' . $sub_id,
				'data'        => array(
					array(
						'name'  => __( 'E-mail', 'checkoff' ),
						'value' => (string) get_post_meta( $sub_id, self::META_EMAIL, true ),
					),
					array(
						'name'  => __( 'Checklist', 'checkoff' ),
						'value' => get_the_title( (int) get_post_meta( $sub_id, self::META_CHECKLIST, true ) ),
					),
					array(
						'name'  => __( 'Subscribed', 'checkoff' ),
						'value' => (string) get_post_field( 'post_date', $sub_id ),
					),
					array(
						'name'  => __( 'Confirmed', 'checkoff' ),
						'value' => $confirmed > 0 ? wp_date( 'Y-m-d H:i:s', $confirmed ) : '—',
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Deletes the subscriptions of an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase( string $email, int $page = 1 ): array {

		$ids = self::ids_for_email( $email );

		foreach ( $ids as $sub_id ) {
			wp_delete_post( $sub_id, true );
		}

		return array(
			'items_removed'  => count( $ids ),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
