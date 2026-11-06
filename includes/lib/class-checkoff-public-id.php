<?php
/**
 * Public checklist IDs.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ID by which a visitor finds a checklist again.
 *
 * It is deliberately not the post ID: post IDs are counted up, so anybody
 * could walk through them. This one is random, long enough not to be
 * guessed (12 characters of 31 symbols are about 59 bits), and it is the only
 * key a checklist is found by — whoever knows it can open and edit the list.
 *
 * The alphabet leaves out characters that are easily confused when an ID is
 * read aloud or copied by hand (0/o, 1/l/i). Stored in lower case without
 * separators; shown in groups of four ("k7f3-q9xm-2ab4"). Input is forgiving:
 * case, spaces and dashes do not matter.
 */
class Checkoff_Public_Id {

	const ALPHABET       = '23456789abcdefghjkmnpqrstuvwxyz';
	const DEFAULT_LENGTH = 12;
	const MIN_LENGTH     = 8;
	const MAX_LENGTH     = 24;
	const GROUP          = 4;

	/**
	 * The configured length of new IDs.
	 *
	 * @return int
	 */
	public static function length(): int {

		$length = (int) get_option( 'checkoff_id_length', self::DEFAULT_LENGTH );

		return max( self::MIN_LENGTH, min( self::MAX_LENGTH, $length ) );
	}

	/**
	 * A new random ID (not yet checked for uniqueness).
	 *
	 * @param int|null $length Length, the configured one by default.
	 * @return string
	 */
	public static function generate( ?int $length = null ): string {

		$length   = $length ?? self::length();
		$alphabet = self::ALPHABET;
		$last     = strlen( $alphabet ) - 1;
		$id       = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$id .= $alphabet[ random_int( 0, $last ) ];
		}

		return $id;
	}

	/**
	 * Normalises user input to the stored form, or '' when it cannot be an
	 * ID at all.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public static function normalize( mixed $input ): string {

		if ( ! is_scalar( $input ) ) {
			return '';
		}

		$id = strtolower( preg_replace( '/[\s\-_.]+/', '', (string) $input ) );

		if ( strlen( $id ) < self::MIN_LENGTH || strlen( $id ) > self::MAX_LENGTH ) {
			return '';
		}

		return preg_match( '/^[' . self::ALPHABET . ']+$/', $id ) ? $id : '';
	}

	/**
	 * The ID as shown to people, in groups of four.
	 *
	 * @param string $id Stored ID.
	 * @return string
	 */
	public static function format( string $id ): string {
		return implode( '-', str_split( $id, self::GROUP ) );
	}
}
