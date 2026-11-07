<?php
/**
 * The public directory of checklists.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checklists their owners show publicly, for `[cko_lists]` and
 * `GET /checkoff/v1/lists`.
 *
 * A list is in it while its owner wants it there (`_cko_listed`) and it is
 * protected — everybody may view and tick it, editing needs the password.
 * An open list is never listed (anybody passing by could change or delete
 * it), a private one cannot be (nobody may see it). Nothing is approved
 * beforehand; an administrator can take a list out in its IDs box. The
 * setting `checkoff_lists_enabled` (default on) switches the whole
 * directory off; the lists keep their mark and come back when it is on.
 */
class Checkoff_Directory {

	const OPTION    = 'checkoff_lists_enabled';
	const LIMIT_MAX = 100;

	/**
	 * Whether the site offers public checklists.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '' !== (string) get_option( self::OPTION, 'on' );
	}

	/**
	 * The listed checklists, most recently used first.
	 *
	 * @param int $limit  How many (1–100).
	 * @param int $offset How many to skip.
	 * @return Checkoff_Checklist[] Opened as by their address: protected, so locked.
	 */
	public static function lists( int $limit = 20, int $offset = 0 ): array {

		if ( ! self::is_enabled() ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => Checkoff_Post_Types::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => max( 1, min( self::LIMIT_MAX, $limit ) ),
				'offset'           => max( 0, $offset ),
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the directory is the query; the result is short.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => Checkoff_Checklist::META_LISTED,
						'value' => '1',
					),
					array(
						'key'     => Checkoff_Checklist::META_PASSWORD,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Checkoff_Checklist::META_PRIVATE,
						'compare' => 'NOT EXISTS',
					),
					'last'     => array(
						'key'  => Checkoff_Checklist::META_LAST_ACCESS,
						'type' => 'NUMERIC',
					),
				),
				'orderby'          => array(
					'last' => 'DESC',
					'ID'   => 'DESC',
				),
			)
		);

		return array_map(
			static fn( $id ) => new Checkoff_Checklist( (int) $id, Checkoff_Checklist::ACCESS_LOCKED ),
			array_filter( array_map( 'intval', $ids ), static fn( $id ) => ( new Checkoff_Checklist( $id ) )->is_listed() )
		);
	}

	/**
	 * What the directory tells about one list: no more than its page shows
	 * a visitor without the password.
	 *
	 * @param Checkoff_Checklist $checklist Listed checklist.
	 * @param string             $fallback  Base URL when no checklist page is configured.
	 * @return array{id:string, title:string, description:string, url:string, progress:array{done:int|float, total:int|float}, repeats:bool}
	 */
	public static function entry( Checkoff_Checklist $checklist, string $fallback = '' ): array {

		$structure = $checklist->get_structure();

		return array(
			'id'          => Checkoff_Public_Id::format( $checklist->get_public_id() ),
			'title'       => $checklist->get_title(),
			'description' => (string) ( $structure['description'] ?? '' ),
			'url'         => $checklist->url( $fallback ),
			'progress'    => Checkoff_Checklist::progress_of( $structure, $checklist->get_values() ),
			'repeats'     => ! empty( $structure['recurrence']['freq'] ) && 'none' !== $structure['recurrence']['freq'],
		);
	}
}
