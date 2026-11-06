<?php
/**
 * Assertions for the checklist model, the building blocks and the shortcodes.
 *
 * Runs against a real install, because that is what it tests (posts, meta,
 * options, transients). Creates its own checklists and deletes them again.
 *
 *   wp eval-file wp-content/plugins/checkoff/tools/checklist-test.php
 *
 * Not distributed (see .distignore).
 *
 * @package CheckOff
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// eval-file runs this inside a function: make the counters real globals.
global $checkoff_failures, $checkoff_passes, $checkoff_created;

$checkoff_failures = 0;
$checkoff_passes   = 0;
$checkoff_created  = array();

function cko_assert( bool $condition, string $message ): void {
	global $checkoff_failures, $checkoff_passes;
	if ( $condition ) {
		++$checkoff_passes;
		return;
	}
	++$checkoff_failures;
	echo "FAIL: {$message}\n";
}

function cko_new( string $title = 'T test', string $type = 'simple' ): Checkoff_Checklist {
	global $checkoff_created;
	$checklist = Checkoff_Checklist::create( $title, $type );
	if ( is_wp_error( $checklist ) ) {
		echo 'Cannot create: ' . $checklist->get_error_message() . "\n";
		exit( 1 );
	}
	$checkoff_created[] = $checklist->get_post_id();
	return $checklist;
}

/* --- public IDs ---------------------------------------------------------- */

$id = Checkoff_Public_Id::generate( 12 );
cko_assert( 12 === strlen( $id ), 'generated ID has the asked length' );
cko_assert( (bool) preg_match( '/^[23456789abcdefghjkmnpqrstuvwxyz]+$/', $id ), 'generated ID uses the alphabet only' );
cko_assert( 'abcd-efgh-jkmn' === Checkoff_Public_Id::format( 'abcdefghjkmn' ), 'format groups by four' );
cko_assert( 'abcdefghjkmn' === Checkoff_Public_Id::normalize( ' ABCD-efgh jkmn ' ), 'normalize ignores case, spaces, dashes' );
cko_assert( '' === Checkoff_Public_Id::normalize( 'abc' ), 'too short is not an ID' );
cko_assert( '' === Checkoff_Public_Id::normalize( 'abcdefghjkm0' ), 'characters outside the alphabet are refused' );
cko_assert( '' === Checkoff_Public_Id::normalize( array( 'x' ) ), 'non-scalar is refused' );

/* --- create and find ----------------------------------------------------- */

$list = cko_new( 'T Moving house' );
$pid  = $list->get_public_id();

cko_assert( '' !== $pid, 'a new checklist has a public ID' );
cko_assert( 'T Moving house' === $list->get_structure()['title'], 'root title is the checklist title' );
cko_assert( 1 === $list->get_revision(), 'a new checklist starts at revision 1' );

$found = Checkoff_Checklist::find( strtoupper( Checkoff_Public_Id::format( $pid ) ) );
cko_assert( $found && $found->get_post_id() === $list->get_post_id(), 'found by formatted, upper-case public ID' );
cko_assert( null === Checkoff_Checklist::find( (string) $list->get_post_id() ), 'not found by its post ID' );
cko_assert( null === Checkoff_Checklist::find( 'zzzzzzzzzzzz' ), 'unknown ID finds nothing' );

$client = $list->to_client( 'https://example.org/page/' );
cko_assert( ! isset( $client['post_id'] ), 'client data has no post_id key' );
cko_assert( str_contains( $client['url'], 'cko=' . Checkoff_Public_Id::format( $pid ) ), 'URL carries the formatted public ID' );

$disabled_option = Checkoff_Block_Types::enabled_option( 'counter' );
update_option( $disabled_option, '' );
$typed = cko_new( 'T counter', 'counter' );
cko_assert( 'simple' === $typed->get_structure()['type'], 'a switched-off block cannot start a checklist' );
delete_option( $disabled_option );
$typed2 = cko_new( 'T counter 2', 'counter' );
cko_assert( 'counter' === $typed2->get_structure()['type'], 'an enabled block can start a checklist' );
cko_assert( 'simple' === cko_new( 'T x', 'nonsense' )->get_structure()['type'], 'an unknown type falls back to simple' );

/* --- structure validation ------------------------------------------------ */

