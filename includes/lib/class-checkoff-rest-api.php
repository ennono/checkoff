<?php
/**
 * REST API.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the website can do with checklists, under /wp-json/checkoff/v1/.
 *
 * Access works exactly as on the website, because it is the same code:
 *
 * - **The ID is the key, the level says how far it opens.** A route names a
 *   checklist by its ID; there is no other way in, and the post ID never
 *   appears. Unknown IDs count against the caller's hourly limit
 *   ({@see Checkoff_Shortcodes::lookup()}), so the API is no faster way to
 *   guess one. An open list does everything by its ID; a protected one
 *   needs the password (header `X-CheckOff-Password`) for changing the
 *   structure, resetting and deleting (403 `checkoff_locked`); a private
 *   one for everything but `POST …/unlock` (403 `checkoff_private`).
 * - **The directory** (`GET /lists`) lists what owners show publicly.
 * - **Creating** follows the website's rules: the login setting and the
 *   hourly limit ({@see Checkoff_Shortcodes::create_checklist()}).
 * - **Subscribing** is step 1 of the double opt-in with explicit consent
 *   ({@see Checkoff_Subscriptions::request()}); confirming stays in the mail.
 *
 * Routes need no WordPress login and no nonce (the ID is the
 * authorisation). Logged-in calls use any REST authentication, e.g. an
 * application password — that is what "logged in" means for the setting
 * "Guests may create checklists".
 *
 * - **Quotas** are the page's ({@see Checkoff_Quota}), taken in the same
 *   shared lookup ({@see Checkoff_Shortcodes::request_checklist()}); a
 *   refusal is 429 with Retry-After.
 * - **Access** (setting `checkoff_rest_access`): everybody, logged-in
 *   users only (401 otherwise), or off (no routes).
 * - **Answers** are never cached, never indexed and pass no referrer
 *   ({@see self::secure_response()}).
 */
class Checkoff_Rest_Api {

	const NAMESPACE = 'checkoff/v1';

	/**
	 * A checklist ID in a route: letters, digits and the dashes of the
	 * grouped form. Normalised and checked by Checkoff_Public_Id.
	 */
	const ID_PATTERN = '(?P<id>[A-Za-z0-9-]{4,64})';

	/**
	 * An entry ID in a route.
	 */
	const ITEM_PATTERN = '(?P<item>[a-z0-9]{6,16})';

	/**
	 * Hooks in.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'secure_response' ), 10, 3 );
		add_filter( 'rest_allowed_cors_headers', array( $this, 'allow_password_header' ) );
	}

	/**
	 * Lets browsers on other origins send the password header.
	 *
	 * @param string[] $headers Allowed headers.
	 * @return string[]
	 */
	public function allow_password_header( array $headers ): array {
		$headers[] = 'X-CheckOff-Password';
		return $headers;
	}

	/**
	 * Who may use the API: 'public' (everybody, by the ID, like the
	 * website), 'users' (logged-in users only — any REST authentication,
	 * e.g. an application password) or 'off'.
	 *
	 * Sites from 1.6.0 have `checkoff_rest_enabled` instead; it is read as
	 * 'public' or 'off' until the new setting is saved.
	 *
	 * @return string
	 */
	public static function access(): string {

		$access = (string) get_option( 'checkoff_rest_access', '' );

		if ( in_array( $access, array( 'public', 'users', 'off' ), true ) ) {
			return $access;
		}

		return 'on' === (string) get_option( 'checkoff_rest_enabled', 'on' ) ? 'public' : 'off';
	}

