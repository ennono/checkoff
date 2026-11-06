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
 * Counts actions per visitor — or for all visitors together — and hour.
 *
 * The visitor is told apart by a salted hash of the IP address, kept in a
 * transient that expires after the window — the address itself is never
 * stored. Which quotas exist and how large they are is
 * {@see Checkoff_Quota}'s business; this class only counts.
 *
 * Two details decide whether a per-visitor limit means anything:
 *
 * - **IPv6 counts per /64.** A single connection is handed a whole /64
 *   network; counting single addresses would let one visitor rotate
 *   through billions of them.
 * - **The address can come from a proxy header** (setting
 *   `checkoff_ip_source`), for sites behind Cloudflare or a reverse proxy,
 *   where REMOTE_ADDR is the proxy and every visitor would share one
 *   limit. Only for sites that really are behind one: a header is set by
 *   the client otherwise, and trusting it would let anybody pick their own
 *   address.
 */
class Checkoff_Rate_Limit {

	const WINDOW = HOUR_IN_SECONDS;

	/**
	 * Where the visitor's address may come from: setting value => $_SERVER key.
	 */
	const IP_SOURCES = array(
		'remote_addr'      => 'REMOTE_ADDR',
		'x_forwarded_for'  => 'HTTP_X_FORWARDED_FOR',
		'x_real_ip'        => 'HTTP_X_REAL_IP',
		'cf_connecting_ip' => 'HTTP_CF_CONNECTING_IP',
	);

	/**
	 * Whether a limit is used up.
	 *
	 * @param string $bucket What is counted, e.g. 'create'.
	 * @param int    $limit  Allowed per window; 0 means unlimited.
	 * @param bool   $all_visitors Count all visitors together instead of this one.
	 * @return bool
	 */
	public static function is_exceeded( string $bucket, int $limit, bool $all_visitors = false ): bool {

		if ( $limit <= 0 ) {
			return false;
		}

		return self::count( $bucket, $all_visitors ) >= $limit;
	}

	/**
	 * Counts one action.
	 *
	 * @param string $bucket What is counted.
	 * @param bool   $all_visitors Count for all visitors together.
	 * @return void
	 */
	public static function hit( string $bucket, bool $all_visitors = false ): void {

		$key  = self::key( $bucket, $all_visitors );
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
	 * @param bool   $all_visitors All visitors together.
	 * @return int
	 */
	public static function count( string $bucket, bool $all_visitors = false ): int {

		$data = get_transient( self::key( $bucket, $all_visitors ) );

		if ( ! is_array( $data ) || ( $data['until'] ?? 0 ) < time() ) {
			return 0;
		}

		return (int) $data['count'];
	}

	/**
	 * Seconds until the current window ends (0 when none is running) — the
	 * Retry-After of a refused request.
	 *
	 * @param string $bucket What is counted.
	 * @param bool   $all_visitors All visitors together.
	 * @return int
	 */
	public static function retry_after( string $bucket, bool $all_visitors = false ): int {

		$data = get_transient( self::key( $bucket, $all_visitors ) );

		return is_array( $data ) ? max( 0, (int) ( $data['until'] ?? 0 ) - time() ) : 0;
	}

	/**
	 * The configured source of the visitor's address.
	 *
	 * @return string A key of IP_SOURCES.
	 */
	public static function ip_source(): string {
		$source = (string) get_option( 'checkoff_ip_source', 'remote_addr' );
		return isset( self::IP_SOURCES[ $source ] ) ? $source : 'remote_addr';
	}

	/**
	 * The visitor as counted: an IPv4 address, an IPv6 /64 network, or ''
	 * when no valid address is known (then all such requests share one
	 * count, which errs on the side of limiting).
	 *
	 * @return string
	 */
	public static function visitor(): string {

		$server = self::IP_SOURCES[ self::ip_source() ];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP address below.
		$raw = isset( $_SERVER[ $server ] ) ? (string) wp_unslash( $_SERVER[ $server ] ) : '';

		// X-Forwarded-For lists client, proxy, proxy: the first is the client.
		$ip = trim( explode( ',', $raw )[0] );

		// A configured header that is missing: fall back to the connection.
		if ( '' === $ip && 'REMOTE_ADDR' !== $server && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
			$ip = trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $ip;
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = (string) inet_pton( $ip );
			return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		}

		return '';
	}

	/**
	 * Transient key: bucket plus a salted hash of the visitor, or the
	 * bucket alone for a count of all visitors.
	 *
	 * @param string $bucket What is counted.
	 * @param bool   $all_visitors All visitors together.
	 * @return string
	 */
	private static function key( string $bucket, bool $all_visitors = false ): string {

		$who = $all_visitors ? '*' : self::visitor();

		return 'checkoff_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $who, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