$raw = array(
	'id'       => 'root0001',
	'type'     => 'simple',
	'title'    => '<b>Root</b>',
	'settings' => array(),
	'items'    => array(
		array( 'id' => 'item0001', 'kind' => 'item', 'label' => 'One' ),
		array( 'id' => 'item0001', 'kind' => 'item', 'label' => 'Duplicate ID' ),
		array( 'id' => 'BAD!', 'kind' => 'item', 'label' => 'Bad ID' ),
		array(
			'id'       => 'week0001',
			'kind'     => 'block',
			'type'     => 'weekdays',
			'title'    => 'Habits',
			'settings' => array( 'days' => array( 1, 3, 9, 'x' ), 'reset' => 'weekly', 'extra' => 1 ),
			'items'    => array(
				array( 'id' => 'wkit0001', 'kind' => 'item', 'label' => 'Run' ),
				array( 'id' => 'nest0001', 'kind' => 'block', 'type' => 'simple', 'items' => array() ),
			),
		),
		array(
			'id'       => 'num00001',
			'kind'     => 'block',
			'type'     => 'number_scale',
			'settings' => array( 'min' => 10, 'max' => 1 ),
			'items'    => array( array( 'id' => 'numit001', 'kind' => 'item', 'label' => 'Mood' ) ),
		),
		array( 'id' => 'unk00001', 'kind' => 'block', 'type' => 'does_not_exist', 'items' => array() ),
		'garbage',
	),
);

$clean = Checkoff_Checklist::sanitize_structure( $raw );
$ids   = array();
array_walk_recursive(
	$clean,
	function ( $v, $k ) use ( &$ids ) {
		if ( 'id' === $k ) {
			$ids[] = $v;
		}
	}
);

cko_assert( 'Root' === $clean['title'], 'tags are stripped from titles' );
cko_assert( count( $ids ) === count( array_unique( $ids ) ), 'all node IDs are unique after validation' );
cko_assert( 'item0001' === $clean['items'][0]['id'], 'a valid ID is kept' );
cko_assert( 'item0001' !== $clean['items'][1]['id'], 'a duplicate ID is replaced' );
cko_assert( Checkoff_Checklist::is_node_id( $clean['items'][2]['id'] ), 'an invalid ID is replaced by a valid one' );
cko_assert( array( 1, 3 ) === $clean['items'][3]['settings']['days'], 'weekday settings keep valid days only' );
cko_assert( ! isset( $clean['items'][3]['settings']['extra'] ), 'unknown settings are dropped' );
cko_assert( 1 === count( $clean['items'][3]['items'] ), 'a block without children support loses its sub-lists' );
cko_assert( array( 'min' => 1, 'max' => 10 ) === $clean['items'][4]['settings'], 'a reversed number range is turned round' );
cko_assert( 'simple' === $clean['items'][5]['type'], 'an unknown block type becomes simple' );
cko_assert( 6 === count( $clean['items'] ), 'non-array entries are dropped' );

// Depth limit.
update_option( 'checkoff_max_depth', 2 );
$deep  = array(
	'type'  => 'simple',
	'items' => array(
		array(
			'kind'  => 'block',
			'type'  => 'simple',
			'items' => array(
				array( 'kind' => 'block', 'type' => 'simple', 'items' => array() ),
				array( 'kind' => 'item', 'label' => 'kept' ),
			),
		),
	),
);
$deep  = Checkoff_Checklist::sanitize_structure( $deep );
cko_assert( 1 === count( $deep['items'][0]['items'] ) && 'item' === $deep['items'][0]['items'][0]['kind'], 'blocks beyond the depth limit are dropped, entries stay' );
delete_option( 'checkoff_max_depth' );

// Size limit.
update_option( 'checkoff_max_nodes', 10 );
$big = array( 'type' => 'simple', 'items' => array_fill( 0, 50, array( 'kind' => 'item', 'label' => 'x' ) ) );
$big = Checkoff_Checklist::sanitize_structure( $big );
cko_assert( 10 === array_sum( Checkoff_Checklist::count_nodes( $big ) ), 'the size limit caps the number of nodes' );
delete_option( 'checkoff_max_nodes' );

/* --- saving structure, revisions ----------------------------------------- */

