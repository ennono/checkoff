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
 * - **The ID is the key.** A route names a checklist by its public ID (edit)
 *   or its tick ID (tick only); there is no other way in, and the post ID
 *   never appears. Unknown IDs count against the caller's hourly limit
 *   ({@see Checkoff_Shortcodes::lookup()}), so the API is no faster way to
 *   guess one.
 * - **A tick ID only ticks.** Reading, the state, values and subscribing
 *   work with either ID; changing the structure, resetting and deleting
 *   need the public ID (403 otherwise).
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
 * The whole API can be switched off (setting `checkoff_rest_enabled`).
 */
class Checkoff_Rest_Api {

	const NAMESPACE = 'checkoff/v1';

	/**
	 * A public or tick ID in a route: letters, digits and the dashes of the
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
	}

	/**
	 * Whether the API is offered.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'on' === (string) get_option( 'checkoff_rest_enabled', 'on' );
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

		$public    = '__return_true';
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
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_value' ),
					'permission_callback' => $public,
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

		$checklist = self::checklist( $request, true );

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

		$checklist = self::checklist( $request, true );

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
			'last'     => $sync['last'],
		);

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

		$checklist = self::checklist( $request, true );

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
		return self::write_value( $request, $request->get_param( 'value' ) );
	}

	/**
	 * Clears the value of one entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_value( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::write_value( $request, null );
	}

	/**
	 * Clears every value of the checklist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_values( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request, true );

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

		$checklist = self::checklist( $request, true );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		Checkoff_History::clear( $checklist->get_post_id() );

		return rest_ensure_response( Checkoff_Shortcodes::history_data( $checklist ) );
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
	 * @param bool            $edit    Whether the tick ID is not enough.
	 * @return Checkoff_Checklist|WP_Error
	 */
	private static function checklist( WP_REST_Request $request, bool $edit = false ): Checkoff_Checklist|WP_Error {

		$checklist = Checkoff_Shortcodes::lookup( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		if ( $edit && ! $checklist->can_edit() ) {
			return new WP_Error( 'checkoff_tick_only', __( 'This link is for ticking only. Ask the owner of the checklist to change it.', 'checkoff' ), array( 'status' => 403 ) );
		}

		return $checklist;
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
	 * @param WP_REST_Request $request Request.
	 * @param mixed           $raw     Raw value.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function write_value( WP_REST_Request $request, mixed $raw ): WP_REST_Response|WP_Error {

		$checklist = self::checklist( $request );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$item  = (string) $request->get_param( 'item' );
		$value = $checklist->set_value( $item, $raw );

		if ( is_wp_error( $value ) ) {
			return self::model_error( $value );
		}

		return rest_ensure_response(
			array(
				'item'     => $item,
				'value'    => $value,
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
			'checkoff_conflict'     => 409,
			'checkoff_unknown_item' => 404,
		)[ $error->get_error_code() ] ?? 400;

		$error->add_data( array( 'status' => $status ) );

		return $error;
	}
}
