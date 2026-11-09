<?php
/**
 * Settings class file.
 *
 * @package CheckOff Plugin/Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
 * Tabs and fields are declared once in settings_fields(); every option is
 * named `checkoff_<field id>`, whatever tab the field sits on. Fields are
 * rendered by Checkoff_Admin_API::display_field() by their `type`.
 */
class Checkoff_Settings {

	/**
	 * The single instance of Checkoff_Settings.
	 *
	 * @var     Checkoff_Settings|null
	 * @access  private
	 */
	private static $_instance = null; //phpcs:ignore

	/**
	 * The main plugin object.
	 *
	 * @var     Checkoff|null
	 * @access  public
	 */
	public $parent = null;

	/**
	 * Prefix for plugin settings.
	 *
	 * @var     string
	 * @access  public
	 */
	public $base = '';

	/**
	 * Available settings for plugin.
	 *
	 * @var     array
	 * @access  public
	 */
	public $settings = array();

	/**
	 * Constructor function.
	 *
	 * @param object $parent Parent object.
	 */
	public function __construct( object $parent ) {
		$this->parent = $parent;

		$this->base = 'checkoff_';

		// Initialise settings.
		add_action( 'init', array( $this, 'init_settings' ), 11 );

		// Register plugin settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Add settings page to menu.
		add_action( 'admin_menu', array( $this, 'add_menu_item' ) );

		// Add settings link to plugins page.
		add_filter( 'plugin_action_links_' . plugin_basename( $this->parent->file ), array( $this, 'add_settings_link' ) );

		// The cleanup follows its setting at once, not on the next page load.
		add_action( 'update_option_checkoff_cleanup_inactive_days', array( $this, 'reschedule_cleanup' ) );
		add_action( 'add_option_checkoff_cleanup_inactive_days', array( $this, 'reschedule_cleanup' ) );
	}

