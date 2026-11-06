<?php
/**
 * Admin API file.
 *
 * Renders the fields of the settings page.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Checkoff_Admin_API
 *
 * Renders settings fields by their `type`: text, number, checkbox, select,
 * page and color_step (a colour with the label that goes with it).
 */
class Checkoff_Admin_API {

	/**
	 * Renders or returns the HTML for a settings field.
	 *
	 * @param array $data Field configuration ('field' and optional 'prefix').
	 * @param bool  $echo Whether to echo the HTML (true) or return it (false).
	 * @return string|bool Generated HTML when $echo is false, true after echoing.
	 */
	public function display_field( array $data = array(), bool $echo = true ): string|bool {

		$field       = $data['field'] ?? $data;
		$prefix      = (string) ( $data['prefix'] ?? '' );
		$option_name = $prefix . $field['id'];
		$value       = get_option( $option_name, $field['default'] ?? '' );
		$type        = (string) ( $field['type'] ?? 'text' );
		$disabled    = ! empty( $field['disabled'] );

		/**
		 * Lets a plugin render a settings field type this class does not know.
		 *
		 * @param string|null $rendered    Markup, or null to let this class handle it.
		 * @param array       $field       Field definition.
		 * @param mixed       $value       Current value.
		 * @param string      $option_name Option name.
		 */
		$html = apply_filters( 'checkoff_render_settings_field', null, $field, $value, $option_name );

		if ( null === $html ) {

			switch ( $type ) {

				case 'number':
					$html = '<input type="number" class="small-text" id="' . esc_attr( $option_name ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( (string) $value ) . '"'
						. ( isset( $field['min'] ) ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '' )
						. ( isset( $field['max'] ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '' )
						. ' step="1" />';
					break;

				case 'checkbox':
					// A disabled box posts nothing; it only shows a fixed state.
					$html = '<label><input type="checkbox" id="' . esc_attr( $option_name ) . '" name="' . esc_attr( $option_name ) . '" value="on"'
						. checked( $disabled || 'on' === $value, true, false )
						. disabled( $disabled, true, false ) . ' /> '
						. esc_html__( 'On', 'checkoff' ) . '</label>';
					break;

				case 'select':
					$html = '<select id="' . esc_attr( $option_name ) . '" name="' . esc_attr( $option_name ) . '">';
					foreach ( (array) ( $field['options'] ?? array() ) as $key => $label ) {
						$html .= '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $label ) . '</option>';
					}
					$html .= '</select>';
					break;

				case 'page':
					$html = wp_dropdown_pages(
						array(
							'name'              => esc_attr( $option_name ),
							'id'                => esc_attr( $option_name ),
							'selected'          => (int) $value,
							'show_option_none'  => esc_html__( '— None —', 'checkoff' ),
							'option_none_value' => '0',
							'echo'              => false,
						)
					);
					if ( (int) $value > 0 && 'publish' === get_post_status( (int) $value ) ) {
						$html .= ' <a href="' . esc_url( (string) get_permalink( (int) $value ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View page', 'checkoff' ) . '</a>';
					}
					break;

				case 'color_step':
					$color      = sanitize_hex_color( (string) $value );
					$color      = $color ? $color : (string) ( $field['default'] ?? '#000000' );
					$label_name = $prefix . $field['pair']['id'];
					$label      = (string) get_option( $label_name, '' );

					$html  = '<span class="cko-color-step">';
					$html .= '<input type="color" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $color ) . '" aria-label="' . esc_attr__( 'Colour', 'checkoff' ) . '" /> ';
					$html .= '<input type="text" class="regular-text" name="' . esc_attr( $label_name ) . '" value="' . esc_attr( $label ) . '" placeholder="' . esc_attr( (string) $field['pair']['placeholder'] ) . '" aria-label="' . esc_attr__( 'Name', 'checkoff' ) . '" />';
					$html .= '</span>';
					break;

				case 'text':
				default:
					$html = '<input type="text" class="regular-text" id="' . esc_attr( $option_name ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '" />';
					break;
			}

			if ( ! empty( $field['description'] ) ) {
				$html .= '<p class="description">' . esc_html( (string) $field['description'] ) . '</p>';
			}
		}

		if ( ! $echo ) {
			return (string) $html;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.

		return true;
	}
}
