<?php
/**
 * Building block: checklist with "not needed".
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries to tick off — or to mark as not needed this time. An entry that
 * is not needed leaves the progress altogether, so a packing or inspection
 * list still reaches 100 % when a point does not apply, without deleting it.
 *
 * Values: 'done' or 'skip'.
 */
class Checkoff_Block_Status extends Checkoff_Block_Type {

	const DONE = 'done';
	const SKIP = 'skip';

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'status';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Checklist with "not needed"', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Tick entries off or mark them as not needed; those no longer count towards the progress.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '⊘';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $value    Value.
	 * @param array $settings The block's settings.
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		if ( true === $value ) {
			return self::DONE;
		}

		return in_array( $value, array( self::DONE, self::SKIP ), true ) ? $value : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Not needed: neither done nor open — the entry is out of the count.
	 *
	 * @param mixed $value    Value.
	 * @param array $settings The block's settings.
	 */
	public function progress( mixed $value, array $settings ): array {

		if ( self::SKIP === $value ) {
			return array(
				'done'  => 0,
				'total' => 0,
			);
		}

		return array(
			'done'  => self::DONE === $value ? 1 : 0,
			'total' => 1,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $value    Value.
	 * @param array $settings The block's settings.
	 */
	public function describe_value( mixed $value, array $settings ): string {

		if ( self::DONE === $value ) {
			return '✓';
		}

		return self::SKIP === $value ? __( 'Not needed', 'checkoff' ) : '';
	}
}
