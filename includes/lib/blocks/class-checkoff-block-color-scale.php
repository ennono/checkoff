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
	 * Longest name of a step.
	 */
	const MAX_LABEL = 40;

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
		return array(
			'steps'   => 5,
			'labels'  => array(),
			'reverse' => false,
		);
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
			array(
				'key'         => 'reverse',
				'type'        => 'checkbox',
				'label'       => __( 'Reverse the scale', 'checkoff' ),
				'description' => __( 'Green on the left, red on the right — e.g. for a pain diary, where green means free of pain.', 'checkoff' ),
			),
			array(
				'key'         => 'labels',
				'type'        => 'options',
				'label'       => __( 'Names of the steps', 'checkoff' ),
				'description' => __( 'One per line, from left to right. Empty: the names set for the whole site.', 'checkoff' ),
				'max'         => 5,
				'maxlength'   => self::MAX_LABEL,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();
		$steps    = 3 === (int) ( $settings['steps'] ?? 5 ) ? 3 : 5;
		$raw      = $settings['labels'] ?? array();
		$raw      = is_string( $raw ) ? preg_split( '/\R/', $raw ) : ( is_array( $raw ) ? $raw : array() );
		$labels   = array();

		foreach ( array_slice( array_values( $raw ), 0, 5 ) as $label ) {
			$labels[] = is_scalar( $label ) ? mb_substr( trim( sanitize_text_field( (string) $label ) ), 0, self::MAX_LABEL ) : '';
		}

		// Trailing empty names say nothing; the site's names stand in.
		while ( $labels && '' === end( $labels ) ) {
			array_pop( $labels );
		}

		return array(
			'steps'   => $steps,
			'labels'  => $labels,
			'reverse' => ! empty( $settings['reverse'] ) && 'false' !== $settings['reverse'],
		);
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

		return self::steps_for( $settings )[ (int) $value ]['label'] ?? '';
	}

	/**
	 * The steps of one block, left to right: the site's scale for its number
	 * of steps, reversed if the block says so (green first), with the
	 * block's own names where it has them.
	 *
	 * The value of an entry is the position (0 = leftmost), so reversing or
	 * renaming changes what a stored position looks like, not the position.
	 *
	 * @param array $settings The block's (sanitized) settings.
	 * @return array<int, array{color:string,label:string}>
	 */
	public static function steps_for( array $settings ): array {

		$steps = self::scale()[ 3 === (int) ( $settings['steps'] ?? 5 ) ? 3 : 5 ];

		if ( ! empty( $settings['reverse'] ) ) {
			$steps = array_reverse( $steps );
		}

		foreach ( (array) ( $settings['labels'] ?? array() ) as $i => $label ) {
			if ( isset( $steps[ $i ] ) && is_string( $label ) && '' !== $label ) {
				$steps[ $i ]['label'] = $label;
			}
		}

		return $steps;
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
