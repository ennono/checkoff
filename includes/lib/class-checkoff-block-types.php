<?php
/**
 * Registry of the building blocks.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows every building block and which of them the site offers.
 *
 * Add-ons register their own blocks through the filter `checkoff_block_types`
 * by adding an instance of a Checkoff_Block_Type subclass. A block that is
 * switched off in the settings is no longer offered in the builder, but
 * checklists already using it keep working.
 */
class Checkoff_Block_Types {

	/**
	 * Type a block falls back to when its own is unknown, and the type of a
	 * new checklist.
	 */
	const FALLBACK = 'simple';

	/**
	 * Memoised registry.
	 *
	 * @var array<string, Checkoff_Block_Type>|null
	 */
	private static ?array $types = null;

	/**
	 * All block types, keyed by their key, in the order they are offered.
	 *
	 * @return array<string, Checkoff_Block_Type>
	 */
	public static function all(): array {

		if ( null !== self::$types ) {
			return self::$types;
		}

		$builtin = array(
			new Checkoff_Block_Simple(),
			new Checkoff_Block_Status(),
			new Checkoff_Block_Deadline(),
			new Checkoff_Block_Weekdays(),
			new Checkoff_Block_Color_Scale(),
			new Checkoff_Block_Number_Scale(),
			new Checkoff_Block_Measurement(),
			new Checkoff_Block_Choice(),
			new Checkoff_Block_Switch(),
			new Checkoff_Block_Counter(),
			new Checkoff_Block_Note(),
		);

		/**
		 * Filters the available building blocks.
		 *
		 * @param Checkoff_Block_Type[] $types Block type instances.
		 */
		$types = apply_filters( 'checkoff_block_types', $builtin );

		$registry = array();

		foreach ( (array) $types as $type ) {
			if ( $type instanceof Checkoff_Block_Type && preg_match( '/^[a-z0-9_]{1,40}$/', $type->key() ) ) {
				$registry[ $type->key() ] = $type;
			}
		}

		// The fallback must always exist, whatever a filter did.
		if ( ! isset( $registry[ self::FALLBACK ] ) ) {
			$registry = array( self::FALLBACK => new Checkoff_Block_Simple() ) + $registry;
		}

		self::$types = $registry;

		return self::$types;
	}

	/**
	 * One block type, or the fallback for an unknown key.
	 *
	 * @param string $key Block key.
	 * @return Checkoff_Block_Type
	 */
	public static function get( string $key ): Checkoff_Block_Type {

		$all = self::all();

		return $all[ $key ] ?? $all[ self::FALLBACK ];
	}

	/**
	 * Whether a key names a registered block.
	 *
	 * @param string $key Block key.
	 * @return bool
	 */
	public static function exists( string $key ): bool {
		return isset( self::all()[ $key ] );
	}

	/**
	 * Name of the option that switches a block on or off.
	 *
	 * @param string $key Block key.
	 * @return string
	 */
	public static function enabled_option( string $key ): string {
		return 'checkoff_block_' . $key . '_enabled';
	}

	/**
	 * Whether the site offers a block in the builder. On until switched off.
	 *
	 * @param string $key Block key.
	 * @return bool
	 */
	public static function is_enabled( string $key ): bool {
		return self::exists( $key ) && 'on' === (string) get_option( self::enabled_option( $key ), 'on' );
	}

	/**
	 * Keys of the blocks offered in the builder. The fallback is always
	 * among them, so a visitor can always build something.
	 *
	 * @return string[]
	 */
	public static function enabled_keys(): array {

		$keys = array();

		foreach ( array_keys( self::all() ) as $key ) {
			if ( self::FALLBACK === $key || self::is_enabled( $key ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Definitions of all blocks for the frontend, keyed by key.
	 *
	 * @return array<string, array>
	 */
	public static function to_client(): array {

		$client = array();

		foreach ( self::all() as $key => $type ) {
			$client[ $key ] = $type->to_client();
		}

		return $client;
	}

	/**
	 * Forgets the memoised registry (tests, late filters).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$types = null;
	}
}
