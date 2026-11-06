<?php
/**
 * Password protection: unlocking.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unlocks password-protected checklists for a browser or an API client.
 *
 * A list's owner can protect it with a password (optional; without one
 * everybody with the public ID edits, as always). Then the public ID alone
 * only views and ticks — "locked" — and editing, clearing and deleting need
 * the password. The tick ID stays tick-only either way.
 *
 * Built from WordPress's own means:
 *
 * - The password is stored as a hash by `wp_hash_password()` and checked
 *   by `wp_check_password()` ({@see Checkoff_Checklist::check_password()}),
 *   as user passwords are. Core's post password (`post_password`) is
 *   deliberately not used: it is stored in plain text, and visitors reuse
 *   passwords.
 * - Unlocking is remembered like core's post-password cookie: a cookie per
 *   list, valid for ten days, signed with `wp_hash()`. The signature covers
 *   the password hash, so changing or removing the password ends every
 *   unlock at once. The cookie's name derives from the post ID through
 *   `wp_hash()`, so it does not name the list. httponly, SameSite=Lax,
 *   secure on HTTPS.
 * - API clients send the password with each request in the header
 *   `X-CheckOff-Password` instead (HTTPS only, like every secret).
 *
 * Guessing is limited: wrong passwords count per visitor (quota `unlock`)
 * and per list for all visitors together (UNLOCK_PER_LIST); once either
 * is used up, every attempt is refused for the hour, the right one
 * included — as with unknown IDs, a refusal must not tell a guesser when
 * they hit. A browser that already holds the cookie stays unlocked.
 */
class Checkoff_Password {

	const COOKIE_PREFIX   = 'checkoff_unlock_';
	const COOKIE_LIFETIME = 10 * DAY_IN_SECONDS;
	const HEADER          = 'HTTP_X_CHECKOFF_PASSWORD';
	const UNLOCK_PER_LIST = 50;

	/**
	 * Applies the request's unlock to a checklist opened with its public
	 * ID: without cookie or right header a protected list becomes locked.
	 * Called by every lookup ({@see Checkoff_Shortcodes::lookup()}).
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return void
	 */
	public static function apply( Checkoff_Checklist $checklist ): void {

		if ( Checkoff_Checklist::ACCESS_EDIT !== $checklist->get_access() || ! $checklist->is_protected() ) {
			return;
		}

		if ( self::has_cookie( $checklist ) || self::header_unlocks( $checklist ) ) {
			return;
		}

		$checklist->lock();
	}

	/**
	 * Unlocks a list with its password for this browser.
	 *
	 * @param Checkoff_Checklist $checklist Checklist, opened with its public ID.
	 * @param string             $password  Plain password.
	 * @return true|WP_Error
	 */
	public static function unlock( Checkoff_Checklist $checklist, string $password ): bool|WP_Error {

		if ( Checkoff_Checklist::ACCESS_TICK === $checklist->get_access() ) {
			return new WP_Error( 'checkoff_tick_only', __( 'This link is for ticking only. Ask the owner of the checklist to change it.', 'checkoff' ), array( 'status' => 403 ) );
		}

		if ( ! $checklist->is_protected() ) {
			return true;
		}

		$verified = self::verify( $checklist, $password );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		self::set_cookie( $checklist );

		return true;
	}

	/**
	 * Checks a password against the guessing limits.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param string             $password  Plain password.
	 * @return true|WP_Error 403 for a wrong password, 429 while limited.
	 */
	public static function verify( Checkoff_Checklist $checklist, string $password ): bool|WP_Error {

		$list_bucket = self::list_bucket( $checklist );
		$refused     = Checkoff_Quota::refusal( Checkoff_Quota::UNLOCK );

		if ( ! is_wp_error( $refused ) && Checkoff_Rate_Limit::is_exceeded( $list_bucket, self::UNLOCK_PER_LIST, true ) ) {
			$refused = new WP_Error(
				'checkoff_quota_unlock',
				__( 'Too many wrong passwords. Please try again later.', 'checkoff' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, Checkoff_Rate_Limit::retry_after( $list_bucket, true ) ),
				)
			);
		}

		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		if ( $checklist->check_password( $password ) ) {
			return true;
		}

		Checkoff_Quota::take( Checkoff_Quota::UNLOCK );
		Checkoff_Rate_Limit::hit( $list_bucket, true );

