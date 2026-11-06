<?php
/**
 * Building block: notes.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entry is a text field. Notes describe a list rather than complete
 * it, so they do not count towards its progress.
 */
class Checkoff_Block_Note extends Checkoff_Block_Type {

	const MAX_LENGTH = 2000;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'note';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Notes', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Text fields for notes. Do not count towards the progress.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '📝';
	}

	/**
	 * {@inheritDoc}
	 */
	public function counts_progress(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {
		return array( 'multiline' => true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'   => 'multiline',
				'type'  => 'checkbox',
				'label' => __( 'Several lines', 'checkoff' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();

		return array( 'multiline' => rest_sanitize_boolean( $settings['multiline'] ?? true ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = $settings['multiline']
			? sanitize_textarea_field( (string) $value )
			: sanitize_text_field( (string) $value );

		$value = mb_substr( $value, 0, self::MAX_LENGTH );

		return '' === trim( $value ) ? null : $value;
	}
}
