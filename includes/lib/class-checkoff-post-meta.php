<?php
/**
 * Admin meta boxes.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checklist screen in wp-admin: its ID, a read-only outline of what is
 * on it, and the two things an administrator may need to do for a visitor —
 * give the list a new ID (when the old one leaked) or clear its ticks.
 *
 * Editing the list itself happens where visitors do it, on the website.
 */
class Checkoff_Post_Meta {

	const NONCE_ACTION = 'checkoff_checklist_meta';
	const NONCE_NAME   = 'checkoff_checklist_meta_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_' . Checkoff_Post_Types::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Checkoff_Post_Types::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Registers the meta boxes.
	 *
	 * @return void
	 */
	public function add_meta_boxes(): void {

		add_meta_box(
			'checkoff-checklist-id',
			__( 'Checklist ID', 'checkoff' ),
			array( $this, 'render_id_box' ),
			Checkoff_Post_Types::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'checkoff-checklist-outline',
			__( 'Contents', 'checkoff' ),
			array( $this, 'render_outline_box' ),
			Checkoff_Post_Types::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * The ID box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_id_box( WP_Post $post ): void {

		$checklist = new Checkoff_Checklist( (int) $post->ID );
		$public_id = Checkoff_Public_Id::format( $checklist->get_public_id() );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<p><code class="cko-admin-id">' . esc_html( $public_id ) . '</code> ';
		echo '<button type="button" class="button button-small cko-admin-copy" data-copy="' . esc_attr( $public_id ) . '">'
			. esc_html__( 'Copy', 'checkoff' ) . '</button></p>';

		if ( '' !== Checkoff_Checklist::page_url() ) {
			echo '<p><a href="' . esc_url( $checklist->url() ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Open on the website', 'checkoff' ) . '</a></p>';
		} else {
			echo '<p class="description">' . wp_kses(
				sprintf(
					/* translators: %s: link to the settings. */
					__( 'Choose a checklist page in the <a href="%s">settings</a> to get a link here.', 'checkoff' ),
					esc_url( Checkoff_Settings::settings_url() )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
		}

		echo '<p class="description">';
		echo esc_html(
			sprintf(
				/* translators: %s: date and time. */
				__( 'Last used: %s', 'checkoff' ),
				$checklist->get_last_access() > 0
					? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checklist->get_last_access() )
					: '—'
			)
		);
		echo '</p>';

		echo '<hr />';
		echo '<p><label><input type="checkbox" name="cko_regenerate_id" value="1" /> '
			. esc_html__( 'Give the checklist a new ID', 'checkoff' ) . '</label><br />';
		echo '<span class="description">' . esc_html__( 'The old ID stops working immediately. Use this if the ID was passed on by mistake.', 'checkoff' ) . '</span></p>';

		echo '<p><label><input type="checkbox" name="cko_reset_values" value="1" /> '
			. esc_html__( 'Clear all ticks and ratings', 'checkoff' ) . '</label></p>';
	}

	/**
	 * The read-only outline.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_outline_box( WP_Post $post ): void {

		$checklist = new Checkoff_Checklist( (int) $post->ID );

		echo '<p class="description">' . esc_html__( 'Visitors edit their checklists on the website. This is a read-only overview.', 'checkoff' ) . '</p>';
		echo '<div class="cko-admin-outline">';
		echo $this->outline( $checklist->get_structure(), $checklist->get_values() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '</div>';
	}

	/**
	 * One block of the outline, recursively.
	 *
	 * @param array $block  Block.
	 * @param array $values Values.
	 * @return string
	 */
	private function outline( array $block, array $values ): string {

		$type = Checkoff_Block_Types::get( $block['type'] );

		$html  = '<div class="cko-admin-outline__block">';
		$html .= '<strong>' . esc_html( '' !== $block['title'] ? $block['title'] : __( '(untitled)', 'checkoff' ) ) . '</strong>';
		$html .= ' <span class="cko-admin-outline__type">' . esc_html( $type->icon() . ' ' . $type->label() ) . '</span>';

		if ( '' !== $block['description'] ) {
			$html .= '<p class="cko-admin-outline__desc">' . esc_html( $block['description'] ) . '</p>';
		}

		if ( ! empty( $block['items'] ) ) {

			$html .= '<ul>';

			foreach ( $block['items'] as $item ) {

				if ( 'block' === $item['kind'] ) {
					$html .= '<li>' . $this->outline( $item, $values ) . '</li>';
					continue;
				}

				$value = $values[ $item['id'] ] ?? null;

				$html .= '<li>' . esc_html( '' !== $item['label'] ? $item['label'] : '—' );

				if ( null !== $value ) {
					$html .= ' <span class="cko-admin-outline__value">' . esc_html( $this->value_text( $block, $value ) ) . '</span>';
				}

				$html .= '</li>';
			}

			$html .= '</ul>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * A value in words, for the outline.
	 *
	 * @param array $block Block the entry belongs to.
	 * @param mixed $value Value.
	 * @return string
	 */
	private function value_text( array $block, mixed $value ): string {

		switch ( $block['type'] ) {

			case 'simple':
				return '✓';

			case 'weekdays':
				$names = Checkoff_Block_Weekdays::weekday_names( true );
				return '✓ ' . implode( ', ', array_map( static fn( $day ) => $names[ $day ] ?? '', (array) ( $value['days'] ?? array() ) ) );

			case 'color_scale':
				$scale = Checkoff_Block_Color_Scale::scale()[ (int) $block['settings']['steps'] ] ?? array();
				return '● ' . ( $scale[ (int) $value ]['label'] ?? '' );

			case 'counter':
				$target = (int) $block['settings']['target'];
				return $target > 0 ? $value . ' / ' . $target : (string) $value;

			default:
				return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}
	}

	/**
	 * Saves the two actions of the ID box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save( int $post_id, WP_Post $post ): void {

		if ( ! isset( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$checklist = new Checkoff_Checklist( $post_id );

		if ( ! empty( $_POST['cko_regenerate_id'] ) ) {
			$checklist->regenerate_public_id();
		}

		if ( ! empty( $_POST['cko_reset_values'] ) ) {
			$checklist->reset_values();
		}

		/*
		 * The title field of this screen renames the post; the frontend reads
		 * the title of the top-level block. Carry a rename across, as a
		 * regular structure save, so open tabs learn about it.
		 */
		$structure = $checklist->get_structure();

		if ( $post->post_title !== $structure['title'] && '' !== trim( $post->post_title ) ) {
			$structure['title'] = $post->post_title;
			remove_action( 'save_post_' . Checkoff_Post_Types::POST_TYPE, array( $this, 'save' ), 10 );
			$checklist->set_structure( $structure, $checklist->get_revision() );
			add_action( 'save_post_' . Checkoff_Post_Types::POST_TYPE, array( $this, 'save' ), 10, 2 );
		}
	}
}