		return new WP_Error( 'checkoff_password_wrong', __( 'The password is not correct.', 'checkoff' ), array( 'status' => 403 ) );
	}

	/**
	 * Sets, changes or (with '') removes a list's password — for somebody
	 * who may edit it. The one who sets it stays unlocked in this browser;
	 * every other unlock ends, since the signature covers the hash.
	 *
	 * @param Checkoff_Checklist $checklist Checklist, with edit access.
	 * @param string             $password  New password, '' to remove.
	 * @return true|WP_Error
	 */
	public static function change( Checkoff_Checklist $checklist, string $password ): bool|WP_Error {

		if ( ! $checklist->can_edit() ) {
			return new WP_Error( 'checkoff_locked', __( 'This checklist is protected by a password. Enter it to edit the checklist.', 'checkoff' ), array( 'status' => 403 ) );
		}

		$result = $checklist->set_password( $password );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( '' === $password ) {
			self::forget( $checklist );
		} else {
			self::set_cookie( $checklist );
		}

		return true;
	}

	/**
	 * The password a request carries in a field (already unslashed) —
	 * never sanitized: a password is compared against its hash, not shown,
	 * and sanitizing would change it.
	 *
	 * @param mixed $raw Unslashed value.
	 * @return string
	 */
	public static function from_request( mixed $raw ): string {
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Forgets the unlock of a list in this browser.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return void
	 */
	public static function forget( Checkoff_Checklist $checklist ): void {

		if ( ! headers_sent() ) {
			setcookie( self::cookie_name( $checklist ), '', self::cookie_options( time() - YEAR_IN_SECONDS ) );
		}

		unset( $_COOKIE[ self::cookie_name( $checklist ) ] );
	}

	/**
	 * Remembers the unlock of a list in this browser — after the password
	 * was given, or set by the owner.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return void
	 */
	public static function set_cookie( Checkoff_Checklist $checklist ): void {

		$expires = time() + self::COOKIE_LIFETIME;
		$value   = $expires . '|' . self::signature( $checklist, $expires );

		if ( ! headers_sent() ) {
			setcookie( self::cookie_name( $checklist ), $value, self::cookie_options( $expires ) );
		}

		// For the rest of this request.
		$_COOKIE[ self::cookie_name( $checklist ) ] = $value;
	}

	/**
	 * Whether this browser holds a valid unlock of the list.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return bool
	 */
	public static function has_cookie( Checkoff_Checklist $checklist ): bool {

		$name = self::cookie_name( $checklist );

		if ( empty( $_COOKIE[ $name ] ) ) {
			return false;
		}

		$parts = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ), 2 );

		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() ) {
			return false;
		}

		return hash_equals( self::signature( $checklist, (int) $parts[0] ), $parts[1] );
	}

	/**
	 * Whether the request carries the list's password in its header.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return bool
	 */
	private static function header_unlocks( Checkoff_Checklist $checklist ): bool {

		if ( empty( $_SERVER[ self::HEADER ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password, compared against its hash only.
		$password = (string) wp_unslash( $_SERVER[ self::HEADER ] );

		return true === self::verify( $checklist, $password );
	}

	/**
	 * The cookie's name: from the post ID, without naming the list.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return string
	 */
	private static function cookie_name( Checkoff_Checklist $checklist ): string {
		return self::COOKIE_PREFIX . substr( wp_hash( 'checkoff-unlock|' . $checklist->get_post_id() ), 0, 16 );
	}

	/**
	 * The cookie's signature: list, expiry and the password hash, so a new
	 * password ends every unlock.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @param int                $expires   Expiry.
	 * @return string
	 */
	private static function signature( Checkoff_Checklist $checklist, int $expires ): string {
		return wp_hash( $checklist->get_post_id() . '|' . $expires . '|' . $checklist->password_hash(), 'auth' );
	}

	/**
	 * Options of the cookie.
	 *
	 * @param int $expires Expiry.
	 * @return array
	 */
	private static function cookie_options( int $expires ): array {
		return array(
			'expires'  => $expires,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	/**
	 * The rate-limit bucket of wrong passwords for one list.
	 *
	 * @param Checkoff_Checklist $checklist Checklist.
	 * @return string
	 */
	private static function list_bucket( Checkoff_Checklist $checklist ): string {
		return 'unlock_list_' . $checklist->get_post_id();
	}
}
