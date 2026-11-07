<?php
/**
 * Templates offered by the site.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checklists the site offers as a starting point ("Moving house", "Weekly
 * cleaning").
 *
 * A template is an ordinary checklist that an administrator built on the
 * website like any visitor and then marked as a template in wp-admin. A
 * visitor who chooses it gets a copy of its structure — with its repetition
 * starting today — and nothing else: no ticks, no subscriptions, no IDs.
 * Changing the template later changes no copy.
 *
 * Templates are referred to by a key of their own, never by post ID and
 * never by one of their IDs, which would let anybody edit or tick the
 * template itself.
 *
 * Meta on the checklist: _cko_template ('1'), _cko_template_key,
 * _cko_template_desc.
 */
class Checkoff_Templates {

	const META_FLAG = '_cko_template';
	const META_KEY  = '_cko_template_key';
	const META_DESC = '_cko_template_desc';

	/**
	 * Prefix of a template choice in the "start with" field of the create form.
	 */
	const CHOICE_PREFIX = 'tpl:';

	const MAX_DESCRIPTION = 500;

	/**
	 * Whether a create form offers the templates when its shortcode does not
	 * say (`[cko_create templates="no"]` hides them): yes — unless the site
	 * switched off the former setting "Offer templates" (up to 1.11), whose
	 * stored value is still honoured so such forms do not suddenly change.
	 *
	 * @return bool
	 */
	public static function shown_by_default(): bool {
		return '' !== (string) get_option( 'checkoff_templates_in_form', 'on' );
	}

	/**
	 * Whether a checklist is a template.
	 *
	 * @param int $post_id Checklist post ID.
	 * @return bool
	 */
	public static function is_template( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::META_FLAG, true );
	}

	/**
	 * Marks a checklist as template, or no longer.
	 *
	 * @param int    $post_id     Checklist post ID.
	 * @param bool   $is_template Offer it.
	 * @param string $description Short description for visitors.
	 * @return void
	 */
	public static function set_template( int $post_id, bool $is_template, string $description = '' ): void {

		if ( ! $is_template ) {
			delete_post_meta( $post_id, self::META_FLAG );
			return;
		}

		update_post_meta( $post_id, self::META_FLAG, '1' );
		update_post_meta( $post_id, self::META_DESC, mb_substr( sanitize_textarea_field( $description ), 0, self::MAX_DESCRIPTION ) );

		if ( '' === (string) get_post_meta( $post_id, self::META_KEY, true ) ) {
			update_post_meta( $post_id, self::META_KEY, Checkoff_Public_Id::generate( 10 ) );
		}
	}

	/**
	 * The template checklists, sorted by title.
	 *
	 * @return array<int, array{key:string, title:string, description:string, items:int, lists:int, repetition:string}>
	 */
	public static function all(): array {

		$ids = get_posts(
			array(
				'post_type'        => Checkoff_Post_Types::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'meta_key'         => self::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$templates = array();

		foreach ( $ids as $id ) {

			$checklist = new Checkoff_Checklist( (int) $id );
			$counts    = Checkoff_Checklist::count_nodes( $checklist->get_structure() );
			$key       = (string) get_post_meta( (int) $id, self::META_KEY, true );

			if ( '' === $key ) {
				continue;
			}

			$templates[] = array(
				'key'         => $key,
				'title'       => $checklist->get_title(),
				'description' => (string) get_post_meta( (int) $id, self::META_DESC, true ),
				'items'       => $counts['items'],
				'lists'       => $counts['blocks'],
				'repetition'  => $checklist->recurrence()->repeats() ? $checklist->recurrence()->describe() : '',
			);
		}

		return $templates;
	}

	/**
	 * The template with a key, or null.
	 *
	 * @param string $key Template key.
	 * @return Checkoff_Checklist|null
	 */
	public static function find( string $key ): ?Checkoff_Checklist {

		$key = sanitize_key( $key );

		if ( '' === $key ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'        => Checkoff_Post_Types::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_KEY,
						'value' => $key,
					),
					array(
						'key'   => self::META_FLAG,
						'value' => '1',
					),
				),
			)
		);

		return empty( $ids ) ? null : new Checkoff_Checklist( (int) $ids[0] );
	}

	/**
	 * Creates a visitor's checklist from a template.
	 *
	 * @param string $key   Template key.
	 * @param string $title Title of the copy; the template's when empty.
	 * @return Checkoff_Checklist|WP_Error
	 */
	public static function create_from( string $key, string $title = '' ): Checkoff_Checklist|WP_Error {

		$template = self::find( $key );

		if ( null === $template ) {
			return new WP_Error( 'checkoff_template', __( 'This template is no longer available.', 'checkoff' ) );
		}

		$structure = $template->get_structure();

		if ( '' !== trim( $title ) ) {
			$structure['title'] = $title;
		}

		// A repeating template starts over today for whoever takes it, or the
		// copy would begin in the template's round 37.
		if ( 'none' !== ( $structure['recurrence']['freq'] ?? 'none' ) ) {
			$structure['recurrence']['start'] = (string) wp_date( 'Y-m-d' );
			if ( Checkoff_Recurrence::END_UNTIL === $structure['recurrence']['end'] && $structure['recurrence']['until'] < $structure['recurrence']['start'] ) {
				$structure['recurrence']['end'] = Checkoff_Recurrence::END_NEVER;
			}
		}

		$copy = Checkoff_Checklist::create( (string) $structure['title'], Checkoff_Block_Types::FALLBACK );

		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		$structure['id'] = $copy->get_structure()['id'];

		$result = $copy->set_structure( $structure, $copy->get_revision() );

		if ( is_wp_error( $result ) ) {
			$copy->delete();
			return $result;
		}

		return new Checkoff_Checklist( $copy->get_post_id() );
	}
}
