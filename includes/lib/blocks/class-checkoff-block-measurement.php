<?php
/**
 * Building block: measurement.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entry records a number in a unit: weight, steps, a meter reading.
 * With a target range an entry is done once its number is inside it;
 * without one, once a number is entered.
 *
 * Unlike the number rating this is a free number, not a scale to grade on.
 */
class Checkoff_Block_Measurement extends Checkoff_Block_Type {

	const MAX_ABS      = 1000000000;
	const MAX_UNIT     = 20;
	const MAX_DECIMALS = 2;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'measurement';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Measurement', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Record a number for every entry, e.g. weight or a meter reading, optionally with a target range.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '📏';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {
		return array(
			'unit'     => '',
			'min'      => '',
			'max'      => '',
			'decimals' => 1,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'         => 'unit',
				'type'        => 'text',
				'label'       => __( 'Unit', 'checkoff' ),
				'placeholder' => _x( 'kg', 'example unit', 'checkoff' ),
				'maxlength'   => self::MAX_UNIT,
			),
			array(
				'key'     => 'decimals',
				'type'    => 'select',
				'label'   => __( 'Decimal places', 'checkoff' ),
				'options' => array(
					'0' => '0',
					'1' => '1',
					'2' => '2',
				),
			),
			array(
				'key'         => 'min',
				'type'        => 'decimal',
				'label'       => __( 'Target from', 'checkoff' ),
				'description' => __( 'empty = no lower limit', 'checkoff' ),
			),
			array(
				'key'         => 'max',
				'type'        => 'decimal',
				'label'       => __( 'Target up to', 'checkoff' ),
				'description' => __( 'empty = no upper limit', 'checkoff' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Limits are numbers or '' for none; swapped when given the wrong way round.
	 *
	 * @param mixed $settings The block's settings.
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();
		$decimals = self::clamp_int( $settings['decimals'] ?? 1, 0, self::MAX_DECIMALS, 1 );
		$unit     = is_scalar( $settings['unit'] ?? null ) ? sanitize_text_field( (string) $settings['unit'] ) : '';
		$min      = self::number( $settings['min'] ?? null, $decimals );
		$max      = self::number( $settings['max'] ?? null, $decimals );

		if ( null !== $min && null !== $max && $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}

		return array(
			'unit'     => mb_substr( $unit, 0, self::MAX_UNIT ),
			'min'      => $min ?? '',
			'max'      => $max ?? '',
			'decimals' => $decimals,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $value    Value.
	 * @param array $settings The block's settings.
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {
		return self::number( $value, (int) $settings['decimals'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Done inside the target range; without a range, once entered.
	 *
	 * @param mixed $value    Value.
	 * @param array $settings The block's settings.
	 */
	public function progress( mixed $value, array $settings ): array {
		return array(
			'done'  => self::in_range( $value, $settings ) ? 1 : 0,
			'total' => 1,
		);
	}

	/**
	 * Whether a value counts as done.
	 *
	 * @param mixed $value    Value or null.
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function in_range( mixed $value, array $settings ): bool {

		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return false;
		}

		if ( '' !== $settings['min'] && $value < (float) $settings['min'] ) {
			return false;
		}

		return '' === $settings['max'] || $value <= (float) $settings['max'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $value    Value.
	 * @param array $settings The block's settings.
	 */
	public function describe_value( mixed $value, array $settings ): string {

		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return '';
		}

		return trim( number_format_i18n( (float) $value, (int) $settings['decimals'] ) . ' ' . $settings['unit'] );
	}

	/**
	 * A number rounded to the decimal places, or null.
	 *
	 * Accepts a decimal comma, since that is what German visitors type.
	 *
	 * @param mixed $raw      Raw value.
	 * @param int   $decimals Decimal places.
	 * @return float|int|null
	 */
	private static function number( mixed $raw, int $decimals ): float|int|null {

		if ( is_string( $raw ) ) {
			$raw = str_replace( ',', '.', trim( $raw ) );
		}

		if ( null === $raw || '' === $raw || is_bool( $raw ) || ! is_numeric( $raw ) ) {
			return null;
		}

		$number = round( (float) $raw, $decimals );

		if ( ! is_finite( $number ) || abs( $number ) > self::MAX_ABS ) {
			return null;
		}

		return 0 === $decimals ? (int) $number : $number;
	}
}
