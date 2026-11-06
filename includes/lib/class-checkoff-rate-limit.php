<?php
/**
 * Simple per-visitor rate limits.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts actions per visitor and hour.
 *
 * Two things need it: creating checklists (so nobody fills the database) and
 * looking up IDs that do not exist (so nobody guesses IDs by trying them).
 *
 * The visitor is told apart by a salted hash of the IP address, kept in a
 * transient that expires after the window — the address itself is never
 * stored.
 */
class Checkoff_Rate_Limit {

	const WINDOW = HOUR_IN_SECONDS;

	/**
	 * Whether the visitor has used up a limit.
	 *
	 * @param string $bucket What is counted, e.g. 'create'.
	 * @param int    $limit  Allowed per window; 0 means unlimited.
	 * @return bool
	 */
	public static function is_exceeded( string $bucket, int $limit ): bool {

		if ( $limit <= 0 ) {
			return false;
		}

		return self::count( $bucket ) >= $limit;
	}

	/**
	 * Counts one action.
	 *
	 * @param string $bucket What is counted.
	 * @return void
	 */
	public static function hit( string $bucket ): void {

		$key  = self::key( $bucket );
		$data = get_transient( $key );

		if ( ! is_array( $data ) || ( $data['until'] ?? 0 ) < time() ) {
			$data = array(
				'count' => 0,
				'until' => time() + self::WINDOW,
			);
		}

		++$data['count'];

		set_transient( $key, $data, max( 1, $data['until'] - time() ) );
	}

	/**
	 * Actions counted in the current window.
	 *
	 * @param string $bucket What is counted.
	 * @return int
	 */
	public static function count( string $bucket ): int {

		$data = get_transient( self::key( $bucket ) );

		if ( ! is_array( $data ) || ( $data['until'] ?? 0 ) < time() ) {
			return 0;
		}

		return (int) $data['count'];
	}

	/**
	 * Transient key: bucket plus a salted hash of the visitor's address.
	 *
	 * @param string $bucket What is counted.
	 * @return string
	 */
	private static function key( string $bucket ): string {

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return 'checkoff_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
