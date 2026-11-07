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
	const BULK_ACTION  = 'checkoff_checklist_bulk';
	const BULK_NONCE   = 'checkoff_checklist_bulk_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_' . Checkoff_Post_Types::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Checkoff_Post_Types::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'render_bulk_edit' ), 10, 2 );
		add_action( 'save_post_' . Checkoff_Post_Types::POST_TYPE, array( $this, 'save_bulk_edit' ), 10, 1 );
	}

	/**
	 * The template choice in the list's bulk edit ("Edit" under bulk
	 * actions): no change, offer, or no longer offer. Printed once, in the
	 * ID column's slot.
	 *
	 * @param string $column    Column.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function render_bulk_edit( string $column, string $post_type ): void {

		if ( Checkoff_Post_Types::POST_TYPE !== $post_type || 'cko_public_id' !== $column ) {
			return;
		}

		wp_nonce_field( self::BULK_ACTION, self::BULK_NONCE, false );

		echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label class="inline-edit-group">';
		echo '<span class="title">' . esc_html__( 'Template', 'checkoff' ) . '</span>';
		echo '<select name="cko_bulk_template">';
		echo '<option value="">' . esc_html__( '— No Change —', 'checkoff' ) . '</option>';
		echo '<option value="1">' . esc_html__( 'Offer as a template', 'checkoff' ) . '</option>';
		echo '<option value="0">' . esc_html__( 'Do not offer as a template', 'checkoff' ) . '</option>';
		echo '</select></label></div></fieldset>';
	}

	/**
	 * Applies the bulk edit's template choice to each checklist core saves.
	 * Each keeps its own description; a new template gets its key as in the
	 * box.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_bulk_edit( int $post_id ): void {

		if ( empty( $_REQUEST['bulk_edit'] ) || ! isset( $_REQUEST[ self::BULK_NONCE ], $_REQUEST['cko_bulk_template'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ self::BULK_NONCE ] ) ), self::BULK_ACTION )
		) {
			return;
		}

		$choice = sanitize_key( wp_unslash( $_REQUEST['cko_bulk_template'] ) );

		if ( ! in_array( $choice, array( '0', '1' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		Checkoff_Templates::set_template( $post_id, '1' === $choice, (string) get_post_meta( $post_id, Checkoff_Templates::META_DESC, true ) );
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
			'checkoff-checklist-template',
			__( 'Template', 'checkoff' ),
			array( $this, 'render_template_box' ),
			Checkoff_Post_Types::POST_TYPE,
			'side',
			'default'
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
		$tick_id   = Checkoff_Public_Id::format( $checklist->get_tick_id() );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<p><strong>' . esc_html__( 'ID', 'checkoff' ) . '</strong><br />';
		echo '<code class="cko-admin-id">' . esc_html( $public_id ) . '</code> ';
		echo '<button type="button" class="button button-small cko-admin-copy" data-copy="' . esc_attr( $public_id ) . '">'
			. esc_html__( 'Copy', 'checkoff' ) . '</button></p>';

		echo '<p><strong>' . esc_html__( 'Access', 'checkoff' ) . '</strong><br />' . esc_html( self::level_label( $checklist->get_level() ) );
		if ( $checklist->is_listed() ) {
			echo '<br />' . esc_html__( 'Shown publicly in the directory.', 'checkoff' );
		}
		echo '</p>';

		// Lists from before 2.0.0 may have handed out a tick-only link.
		if ( '' !== $tick_id ) {
			echo '<p><strong>' . esc_html__( 'Earlier link to share (tick only)', 'checkoff' ) . '</strong><br />';
			echo '<code class="cko-admin-id">' . esc_html( $tick_id ) . '</code></p>';
		}

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

		if ( '' !== $tick_id ) {
			echo '<p><label><input type="checkbox" name="cko_remove_tick_id" value="1" /> '
				. esc_html__( 'End the earlier link to share', 'checkoff' ) . '</label><br />';
			echo '<span class="description">' . esc_html__( 'It stops working immediately.', 'checkoff' ) . '</span></p>';
		}

		if ( $checklist->is_listed() ) {
			echo '<p><label><input type="checkbox" name="cko_unlist" value="1" /> '
				. esc_html__( 'Take out of the public directory', 'checkoff' ) . '</label><br />';
			echo '<span class="description">' . esc_html__( 'Its owner can show it again.', 'checkoff' ) . '</span></p>';
		}

		echo '<p><label><input type="checkbox" name="cko_reset_values" value="1" /> '
			. esc_html__( 'Clear all ticks and ratings', 'checkoff' ) . '</label></p>';

		// Only the hash is stored: the password can be removed here, never read.
		if ( $checklist->is_protected() ) {
			echo '<p><label><input type="checkbox" name="cko_remove_password" value="1" /> '
				. esc_html__( 'Remove the password', 'checkoff' ) . '</label><br />';
			echo '<span class="description">' . esc_html__( 'For an owner who forgot it: afterwards the list is open — everybody with its link can view, tick and edit it. The password itself cannot be shown, only its hash is stored.', 'checkoff' ) . '</span></p>';
		}
	}

	/**
	 * A level in words, for wp-admin.
	 *
	 * @param string $level Checkoff_Checklist::LEVEL_*.
	 * @return string
	 */
	public static function level_label( string $level ): string {

		switch ( $level ) {
			case Checkoff_Checklist::LEVEL_PRIVATE:
				return __( 'Private — viewing needs the password', 'checkoff' );
			case Checkoff_Checklist::LEVEL_PROTECTED:
				return __( 'Protected — editing needs the password', 'checkoff' );
			default:
				return __( 'Open — no password', 'checkoff' );
		}
	}

	/**
	 * The template box: offer this checklist as a starting point.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_template_box( WP_Post $post ): void {

		$is_template = Checkoff_Templates::is_template( (int) $post->ID );
		$description = (string) get_post_meta( (int) $post->ID, Checkoff_Templates::META_DESC, true );

		echo '<p><label><input type="checkbox" name="cko_template" value="1"' . checked( $is_template, true, false ) . ' /> '
			. esc_html__( 'Offer as a template', 'checkoff' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Visitors can start their own checklist from it: they get a copy of its entries and its repetition, without ticks. Later changes here do not change their copies.', 'checkoff' ) . '</p>';
		echo '<p><label for="cko-template-desc">' . esc_html__( 'Short description for visitors', 'checkoff' ) . '</label>';
		echo '<textarea id="cko-template-desc" name="cko_template_desc" class="widefat" rows="3" maxlength="' . esc_attr( (string) Checkoff_Templates::MAX_DESCRIPTION ) . '">' . esc_textarea( $description ) . '</textarea></p>';
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

		// The container: its title, then its blocks as sections.
		if ( Checkoff_Checklist::KIND_CHECKLIST === ( $block['kind'] ?? '' ) ) {

			$html = '<div class="cko-admin-outline__checklist">';

			if ( '' !== $block['description'] ) {
				$html .= '<p class="cko-admin-outline__desc">' . esc_html( $block['description'] ) . '</p>';
			}

			foreach ( $block['items'] as $section ) {
				$html .= '<div class="cko-admin-outline__section">' . $this->outline( $section, $values ) . '</div>';
			}

			return $html . '</div>';
		}

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

				$text = Checkoff_Block_Types::get( $block['type'] )->describe_item( $item, $value, $block['settings'] );

				if ( '' !== $text ) {
					$html .= ' <span class="cko-admin-outline__value">' . esc_html( $text ) . '</span>';
				}

				$html .= '</li>';
			}

			$html .= '</ul>';
		}

		$html .= '</div>';

		return $html;
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

		Checkoff_Templates::set_template(
			$post_id,
			! empty( $_POST['cko_template'] ),
			isset( $_POST['cko_template_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cko_template_desc'] ) ) : ''
		);

		if ( ! empty( $_POST['cko_remove_tick_id'] ) ) {
			$checklist->remove_tick_id();
		}

		if ( ! empty( $_POST['cko_unlist'] ) ) {
			$checklist->set_listed( false );
		}

		if ( ! empty( $_POST['cko_reset_values'] ) ) {
			$checklist->reset_values();
		}

		if ( ! empty( $_POST['cko_remove_password'] ) ) {
			$checklist->set_level( Checkoff_Checklist::LEVEL_OPEN );
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