$list = cko_new( 'T revisions' );
$res  = $list->set_structure( $raw, 1 );
cko_assert( ! is_wp_error( $res ) && 2 === $res['revision'], 'saving with the current revision succeeds and counts up' );
cko_assert( 'Root' === get_the_title( $list->get_post_id() ), 'the post title follows the root title' );

$stale = $list->set_structure( $raw, 1 );
cko_assert( is_wp_error( $stale ) && 'checkoff_conflict' === $stale->get_error_code(), 'saving with a stale revision is a conflict' );
cko_assert( is_wp_error( $list->set_structure( 'nope', 2 ) ), 'a non-array structure is refused' );

/* --- values ---------------------------------------------------------------- */

$structure = $list->get_structure();
$item_id   = $structure['items'][0]['id'];

cko_assert( true === $list->set_value( $item_id, true ), 'simple: true is stored' );
cko_assert( null === $list->set_value( $item_id, false ), 'simple: false clears' );
$list->set_value( $item_id, '1' );
cko_assert( true === ( $list->get_values()[ $item_id ] ?? null ), 'simple: "1" reads as ticked' );
cko_assert( is_wp_error( $list->set_value( 'nosuchit', true ) ), 'an unknown entry is refused' );
cko_assert( is_wp_error( $list->set_value( $structure['id'], true ) ), 'a block ID is not an entry' );

// Weekdays.
$week_item = 'wkit0001';
$v         = $list->set_value( $week_item, array( 'days' => array( 1, 2, 3 ), 'week' => '1999-W01' ) );
cko_assert( array( 1, 3 ) === $v['days'], 'weekdays: only the block\'s days are kept' );
cko_assert( Checkoff_Block_Weekdays::current_week() === $v['week'], 'weekdays: the server dates the value, not the client' );
cko_assert( null === $list->set_value( $week_item, array( 'days' => array() ) ), 'weekdays: no day clears' );

$list->set_value( $week_item, array( 'days' => array( 1 ) ) );
$values                = get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUES, true );
$values[ $week_item ]['week'] = '2000-W01';
update_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUES, $values );
cko_assert( ! isset( $list->get_values()[ $week_item ] ), 'weekdays: last week\'s ticks read as empty with the weekly reset' );

// Re-saving the structure must not move last week's ticks into this week.
$list->set_structure( $list->get_structure(), $list->get_revision() );
$values = get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUES, true );
cko_assert( '2000-W01' === ( $values[ $week_item ]['week'] ?? '' ), 'weekdays: re-validation keeps the week a value was set in' );

// Number scale.
cko_assert( 7 === $list->set_value( 'numit001', 7 ), 'number: a value in range is stored' );
cko_assert( null === $list->set_value( 'numit001', 11 ), 'number: a value out of range clears' );
$list->set_value( 'numit001', 9 );
$s                                = $list->get_structure();
$s['items'][4]['settings']['max'] = 5;
$list->set_structure( $s, $list->get_revision() );
cko_assert( ! isset( $list->get_values()['numit001'] ), 'number: a value outside a narrowed range is dropped on save' );

// Removing an entry removes its value.
$list->set_value( $item_id, true );
$s = $list->get_structure();
array_shift( $s['items'] );
$list->set_structure( $s, $list->get_revision() );
$values = get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUES, true );
cko_assert( ! isset( $values[ $item_id ] ), 'the value of a removed entry is deleted' );

// Other block types, directly.
$color = Checkoff_Block_Types::get( 'color_scale' );
cko_assert( 2 === $color->sanitize_value( 2, array( 'steps' => 3 ) ), 'colour: a step in range is kept' );
cko_assert( null === $color->sanitize_value( 3, array( 'steps' => 3 ) ), 'colour: a step beyond the scale clears' );
cko_assert( array( 'steps' => 5 ) === $color->sanitize_settings( array( 'steps' => 4 ) ), 'colour: only 3 or 5 steps' );
cko_assert( 3 === count( Checkoff_Block_Color_Scale::scale()[3] ), 'colour: the 3-step scale has three colours' );

$counter = Checkoff_Block_Types::get( 'counter' );
cko_assert( 4 === $counter->sanitize_value( '4', array( 'target' => 3 ) ), 'counter: may exceed the target' );
cko_assert( null === $counter->sanitize_value( -2, array( 'target' => 3 ) ), 'counter: zero or less clears' );

