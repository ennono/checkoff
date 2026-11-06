<?php
/**
 * Building block: rating with numbers.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entry is rated with a whole number between a minimum and a maximum,
 * 1 to 5 by default (site setting), adjustable per block.
 */
class Checkoff_Block_Number_Scale extends Checkoff_Block_Type {

	/**
	 * Limits of what a block may be set to.
	 */
	const LIMIT_MIN  = -100;
	const LIMIT_MAX  = 100;
	const MAX_SPREAD = 100;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'number_scale';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Rating with numbers', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Rate every entry with a number, e.g. from 1 to 5.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '🔢';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {

		$min = self::clamp_int( get_option( 'checkoff_number_scale_min', 1 ), self::LIMIT_MIN, self::LIMIT_MAX - 1, 1 );
		$max = self::clamp_int( get_option( 'checkoff_number_scale_max', 5 ), $min + 1, min( self::LIMIT_MAX, $min + self::MAX_SPREAD ), 5 );

		return array(
			'min' => $min,
			'max' => max( $min + 1, $max ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'   => 'min',
				'type'  => 'number',
				'label' => __( 'From', 'checkoff' ),
				'min'   => self::LIMIT_MIN,
				'max'   => self::LIMIT_MAX - 1,
			),
			array(
				'key'   => 'max',
				'type'  => 'number',
				'label' => __( 'To', 'checkoff' ),
				'min'   => self::LIMIT_MIN + 1,
				'max'   => self::LIMIT_MAX,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * A reversed range is turned round rather than refused; an empty or too
	 * wide one falls back to the defaults.
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();
		$defaults = $this->default_settings();

		$min = self::clamp_int( $settings['min'] ?? $defaults['min'], self::LIMIT_MIN, self::LIMIT_MAX, $defaults['min'] );
		$max = self::clamp_int( $settings['max'] ?? $defaults['max'], self::LIMIT_MIN, self::LIMIT_MAX, $defaults['max'] );

		if ( $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}

		if ( $min === $max || $max - $min > self::MAX_SPREAD ) {
			return $defaults;
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$value = (int) round( (float) $value );

		return ( $value >= $settings['min'] && $value <= $settings['max'] ) ? $value : null;
	}
}
