<?php
/**
 * Building block: simple checklist.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries are boxes to tick. The one block that may contain sub-lists of any
 * type, so it is also what a nested checklist is built from.
 */
class Checkoff_Block_Simple extends Checkoff_Block_Type {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'simple';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Simple checklist', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Entries to tick off. Can contain sub-lists of any type.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '☑';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_children(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {
		return rest_sanitize_boolean( $value ) ? true : null;
	}
}
