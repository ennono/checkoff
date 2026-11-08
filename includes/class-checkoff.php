<?php
/**
 * Main plugin class file.
 *
 * @package CheckOff Plugin/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class Checkoff {

	/**
	 * The single instance of Checkoff.
	 *
	 * @var     Checkoff|null
	 * @access  private
	 */
	private static $_instance = null; //phpcs:ignore

	/**
	 * Local instance of Checkoff_Admin_API
	 *
	 * @var Checkoff_Admin_API|null
	 */
	public $admin = null;

	/**
	 * Settings class object
	 *
	 * @var     Checkoff_Settings|null
	 * @access  public
	 */
	public $settings = null;

	/**
	 * The version number.
	 *
	 * @var     string
	 * @access  public
	 */
	public $_version; //phpcs:ignore

	/**
	 * The token.
	 *
	 * @var     string
	 * @access  public
	 */
	public $_token; //phpcs:ignore

	/**
	 * The main plugin file.
	 *
	 * @var     string
	 * @access  public
	 */
	public $file;

	/**
	 * The main plugin directory.
	 *
	 * @var     string
	 * @access  public
	 */
	public $dir;

	/**
	 * The plugin assets directory.
	 *
	 * @var     string
	 * @access  public
	 */
	public $assets_dir;

	/**
	 * The plugin assets URL.
	 *
	 * @var     string
	 * @access  public
	 */
	public $assets_url;

	/**
	 * Suffix for JavaScripts.
	 *
	 * @var     string
	 * @access  public
	 */
	public $script_suffix;

	/**
	 * Constructor function.
	 *
	 * @param   string $file     Main plugin file.
	 * @param   string $version  Plugin version.
	 */
	public function __construct( string $file = '', string $version = '0.0.1' ) {
		$this->_version = $version;
		$this->_token   = 'checkoff';

		// Load plugin environment variables.
		$this->file       = $file;
		$this->dir        = dirname( $this->file );
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		$this->script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		register_activation_hook( $this->file, array( $this, 'install' ) );
		register_deactivation_hook( $this->file, array( $this, 'deactivate' ) );

		// Frontend assets are only registered here; the shortcodes enqueue
		// them, so pages without a checklist load nothing of this plugin.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );

		// Load admin JS & CSS.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_styles' ) );

		// Load API for generic admin functions.
		if ( is_admin() ) {
			$this->admin = new Checkoff_Admin_API();
		}

		// Handle localisation. Must wait for init: since WP 6.7 a textdomain
		// loaded earlier is refused with a _doing_it_wrong notice.
		add_action( 'init', array( $this, 'load_plugin_textdomain' ), 0 );
	}

	/**
	 * Main Checkoff Instance
	 *
	 * Ensures only one instance of Checkoff is loaded or can be loaded.
	 *
	 * @param   string $file     Main plugin file.
	 * @param   string $version  Version parameter.
	 *
	 * @return static Checkoff instance
	 */
	public static function instance( string $file = '', string $version = '0.0.1' ): static {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $file, $version );
		}

		return self::$_instance;
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @return void
	 */
	public function load_plugin_textdomain(): void {
		$domain = 'checkoff';

		// A core filter, not one this plugin invents.
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.

		load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );

		/*
		 * Kept despite being discouraged: the plugin ships its own catalogue in
		 * lang/, which core does not load on its own.
		 */
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- plugin ships its own catalogue in lang/.
		load_plugin_textdomain( $domain, false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
	}

	/**
	 * Wrapper function to instantiate the post types class.
	 *
	 * @return Checkoff_Post_Types
	 */
	public function add_post_types(): Checkoff_Post_Types {
		return new Checkoff_Post_Types();
	}

	/**
	 * Wrapper function to add the admin meta boxes.
	 *
	 * @return Checkoff_Post_Meta
	 */
	public function add_post_meta(): Checkoff_Post_Meta {
		return new Checkoff_Post_Meta();
	}

	/**
	 * Wrapper function to instantiate the shortcodes (and their AJAX handlers).
	 *
	 * @return Checkoff_Shortcodes
	 */
	public function add_shortcodes(): Checkoff_Shortcodes {
		return new Checkoff_Shortcodes();
	}

	/**
	 * Wrapper function to instantiate the cleanup (WP-Cron).
	 *
	 * @return Checkoff_Cleanup
	 */
	public function add_cleanup(): Checkoff_Cleanup {
		return new Checkoff_Cleanup();
	}

	/**
	 * Wrapper function to instantiate the e-mail subscriptions (double
	 * opt-in, sending rules, cron dispatch).
	 *
	 * @return Checkoff_Subscriptions
	 */
	public function add_subscriptions(): Checkoff_Subscriptions {
		return new Checkoff_Subscriptions();
	}

	/**
	 * Wrapper function to instantiate the privacy integration.
	 *
	 * @return Checkoff_Privacy
	 */
	public function add_privacy(): Checkoff_Privacy {
		return new Checkoff_Privacy();
	}

	/**
	 * Wrapper function to instantiate the REST API.
	 *
	 * @return Checkoff_Rest_Api
	 */
	public function add_rest_api(): Checkoff_Rest_Api {
		return new Checkoff_Rest_Api();
	}

	/**
	 * Wrapper function to instantiate the tables and their upkeep.
	 *
	 * @return Checkoff_Schema
	 */
	public function add_schema(): Checkoff_Schema {
		return new Checkoff_Schema();
	}

	/**
	 * Wrapper function to instantiate the history of closed rounds.
	 *
	 * @return Checkoff_History
	 */
	public function add_history(): Checkoff_History {
		return new Checkoff_History();
	}

	/**
	 * Wrapper function to instantiate the checklist page lookup and notice.
	 *
	 * @return Checkoff_Checklist_Page
	 */
	public function add_checklist_page(): Checkoff_Checklist_Page {
		return new Checkoff_Checklist_Page();
	}

	/**
	 * Registers (does not enqueue) the frontend CSS and JS.
	 *
	 * @return void
	 */
	public function register_frontend_assets(): void {

		$css_file = 'css/frontend.css';
		$js_file  = 'js/frontend' . $this->script_suffix . '.js';

		wp_register_style(
			$this->_token . '-frontend',
			esc_url( $this->assets_url ) . $css_file,
			array(),
			$this->asset_version( $css_file )
		);

		// Bundled, not loaded from a CDN: no visitor's address goes to a
		// third party. SortableJS (MIT) sorts by drag and drop, also by
		// touch; the Lucide icons (ISC) are inline SVG.
		wp_register_script(
			$this->_token . '-sortable',
			esc_url( $this->assets_url ) . 'vendor/sortablejs/Sortable.min.js',
			array(),
			'1.15.6',
			array( 'in_footer' => true )
		);

		wp_register_script(
			$this->_token . '-icons',
			esc_url( $this->assets_url ) . 'vendor/lucide/icons.js',
			array(),
			$this->asset_version( 'vendor/lucide/icons.js' ),
			array( 'in_footer' => true )
		);

		wp_register_script(
			$this->_token . '-frontend',
			esc_url( $this->assets_url ) . $js_file,
			array( 'jquery', $this->_token . '-sortable', $this->_token . '-icons' ),
			$this->asset_version( $js_file ),
			array( 'in_footer' => true )
		);
	}

	/**
	 * Enqueues the frontend assets once and passes the configuration and the
	 * translated strings to the script. Called by every shortcode that needs
	 * them.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {

		static $done = false;

		if ( $done ) {
			return;
		}
		$done = true;

		if ( ! wp_script_is( $this->_token . '-frontend', 'registered' ) ) {
			$this->register_frontend_assets();
		}

		wp_enqueue_style( $this->_token . '-frontend' );
		wp_enqueue_script( $this->_token . '-frontend' );

		wp_localize_script( $this->_token . '-frontend', 'checkoff', $this->frontend_config() );
	}

	/**
	 * Everything the frontend script needs to know: endpoint, nonce, the
	 * building blocks (from the same classes that validate them on save) and
	 * every string it shows.
	 *
	 * @return array
	 */
	private function frontend_config(): array {

		return array(
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( Checkoff_Shortcodes::NONCE_ACTION ),
			'query_var'     => Checkoff_Checklist::QUERY_VAR,
			'blocks'        => Checkoff_Block_Types::to_client(),
			'enabled'       => Checkoff_Block_Types::enabled_keys(),
			'max_depth'     => Checkoff_Checklist::max_depth(),
			'max_nodes'     => Checkoff_Checklist::max_nodes(),
			'week_start'    => (int) get_option( 'start_of_week', 1 ),
			'today'         => (int) wp_date( 'N' ),
			'weekdays'      => Checkoff_Block_Weekdays::weekday_names( false ),
			'weekdays_abbr' => Checkoff_Block_Weekdays::weekday_names( true ),
			'today_ymd'     => (string) wp_date( 'Y-m-d' ),
			'locale'        => str_replace( '_', '-', determine_locale() ),
			'password_min'  => Checkoff_Checklist::PASSWORD_MIN,
			'lists_enabled' => Checkoff_Directory::is_enabled(),
			'sync_interval' => Checkoff_Shortcodes::sync_interval(),
			'subscribe'     => array(
				'enabled' => Checkoff_Subscriptions::is_enabled(),
				'consent' => Checkoff_Subscriptions::is_enabled() ? Checkoff_Subscriptions::consent_html() : '',
			),
			'i18n'          => array(
				'edit'                    => __( 'Edit', 'checkoff' ),
				'done_editing'            => __( 'Done', 'checkoff' ),
				'add_item'                => __( 'Add item', 'checkoff' ),
				'add_block'               => __( 'Add sub-list', 'checkoff' ),
				'add_section'             => __( 'Add building block', 'checkoff' ),
				'mode'                    => __( 'Mode', 'checkoff' ),
				'mode_tick'               => __( 'Tick off', 'checkoff' ),
				'mode_edit'               => __( 'Edit', 'checkoff' ),
				'share'                   => __( 'Share', 'checkoff' ),
				'copy'                    => __( 'Copy', 'checkoff' ),
				'link_list'               => __( 'Link to the checklist', 'checkoff' ),
				'list_id'                 => __( 'ID of the checklist', 'checkoff' ),
				'link_hint_open'          => __( 'Whoever has the link can view, tick and change the checklist.', 'checkoff' ),
				'link_hint_protected'     => __( 'Whoever has the link can view and tick the checklist; changing it needs the password.', 'checkoff' ),
				'link_hint_private'       => __( 'Whoever has the link also needs the password to see the checklist.', 'checkoff' ),
				'access'                  => __( 'Access', 'checkoff' ),
				'access_change'           => __( 'Change access', 'checkoff' ),
				'access_title'            => __( 'Who may do what with the link?', 'checkoff' ),
				'access_saved'            => __( 'Access changed.', 'checkoff' ),
				'level_open'              => _x( 'Open', 'access level', 'checkoff' ),
				'level_open_desc'         => __( 'Everybody with the link can view, tick and change the checklist.', 'checkoff' ),
				'level_protected'         => __( 'Protected', 'checkoff' ),
				'level_protected_desc'    => __( 'Everybody with the link can view and tick; changing and deleting need the password.', 'checkoff' ),
				'level_private'           => __( 'Private', 'checkoff' ),
				'level_private_desc'      => __( 'Without the password nobody sees the checklist, not even with the link.', 'checkoff' ),
				'password_keep'           => __( 'Leave empty to keep the current password.', 'checkoff' ),
				'password_forget'         => __( 'There are no accounts here: if you forget the password, only the administrator of this website can remove it.', 'checkoff' ),
				'listed'                  => __( 'Show publicly', 'checkoff' ),
				'listed_hint'             => __( 'The checklist appears in the directory of public checklists: everybody can open, view and tick it. Do not show personal data.', 'checkoff' ),
				'listed_stop'             => __( 'Do not show publicly any more', 'checkoff' ),
				'listed_on'               => __( 'The checklist is shown publicly.', 'checkoff' ),
				'listed_off'              => __( 'The checklist is no longer shown publicly.', 'checkoff' ),
				'sealed_title'            => __( 'Private checklist', 'checkoff' ),
				'sealed_text'             => __( 'This checklist is private. Enter its password to open it.', 'checkoff' ),
				'open'                    => __( 'Open', 'checkoff' ),
				'new_entry'               => __( 'New entry …', 'checkoff' ),
				'block_actions'           => __( 'More actions', 'checkoff' ),
				'more_actions'            => __( 'More actions', 'checkoff' ),
				'undo'                    => __( 'Undo', 'checkoff' ),
				'deleted_entry'           => __( 'Entry deleted.', 'checkoff' ),
				'deleted_section'         => __( 'Building block deleted.', 'checkoff' ),
				'all_done'                => __( 'All done!', 'checkoff' ),
				'drag'                    => __( 'Drag to sort', 'checkoff' ),
				'cancel'                  => __( 'Cancel', 'checkoff' ),
				'close'                   => __( 'Close', 'checkoff' ),
				'section_placeholder'     => __( 'Heading (optional)', 'checkoff' ),
				'empty_checklist'         => __( 'This checklist is still empty.', 'checkoff' ),
				'empty_checklist_edit'    => __( 'No building blocks yet. Add one below.', 'checkoff' ),
				'item_placeholder'        => __( 'What needs to be done?', 'checkoff' ),
				'title_placeholder'       => __( 'Title', 'checkoff' ),
				'description_placeholder' => __( 'Description (optional)', 'checkoff' ),
				'choose_block'            => __( 'Choose a building block', 'checkoff' ),
				'move_up'                 => __( 'Move up', 'checkoff' ),
				'move_down'               => __( 'Move down', 'checkoff' ),
				'duplicate'               => __( 'Duplicate', 'checkoff' ),
				'delete'                  => __( 'Delete', 'checkoff' ),
				'settings'                => __( 'Settings', 'checkoff' ),
				'collapse'                => __( 'Collapse', 'checkoff' ),
				'expand'                  => __( 'Expand', 'checkoff' ),
				'confirm_delete_item'     => __( 'Delete this entry?', 'checkoff' ),
				'confirm_delete_block'    => __( 'Delete this sub-list with everything in it?', 'checkoff' ),
				'confirm_delete_list'     => __( 'Delete the whole checklist? This cannot be undone.', 'checkoff' ),
				'confirm_reset'           => __( 'Clear all ticks and ratings of this checklist?', 'checkoff' ),
				'delete_list'             => __( 'Delete checklist', 'checkoff' ),
				'reset_values'            => __( 'Clear all ticks', 'checkoff' ),
				'saving'                  => __( 'Saving…', 'checkoff' ),
				'saved'                   => __( 'Saved', 'checkoff' ),
				'save_failed'             => __( 'Could not be saved. Please try again.', 'checkoff' ),
				'conflict'                => __( 'This checklist was changed somewhere else. Reload the page to see the latest version.', 'checkoff' ),
				'reload'                  => __( 'Reload', 'checkoff' ),
				'limit_depth'             => __( 'Sub-lists cannot be nested any deeper.', 'checkoff' ),
				'limit_nodes'             => __( 'This checklist has reached its maximum size.', 'checkoff' ),
				'empty_block'             => __( 'Nothing here yet.', 'checkoff' ),
				'empty_block_edit'        => __( 'Nothing here yet. Add an item or a sub-list.', 'checkoff' ),
				/* translators: 1: number of completed entries, 2: number of entries. */
				'progress'                => __( '%1$s of %2$s', 'checkoff' ),
				'id_hint'                 => __( 'Keep the link or the ID: with it you find this checklist again.', 'checkoff' ),
				'copy_id'                 => __( 'Copy ID', 'checkoff' ),
				'copy_link'               => __( 'Copy link', 'checkoff' ),
				'copied'                  => __( 'Copied.', 'checkoff' ),
				'copy_failed'             => __( 'Copying failed. Please copy it by hand.', 'checkoff' ),
				'created'                 => __( 'Your checklist has been created.', 'checkoff' ),
				'create_failed'           => __( 'The checklist could not be created.', 'checkoff' ),
				'deleted'                 => __( 'The checklist has been deleted.', 'checkoff' ),
				'view'                    => _x( 'View', 'list or board', 'checkoff' ),
				'view_default_hint'       => __( 'What everybody sees first. Beside the title, anybody can switch between list and board for themselves.', 'checkoff' ),
				'view_list'               => __( 'List', 'checkoff' ),
				'view_board'              => __( 'Board', 'checkoff' ),
				/* translators: %s: 1, 2 or 3. */
				'board_column_n'          => __( 'Column %s', 'checkoff' ),
				'board_hint'              => __( 'While ticking, the sections become lanes and the entries cards that move from column to column: open while nothing is entered, in progress once something is, done by the building block\'s own rule. Empty fields keep the default names.', 'checkoff' ),
				'stage_open'              => _x( 'Open', 'board column', 'checkoff' ),
				'stage_doing'             => __( 'In progress', 'checkoff' ),
				'stage_done'              => _x( 'Done', 'board column', 'checkoff' ),
				'move_card'               => __( 'Move card', 'checkoff' ),
				/* translators: %s: column name. */
				'move_to'                 => __( 'Move to “%s”', 'checkoff' ),
				/* translators: %s: column name. */
				'card_moved'              => __( 'Moved to “%s”.', 'checkoff' ),
				'card_needs_value'        => __( 'Enter the value — then the card moves on by itself.', 'checkoff' ),
				'recent_clear'            => __( 'Clear the list', 'checkoff' ),
				'recent_clear_confirm'    => __( 'Remove every checklist from this list on this device? The checklists themselves stay as they are.', 'checkoff' ),
				/* translators: %s: number of ticks waiting. */
				'offline_waiting'         => __( 'Offline — %s waiting to be sent', 'checkoff' ),
				'offline_sent'            => __( 'Sent now that there is a connection again.', 'checkoff' ),
				'recent_remove'           => __( 'Remove from this list', 'checkoff' ),
				'week_reset_note'         => __( 'Starts empty every week.', 'checkoff' ),
				'today'                   => __( 'Today', 'checkoff' ),
				'not_rated'               => __( 'Not rated', 'checkoff' ),
				'not_needed'              => __( 'Not needed', 'checkoff' ),
				'unlock_to_edit'          => __( 'Unlock to edit', 'checkoff' ),
				'unlock_title'            => __( 'Protected checklist', 'checkoff' ),
				'unlock_text'             => __( 'Enter the password to edit, clear or delete this checklist. Ticking works without it.', 'checkoff' ),
				'unlock'                  => __( 'Unlock', 'checkoff' ),
				'password'                => __( 'Password', 'checkoff' ),
				'password_new'            => __( 'New password', 'checkoff' ),
				'password_wrong'          => __( 'The password is not correct.', 'checkoff' ),
				'password_lock'           => __( 'Lock on this device', 'checkoff' ),
				'save'                    => __( 'Save', 'checkoff' ),
				/* translators: %s: number of the variant. */
				'variant_n'               => __( 'Variant %s', 'checkoff' ),
				'add_variant'             => __( 'Add variant', 'checkoff' ),
				'switch_edit_note'        => __( 'Name the variants in their headings. When ticking, a switch above them chooses which one applies; only that one is shown and counts.', 'checkoff' ),
				'round_previous'          => __( 'Previous round', 'checkoff' ),
				'round_next'              => __( 'Next round', 'checkoff' ),
				'round_current'           => __( 'Back to the current round', 'checkoff' ),
				'round_past'              => __( 'A past round, as it was ticked — only to look at.', 'checkoff' ),
				'history_failed'          => __( 'The history could not be loaded.', 'checkoff' ),
				'history_delete'          => __( 'Delete history', 'checkoff' ),
				'history_delete_confirm'  => __( 'Delete the ticks and values of all closed rounds? This cannot be undone.', 'checkoff' ),
				'history_deleted'         => __( 'History deleted.', 'checkoff' ),
				/* translators: %s: number of the round. */
				'round_number'            => __( 'Round %s', 'checkoff' ),
				'mark_not_needed'         => __( 'Not needed this time', 'checkoff' ),
				'due_date'                => __( 'Due date', 'checkoff' ),
				/* translators: %s: short date. */
				'due_until'               => __( 'by %s', 'checkoff' ),
				/* translators: %s: short date. */
				'due_overdue'             => __( 'overdue since %s', 'checkoff' ),
				'due_today'               => __( 'due today', 'checkoff' ),
				'due_tomorrow'            => __( 'due tomorrow', 'checkoff' ),
				/* translators: %s: target range, e.g. "60–70 kg". */
				'target'                  => __( 'Target: %s', 'checkoff' ),
				'in_range'                => __( 'In the target range', 'checkoff' ),
				'out_of_range'            => __( 'Outside the target range', 'checkoff' ),
				'increase'                => __( 'Increase', 'checkoff' ),
				'decrease'                => __( 'Decrease', 'checkoff' ),
				'block_type'              => __( 'Building block', 'checkoff' ),
				'item_label'              => __( 'Entry', 'checkoff' ),
				'updated'                 => __( 'Updated from another device.', 'checkoff' ),
				'repeat'                  => __( 'Repeat', 'checkoff' ),
				'repeat_none'             => __( 'Does not repeat', 'checkoff' ),
				'repeat_daily'            => __( 'Daily', 'checkoff' ),
				'repeat_weekly'           => __( 'Weekly', 'checkoff' ),
				'repeat_monthly'          => __( 'Monthly', 'checkoff' ),
				'repeat_every'            => __( 'Every', 'checkoff' ),
				'unit_days'               => __( 'days', 'checkoff' ),
				'unit_weeks'              => __( 'weeks', 'checkoff' ),
				'unit_months'             => __( 'months', 'checkoff' ),
				'repeat_start'            => __( 'Starting', 'checkoff' ),
				'repeat_end'              => __( 'Ends', 'checkoff' ),
				'end_never'               => __( 'Never', 'checkoff' ),
				'end_count'               => __( 'After a number of rounds', 'checkoff' ),
				'end_until'               => __( 'On a date', 'checkoff' ),
				'rounds'                  => __( 'Rounds', 'checkoff' ),
				'repeat_hint'             => __( 'Every round starts with all ticks cleared. The previous round is kept as a summary.', 'checkoff' ),
				/* translators: 1: number of the round, 2: number of rounds. */
				'round_of'                => __( 'Round %1$s of %2$s', 'checkoff' ),
				/* translators: %s: number of the round. */
				'round'                   => __( 'Round %s', 'checkoff' ),
				/* translators: 1: first day, 2: last day. */
				'round_dates'             => __( '%1$s – %2$s', 'checkoff' ),
				/* translators: %s: date. */
				'starts_on'               => __( 'Starts on %s', 'checkoff' ),
				'series_finished'         => __( 'All rounds are over.', 'checkoff' ),
				/* translators: 1: "Round 2", 2: entries done, 3: entries. */
				'last_round'              => __( 'Last time (%1$s): %2$s of %3$s done', 'checkoff' ),
				'subscribe'               => __( 'Subscribe', 'checkoff' ),
				'subscribe_title'         => __( 'Get e-mails about this checklist', 'checkoff' ),
				'email'                   => __( 'E-mail address', 'checkoff' ),
				'subscribe_submit'        => __( 'Subscribe', 'checkoff' ),
				'subscribe_failed'        => __( 'The subscription could not be saved. Please try again.', 'checkoff' ),
			),
		);
	}

	/**
	 * Admin enqueue style (only on the plugin's own screens).
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 * @return void
	 */
	public function admin_enqueue_styles( string $hook = '' ): void {

		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		wp_register_style(
			$this->_token . '-admin',
			esc_url( $this->assets_url ) . 'css/admin.css',
			array(),
			$this->asset_version( 'css/admin.css' )
		);
		wp_enqueue_style( $this->_token . '-admin' );
	}

	/**
	 * Load admin Javascript (only on the plugin's own screens).
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 * @return void
	 */
	public function admin_enqueue_scripts( string $hook = '' ): void {

		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		$js_file = 'js/admin' . $this->script_suffix . '.js';

		wp_register_script(
			$this->_token . '-admin',
			esc_url( $this->assets_url ) . $js_file,
			array( 'jquery' ),
			$this->asset_version( $js_file ),
			array( 'in_footer' => true )
		);
		wp_enqueue_script( $this->_token . '-admin' );

		wp_localize_script(
			$this->_token . '-admin',
			'checkoffAdmin',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'copied'        => __( 'Copied.', 'checkoff' ),
				'copy_failed'   => __( 'Copying failed. Please copy it by hand.', 'checkoff' ),
				'sending'       => __( 'Sending…', 'checkoff' ),
				'test_failed'   => __( 'The test e-mail could not be sent.', 'checkoff' ),
				'confirm_clear' => __( 'Delete all entries of the send log?', 'checkoff' ),
				'cleared'       => __( 'The send log is empty.', 'checkoff' ),
			)
		);
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function is_plugin_screen(): bool {

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return Checkoff_Post_Types::POST_TYPE === $screen->post_type
			|| str_contains( (string) $screen->id, $this->_token . '_settings' );
	}

	/**
	 * Version string for an asset: the plugin version plus the file's
	 * modification time, so a changed file behind an unchanged plugin version
	 * does not stay in browser caches.
	 *
	 * @param string $relative Path below assets/.
	 * @return string
	 */
	private function asset_version( string $relative ): string {

		$path = $this->assets_dir . '/' . $relative;

		return file_exists( $path ) ? $this->_version . '.' . filemtime( $path ) : $this->_version;
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone(): void {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cloning of CheckOff is forbidden', 'checkoff' ) ), esc_attr( $this->_version ) );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup(): void {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Unserializing instances of CheckOff is forbidden', 'checkoff' ) ), esc_attr( $this->_version ) );
	}

	/**
	 * Installation. Runs on activation.
	 *
	 * @return void
	 */
	public function install(): void {
		Checkoff_Schema::maybe_install();
		$this->_log_version_number();
	}

	/**
	 * Deactivation: removes the scheduled cleanup.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		Checkoff_Cleanup::unschedule();
		Checkoff_Subscriptions::unschedule();
	}

	/**
	 * Log the plugin version number.
	 *
	 * @return void
	 */
	private function _log_version_number(): void { //phpcs:ignore
		update_option( $this->_token . '_version', $this->_version );
	}
}
