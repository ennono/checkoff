<?php
/**
 * Quotas.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every hourly quota of the plugin, in one place, for the page and the REST
 * API alike — a quota checked in only one of them would simply be bypassed
 * through the other.
 *
 * | Action      | Counts                                                  | Setting (per hour, 0 = unlimited)        |
 * |-------------|---------------------------------------------------------|------------------------------------------|
 * | `create`    | new checklists, per visitor                             | `checkoff_create_per_hour` (10)          |
 * | `create`    | new checklists, all visitors together                   | `checkoff_create_total_per_hour` (100)   |
 * | `tick`      | values written (ticks, ratings, notes typed)            | `checkoff_ticks_per_hour` (1200)         |
 * | `edit`      | structure saves, resets, deletions                      | `checkoff_edits_per_hour` (600)          |
 * | `subscribe` | subscription requests                                   | `checkoff_subscribe_per_hour` (5)        |
 * | `lookup`    | unknown IDs tried (then every lookup is refused)        | `checkoff_failed_lookups_per_hour` (20)  |
 * | `unlock`    | wrong passwords (then every unlock is refused)          | `checkoff_unlock_per_hour` (10)          |
 *
 * The page saves a typed note or a renamed entry after a short pause, so
 * the tick and edit quotas are generous: they stop scripts, not people.
 * The total for creating stops a flood from many addresses, which no
 * per-visitor quota can.
 */
class Checkoff_Quota {

	const CREATE    = 'create';
	const TICK      = 'tick';
	const EDIT      = 'edit';
	const SUBSCRIBE = 'subscribe';
	const UNLOCK    = 'unlock';

	/**
	 * Setting and default of each per-visitor quota.
	 */
	const SETTINGS = array(
		self::CREATE    => array( 'checkoff_create_per_hour', 10 ),
		self::TICK      => array( 'checkoff_ticks_per_hour', 1200 ),
		self::EDIT      => array( 'checkoff_edits_per_hour', 600 ),
		self::SUBSCRIBE => array( 'checkoff_subscribe_per_hour', 5 ),
		self::UNLOCK    => array( 'checkoff_unlock_per_hour', 10 ),
	);

	/**
	 * Setting and default of the quota of all visitors together.
	 */
	const TOTAL_SETTINGS = array(
		self::CREATE => array( 'checkoff_create_total_per_hour', 100 ),
	);

	/**
	 * The hourly quota of one visitor for an action (0 = unlimited).
	 *
	 * @param string $action An action constant.
	 * @return int
	 */
	public static function limit( string $action ): int {

		if ( ! isset( self::SETTINGS[ $action ] ) ) {
			return 0;
		}

		list( $option, $default ) = self::SETTINGS[ $action ];

		return max( 0, (int) get_option( $option, $default ) );
	}

	/**
	 * The hourly quota of all visitors together (0 = unlimited).
	 *
	 * @param string $action An action constant.
	 * @return int
	 */
	public static function total_limit( string $action ): int {

		if ( ! isset( self::TOTAL_SETTINGS[ $action ] ) ) {
			return 0;
		}

		list( $option, $default ) = self::TOTAL_SETTINGS[ $action ];

		return max( 0, (int) get_option( $option, $default ) );
	}

	/**
	 * Takes one unit of a quota: refuses when it is used up, counts it
	 * otherwise.
	 *
	 * @param string $action An action constant.
	 * @return true|WP_Error 429 with retry_after (seconds) when used up.
	 */
	public static function take( string $action ): bool|WP_Error {

		$refused = self::refusal( $action );

		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		Checkoff_Rate_Limit::hit( $action );

		if ( self::total_limit( $action ) > 0 ) {
			Checkoff_Rate_Limit::hit( $action, true );
		}

		return true;
	}

	/**
	 * Whether a quota is used up, without counting.
	 *
	 * @param string $action An action constant.
	 * @return true|WP_Error
	 */
	public static function refusal( string $action ): bool|WP_Error {

		if ( Checkoff_Rate_Limit::is_exceeded( $action, self::limit( $action ) ) ) {
			return self::error( $action, Checkoff_Rate_Limit::retry_after( $action ) );
		}

		if ( Checkoff_Rate_Limit::is_exceeded( $action, self::total_limit( $action ), true ) ) {
			return self::error( $action, Checkoff_Rate_Limit::retry_after( $action, true ) );
		}

		return true;
	}

	/**
	 * The refusal of a used-up quota.
	 *
	 * @param string $action      An action constant.
	 * @param int    $retry_after Seconds until it resets.
	 * @return WP_Error
	 */
	private static function error( string $action, int $retry_after ): WP_Error {

		$messages = array(
			self::CREATE    => __( 'You have created many checklists in a short time. Please try again later.', 'checkoff' ),
			self::TICK      => __( 'Too many changes in a short time. Please wait a moment and try again.', 'checkoff' ),
			self::EDIT      => __( 'Too many changes in a short time. Please wait a moment and try again.', 'checkoff' ),
			self::SUBSCRIBE => __( 'Too many requests. Please try again later.', 'checkoff' ),
			self::UNLOCK    => __( 'Too many wrong passwords. Please try again later.', 'checkoff' ),
		);

		return new WP_Error(
			'checkoff_quota_' . $action,
			$messages[ $action ] ?? __( 'Too many requests. Please try again later.', 'checkoff' ),
			array(
				'status'      => 429,
				'retry_after' => max( 1, $retry_after ),
			)
		);
	}
}