$note = Checkoff_Block_Types::get( 'note' );
cko_assert( "a\nb" === $note->sanitize_value( "a\nb<script>x</script>", array( 'multiline' => true ) ), 'note: tags stripped, lines kept' );
cko_assert( null === $note->sanitize_value( '   ', array( 'multiline' => true ) ), 'note: blank clears' );
cko_assert( Checkoff_Block_Note::MAX_LENGTH === mb_strlen( $note->sanitize_value( str_repeat( 'ä', 5000 ), array( 'multiline' => false ) ) ), 'note: length is capped' );
cko_assert( false === $note->counts_progress(), 'note: does not count towards progress' );

// Reset.
$list->reset_values();
cko_assert( array() === $list->get_values(), 'reset clears every value' );

/* --- rate limit ------------------------------------------------------------ */

$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 1, 250 );
cko_assert( ! Checkoff_Rate_Limit::is_exceeded( 'test', 2 ), 'rate limit: fresh visitor is not limited' );
Checkoff_Rate_Limit::hit( 'test' );
Checkoff_Rate_Limit::hit( 'test' );
cko_assert( Checkoff_Rate_Limit::is_exceeded( 'test', 2 ), 'rate limit: limited after the allowed number' );
cko_assert( ! Checkoff_Rate_Limit::is_exceeded( 'test', 0 ), 'rate limit: 0 means unlimited' );

/* --- shortcodes ------------------------------------------------------------ */

$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
$list = cko_new( 'T </script><script>alert(1)</script>' );

$html = do_shortcode( '[cko_checklist id="' . Checkoff_Public_Id::format( $list->get_public_id() ) . '"]' );
cko_assert( str_contains( $html, 'data-cko-checklist' ), 'shortcode renders the checklist container' );
cko_assert( 1 === substr_count( strtolower( $html ), '</script>' ), 'shortcode JSON cannot close its script element' );
cko_assert( ! str_contains( $html, '"post_id"' ) && ! str_contains( $html, 'post=' . $list->get_post_id() ), 'shortcode exposes no post ID' );

$html = do_shortcode( '[cko_checklist id="zzzz-zzzz-zzzz"]' );
cko_assert( str_contains( $html, 'cko-message--error' ), 'unknown ID shows an error' );

update_option( 'checkoff_failed_lookups_per_hour', 2 );
do_shortcode( '[cko_checklist id="zzzz-zzzz-zzzy"]' );
$html = do_shortcode( '[cko_checklist id="' . $list->get_public_id() . '"]' );
cko_assert( ! str_contains( $html, 'data-cko-checklist' ), 'after too many unknown IDs even a correct one is refused' );
delete_option( 'checkoff_failed_lookups_per_hour' );

$html = do_shortcode( '[cko_create]' );
cko_assert( str_contains( $html, 'data-cko-create' ), 'create form renders' );
update_option( 'checkoff_guests_can_create', '' );
wp_set_current_user( 0 );
$html = do_shortcode( '[cko_create]' );
cko_assert( ! str_contains( $html, 'data-cko-create' ), 'create form is replaced by a login hint when guests may not create' );
delete_option( 'checkoff_guests_can_create' );

$html = do_shortcode( '[cko_open]' );
cko_assert( str_contains( $html, 'name="cko"' ), 'open form uses the cko parameter' );

/* --- cleanup --------------------------------------------------------------- */

$old = cko_new( 'T old' );
update_post_meta( $old->get_post_id(), Checkoff_Checklist::META_LAST_ACCESS, time() - 40 * DAY_IN_SECONDS );
update_option( 'checkoff_cleanup_inactive_days', 30 );
( new Checkoff_Cleanup() )->run();
cko_assert( null === get_post( $old->get_post_id() ), 'cleanup deletes a checklist unused for longer than the limit' );
cko_assert( null !== get_post( $list->get_post_id() ), 'cleanup keeps a recently used checklist' );
delete_option( 'checkoff_cleanup_inactive_days' );

/* --- done ------------------------------------------------------------------ */

foreach ( $checkoff_created as $post_id ) {
	wp_delete_post( $post_id, true );
}

echo "{$checkoff_passes} passed, {$checkoff_failures} failed\n";

if ( $checkoff_failures > 0 ) {
	exit( 1 );
}
