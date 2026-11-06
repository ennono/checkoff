<?php
/**
 * Building block: rating on a colour scale.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entry is rated on a scale of colours, from red to green by default.
 *
 * The colours and their names are site settings, five of them; a block uses
 * all five or three (the first, the middle and the last). A value is the
 * position on the block's own scale, 0 being the lowest.
 */
class Checkoff_Block_Color_Scale extends Checkoff_Block_Type {

	/**
	 * Default colours of the five steps, lowest first.
	 *
	 * @var string[]
	 */
	const DEFAULT_COLORS = array( '#d63638', '#f0883e', '#f2c94c', '#8bc34a', '#2e9e4f' );

	/**
	 * Option names (without the plugin prefix) of the five colours and labels.
	 */
	const COLOR_OPTION = 'color_scale_color_';
	const LABEL_OPTION = 'color_scale_label_';

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'color_scale';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Rating with colour scale', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Rate every entry on a scale from red to green.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '🎨';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {
		return array( 'steps' => 5 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'steps',
				'type'    => 'select',
				'label'   => __( 'Steps', 'checkoff' ),
				'options' => array(
					'3' => __( '3 colours', 'checkoff' ),
					'5' => __( '5 colours', 'checkoff' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();
		$steps    = (int) ( $settings['steps'] ?? 5 );

		return array( 'steps' => 3 === $steps ? 3 : 5 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$value = (int) $value;

		return ( $value >= 0 && $value < (int) $settings['steps'] ) ? $value : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe_value( mixed $value, array $settings ): string {

		if ( null === $value ) {
			return '';
		}

		$scale = self::scale()[ (int) $settings['steps'] ] ?? array();

		return $scale[ (int) $value ]['label'] ?? '';
	}

	/**
	 * The scales as configured: for 3 and 5 steps, each step's colour and name.
	 *
	 * @return array{3: array<int, array{color:string,label:string}>, 5: array<int, array{color:string,label:string}>}
	 */
	public static function scale(): array {

		$labels = self::default_labels();
		$five   = array();

		foreach ( self::DEFAULT_COLORS as $i => $default_color ) {

			$color = sanitize_hex_color( (string) get_option( 'checkoff_' . self::COLOR_OPTION . ( $i + 1 ), '' ) );
			$label = trim( (string) get_option( 'checkoff_' . self::LABEL_OPTION . ( $i + 1 ), '' ) );

			$five[] = array(
				'color' => $color ? $color : $default_color,
				'label' => '' !== $label ? $label : $labels[ $i ],
			);
		}

		return array(
			3 => array( $five[0], $five[2], $five[4] ),
			5 => $five,
		);
	}

	/**
	 * Default names of the five steps, lowest first.
	 *
	 * @return string[]
	 */
	public static function default_labels(): array {
		return array(
			_x( 'Bad', 'colour scale step', 'checkoff' ),
			_x( 'Weak', 'colour scale step', 'checkoff' ),
			_x( 'Okay', 'colour scale step', 'checkoff' ),
			_x( 'Good', 'colour scale step', 'checkoff' ),
			_x( 'Very good', 'colour scale step', 'checkoff' ),
		);
	}
}