	/**
	 * Main Checkoff_Settings Instance
	 *
	 * @param object $parent Object instance.
	 * @return static
	 */
	public static function instance( object $parent ): static {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $parent );
		}

		return self::$_instance;
	}

	/**
	 * Initialise settings
	 *
	 * @return void
	 */
	public function init_settings(): void {
		$this->settings = $this->settings_fields();
	}

	/**
	 * Build settings fields.
	 *
	 * @return array Fields to be displayed on settings page.
	 */
	private function settings_fields(): array {

		$settings['checkoff-general'] = array(
			'title'       => __( 'General', 'checkoff' ),
			'description' => __( 'Where checklists are shown and who may create them.', 'checkoff' ),
			'fields'      => array(
				array(
					'id'          => 'checklist_page',
					'label'       => __( 'Checklist page', 'checkoff' ),
					'description' => __( 'The page that contains the [cko_app] or [cko_checklist] shortcode. Links to checklists — in the REST API, the mails, the share menu — and the "Open" form lead there. Without a choice, the first published page with one of these shortcodes (also in Elementor) is used.', 'checkoff' ),
					'type'        => 'page',
					'default'     => 0,
					'callback'    => array( $this, 'page_callback' ),
				),
				array(
					'id'          => 'guests_can_create',
					'label'       => __( 'Guests may create checklists', 'checkoff' ),
					'description' => __( 'Off: only logged-in users can create checklists. Opening and ticking existing checklists by their ID is always possible.', 'checkoff' ),
					'type'        => 'checkbox',
					'default'     => 'on',
					'callback'    => array( $this, 'checkbox_callback' ),
				),
				array(
					'id'          => 'sync_interval',
					'label'       => __( 'Live update', 'checkoff' ),
					'description' => __( 'Seconds between two checks for changes made on other devices while a checklist is open. 0 = off (changes appear after reloading).', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 300,
					'default'     => 20,
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'rest_access',
					'label'       => __( 'REST API', 'checkoff' ),
					'description' => sprintf(
						/* translators: %s: REST namespace. */
						__( 'Everything the website can do with checklists, also under %s. Access to a checklist works as on the website, by its ID, with the same quotas. "Logged-in users only" requires a WordPress login, e.g. an application password.', 'checkoff' ),
						'/wp-json/' . Checkoff_Rest_Api::NAMESPACE . '/'
					),
					'type'        => 'select',
					'options'     => array(
						'public' => __( 'Everybody (like the website)', 'checkoff' ),
						'users'  => __( 'Logged-in users only', 'checkoff' ),
						'off'    => __( 'Off', 'checkoff' ),
					),
					'default'     => Checkoff_Rest_Api::access(),
					'callback'    => array( $this, 'rest_access_callback' ),
				),
				array(
					'id'          => 'lists_enabled',
					'label'       => __( 'Public checklists', 'checkoff' ),
					'description' => __( 'Owners of protected checklists may show them publicly: they appear in the directory [cko_lists], where everybody can open, view and tick them; changing them still needs the password. Nothing is approved beforehand — you can take a list out in its IDs box. Off: the directory is empty; the lists keep their choice and come back when it is switched on again.', 'checkoff' ),
					'type'        => 'checkbox',
					'default'     => 'on',
					'callback'    => array( $this, 'checkbox_callback' ),
				),
				array(
					'id'          => 'history_enabled',
					'label'       => __( 'History of rounds', 'checkoff' ),
					'description' => __( 'Keep the ticks and values of the closed rounds of repeating checklists, visible only with the ID that edits. Off: only a summary of the last round is kept. Switching it off deletes every kept round.', 'checkoff' ),
					'type'        => 'checkbox',
					'default'     => '',
					'callback'    => array( $this, 'checkbox_callback' ),
				),
				array(
					'id'          => 'history_days',
					'label'       => __( 'Keep rounds for', 'checkoff' ),
					'description' => __( 'days after a round ended; older rounds are deleted automatically.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 1,
					'max'         => Checkoff_History::MAX_DAYS,
					'default'     => Checkoff_History::DEFAULT_DAYS,
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'upcoming_rounds',
					'label'       => __( 'Tick ahead', 'checkoff' ),
					'description' => sprintf(
						/* translators: %s: maximum. */
						__( 'rounds of a repeating checklist that can be paged to and ticked in advance (up to %s) — e.g. "not needed" for next week\'s holiday. 0: only the round in force.', 'checkoff' ),
						Checkoff_Checklist::UPCOMING_MAX
					),
					'type'        => 'number',
					'min'         => 0,
					'max'         => Checkoff_Checklist::UPCOMING_MAX,
					'default'     => Checkoff_Checklist::UPCOMING_DEFAULT,
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'id_length',
					'label'       => __( 'Length of new IDs', 'checkoff' ),
					'description' => sprintf(
						/* translators: 1: minimum, 2: maximum. */
						__( 'Characters, %1$s to %2$s. Longer IDs are harder to guess and harder to type. Existing IDs keep their length.', 'checkoff' ),
						Checkoff_Public_Id::MIN_LENGTH,
						Checkoff_Public_Id::MAX_LENGTH
					),
					'type'        => 'number',
					'min'         => Checkoff_Public_Id::MIN_LENGTH,
					'max'         => Checkoff_Public_Id::MAX_LENGTH,
					'default'     => Checkoff_Public_Id::DEFAULT_LENGTH,
					'callback'    => array( $this, 'id_length_callback' ),
				),
			),
		);

		$settings['checkoff-blocks'] = array(
			'title'       => __( 'Building blocks', 'checkoff' ),
			'description' => __( 'Which building blocks visitors can use, and how the ratings look. A block that is switched off is no longer offered; checklists already using it keep working.', 'checkoff' ),
			'fields'      => $this->block_fields(),
		);

		$settings['checkoff-limits'] = array(
			'title'       => __( 'Limits', 'checkoff' ),
			'description' => __( 'Protect the site against oversized checklists and misuse.', 'checkoff' ),
			'fields'      => array(
				array(
					'id'          => 'max_depth',
					'label'       => __( 'Nesting depth', 'checkoff' ),
					'description' => __( 'How many levels a checklist may have, the top level included.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 1,
					'max'         => Checkoff_Checklist::LIMIT_MAX_DEPTH,
					'default'     => Checkoff_Checklist::DEFAULT_MAX_DEPTH,
					'callback'    => array( $this, 'max_depth_callback' ),
				),
				array(
					'id'          => 'max_nodes',
					'label'       => __( 'Maximum size', 'checkoff' ),
					'description' => __( 'How many entries and sub-lists one checklist may contain in total.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 10,
					'max'         => Checkoff_Checklist::LIMIT_MAX_NODES,
					'default'     => Checkoff_Checklist::DEFAULT_MAX_NODES,
					'callback'    => array( $this, 'max_nodes_callback' ),
				),
				array(
					'id'          => 'create_per_hour',
					'label'       => __( 'New checklists per hour', 'checkoff' ),
					'description' => __( 'Per visitor. 0 = unlimited.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 1000,
					'default'     => 10,
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'create_total_per_hour',
					'label'       => __( 'New checklists per hour, all visitors', 'checkoff' ),
					'description' => __( 'All visitors together — stops a flood from many addresses, which no per-visitor quota can. 0 = unlimited.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 100000,
					'default'     => Checkoff_Quota::TOTAL_SETTINGS[ Checkoff_Quota::CREATE ][1],
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'ticks_per_hour',
					'label'       => __( 'Ticks per hour', 'checkoff' ),
					'description' => __( 'Per visitor: values written — ticks, ratings, counts, notes (a typed note is saved after each pause). Applies to the website and the REST API. 0 = unlimited.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 100000,
					'default'     => Checkoff_Quota::SETTINGS[ Checkoff_Quota::TICK ][1],
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'edits_per_hour',
					'label'       => __( 'Changes per hour', 'checkoff' ),
					'description' => __( 'Per visitor: saves of a checklist\'s structure (while editing, after each pause in typing), clearing all ticks, deleting. Applies to the website and the REST API. 0 = unlimited.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 100000,
					'default'     => Checkoff_Quota::SETTINGS[ Checkoff_Quota::EDIT ][1],
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'subscribe_per_hour',
					'label'       => __( 'Subscription requests per hour', 'checkoff' ),
					'description' => __( 'Per visitor. Every request sends a confirmation e-mail. 0 = unlimited (not recommended).', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 1000,
					'default'     => Checkoff_Quota::SETTINGS[ Checkoff_Quota::SUBSCRIBE ][1],
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'failed_lookups_per_hour',
					'label'       => __( 'Unknown IDs per hour', 'checkoff' ),
					'description' => __( 'Per visitor. After this many attempts with IDs that do not exist, opening checklists is blocked for an hour, so IDs cannot be guessed by trying. 0 = unlimited (not recommended).', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 1000,
					'default'     => 20,
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'unlock_per_hour',
					'label'       => __( 'Wrong passwords per hour', 'checkoff' ),
					/* translators: %s: number of wrong passwords per checklist and hour. */
					'description' => sprintf( __( 'Per visitor, for password-protected checklists. After this many wrong passwords, unlocking is refused for an hour, the right password included; the same happens after %s wrong passwords for one checklist from all visitors together. 0 = unlimited (not recommended).', 'checkoff' ), number_format_i18n( Checkoff_Password::UNLOCK_PER_LIST ) ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 1000,
					'default'     => Checkoff_Quota::SETTINGS[ Checkoff_Quota::UNLOCK ][1],
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'ip_source',
					'label'       => __( 'Visitor address from', 'checkoff' ),
					'description' => __( 'How visitors are told apart for the quotas. Keep "Connection" unless the site is behind a proxy or CDN such as Cloudflare — there every visitor would otherwise share one quota. Choose a header only if the proxy sets it: otherwise visitors can write it themselves and escape every quota. IPv6 addresses count per /64 network. The address is never stored, only a salted hash for one hour.', 'checkoff' ),
					'type'        => 'select',
					'options'     => array(
						'remote_addr'      => __( 'Connection (REMOTE_ADDR)', 'checkoff' ),
						'x_forwarded_for'  => 'X-Forwarded-For',
						'x_real_ip'        => 'X-Real-IP',
						'cf_connecting_ip' => 'CF-Connecting-IP (Cloudflare)',
					),
					'default'     => 'remote_addr',
					'callback'    => array( $this, 'ip_source_callback' ),
				),
			),
		);

		$settings['checkoff-email'] = array(
			'title'       => __( 'E-mail', 'checkoff' ),
			'description' => __( 'Subscriptions by e-mail and the mails CheckOff sends. Subscribers confirm their address first (double opt-in); only the address, the checklist and the time of confirmation are stored.', 'checkoff' ),
			'fields'      => $this->email_fields(),
		);

		$settings['checkoff-maintenance'] = array(
			'title'       => __( 'Maintenance', 'checkoff' ),
			'description' => __( 'Cleanup and uninstall.', 'checkoff' ),
			'fields'      => array(
				array(
					'id'          => 'cleanup_inactive_days',
					'label'       => __( 'Delete unused checklists', 'checkoff' ),
					'description' => __( 'Days without being opened after which a checklist is deleted automatically (checked once a day). 0 = never.', 'checkoff' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 3650,
					'default'     => 0,
					'callback'    => array( $this, 'absint_callback' ),
				),
				array(
					'id'          => 'delete_data_on_uninstall',
					'label'       => __( 'Delete checklists on uninstall', 'checkoff' ),
					'description' => __( 'When the plugin is deleted, also delete all checklists. Settings are always removed.', 'checkoff' ),
					'type'        => 'checkbox',
					'default'     => '',
					'callback'    => array( $this, 'checkbox_callback' ),
				),
			),
		);

		/**
		 * Filters the settings tabs and fields; add-ons append their own here.
		 *
		 * @param array $settings Tabs with their fields.
		 */
		return apply_filters( $this->base . 'settings_fields', $settings );
	}

	/**
	 * Fields of the e-mail tab.
	 *
	 * @return array
	 */
	private function email_fields(): array {
		return array(
			array(
				'id'    => 'email_group_subscriptions',
				'type'  => 'heading',
				'label' => __( 'Subscriptions', 'checkoff' ),
			),
			array(
				'id'          => 'subscriptions_enabled',
				'label'       => __( 'Allow subscriptions', 'checkoff' ),
				'description' => __( 'Visitors can subscribe to a checklist with their e-mail address. Set up the sender and the footer (imprint) below first.', 'checkoff' ),
				'type'        => 'checkbox',
				'default'     => '',
				'callback'    => array( $this, 'checkbox_callback' ),
			),
			array(
				'id'          => 'subscription_consent',
				'label'       => __( 'Consent text', 'checkoff' ),
				'description' => __( 'Shown beside the checkbox visitors tick to subscribe. Links are allowed. Empty: a standard sentence with a link to the privacy policy page.', 'checkoff' ),
				'type'        => 'textarea',
				'default'     => '',
				'callback'    => array( $this, 'consent_callback' ),
			),
			array(
				'id'          => 'subscriptions_per_checklist',
				'label'       => __( 'Subscriptions per checklist', 'checkoff' ),
				'description' => __( 'At most this many addresses can subscribe to one checklist.', 'checkoff' ),
				'type'        => 'number',
				'min'         => 1,
				'max'         => 50,
				'default'     => Checkoff_Subscriptions::DEFAULT_PER_CHECKLIST,
				'callback'    => array( $this, 'absint_callback' ),
			),
			array(
				'id'          => 'subscription_pending_hours',
				'label'       => __( 'Delete unconfirmed after', 'checkoff' ),
				'description' => __( 'Hours. A subscription that is not confirmed by then is deleted together with its address.', 'checkoff' ),
				'type'        => 'number',
				'min'         => 1,
				'max'         => 168,
				'default'     => Checkoff_Subscriptions::DEFAULT_PENDING_HOURS,
				'callback'    => array( $this, 'absint_callback' ),
			),
			array(
				'id'    => 'email_group_sender',
				'type'  => 'heading',
				'label' => __( 'Sender', 'checkoff' ),
			),
			array(
				'id'          => 'mail_from_name',
				'label'       => __( 'Sender name', 'checkoff' ),
				'description' => __( 'Empty: the site title.', 'checkoff' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'callback'    => array( $this, 'text_callback' ),
			),
			array(
				'id'          => 'mail_from_email',
				'label'       => __( 'Sender address', 'checkoff' ),
				'description' => __( 'Empty: the administration e-mail address. Use an address of this domain, or mails may end up as spam.', 'checkoff' ),
				'type'        => 'email',
				'default'     => '',
				'placeholder' => (string) get_option( 'admin_email' ),
				'callback'    => array( $this, 'email_callback' ),
			),
			array(
				'id'          => 'mail_footer',
				'label'       => __( 'Footer', 'checkoff' ),
				'description' => __( 'Under every mail, e.g. the imprint (who sends this mail and how to reach them). Links are allowed. Placeholder in templates: {{ footer_html }}.', 'checkoff' ),
				'type'        => 'textarea',
				'default'     => '',
				'callback'    => array( $this, 'html_callback' ),
			),
			array(
				'id'          => 'email_group_mails',
				'type'        => 'heading',
				'label'       => __( 'Mails', 'checkoff' ),
				'description' => __( 'Which mails subscribers receive and when. Each row is one mail: a trigger, a time, a subject and a template. Subjects may contain placeholders such as {{ checklist }}.', 'checkoff' ),
			),
			array(
				'id'          => 'confirm_subject',
				'label'       => __( 'Subject of the confirmation mail', 'checkoff' ),
				'description' => __( 'The mail with the confirmation link (template confirm.html). Empty: the default subject.', 'checkoff' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'Please confirm your subscription: {{ checklist }}', 'checkoff' ),
				'callback'    => array( $this, 'text_callback' ),
			),
			array(
				'id'       => 'mail_rules',
				'label'    => __( 'Sending rules', 'checkoff' ),
				'type'     => 'mail_rules',
				'callback' => array( 'Checkoff_Subscriptions', 'sanitize_rules' ),
			),
			array(
				'id'    => 'email_group_log',
				'type'  => 'heading',
				'label' => __( 'Send log', 'checkoff' ),
			),
			array(
				'id'          => 'mail_log_enabled',
				'label'       => __( 'Keep a send log', 'checkoff' ),
				'description' => __( 'Records time, masked recipient (e.g. jo***@ex***.de), subject and success of every mail — never the full address or the content. Off by default.', 'checkoff' ),
				'type'        => 'checkbox',
				'default'     => '',
				'callback'    => array( $this, 'checkbox_callback' ),
			),
			array(
				'id'          => 'mail_log_days',
				'label'       => __( 'Keep log entries for', 'checkoff' ),
				'description' => __( 'Days.', 'checkoff' ),
				'type'        => 'number',
				'min'         => 1,
				'max'         => 365,
				'default'     => Checkoff_Mail_Log::DEFAULT_RETENTION,
				'callback'    => array( $this, 'absint_callback' ),
			),
		);
	}

	/**
	 * Fields of the building blocks tab: one switch per block, then the
	 * colour scale and the number scale defaults.
	 *
	 * @return array
	 */
	private function block_fields(): array {

		$fields = array(
			array(
				'id'    => 'blocks_group_available',
				'type'  => 'heading',
				'label' => __( 'Available building blocks', 'checkoff' ),
			),
		);

		foreach ( Checkoff_Block_Types::all() as $key => $type ) {

			$fixed = Checkoff_Block_Types::FALLBACK === $key;

			$fields[] = array(
				'id'          => 'block_' . $key . '_enabled',
				'label'       => $type->icon() . ' ' . $type->label(),
				'description' => $fixed
					? $type->description() . ' ' . __( 'Always available, as every checklist is built on it.', 'checkoff' )
					: $type->description(),
				'type'        => 'checkbox',
				'disabled'    => $fixed,
				'default'     => 'on',
				'callback'    => array( $this, 'checkbox_callback' ),
			);
		}

		$fields[] = array(
			'id'          => 'blocks_group_colors',
			'type'        => 'heading',
			'label'       => __( 'Colour scale', 'checkoff' ),
			'description' => __( 'Five steps from lowest to highest. A scale with three colours uses the first, the middle and the last step.', 'checkoff' ),
		);

		$labels = Checkoff_Block_Color_Scale::default_labels();

		foreach ( Checkoff_Block_Color_Scale::DEFAULT_COLORS as $i => $color ) {

			$step = $i + 1;

			$fields[] = array(
				/* translators: %s: step number. */
				'label'    => sprintf( __( 'Step %s', 'checkoff' ), $step ),
				'id'       => Checkoff_Block_Color_Scale::COLOR_OPTION . $step,
				'type'     => 'color_step',
				'default'  => $color,
				'callback' => array( $this, 'color_callback' ),
				'pair'     => array(
					'id'          => Checkoff_Block_Color_Scale::LABEL_OPTION . $step,
					'placeholder' => $labels[ $i ],
				),
			);
		}

		$fields[] = array(
			'id'    => 'blocks_group_numbers',
			'type'  => 'heading',
			'label' => __( 'Rating with numbers', 'checkoff' ),
		);

		$fields[] = array(
			'id'          => 'number_scale_min',
			'label'       => __( 'Default from', 'checkoff' ),
			'description' => __( 'Lowest number of a new rating block. Visitors can change it per block.', 'checkoff' ),
			'type'        => 'number',
			'min'         => Checkoff_Block_Number_Scale::LIMIT_MIN,
			'max'         => Checkoff_Block_Number_Scale::LIMIT_MAX - 1,
			'default'     => 1,
			'callback'    => array( $this, 'int_callback' ),
		);

		$fields[] = array(
			'id'          => 'number_scale_max',
			'label'       => __( 'Default to', 'checkoff' ),
			'description' => __( 'Highest number of a new rating block.', 'checkoff' ),
			'type'        => 'number',
			'min'         => Checkoff_Block_Number_Scale::LIMIT_MIN + 1,
			'max'         => Checkoff_Block_Number_Scale::LIMIT_MAX,
			'default'     => 5,
			'callback'    => array( $this, 'int_callback' ),
		);

		return $fields;
	}

	/**
	 * Adds the settings page under the checklist menu.
	 *
	 * @return void
	 */
	public function add_menu_item(): void {

		$args = $this->menu_settings();

		if ( ! is_array( $args ) || empty( $args['location'] ) ) {
			return;
		}

		switch ( $args['location'] ) {
			case 'options':
			case 'submenu':
				add_submenu_page(
					$args['parent_slug'],
					$args['page_title'],
					$args['menu_title'],
					$args['capability'],
					$args['menu_slug'],
					$args['function']
				);
				break;
			case 'menu':
				add_menu_page(
					$args['page_title'],
					$args['menu_title'],
					$args['capability'],
					$args['menu_slug'],
					$args['function'],
					$args['icon_url'],
					$args['position']
				);
				break;
		}
	}

	/**
	 * Settings page arguments. Filterable to move the page elsewhere.
	 *
	 * @return mixed
	 */
	private function menu_settings(): mixed {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- resolves to checkoff_menu_settings.
		return apply_filters(
			$this->base . 'menu_settings',
			array(
				// Under the plugin's own menu, beside the checklists it governs.
				'location'    => 'submenu',
				'parent_slug' => 'edit.php?post_type=' . Checkoff_Post_Types::POST_TYPE,
				'page_title'  => __( 'CheckOff Settings', 'checkoff' ),
				'menu_title'  => __( 'Settings', 'checkoff' ),
				'capability'  => 'manage_options',
				'menu_slug'   => $this->parent->_token . '_settings',
				'function'    => array( $this, 'settings_page' ),
				'icon_url'    => 'dashicons-yes-alt',
				'position'    => 30,
			)
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * The admin URL of the settings screen, optionally of one tab.
	 *
	 * @param string $tab Optional tab key, e.g. 'checkoff-blocks'.
	 * @return string
	 */
	public static function settings_url( string $tab = '' ): string {

		$args = array(
			'post_type' => Checkoff_Post_Types::POST_TYPE,
			'page'      => 'checkoff_settings',
		);

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Add settings link to plugin list table.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_settings_link( array $links ): array {
		$links[] = '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'checkoff' ) . '</a>';

		return $links;
	}

	/**
	 * The tab to show: the requested one if it exists, else the first.
	 *
	 * @param string $requested Requested tab key.
	 * @return string
	 */
	public function resolve_tab( string $requested ): string {

		if ( '' !== $requested && isset( $this->settings[ $requested ] ) ) {
			return $requested;
		}

		return (string) array_key_first( (array) $this->settings );
	}

	/**
	 * Register plugin settings (of the current tab only — options.php saves
	 * exactly what was registered, so the other tabs keep their values).
	 *
	 * @return void
	 */
	public function register_settings(): void {

		if ( ! is_array( $this->settings ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- selects a tab, changes nothing.
		$current_section = '';
		if ( ! empty( $_POST['tab'] ) ) {
			$current_section = sanitize_text_field( wp_unslash( $_POST['tab'] ) );
		} elseif ( ! empty( $_GET['tab'] ) ) {
			$current_section = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$current_section = $this->resolve_tab( $current_section );
		$page            = $this->parent->_token . '_settings';

		foreach ( $this->settings as $section => $data ) {

			if ( $current_section !== $section ) {
				continue;
			}

			add_settings_section( $section, $data['title'], array( $this, 'settings_section' ), $page );

			foreach ( $data['fields'] as $field ) {

				// Group headings are display-only rows without an option.
				if ( 'heading' === ( $field['type'] ?? '' ) ) {
					add_settings_field(
						$field['id'],
						'',
						array( $this, 'settings_group_heading' ),
						$page,
						$section,
						array(
							'field' => $field,
							'class' => 'cko-settings-group',
						)
					);
					continue;
				}

				$args = array();
				if ( isset( $field['callback'] ) ) {
					$args['sanitize_callback'] = $field['callback'];
				}
				if ( isset( $field['default'] ) ) {
					$args['default'] = $field['default'];
				}

				register_setting( $page, $this->base . $field['id'], $args );

				// The label that belongs to a colour is a second option.
				if ( isset( $field['pair']['id'] ) ) {
					register_setting(
						$page,
						$this->base . $field['pair']['id'],
						array(
							'sanitize_callback' => array( $this, 'text_callback' ),
							'default'           => '',
						)
					);
				}

				add_settings_field(
					$field['id'],
					$field['label'],
					array( $this->parent->admin, 'display_field' ),
					$page,
					$section,
					array(
						'field'     => $field,
						'prefix'    => $this->base,
						'label_for' => in_array( $field['type'] ?? 'text', array( 'checkbox', 'color_step', 'mail_rules' ), true ) ? '' : $this->base . $field['id'],
					)
				);
			}
		}
	}

	/**
	 * Renders a visual group heading between settings rows.
	 *
	 * @param array $args Field arguments ('field' key).
	 * @return void
	 */
	public function settings_group_heading( array $args ): void {

		$field = $args['field'] ?? array();

		echo '<h3 class="cko-settings-group-title">' . esc_html( (string) ( $field['label'] ?? '' ) ) . '</h3>';

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="cko-settings-group-desc">' . esc_html( (string) $field['description'] ) . '</p>';
		}
	}

	/**
	 * Settings section intro.
	 *
	 * @param array $section Section arguments.
	 * @return void
	 */
	public function settings_section( array $section ): void {
		echo '<p>' . esc_html( $this->settings[ $section['id'] ]['description'] ) . '</p>' . "\n";
	}

	/**
	 * Load settings page content.
	 *
	 * @return void
	 */
	public function settings_page(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$tab = $this->resolve_tab( $tab );

		$html  = '<div class="wrap" id="' . esc_attr( $this->parent->_token ) . '_settings">' . "\n";
		$html .= '<h1>' . esc_html__( 'CheckOff Settings', 'checkoff' ) . '</h1>' . "\n";

		if ( is_array( $this->settings ) && 1 < count( $this->settings ) ) {

			$html .= '<h2 class="nav-tab-wrapper">' . "\n";

			foreach ( $this->settings as $section => $data ) {
				$class = 'nav-tab' . ( $section === $tab ? ' nav-tab-active' : '' );
				$html .= '<a href="' . esc_url( self::settings_url( $section ) ) . '" class="' . esc_attr( $class ) . '">'
					. esc_html( $data['title'] ) . '</a>' . "\n";
			}

			$html .= '</h2>' . "\n";
		}

		$html .= '<form method="post" action="options.php">' . "\n";

		ob_start();
		settings_fields( $this->parent->_token . '_settings' );
		do_settings_sections( $this->parent->_token . '_settings' );
		$html .= ob_get_clean();

		$html .= '<p class="submit">' . "\n";
		$html .= '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />' . "\n";
		$html .= '<input name="Submit" type="submit" class="button-primary" value="' . esc_attr__( 'Save Settings', 'checkoff' ) . '" />' . "\n";
		$html .= '</p>' . "\n";
		$html .= '</form>' . "\n";

		if ( 'checkoff-general' === $tab ) {
			$html .= $this->render_shortcode_help();
		}

		if ( 'checkoff-email' === $tab ) {
			$html .= $this->render_mail_help();
			$html .= $this->render_mail_log();
		}

		$html .= '</div>' . "\n";

		echo $html; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}

	/**
	 * A short reference of the shortcodes under the general tab.
	 *
	 * @return string
	 */
	private function render_shortcode_help(): string {

		$rows = array(
			'[cko_app]'              => __( 'Everything on one page: the checklist when the address carries an ID, otherwise the forms to create and open one and the recently opened checklists.', 'checkoff' ),
			'[cko_checklist]'        => __( 'The checklist whose ID is in the address (?cko=…).', 'checkoff' ),
			'[cko_checklist id="…"]' => __( 'A fixed checklist, e.g. embedded by the theme.', 'checkoff' ),
			'[cko_create]'           => __( 'Form to create a checklist. Attributes: title, button.', 'checkoff' ),
			'[cko_open]'             => __( 'Form to open a checklist by its ID. Attributes: title, button.', 'checkoff' ),
			'[cko_templates]'        => __( 'The templates of this site as cards, each with a button to start from it. Attributes: title, button.', 'checkoff' ),
			'[cko_recent]'           => __( 'Checklists recently opened on this device. Attribute: title.', 'checkoff' ),
		);

		$html  = '<h2>' . esc_html__( 'Shortcodes', 'checkoff' ) . '</h2>';
		$html .= '<table class="widefat striped cko-shortcode-help"><tbody>';

		foreach ( $rows as $code => $text ) {
			$html .= '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $text ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * The placeholders mail templates can use, and where templates live.
	 *
	 * @return string
	 */
	private function render_mail_help(): string {

		$placeholders = array(
			'{{ checklist }}'                         => __( 'Title of the checklist.', 'checkoff' ),
			'{{ checklist_url }}'                     => __( 'Link to the checklist (contains its ID).', 'checkoff' ),
			'{{ checklist_id }}'                      => __( 'The ID of the checklist.', 'checkoff' ),
			'{{ repetition }}'                        => __( 'The repetition in words, e.g. "Weekly, 6 times".', 'checkoff' ),
			'{{ round_label }}'                       => __( 'The current round, e.g. "Round 3 of 6" (empty if the checklist does not repeat).', 'checkoff' ),
			'{{ round_start }} / {{ round_end }}'     => __( 'First and last day of the current round.', 'checkoff' ),
			'{{ progress_summary }}'                  => __( 'E.g. "Round 3 of 6: 4 of 10 done (40 %)".', 'checkoff' ),
			'{{ progress_done }} / {{ progress_total }} / {{ progress_percent }}' => __( 'Progress of the current round as numbers.', 'checkoff' ),
			'{{ open_count }}'                        => __( 'Number of open entries.', 'checkoff' ),
			'{{ open_items_html }}'                   => __( 'The open entries as a list.', 'checkoff' ),
			'{{ items_html }}'                        => __( 'The whole checklist with the state of every entry.', 'checkoff' ),
			'{{ last_round_summary }}'                => __( 'How the previous round went, e.g. "Round 2: 8 of 10 done (80 %)." (empty in the first round).', 'checkoff' ),
			'{{ last_round_open_html }}'              => __( 'What stayed open in the previous round.', 'checkoff' ),
			'{{ unsubscribe_url }}'                   => __( 'Unsubscribe link (the layout already shows one).', 'checkoff' ),
			'{{ site_name }} / {{ site_url }} / {{ year }}' => __( 'The site.', 'checkoff' ),
			'{{ confirm_url }} / {{ pending_hours }}' => __( 'Only in confirm.html: the confirmation link and the hours until an unconfirmed subscription is deleted.', 'checkoff' ),
		);

		$html  = '<h2>' . esc_html__( 'Templates and placeholders', 'checkoff' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Templates are HTML files. To change one, copy it from the plugin folder templates/emails/ to <your theme>/checkoff/emails/ and edit the copy; files in the theme always win. A subfolder named after the site language (e.g. de_DE/) is used first. layout.html is the frame around every mail, confirm.html the confirmation mail; every other file can be chosen in a sending rule.', 'checkoff' ) . '</p>';
		$html .= '<table class="widefat striped cko-shortcode-help"><tbody>';

		foreach ( $placeholders as $code => $text ) {
			$html .= '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $text ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * The send log, when it is switched on.
	 *
	 * @return string
	 */
	private function render_mail_log(): string {

		if ( ! Checkoff_Mail_Log::is_enabled() ) {
			return '';
		}

		$entries = Checkoff_Mail_Log::get_entries();

		$html = '<h2>' . esc_html__( 'Send log', 'checkoff' ) . '</h2>';

		if ( empty( $entries ) ) {
			return $html . '<p>' . esc_html__( 'No mails sent yet.', 'checkoff' ) . '</p>';
		}

		$html .= '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Time', 'checkoff' ) . '</th>'
			. '<th>' . esc_html__( 'Recipient', 'checkoff' ) . '</th>'
			. '<th>' . esc_html__( 'Subject', 'checkoff' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'checkoff' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$html .= '<tr>'
				. '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</td>'
				. '<td>' . esc_html( (string) $entry['to'] ) . '</td>'
				. '<td>' . esc_html( (string) $entry['subject'] ) . '</td>'
				. '<td>' . ( 'sent' === $entry['status'] ? esc_html__( 'Sent', 'checkoff' ) : '<strong>' . esc_html__( 'Failed', 'checkoff' ) . '</strong>' ) . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table>';
		$html .= '<p><button type="button" class="button" id="cko-clear-log" data-nonce="' . esc_attr( wp_create_nonce( 'checkoff_mail_admin' ) ) . '">' . esc_html__( 'Clear log', 'checkoff' ) . '</button></p>';

		return $html;
	}

	/**
	 * Re-schedules the cleanup after its setting changed.
	 *
	 * @return void
	 */
	public function reschedule_cleanup(): void {
		Checkoff_Cleanup::unschedule();
		( new Checkoff_Cleanup() )->maybe_schedule();
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone(): void {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cloning of Checkoff_Settings is forbidden.', 'checkoff' ) ), esc_attr( $this->parent->_version ) );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup(): void {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Unserializing instances of Checkoff_Settings is forbidden.', 'checkoff' ) ), esc_attr( $this->parent->_version ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sanitize callbacks
	 * ------------------------------------------------------------------
	 */

	/**
	 * Checkbox: 'on' or ''.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function checkbox_callback( mixed $input ): string {
		return 'on' === $input ? 'on' : '';
	}

	/**
	 * Plain text.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function text_callback( mixed $input ): string {
		return is_scalar( $input ) ? sanitize_text_field( (string) $input ) : '';
	}

	/**
	 * Who may use the REST API.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function rest_access_callback( mixed $input ): string {
		return in_array( $input, array( 'public', 'users', 'off' ), true ) ? $input : 'public';
	}

	/**
	 * Where the visitor's address comes from.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function ip_source_callback( mixed $input ): string {
		return is_string( $input ) && isset( Checkoff_Rate_Limit::IP_SOURCES[ $input ] ) ? $input : 'remote_addr';
	}

	/**
	 * A non-negative whole number.
	 *
	 * @param mixed $input Raw input.
	 * @return int
	 */
	public function absint_callback( mixed $input ): int {
		return is_scalar( $input ) ? absint( $input ) : 0;
	}

	/**
	 * A whole number, possibly negative.
	 *
	 * @param mixed $input Raw input.
	 * @return int
	 */
	public function int_callback( mixed $input ): int {
		return is_numeric( $input ) ? (int) $input : 0;
	}

	/**
	 * A published page, or 0.
	 *
	 * @param mixed $input Raw input.
	 * @return int
	 */
	public function page_callback( mixed $input ): int {
		$page_id = is_scalar( $input ) ? absint( $input ) : 0;
		return ( $page_id > 0 && 'page' === get_post_type( $page_id ) ) ? $page_id : 0;
	}

	/**
	 * Length of new IDs, clamped.
	 *
	 * @param mixed $input Raw input.
	 * @return int
	 */
	public function id_length_callback( mixed $input ): int {
		$length = is_numeric( $input ) ? (int) $input : Checkoff_Public_Id::DEFAULT_LENGTH;
		return max( Checkoff_Public_Id::MIN_LENGTH, min( Checkoff_Public_Id::MAX_LENGTH, $length ) );
	}

	/**
	 * Nesting depth, clamped.
	 *
	 * @param mixed $input Raw input.
	 * @return int
	 */
	public function max_depth_callback( mixed $input ): int {
		$depth = is_numeric( $input ) ? (int) $input : Checkoff_Checklist::DEFAULT_MAX_DEPTH;
		return max( 1, min( Checkoff_Checklist::LIMIT_MAX_DEPTH, $depth ) );
	}

	/**
	 * Maximum size, clamped.
	 *
	 * @param mixed $input Raw input.
	 * @return int
	 */
	public function max_nodes_callback( mixed $input ): int {
		$nodes = is_numeric( $input ) ? (int) $input : Checkoff_Checklist::DEFAULT_MAX_NODES;
		return max( 10, min( Checkoff_Checklist::LIMIT_MAX_NODES, $nodes ) );
	}

	/**
	 * An e-mail address, or ''.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function email_callback( mixed $input ): string {
		$email = is_scalar( $input ) ? sanitize_email( (string) $input ) : '';
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Text with a few inline tags (links, emphasis) and line breaks.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function html_callback( mixed $input ): string {
		return is_scalar( $input ) ? wp_kses_post( trim( (string) $input ) ) : '';
	}

	/**
	 * The consent sentence: text with links and emphasis only.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function consent_callback( mixed $input ): string {
		return is_scalar( $input )
			? wp_kses(
				trim( (string) $input ),
				array(
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
					'strong' => array(),
					'em'     => array(),
				)
			)
			: '';
	}

	/**
	 * A hex colour, or '' for the default.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public function color_callback( mixed $input ): string {
		return is_scalar( $input ) ? (string) sanitize_hex_color( (string) $input ) : '';
	}
}