	/**
	 * Whether the API is offered at all.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'off' !== self::access();
	}

	/**
	 * Permission of every route: everybody, or logged-in users only.
	 *
	 * @return true|WP_Error
	 */
	public static function permission(): bool|WP_Error {

		if ( 'users' === self::access() && ! is_user_logged_in() ) {
			return new WP_Error( 'checkoff_rest_login', __( 'The CheckOff API is only available to logged-in users on this site.', 'checkoff' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Headers of every CheckOff answer: never cached (a checklist and its
	 * IDs must not end up in a shared cache), never indexed, never passing
	 * the address on, and Retry-After when a quota refused the request.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public function secure_response( $response, $server, $request ) {

		if ( ! $request instanceof WP_REST_Request || ! str_starts_with( ltrim( $request->get_route(), '/' ), self::NAMESPACE ) ) {
			return $response;
		}

		if ( $response instanceof WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
			$response->header( 'Referrer-Policy', 'no-referrer' );

			$data = $response->get_data();
			if ( 429 === $response->get_status() && is_array( $data ) && ! empty( $data['data']['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
			}
		}

		return $response;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		if ( ! self::is_enabled() ) {
			return;
		}

		// The ID in the route is the authorisation, as ?cko= is on the page;
		// permission() only adds the site's choice of logged-in users only.
		$public    = array( __CLASS__, 'permission' );
		$checklist = '/checklists/' . self::ID_PATTERN;

		register_rest_route(
			self::NAMESPACE,
			'/blocks',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_blocks' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/templates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_templates' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/lists',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_lists' ),
				'permission_callback' => $public,
				'args'                => array(
					'limit'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => Checkoff_Directory::LIMIT_MAX,
					),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/checklists',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_checklist' ),
				'permission_callback' => $public,
				'args'                => array(
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'Title; empty for the default, or the template\'s title.', 'checkoff' ),
					),
					'template'  => array(
						'type'        => 'string',
						'description' => __( 'Key of a template to copy (see /templates).', 'checkoff' ),
					),
					'structure' => array(
						'type'        => 'object',
						'description' => __( 'The whole checklist to start with, in the format GET returns. Not together with a template.', 'checkoff' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_checklist' ),
					'permission_callback' => $public,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_checklist' ),
					'permission_callback' => $public,
					'args'                => array(
						'revision'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'recurrence'  => array( 'type' => 'object' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_checklist' ),
					'permission_callback' => $public,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => $public,
				'args'                => array(
					'state'    => array(
						'type'        => 'string',
						'description' => __( 'Fingerprint of what the caller shows; unchanged state is answered with the fingerprint alone.', 'checkoff' ),
					),
					'revision' => array(
						'type'        => 'integer',
						'description' => __( 'Revision of the structure the caller has; the structure is sent only when it is older.', 'checkoff' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/structure',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'put_structure' ),
				'permission_callback' => $public,
				'args'                => array(
					'structure' => array(
						'type'     => 'object',
						'required' => true,
					),
					'revision'  => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/values',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'reset_values' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/values/' . self::ITEM_PATTERN,
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'put_value' ),
					'permission_callback' => $public,
					'args'                => array(
						'value' => array(
							'description' => __( 'The value in the format of the entry\'s building block; null clears it.', 'checkoff' ),
						),
						'stage' => array(
							'type'        => array( 'string', 'null' ),
							'enum'        => array( 'doing', '', null ),
							'description' => __( 'On a board: "doing" marks the entry as in progress, null or "" clears the mark.', 'checkoff' ),
						),
						'at'    => array(
							'type'        => 'integer',
							'description' => __( 'Unix time the tick was made, for a tick sent late; refused (409) when its round has ended since.', 'checkoff' ),
						),
						'round' => array(
							'type'        => 'integer',
							'description' => __( 'Number of a round ahead to tick in advance (see `upcoming`); the round in force by default.', 'checkoff' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_value' ),
					'permission_callback' => $public,
					'args'                => array(
						'round' => array(
							'type' => 'integer',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/rounds',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rounds' ),
					'permission_callback' => $public,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_rounds' ),
					'permission_callback' => $public,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/rounds/(?P<number>\\d{1,6})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_round' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/upcoming/(?P<number>\\d{1,6})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_upcoming' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/unlock',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'unlock' ),
					'permission_callback' => $public,
					'args'                => array(
						'password' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'lock' ),
					'permission_callback' => $public,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/password',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_password' ),
					'permission_callback' => $public,
					'args'                => array(
						'password' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_password' ),
					'permission_callback' => $public,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/access',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_access' ),
				'permission_callback' => $public,
				'args'                => array(
					'level'    => array(
						'type'     => 'string',
						'enum'     => array( Checkoff_Checklist::LEVEL_OPEN, Checkoff_Checklist::LEVEL_PROTECTED, Checkoff_Checklist::LEVEL_PRIVATE ),
						'required' => true,
					),
					'password' => array(
						'type'        => 'string',
						'description' => __( 'New password; empty keeps the current one. Needed to protect an open list.', 'checkoff' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/listed',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_listed' ),
					'permission_callback' => $public,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'set_listed' ),
					'permission_callback' => $public,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$checklist . '/subscriptions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => $public,
				'args'                => array(
					'email'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'consent' => array(
						'type'        => 'boolean',
						'required'    => true,
						'description' => __( 'Must be true: the person agrees to receive the e-mails.', 'checkoff' ),
					),
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Site
	 * ------------------------------------------------------------------
	 */

	/**
	 * The building blocks: their definitions, which the site offers, the
	 * limits a structure must keep.
	 *
	 * @return WP_REST_Response
	 */
	public function get_blocks(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'blocks'    => Checkoff_Block_Types::to_client(),
				'enabled'   => Checkoff_Block_Types::enabled_keys(),
				'max_depth' => Checkoff_Checklist::max_depth(),
				'max_nodes' => Checkoff_Checklist::max_nodes(),
			)
		);
	}

	/**
	 * The templates a checklist can start from.
	 *
	 * @return WP_REST_Response
	 */
	public function get_templates(): WP_REST_Response {

		$templates = array();

		foreach ( Checkoff_Templates::all() as $template ) {
			$templates[] = array(
				'key'         => $template['key'],
				'title'       => $template['title'],
				'description' => $template['description'],
				'entries'     => $template['items'],
				'blocks'      => $template['lists'],
				'repetition'  => $template['repetition'],
			);
		}

		return rest_ensure_response( $templates );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Checklists
	 * ------------------------------------------------------------------
	 */

	/**
	 * Creates a checklist: empty, from a template or from a whole structure.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_checklist( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$title     = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$template  = sanitize_key( (string) $request->get_param( 'template' ) );
		$structure = $request->get_param( 'structure' );

		if ( '' !== $template && null !== $structure ) {
			return new WP_Error( 'checkoff_rest_both', __( 'Start either from a template or from a structure, not both.', 'checkoff' ), array( 'status' => 400 ) );
		}

		if ( null !== $structure ) {
			$size = self::check_size( $structure );
			if ( is_wp_error( $size ) ) {
				return $size;
			}
		}

		$checklist = Checkoff_Shortcodes::create_checklist( $title, '' !== $template ? Checkoff_Templates::CHOICE_PREFIX . $template : Checkoff_Block_Types::FALLBACK );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		if ( is_array( $structure ) ) {

			// The title given beside the structure wins over the one inside.
			if ( '' !== $title ) {
				$structure['title'] = $title;
			}

			$saved = $checklist->set_structure( $structure, $checklist->get_revision() );

			if ( is_wp_error( $saved ) ) {
				$checklist->delete();
				return self::model_error( $saved );
			}
		}

		$response = rest_ensure_response( self::representation( $checklist ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * A checklist as the page shows it. Counts as a visit, like opening the
	 * page, and closes a round that has ended.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checklist( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$checklist->touch();

		return rest_ensure_response( self::representation( $checklist ) );
	}

	/**
	 * Changes title, description or repetition without sending the whole
	 * tree — the same save as the structure, with the same revision check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_checklist( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$structure = $checklist->get_structure();
		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : $request->get_body_params();

		foreach ( array( 'title', 'description', 'recurrence' ) as $key ) {
			if ( array_key_exists( $key, (array) $params ) ) {
				$structure[ $key ] = $params[ $key ];
			}
		}

		return self::save_structure( $checklist, $structure, (int) $request->get_param( 'revision' ) );
	}

	/**
	 * Deletes a checklist, with its values and subscriptions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_checklist( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		if ( ! $checklist->delete() ) {
			return new WP_Error( 'checkoff_delete_failed', __( 'The checklist could not be deleted.', 'checkoff' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * The live update: what changed since the caller's fingerprint. Only
	 * reads — no visit, no round closed — exactly like the page's poll.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_state( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$sync  = $checklist->sync_state();
		$known = sanitize_key( (string) $request->get_param( 'state' ) );

		if ( $sync['state'] === $known ) {
			return rest_ensure_response(
				array(
					'state'   => $sync['state'],
					'changed' => false,
				)
			);
		}

		$data = array(
			'state'    => $sync['state'],
			'changed'  => true,
			'revision' => $checklist->get_revision(),
			'values'   => (object) $sync['values'],
			'progress' => Checkoff_Checklist::progress_of( $checklist->get_structure(), $sync['values'] ),
			'round'    => $sync['round'],
			'stages'   => (object) $sync['stages'],
			'last'     => $sync['last'],
		);

		if ( $checklist->shows_times() ) {
			$data['times'] = (object) $sync['times'];
		}

		if ( (int) $request->get_param( 'revision' ) !== $checklist->get_revision() ) {
			$data['structure'] = $checklist->get_structure();
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Saves the whole structure, based on the revision the caller read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function put_structure( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$structure = $request->get_param( 'structure' );
		$size      = self::check_size( $structure );

		if ( is_wp_error( $size ) ) {
			return $size;
		}

		return self::save_structure( $checklist, $structure, (int) $request->get_param( 'revision' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Values
	 * ------------------------------------------------------------------
	 */

	/**
	 * Sets the value of one entry; null clears it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function put_value( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		return self::write_value( $request, array_key_exists( 'value', $params ) || ! array_key_exists( 'stage', $params ), $request->get_param( 'value' ), array_key_exists( 'stage', $params ), $request->get_param( 'stage' ) );
	}

	/**
	 * Clears the value of one entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_value( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::write_value( $request, true, null, true, null );
	}

	/**
	 * Clears every value of the checklist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_values( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$checklist->reset_values();

		return rest_ensure_response( self::representation( $checklist ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * History
	 * ------------------------------------------------------------------
	 */

	/**
	 * The kept rounds, newest first — edit ID only, like the page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_rounds( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		return rest_ensure_response( Checkoff_Shortcodes::history_data( $checklist ) );
	}

	/**
	 * Deletes the kept rounds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_rounds( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		Checkoff_History::clear( $checklist );

		return rest_ensure_response( Checkoff_Shortcodes::history_data( $checklist ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Password
	 * ------------------------------------------------------------------
	 */

	/**
	 * Unlocks a protected list with its password: sets the browser's cookie
	 * and answers with the checklist as it is now open. API clients may
	 * instead send the header X-CheckOff-Password with every request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlock( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, false, '', true );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$result = Checkoff_Password::unlock( $checklist, (string) $request->get_param( 'password' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Opened again, now with the cookie this request just set.
		$checklist = self::checklist( $request );

		return is_wp_error( $checklist ) ? $checklist : rest_ensure_response( self::representation( $checklist ) );
	}

	/**
	 * One kept round as it was: structure, values and when each was set —
	 * edit access only, like the history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_round( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$round = Checkoff_Shortcodes::history_round( $checklist, (int) $request->get_param( 'number' ) );

		return is_wp_error( $round ) ? $round : rest_ensure_response( $round );
	}

	/**
	 * One round ahead, to tick in advance — whoever may tick, like the page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_upcoming( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$round = Checkoff_Shortcodes::upcoming_round( $checklist, (int) $request->get_param( 'number' ) );

		return is_wp_error( $round ) ? $round : rest_ensure_response( $round );
	}

	/**
	 * Ends the browser's unlock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lock( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, false, '', true );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		Checkoff_Password::forget( $checklist );

		return rest_ensure_response( array( 'locked' => true ) );
	}

	/**
	 * Sets or changes the password — edit access, so on a protected list
	 * only with the current one (cookie or header).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$password = (string) $request->get_param( 'password' );

		if ( '' === $password ) {
			return new WP_Error( 'checkoff_password_length', __( 'Please enter a password.', 'checkoff' ), array( 'status' => 400 ) );
		}

		// A password alone means protected; a private list stays private.
		$level  = Checkoff_Checklist::LEVEL_PRIVATE === $checklist->get_level() ? Checkoff_Checklist::LEVEL_PRIVATE : Checkoff_Checklist::LEVEL_PROTECTED;
		$result = Checkoff_Password::change( $checklist, $level, $password );

		return is_wp_error( $result ) ? $result : rest_ensure_response( Checkoff_Shortcodes::access_data( $checklist ) );
	}

	/**
	 * Removes the password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$result = Checkoff_Password::change( $checklist, Checkoff_Checklist::LEVEL_OPEN );

		return is_wp_error( $result ) ? $result : rest_ensure_response( Checkoff_Shortcodes::access_data( $checklist ) );
	}

	/**
	 * Sets the level — open, protected or private — with a new password or
	 * the current one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_access( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$result = Checkoff_Password::change( $checklist, (string) $request->get_param( 'level' ), (string) $request->get_param( 'password' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( Checkoff_Shortcodes::access_data( $checklist ) );
	}

	/**
	 * Shows the list in the directory (PUT) or no longer (DELETE).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_listed( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true, Checkoff_Quota::EDIT );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$result = $checklist->set_listed( 'DELETE' !== $request->get_method() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( Checkoff_Shortcodes::access_data( $checklist ) );
	}

	/**
	 * The public directory, as `[cko_lists]` shows it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_lists( WP_REST_Request $request ): WP_REST_Response {

		$lists = Checkoff_Directory::lists( (int) $request->get_param( 'limit' ), (int) $request->get_param( 'offset' ) );

		return rest_ensure_response(
			array(
				'enabled' => Checkoff_Directory::is_enabled(),
				'lists'   => array_map( array( 'Checkoff_Directory', 'entry' ), $lists ),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Subscriptions
	 * ------------------------------------------------------------------
	 */

	/**
	 * Step 1 of the double opt-in: sends the confirmation mail. Like the
	 * page, the answer never tells whether the address was subscribed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function subscribe( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$result = Checkoff_Subscriptions::request(
			(string) $request->get_param( 'id' ),
			sanitize_email( (string) $request->get_param( 'email' ) ),
			true === rest_sanitize_boolean( $request->get_param( 'consent' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( array( 'message' => Checkoff_Subscriptions::pending_message() ) );
		$response->set_status( 202 );

		return $response;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * The checklist a route names, rate limited like the page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $edit    Whether the request changes more than a value.
	 * @param string          $quota   The quota the request takes, or ''.
	 * @param bool            $sealed_ok Whether a private list without its password will do.
	 * @return Checkoff_Checklist|WP_Error
	 */
	private static function checklist( WP_REST_Request $request, bool $edit = false, string $quota = '', bool $sealed_ok = false ): Checkoff_Checklist|WP_Error {
		return Checkoff_Shortcodes::request_checklist( (string) $request->get_param( 'id' ), $edit, $quota, $sealed_ok );
	}

	/**
	 * What a client gets of a checklist: what the page gets, plus the
	 * progress the server computes for mails.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return array
	 */
	private static function representation( Checkoff_Checklist $checklist ): array {

		$data             = $checklist->to_client();
		$data['progress'] = Checkoff_Checklist::progress_of( $data['structure'], (array) $data['values'] );

		return $data;
	}

	/**
	 * Saves a structure and answers with the checklist.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param mixed              $structure Raw structure.
	 * @param int                $revision  Revision it is based on.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function save_structure( Checkoff_Checklist $checklist, mixed $structure, int $revision ): WP_REST_Response|WP_Error {

		$saved = $checklist->set_structure( $structure, $revision );

		if ( is_wp_error( $saved ) ) {
			return self::model_error( $saved );
		}

		return rest_ensure_response( self::representation( $checklist ) );
	}

	/**
	 * Writes one value and answers with it and the new progress.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param bool            $set_value Whether to write the value.
	 * @param mixed           $raw       Raw value.
	 * @param bool            $set_stage Whether to write the stage.
	 * @param mixed           $stage     'doing', or null / '' to clear.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function write_value( WP_REST_Request $request, bool $set_value, mixed $raw, bool $set_stage, mixed $stage ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, false, Checkoff_Quota::TICK );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$item  = (string) $request->get_param( 'item' );
		$at    = $request->get_param( 'at' );
		$at    = null === $at ? null : (int) $at;
		$round = $request->get_param( 'round' );
		$round = empty( $round ) ? null : (int) $round;

		if ( $set_value ) {
			$result = $checklist->set_value( $item, $raw, $at, $round );
			if ( is_wp_error( $result ) ) {
				return self::model_error( $result );
			}
		}

		if ( $set_stage ) {
			$result = $checklist->set_stage( $item, is_string( $stage ) && '' !== $stage ? $stage : null, $at, $round );
			if ( is_wp_error( $result ) ) {
				return self::model_error( $result );
			}
		}

		if ( null !== $round && $round !== $checklist->round_index() + 1 ) {
			$view = $checklist->upcoming_view( $round );
			return rest_ensure_response(
				array(
					'item'     => $item,
					'round'    => $round,
					'value'    => ( (array) $view['values'] )[ $item ] ?? null,
					'stage'    => ( (array) $view['stages'] )[ $item ] ?? null,
					'progress' => $view['progress'],
				)
			);
		}

		return rest_ensure_response(
			array(
				'item'     => $item,
				'value'    => $checklist->get_values()[ $item ] ?? null,
				'stage'    => $checklist->get_stages()[ $item ] ?? null,
				'progress' => $checklist->progress(),
			)
		);
	}

	/**
	 * Refuses a structure larger than the page may send.
	 *
	 * @param mixed $structure Raw structure.
	 * @return true|WP_Error
	 */
	private static function check_size( mixed $structure ): bool|WP_Error {

		if ( ! is_array( $structure ) ) {
			return new WP_Error( 'checkoff_invalid', __( 'The checklist could not be read.', 'checkoff' ), array( 'status' => 400 ) );
		}

		if ( strlen( (string) wp_json_encode( $structure ) ) > Checkoff_Shortcodes::MAX_PAYLOAD ) {
			return new WP_Error( 'checkoff_too_large', __( 'The checklist is too large.', 'checkoff' ), array( 'status' => 413 ) );
		}

		return true;
	}

	/**
	 * A model error with the HTTP status it stands for.
	 *
	 * @param WP_Error $error Error from Checkoff_Checklist.
	 * @return WP_Error
	 */
	private static function model_error( WP_Error $error ): WP_Error {

		$status = array(
			'checkoff_conflict'       => 409,
			'checkoff_round_over'     => 409,
			'checkoff_round_not_open' => 409,
			'checkoff_unknown_item'   => 404,
		)[ $error->get_error_code() ] ?? 400;

		$error->add_data( array( 'status' => $status ) );

		return $error;
	}
}
