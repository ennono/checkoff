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
	 * @param bool  $output Whether to echo the HTML (true) or return it (false).
	 * @return string|bool Generated HTML when $output is false, true after echoing.
	 */
	public function display_field( array $data = array(), bool $output = true ): string|bool {

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

				case 'email':
					$html = '<input type="email" class="regular-text" id="' . esc_attr( $option_name ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '" />';
					break;

				case 'textarea':
					$html = '<textarea class="large-text" rows="4" id="' . esc_attr( $option_name ) . '" name="' . esc_attr( $option_name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
					break;

				case 'mail_rules':
					$html = $this->render_mail_rules( $option_name );
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

		if ( ! $output ) {
			return (string) $html;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.

		return true;
	}

	/**
	 * The sending rules as an editable table: one row per mail, with a test
	 * button each. Rows are added from a template in a script element.
	 *
	 * @param string $option_name Option name.
	 * @return string
	 */
	private function render_mail_rules( string $option_name ): string {

		$triggers  = Checkoff_Subscriptions::trigger_labels();
		$templates = Checkoff_Mailer::list_templates();
		$open      = Checkoff_Subscriptions::OPEN_TRIGGERS;

		$row = static function ( string $index, array $rule ) use ( $option_name, $triggers, $templates, $open ): string {

			$name = $option_name . '[' . $index . ']';

			$trigger_options = '';
			foreach ( $triggers as $key => $label ) {
				$trigger_options .= '<option value="' . esc_attr( $key ) . '"' . selected( $rule['trigger'], $key, false ) . '>' . esc_html( $label ) . '</option>';
			}

			$template_options = '';
			foreach ( $templates as $file ) {
				$template_options .= '<option value="' . esc_attr( $file ) . '"' . selected( $rule['template'], $file, false ) . '>' . esc_html( $file ) . '</option>';
			}

			return '<tr class="cko-rule">'
				. '<td><select name="' . esc_attr( $name . '[trigger]' ) . '" class="cko-rule__trigger" data-open-triggers="' . esc_attr( implode( ',', $open ) ) . '">' . $trigger_options . '</select></td>'
				. '<td><input type="time" name="' . esc_attr( $name . '[time]' ) . '" class="cko-rule__time" value="' . esc_attr( '' !== $rule['time'] ? $rule['time'] : '08:00' ) . '" /></td>'
				. '<td><input type="text" name="' . esc_attr( $name . '[subject]' ) . '" class="regular-text cko-rule__subject" value="' . esc_attr( $rule['subject'] ) . '" /></td>'
				. '<td><select name="' . esc_attr( $name . '[template]' ) . '" class="cko-rule__template">' . $template_options . '</select></td>'
				. '<td class="cko-rule__open-cell"><label><input type="checkbox" name="' . esc_attr( $name . '[only_open]' ) . '" class="cko-rule__open" value="on"' . checked( 'on', $rule['only_open'], false ) . ' /> ' . esc_html__( 'only if open', 'checkoff' ) . '</label></td>'
				. '<td class="cko-rule__actions">'
				. '<button type="button" class="button cko-rule__test">' . esc_html__( 'Test', 'checkoff' ) . '</button> '
				. '<button type="button" class="button-link button-link-delete cko-rule__remove" aria-label="' . esc_attr__( 'Remove row', 'checkoff' ) . '">' . esc_html__( 'Remove', 'checkoff' ) . '</button>'
				. '</td>'
				. '</tr>';
		};

		$body = '';
		foreach ( Checkoff_Subscriptions::get_rules() as $i => $rule ) {
			$body .= $row( (string) $i, $rule );
		}

		$blank = array(
			'trigger'   => 'each_day',
			'time'      => '08:00',
			'subject'   => '{{ checklist }}',
			'template'  => $templates[0] ?? '',
			'only_open' => '',
		);

		$html  = '<div class="cko-rules" data-nonce="' . esc_attr( wp_create_nonce( 'checkoff_mail_admin' ) ) . '">';
		$html .= '<table class="widefat cko-rules__table"><thead><tr>'
			. '<th>' . esc_html__( 'Trigger', 'checkoff' ) . '</th>'
			. '<th>' . esc_html__( 'Time', 'checkoff' ) . '</th>'
			. '<th>' . esc_html__( 'Subject', 'checkoff' ) . '</th>'
			. '<th>' . esc_html__( 'Template', 'checkoff' ) . '</th>'
			. '<th>' . esc_html__( 'Condition', 'checkoff' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>' . $body . '</tbody></table>';
		$html .= '<script type="text/html" class="cko-rules__template">' . $row( '__INDEX__', $blank ) . '</script>';
		$html .= '<p><button type="button" class="button cko-rules__add">' . esc_html__( 'Add mail', 'checkoff' ) . '</button></p>';
		$html .= '<p><label>' . esc_html__( 'Send tests to:', 'checkoff' ) . ' <input type="email" class="regular-text cko-rules__to" value="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" /></label> '
			. '<button type="button" class="button cko-rules__test-confirm">' . esc_html__( 'Test confirmation mail', 'checkoff' ) . '</button></p>';
		$html .= '<p class="cko-rules__result" role="status" aria-live="polite"></p>';
		$html .= '<p class="description">' . esc_html__( 'Tests are filled with a made-up example checklist, not with a visitor\'s list. The time is the site\'s time zone. A mail that is due while nobody visits the site goes out with the next visit (WordPress cron); one that is more than a day late is skipped. "Only if open" skips the mail when everything is done.', 'checkoff' ) . '</p>';
		$html .= '</div>';

		return $html;
	}
}
