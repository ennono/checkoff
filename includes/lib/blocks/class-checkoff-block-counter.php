<?php
/**
 * Building block: counter.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entry counts up, optionally towards a target: glasses of water,
 * pages read, laps run. An entry is done once it reaches the target.
 */
class Checkoff_Block_Counter extends Checkoff_Block_Type {

	const MAX_TARGET = 100000;
	const MAX_VALUE  = 1000000;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'counter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Counter', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Count every entry up to a target, e.g. glasses of water.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '➕';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {
		return array( 'target' => 1 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'         => 'target',
				'type'        => 'number',
				'label'       => __( 'Target', 'checkoff' ),
				'description' => __( '0 = no target', 'checkoff' ),
				'min'         => 0,
				'max'         => self::MAX_TARGET,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();

		return array( 'target' => self::clamp_int( $settings['target'] ?? 1, 0, self::MAX_TARGET, 1 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		$value = self::clamp_int( $value, 0, self::MAX_VALUE, 0 );

		return $value > 0 ? $value : null;
	}
}
