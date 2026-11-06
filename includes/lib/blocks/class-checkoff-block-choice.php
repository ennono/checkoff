<?php
/**
 * Building block: choice.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entry takes one of the answers the list's author wrote: yes / no /
 * maybe, a room, a size. An entry with an answer is done.
 *
 * The value is the answer's position, so renaming an answer keeps what was
 * chosen; removing answers drops choices that no longer exist.
 */
class Checkoff_Block_Choice extends Checkoff_Block_Type {

	const MAX_OPTIONS = 12;
	const MAX_OPTION  = 40;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'choice';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Choice', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Pick one of your own answers for every entry, e.g. yes / no / maybe.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '🔘';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {
		return array(
			'options' => array(
				_x( 'Yes', 'choice answer', 'checkoff' ),
				_x( 'No', 'choice answer', 'checkoff' ),
				_x( 'Maybe', 'choice answer', 'checkoff' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'         => 'options',
				'type'        => 'options',
				'label'       => __( 'Answers', 'checkoff' ),
				/* translators: %s: maximum number of answers. */
				'description' => sprintf( __( 'One answer per line, up to %s.', 'checkoff' ), number_format_i18n( self::MAX_OPTIONS ) ),
				'max'         => self::MAX_OPTIONS,
				'maxlength'   => self::MAX_OPTION,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Answers as a list or as lines; empty ones are dropped, at least one stays.
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();
		$raw      = $settings['options'] ?? null;
		$raw      = is_string( $raw ) ? preg_split( '/\R/', $raw ) : ( is_array( $raw ) ? $raw : array() );
		$options  = array();

		foreach ( $raw as $option ) {

			if ( ! is_scalar( $option ) ) {
				continue;
			}

			$option = mb_substr( trim( sanitize_text_field( (string) $option ) ), 0, self::MAX_OPTION );

			if ( '' !== $option ) {
				$options[] = $option;
			}

			if ( count( $options ) >= self::MAX_OPTIONS ) {
				break;
			}
		}

		return array( 'options' => $options ? $options : $this->default_settings()['options'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		if ( null === $value || '' === $value || is_bool( $value ) || ! is_numeric( $value ) ) {
			return null;
		}

		$index = (int) $value;

		return ( $index >= 0 && $index < count( $settings['options'] ) ) ? $index : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe_value( mixed $value, array $settings ): string {
		return null === $value ? '' : (string) ( $settings['options'][ (int) $value ] ?? '' );
	}
}
