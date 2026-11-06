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

// The assertions read English messages; run in the source language.
switch_to_locale( 'en_US' );

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
cko_assert( 'simple' === $typed->get_structure()['items'][0]['type'], 'a switched-off block cannot start a checklist' );
delete_option( $disabled_option );
$typed2 = cko_new( 'T counter 2', 'counter' );
cko_assert( 'counter' === $typed2->get_structure()['items'][0]['type'], 'an enabled block can start a checklist' );
cko_assert( 'simple' === cko_new( 'T x', 'nonsense' )->get_structure()['items'][0]['type'], 'an unknown type falls back to simple' );
cko_assert( 'checklist' === $typed2->get_structure()['kind'] && ! isset( $typed2->get_structure()['type'] ), 'a checklist itself has no type: it is a container of blocks' );

/* --- structure validation ------------------------------------------------ */

$raw = array(
	'id'    => 'root0001',
	'kind'  => 'checklist',
	'title' => '<b>Root</b>',
	'items' => array(
		array(
			'id'    => 'smp00001',
			'kind'  => 'block',
			'type'  => 'simple',
			'title' => 'Main',
			'items' => array(
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
		array( 'id' => 'loose001', 'kind' => 'item', 'label' => 'An entry needs a block' ),
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
$main = $clean['items'][0];

cko_assert( 'checklist' === $clean['kind'] && 'Root' === $clean['title'], 'the container keeps its kind; tags are stripped from titles' );
cko_assert( count( $ids ) === count( array_unique( $ids ) ), 'all node IDs are unique after validation' );
cko_assert( 'item0001' === $main['items'][0]['id'], 'a valid ID is kept' );
cko_assert( 'item0001' !== $main['items'][1]['id'], 'a duplicate ID is replaced' );
cko_assert( Checkoff_Checklist::is_node_id( $main['items'][2]['id'] ), 'an invalid ID is replaced by a valid one' );
cko_assert( array( 1, 3 ) === $main['items'][3]['settings']['days'], 'weekday settings keep valid days only' );
cko_assert( ! isset( $main['items'][3]['settings']['extra'] ), 'unknown settings are dropped' );
cko_assert( 2 === count( $main['items'][3]['items'] ) && 'block' === $main['items'][3]['items'][1]['kind'], 'every block type may hold sub-lists' );
cko_assert( array( 'min' => 1, 'max' => 10 ) === $clean['items'][1]['settings'], 'a reversed number range is turned round' );
cko_assert( 'simple' === $clean['items'][2]['type'], 'an unknown block type becomes simple' );
cko_assert( 3 === count( $clean['items'] ), 'the top level keeps blocks only: loose entries and non-arrays are dropped' );

// Depth limit: blocks count from the top level, the container is not one.
update_option( 'checkoff_max_depth', 2 );
$deep = Checkoff_Checklist::sanitize_structure(
	array(
		'kind'  => 'checklist',
		'items' => array(
			array(
				'kind'  => 'block',
				'type'  => 'simple',
				'items' => array(
					array(
						'kind'  => 'block',
						'type'  => 'counter',
						'items' => array(
							array( 'kind' => 'block', 'type' => 'simple', 'items' => array() ),
							array( 'kind' => 'item', 'label' => 'kept' ),
						),
					),
				),
			),
		),
	)
);
$second = $deep['items'][0]['items'][0];
cko_assert( 1 === count( $second['items'] ) && 'item' === $second['items'][0]['kind'], 'blocks beyond the depth limit are dropped, entries stay' );
delete_option( 'checkoff_max_depth' );

// Size limit (the container counts as one node).
update_option( 'checkoff_max_nodes', 10 );
$big = Checkoff_Checklist::sanitize_structure(
	array(
		'kind'  => 'checklist',
		'items' => array( array( 'kind' => 'block', 'type' => 'simple', 'items' => array_fill( 0, 50, array( 'kind' => 'item', 'label' => 'x' ) ) ) ),
	)
);
cko_assert( 9 === array_sum( Checkoff_Checklist::count_nodes( $big ) ), 'the size limit caps the number of nodes' );
delete_option( 'checkoff_max_nodes' );

/* --- stacked blocks: upgrading the old format ------------------------------ */

$old_simple = array(
	'id'          => 'oldroot1',
	'kind'        => 'block',
	'type'        => 'simple',
	'title'       => 'Old list',
	'description' => 'Old description',
	'recurrence'  => array( 'freq' => 'weekly', 'start' => '2026-01-01' ),
	'items'       => array(
		array( 'id' => 'olditem1', 'kind' => 'item', 'label' => 'A' ),
		array( 'id' => 'olditem2', 'kind' => 'item', 'label' => 'B' ),
		array( 'id' => 'oldweek1', 'kind' => 'block', 'type' => 'weekdays', 'title' => 'Week', 'items' => array( array( 'id' => 'oldwkit1', 'kind' => 'item', 'label' => 'W' ) ) ),
		array( 'id' => 'olditem3', 'kind' => 'item', 'label' => 'C' ),
	),
);
$up = Checkoff_Checklist::sanitize_structure( $old_simple );
cko_assert( 'checklist' === $up['kind'] && 'oldroot1' === $up['id'] && 'Old list' === $up['title'] && 'Old description' === $up['description'], 'an old top-level block becomes the container, keeping ID, title and description' );
cko_assert( 'weekly' === $up['recurrence']['freq'], 'the repetition moves to the container' );
cko_assert( 3 === count( $up['items'] ), 'old entries between sub-lists are gathered into blocks, in order' );
cko_assert( array( 'olditem1', 'olditem2' ) === array_column( $up['items'][0]['items'], 'id' ) && 'simple' === $up['items'][0]['type'] && '' === $up['items'][0]['title'], 'leading entries form an untitled simple block' );
cko_assert( 'oldweek1' === $up['items'][1]['id'] && 'Week' === $up['items'][1]['title'], 'an old sub-list becomes a stacked block' );
cko_assert( array( 'olditem3' ) === array_column( $up['items'][2]['items'], 'id' ), 'trailing entries form a block of their own' );
cko_assert( $up === Checkoff_Checklist::sanitize_structure( $old_simple ), 'the same old tree always upgrades to the same new one' );
cko_assert( $up === Checkoff_Checklist::sanitize_structure( $up ), 'an upgraded tree stays as it is' );

$old_weekly = array(
	'id'       => 'oldroot2',
	'type'     => 'weekdays',
	'title'    => 'Habits',
	'settings' => array( 'days' => array( 1, 2 ), 'reset' => 'none' ),
	'items'    => array( array( 'id' => 'oldhab01', 'kind' => 'item', 'label' => 'Run' ) ),
);
$up2 = Checkoff_Checklist::sanitize_structure( $old_weekly );
cko_assert( 1 === count( $up2['items'] ) && 'weekdays' === $up2['items'][0]['type'] && array( 1, 2 ) === $up2['items'][0]['settings']['days'] && 'Habits' === $up2['title'], 'an old list of another type becomes a container with that one block' );

// Stored in the old format, with ticks: the ticks survive the upgrade.
$legacy = cko_new( 'T legacy' );
update_post_meta( $legacy->get_post_id(), Checkoff_Checklist::META_STRUCTURE, $old_simple );
update_post_meta( $legacy->get_post_id(), Checkoff_Checklist::META_VALUE_PREFIX . 'olditem2', array( 'v' => true ) );
$legacy = new Checkoff_Checklist( $legacy->get_post_id() );
cko_assert( 'checklist' === $legacy->get_structure()['kind'] && true === ( $legacy->get_values()['olditem2'] ?? null ), 'a stored old list reads as a container and keeps its ticks' );

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
$item_id   = $structure['items'][0]['items'][0]['id'];

cko_assert( true === $list->set_value( $item_id, true ), 'simple: true is stored' );
cko_assert( null === $list->set_value( $item_id, false ), 'simple: false clears' );
$list->set_value( $item_id, '1' );
cko_assert( true === ( $list->get_values()[ $item_id ] ?? null ), 'simple: "1" reads as ticked' );
cko_assert( is_wp_error( $list->set_value( 'nosuchit', true ) ), 'an unknown entry is refused' );
cko_assert( is_wp_error( $list->set_value( $structure['items'][0]['id'], true ) ), 'a block ID is not an entry' );
cko_assert( is_wp_error( $list->set_value( $structure['id'], true ) ), 'the container ID is not an entry' );

// Weekdays.
$week_item = 'wkit0001';
$v         = $list->set_value( $week_item, array( 'days' => array( 1, 2, 3 ), 'week' => '1999-W01' ) );
cko_assert( array( 1, 3 ) === $v['days'], 'weekdays: only the block\'s days are kept' );
cko_assert( Checkoff_Block_Weekdays::current_week() === $v['week'], 'weekdays: the server dates the value, not the client' );
cko_assert( null === $list->set_value( $week_item, array( 'days' => array() ) ), 'weekdays: no day clears' );

$list->set_value( $week_item, array( 'days' => array( 1 ) ) );
$week_row              = get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUE_PREFIX . $week_item, true );
$week_row['v']['week'] = '2000-W01';
update_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUE_PREFIX . $week_item, $week_row );
cko_assert( ! isset( $list->get_values()[ $week_item ] ), 'weekdays: last week\'s ticks read as empty with the weekly reset' );

// Re-saving the structure must not move last week's ticks into this week.
$list->set_structure( $list->get_structure(), $list->get_revision() );
$week_value = get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUE_PREFIX . $week_item, true );
cko_assert( '2000-W01' === ( $week_value['v']['week'] ?? '' ), 'weekdays: re-validation keeps the week a value was set in' );

// Number scale.
cko_assert( 7 === $list->set_value( 'numit001', 7 ), 'number: a value in range is stored' );
cko_assert( null === $list->set_value( 'numit001', 11 ), 'number: a value out of range clears' );
$list->set_value( 'numit001', 9 );
$s                                = $list->get_structure();
$s['items'][1]['settings']['max'] = 5;
$list->set_structure( $s, $list->get_revision() );
cko_assert( ! isset( $list->get_values()['numit001'] ), 'number: a value outside a narrowed range is dropped on save' );

// Removing an entry removes its value.
$list->set_value( $item_id, true );
$s = $list->get_structure();
array_shift( $s['items'][0]['items'] );
$list->set_structure( $s, $list->get_revision() );
cko_assert( '' === get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUE_PREFIX . $item_id, true ), 'the value of a removed entry is deleted' );

// One row per entry: two visitors ticking different entries do not touch
// each other's row, and a legacy single-array checklist is migrated on read.
$a = new Checkoff_Checklist( $list->get_post_id() );
$b = new Checkoff_Checklist( $list->get_post_id() );
$first_leaf  = array_keys( Checkoff_Checklist::leaf_map( $a->get_structure() ) )[0];
$a->reset_values();
$a->set_value( $first_leaf, true );
$b->set_value( 'numit001', 3 );
$both = ( new Checkoff_Checklist( $list->get_post_id() ) )->get_values();
cko_assert( isset( $both[ $first_leaf ], $both['numit001'] ), 'two writers on different entries both keep their value' );

$a->reset_values();
update_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUES, array( $first_leaf => true, 'numit001' => 4, 'BAD KEY' => 1 ) );
$migrated = ( new Checkoff_Checklist( $list->get_post_id() ) )->get_values();
cko_assert( true === ( $migrated[ $first_leaf ] ?? null ) && 4 === ( $migrated['numit001'] ?? null ), 'legacy values are migrated into rows' );
cko_assert( '' === get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUES, true ), 'the legacy row is gone after migration' );
cko_assert( '' === get_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUE_PREFIX . 'BAD KEY', true ), 'invalid legacy keys are not migrated' );

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

/* --- blocks of 1.5.0: status, due dates, measurement, choice ---------------- */

$status = Checkoff_Block_Types::get( 'status' );
cko_assert( 'done' === $status->sanitize_value( true, array() ) && 'skip' === $status->sanitize_value( 'skip', array() ), 'status: done and skip are kept' );
cko_assert( null === $status->sanitize_value( 'maybe', array() ), 'status: anything else clears' );
cko_assert( array( 'done' => 0, 'total' => 0 ) === $status->progress( 'skip', array() ), 'status: "not needed" leaves the count' );
cko_assert( array( 'done' => 0, 'total' => 1 ) === $status->progress( null, array() ), 'status: open counts as open' );

$deadline = Checkoff_Block_Types::get( 'deadline' );
cko_assert( array( 'due' => '2026-02-28' ) === $deadline->sanitize_item_data( array( 'due' => '2026-02-28' ), array() ), 'deadline: a real date is kept' );
cko_assert( array() === $deadline->sanitize_item_data( array( 'due' => '2026-02-30' ), array() ), 'deadline: an impossible date is dropped' );
cko_assert( array() === $deadline->sanitize_item_data( array( 'due' => 'tomorrow' ), array() ), 'deadline: text is not a date' );
$noon = ( new DateTimeImmutable( '2026-03-10 12:00', wp_timezone() ) )->getTimestamp();
$late = array( 'id' => 'x', 'kind' => 'item', 'label' => 'L', 'data' => array( 'due' => '2026-03-01' ) );
cko_assert( str_starts_with( $deadline->describe_item( $late, null, array( 'soon' => 2 ), $noon ), 'overdue since' ), 'deadline: an open entry past its date is overdue' );
cko_assert( 'due today' === $deadline->describe_item( array( 'data' => array( 'due' => '2026-03-10' ) ), null, array( 'soon' => 2 ), $noon ), 'deadline: due today' );
cko_assert( '✓' === $deadline->describe_item( $late, true, array( 'soon' => 2 ), $noon ), 'deadline: a done entry is not overdue' );

$measure = Checkoff_Block_Types::get( 'measurement' );
$ms      = $measure->sanitize_settings( array( 'unit' => ' kg ', 'min' => '70', 'max' => '60,5', 'decimals' => 1 ) );
cko_assert( 60.5 === $ms['min'] && 70.0 === $ms['max'] && 'kg' === $ms['unit'], 'measurement: limits are swapped when given the wrong way round, decimal comma read' );
cko_assert( 72.4 === $measure->sanitize_value( '72,44', $ms ), 'measurement: rounded to the decimal places' );
cko_assert( null === $measure->sanitize_value( 'heavy', $ms ) && null === $measure->sanitize_value( '', $ms ), 'measurement: no number clears' );
cko_assert( 1 === $measure->progress( 65.0, $ms )['done'] && 0 === $measure->progress( 72.4, $ms )['done'], 'measurement: done inside the target range only' );
$open_ms = $measure->sanitize_settings( array() );
cko_assert( '' === $open_ms['min'] && 1 === $measure->progress( 3.0, $open_ms )['done'], 'measurement: without a range, any number is done' );
cko_assert( 3 === $measure->sanitize_value( '2.6', array_merge( $open_ms, array( 'decimals' => 0 ) ) ), 'measurement: no decimal places stores whole numbers' );

$choice = Checkoff_Block_Types::get( 'choice' );
$cs     = $choice->sanitize_settings( array( 'options' => "Kitchen\n\n Bath \n" . str_repeat( 'x', 80 ) ) );
cko_assert( 3 === count( $cs['options'] ) && 'Bath' === $cs['options'][1] && 40 === mb_strlen( $cs['options'][2] ), 'choice: lines become answers, blanks dropped, length capped' );
cko_assert( 3 === count( $choice->sanitize_settings( array( 'options' => array() ) )['options'] ), 'choice: no answers fall back to the defaults' );
cko_assert( 1 === $choice->sanitize_value( '1', $cs ) && null === $choice->sanitize_value( 3, $cs ), 'choice: only existing answers' );
cko_assert( 'Bath' === $choice->describe_value( 1, $cs ), 'choice: described by its answer' );

// Entry data travels with the structure, only for the block that knows it.
$nb = cko_new( 'T new blocks' );
$ns = $nb->get_structure();
$ns['items'] = array(
	array( 'id' => 'dlblk001', 'kind' => 'block', 'type' => 'deadline', 'settings' => array(), 'items' => array(
		array( 'id' => 'dlit0001', 'kind' => 'item', 'label' => 'Pack', 'data' => array( 'due' => '2026-03-01' ) ),
		array( 'id' => 'dlit0002', 'kind' => 'item', 'label' => 'Move', 'data' => array( 'due' => 'soon' ) ),
	) ),
	array( 'id' => 'stblk001', 'kind' => 'block', 'type' => 'status', 'settings' => array(), 'items' => array(
		array( 'id' => 'stit0001', 'kind' => 'item', 'label' => 'Passport', 'data' => array( 'due' => '2026-03-01' ) ),
		array( 'id' => 'stit0002', 'kind' => 'item', 'label' => 'Visa' ),
	) ),
);
$nb->set_structure( $ns, $nb->get_revision() );
$ns = $nb->get_structure();
cko_assert( array( 'due' => '2026-03-01' ) === ( $ns['items'][0]['items'][0]['data'] ?? null ), 'deadline: the due date is stored with the entry' );
cko_assert( ! isset( $ns['items'][0]['items'][1]['data'] ), 'deadline: an invalid date is not stored' );
cko_assert( ! isset( $ns['items'][1]['items'][0]['data'] ), 'entry data is dropped for blocks that do not know it' );
$nb->set_value( 'stit0002', 'skip' );
$nb->set_value( 'stit0001', 'done' );
$np = $nb->progress();
cko_assert( 1 === $np['done'] && 3 === $np['total'], 'status: a skipped entry is out of the total, a done one counts' );
$open_nb = Checkoff_Checklist::open_items_of( $ns, $nb->get_values(), $noon );
cko_assert( 2 === count( $open_nb ) && str_starts_with( $open_nb[0]['note'], 'overdue since' ) && '' === $open_nb[1]['note'], 'open entries carry the due note' );

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

/* --- tick link (read and tick only) ---------------------------------------- */

$shared   = cko_new( 'T shared' );
$edit_id  = $shared->get_public_id();
$tick_id  = $shared->get_tick_id();
cko_assert( '' !== $tick_id && $tick_id !== $edit_id, 'a new checklist has its own tick ID' );

$by_tick = Checkoff_Checklist::find( Checkoff_Public_Id::format( $tick_id ) );
$by_edit = Checkoff_Checklist::find( $edit_id );
cko_assert( $by_tick && ! $by_tick->can_edit() && Checkoff_Checklist::ACCESS_TICK === $by_tick->get_access(), 'the tick ID opens for ticking only' );
cko_assert( $by_edit && $by_edit->can_edit(), 'the public ID opens for editing' );

$tick_client = $by_tick->to_client( 'https://example.org/p/' );
cko_assert( Checkoff_Public_Id::format( $tick_id ) === $tick_client['id'] && ! isset( $tick_client['tick_id'] ) && ! str_contains( wp_json_encode( $tick_client ), $edit_id ), 'the tick view never sees the edit ID' );
$edit_client = $by_edit->to_client( 'https://example.org/p/' );
cko_assert( str_contains( $edit_client['tick_url'], Checkoff_Public_Id::format( $tick_id ) ), 'the edit view gets the tick link to hand out' );
cko_assert( '' !== $edit_client['state'], 'the page carries the sync fingerprint' );

$old_tick = $tick_id;
$shared->regenerate_tick_id();
cko_assert( null === Checkoff_Checklist::find( $old_tick ), 'a regenerated tick ID retires the old one' );

delete_post_meta( $shared->get_post_id(), Checkoff_Checklist::META_TICK_ID );
cko_assert( '' !== ( new Checkoff_Checklist( $shared->get_post_id() ) )->get_tick_id(), 'a checklist from before 1.2.0 gets a tick ID on request' );

/* --- AJAX handlers (access and live update) --------------------------------- */

function cko_ajax( string $action, array $post ): array {

	$_POST    = array_merge( $post, array( 'nonce' => wp_create_nonce( Checkoff_Shortcodes::NONCE_ACTION ) ) );
	$_REQUEST = $_POST;

	add_filter( 'wp_doing_ajax', '__return_true' );
	$die = static function () {
		return static function () {
			throw new RuntimeException( 'die' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $die );

	ob_start();
	try {
		do_action( 'wp_ajax_nopriv_' . $action );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$out = (string) ob_get_clean();

	remove_filter( 'wp_die_ajax_handler', $die );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	$_POST = $_REQUEST = array();

	return (array) json_decode( $out, true );
}

wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );

$shared  = new Checkoff_Checklist( $shared->get_post_id() );
$tick_id = Checkoff_Public_Id::format( $shared->get_tick_id() );
$leaf    = 'shr00001';
$s       = $shared->get_structure();
$s['items'] = array( array( 'id' => 'shrblk01', 'kind' => 'block', 'type' => 'simple', 'items' => array( array( 'id' => $leaf, 'kind' => 'item', 'label' => 'x' ) ) ) );
$shared->set_structure( $s, $shared->get_revision() );

$r = cko_ajax( 'cko_set_value', array( 'id' => $tick_id, 'item' => $leaf, 'value' => 'true' ) );
cko_assert( ! empty( $r['success'] ), 'the tick link may tick' );

$r = cko_ajax( 'cko_save_structure', array( 'id' => $tick_id, 'revision' => $shared->get_revision(), 'structure' => wp_json_encode( $s ) ) );
cko_assert( empty( $r['success'] ) && str_contains( (string) ( $r['data']['message'] ?? '' ), 'ticking' ), 'the tick link may not change the structure' );

$r = cko_ajax( 'cko_reset_values', array( 'id' => $tick_id ) );
cko_assert( empty( $r['success'] ), 'the tick link may not clear all ticks' );

$r = cko_ajax( 'cko_delete', array( 'id' => $tick_id ) );
cko_assert( empty( $r['success'] ) && null !== get_post( $shared->get_post_id() ), 'the tick link may not delete' );

$state = ( new Checkoff_Checklist( $shared->get_post_id() ) )->sync_state()['state'];
$r     = cko_ajax( 'cko_poll', array( 'id' => $tick_id, 'state' => $state, 'revision' => $shared->get_revision() ) );
cko_assert( ! empty( $r['success'] ) && false === $r['data']['changed'] && ! isset( $r['data']['values'] ), 'an unchanged checklist answers the poll with its fingerprint only' );

( new Checkoff_Checklist( $shared->get_post_id() ) )->set_value( $leaf, null );
$r = cko_ajax( 'cko_poll', array( 'id' => $tick_id, 'state' => $state, 'revision' => $shared->get_revision() ) );
cko_assert( true === $r['data']['changed'] && array() === $r['data']['values'] && ! isset( $r['data']['structure'] ), 'a changed value is sent, the unchanged structure is not' );

$r = cko_ajax( 'cko_poll', array( 'id' => $tick_id, 'state' => 'x', 'revision' => 1 ) );
cko_assert( isset( $r['data']['structure'] ), 'a client on an old revision gets the structure' );

$last_access = $shared->get_last_access();
update_post_meta( $shared->get_post_id(), Checkoff_Checklist::META_LAST_ACCESS, 12345 );
cko_ajax( 'cko_poll', array( 'id' => $tick_id, 'state' => '', 'revision' => 0 ) );
cko_assert( 12345 === ( new Checkoff_Checklist( $shared->get_post_id() ) )->get_last_access(), 'polling does not count as a visit' );

$r = cko_ajax( 'cko_delete', array( 'id' => Checkoff_Public_Id::format( $shared->get_public_id() ) ) );
cko_assert( ! empty( $r['success'] ) && null === get_post( $shared->get_post_id() ), 'the public ID may delete' );

/* --- templates ------------------------------------------------------------ */

$tpl = cko_new( 'T template' );
$ts  = $tpl->get_structure();
$ts['recurrence'] = array( 'freq' => 'weekly', 'start' => wp_date( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ) );
$ts['items']      = array(
	array( 'id' => 'tplblk01', 'kind' => 'block', 'type' => 'simple', 'title' => '', 'items' => array( array( 'id' => 'tpl00001', 'kind' => 'item', 'label' => 'Boxes' ) ) ),
	array( 'id' => 'tpl00002', 'kind' => 'block', 'type' => 'counter', 'title' => 'Count', 'settings' => array( 'target' => 3 ), 'items' => array( array( 'id' => 'tpl00003', 'kind' => 'item', 'label' => 'Trips' ) ) ),
);
$tpl->set_structure( $ts, $tpl->get_revision() );
$tpl = new Checkoff_Checklist( $tpl->get_post_id() );
$tpl->set_value( 'tpl00001', true );

cko_assert( array() === array_filter( Checkoff_Templates::all(), static fn( $t ) => 'T template' === $t['title'] ), 'an unmarked checklist is no template' );

Checkoff_Templates::set_template( $tpl->get_post_id(), true, "Short\ndescription" );
$entry = array_values( array_filter( Checkoff_Templates::all(), static fn( $t ) => 'T template' === $t['title'] ) )[0] ?? null;
cko_assert( null !== $entry && 2 === $entry['items'] && '' !== $entry['repetition'], 'a marked checklist is listed with its size and repetition' );
cko_assert( null !== Checkoff_Templates::find( $entry['key'] ), 'a template is found by its key' );
cko_assert( null === Checkoff_Templates::find( (string) $tpl->get_post_id() ) && null === Checkoff_Templates::find( $tpl->get_public_id() ), 'not by its post ID or its public ID' );
cko_assert( ! str_contains( wp_json_encode( Checkoff_Templates::all() ), $tpl->get_public_id() ) && ! str_contains( wp_json_encode( Checkoff_Templates::all() ), $tpl->get_tick_id() ), 'the template list carries neither of its IDs' );

$copy = Checkoff_Templates::create_from( $entry['key'], 'T my move' );
$checkoff_created[] = $copy->get_post_id();
$cs   = $copy->get_structure();
cko_assert( 'T my move' === $cs['title'] && 'Count' === $cs['items'][1]['title'] && 3 === $cs['items'][1]['settings']['target'], 'the copy has the template\'s entries and settings under its own title' );
cko_assert( array() === $copy->get_values(), 'the copy starts without ticks' );
cko_assert( wp_date( 'Y-m-d' ) === $cs['recurrence']['start'] && 1 === $copy->round_info()['number'], 'a repeating template starts over today in the copy' );
cko_assert( $copy->get_public_id() !== $tpl->get_public_id() && ! Checkoff_Templates::is_template( $copy->get_post_id() ), 'the copy is an ordinary checklist of its own' );

$ts2 = $tpl->get_structure();
$ts2['items'][0]['items'][0]['label'] = 'Changed';
$tpl->set_structure( $ts2, $tpl->get_revision() );
cko_assert( 'Boxes' === ( new Checkoff_Checklist( $copy->get_post_id() ) )->get_structure()['items'][0]['items'][0]['label'], 'changing the template later leaves the copy alone' );

$default_title = Checkoff_Templates::create_from( $entry['key'] );
$checkoff_created[] = $default_title->get_post_id();
cko_assert( 'T template' === $default_title->get_title(), 'without a title the copy is named like the template' );

cko_assert( is_wp_error( Checkoff_Templates::create_from( 'nosuchkey' ) ), 'an unknown template is refused' );

$html = do_shortcode( '[cko_create template="' . $entry['key'] . '"]' );
cko_assert( str_contains( $html, 'value="tpl:' . $entry['key'] . '" checked' ) || str_contains( $html, 'value="tpl:' . $entry['key'] . '"  checked' ) || preg_match( '/value="tpl:' . $entry['key'] . '"[^>]*checked/', $html ), 'the create form offers and preselects the template' );
cko_assert( ! preg_match( '/name="title"[^>]*required/', $html ), 'the name is optional (a template brings its own)' );
update_option( 'checkoff_templates_in_form', '' );
cko_assert( ! str_contains( do_shortcode( '[cko_create]' ), 'tpl:' ), 'templates can be switched off in the form' );
delete_option( 'checkoff_templates_in_form' );

$gallery = do_shortcode( '[cko_templates]' );
cko_assert( str_contains( $gallery, 'cko-template' ) && str_contains( $gallery, 'Short<br />' ) && str_contains( $gallery, 'tpl:' . $entry['key'] ), 'the gallery shows the template with its description and a button' );

$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
$r = cko_ajax( 'cko_create', array( 'title' => '', 'type' => 'tpl:' . $entry['key'], 'page_url' => home_url( '/' ) ) );
cko_assert( ! empty( $r['success'] ) && false === $r['data']['edit'], 'creating from a template over AJAX opens the copy ready to use' );
$made = Checkoff_Checklist::find( $r['data']['id'] ?? '' );
if ( $made ) {
	$checkoff_created[] = $made->get_post_id();
}
cko_assert( $made && 2 === count( $made->get_structure()['items'] ), 'the AJAX copy has the template\'s entries' );

update_post_meta( $tpl->get_post_id(), Checkoff_Checklist::META_LAST_ACCESS, time() - 400 * DAY_IN_SECONDS );
update_option( 'checkoff_cleanup_inactive_days', 30 );
( new Checkoff_Cleanup() )->run();
cko_assert( null !== get_post( $tpl->get_post_id() ), 'the cleanup never deletes a template' );
delete_option( 'checkoff_cleanup_inactive_days' );

Checkoff_Templates::set_template( $tpl->get_post_id(), false );
cko_assert( null === Checkoff_Templates::find( $entry['key'] ), 'an unmarked template is no longer offered' );

/* --- history of closed rounds -------------------------------------------- */

$hist_opt = array( get_option( Checkoff_History::OPT_ENABLED, null ), get_option( Checkoff_History::OPT_DAYS, null ) );

$hl = cko_new( 'T history' );
$hs = $hl->get_structure();
$hs['recurrence'] = array( 'freq' => 'daily', 'interval' => 1, 'start' => wp_date( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ), 'end' => 'never' );
$hs['items']      = array(
	array( 'id' => 'hblk0001', 'kind' => 'block', 'type' => 'simple', 'title' => 'Chores', 'settings' => array(), 'items' => array(
		array( 'id' => 'hitm0001', 'kind' => 'item', 'label' => 'Dishes' ),
		array( 'id' => 'hitm0002', 'kind' => 'item', 'label' => 'Laundry' ),
	) ),
	array( 'id' => 'hblk0002', 'kind' => 'block', 'type' => 'measurement', 'title' => 'Body', 'settings' => array( 'unit' => 'kg', 'decimals' => 1 ), 'items' => array(
		array( 'id' => 'hitm0003', 'kind' => 'item', 'label' => 'Weight' ),
	) ),
);
$hl->set_structure( $hs, $hl->get_revision() );

// Pretends the values belong to the round before the one in force, so the
// next read closes it.
$close_round = static function ( Checkoff_Checklist $list, int $back = 1 ): void {
	$current = $list->recurrence()->current()['index'];
	update_post_meta( $list->get_post_id(), Checkoff_Checklist::META_VALUES_ROUND, $current - $back );
	$list->rollover();
};

update_option( Checkoff_History::OPT_ENABLED, '' );
$hl->set_value( 'hitm0001', true );
$close_round( $hl );
cko_assert( 0 === Checkoff_History::count( $hl->get_post_id() ), 'history: off by default, a closed round keeps nothing' );
cko_assert( null !== $hl->get_last_round(), 'history: the summary of the last round is kept anyway' );

update_option( Checkoff_History::OPT_ENABLED, 'on' );
$hl->set_value( 'hitm0001', true );
$hl->set_value( 'hitm0003', '72,5' );
$close_round( $hl );
$rounds = Checkoff_History::rounds( $hl->get_post_id() );
cko_assert( 1 === count( $rounds ) && 2 === $rounds[0]['done'] && 3 === $rounds[0]['total'], 'history: the closed round is kept with its progress' );
cko_assert( 'Chores' === $rounds[0]['sections'][0]['title'] && true === $rounds[0]['sections'][0]['entries'][0]['done'], 'history: entries with their state' );
cko_assert( 72.5 === $rounds[0]['sections'][1]['entries'][0]['value'] && '72.5 kg' === str_replace( ',', '.', $rounds[0]['sections'][1]['entries'][0]['text'] ), 'history: values and their wording' );
$prev_day = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
cko_assert( $prev_day === $rounds[0]['start'] && $prev_day === $rounds[0]['end'], 'history: the round\'s dates' );
cko_assert( array() === $hl->get_values(), 'history: the new round starts empty all the same' );

// The past stays as it was when the structure changes later.
$hs2                                    = $hl->get_structure();
$hs2['items'][0]['items'][0]['label'] = 'Renamed';
$hl->set_structure( $hs2, $hl->get_revision() );
cko_assert( 'Dishes' === Checkoff_History::rounds( $hl->get_post_id() )[0]['sections'][0]['entries'][0]['label'], 'history: a later rename does not change the past' );

// Only the edit ID learns it exists.
$hl_edit = Checkoff_Checklist::find( $hl->get_public_id() );
$hl_tick = Checkoff_Checklist::find( $hl->get_tick_id() );
cko_assert( 1 === ( $hl_edit->to_client()['history']['rounds'] ?? null ), 'history: the edit view knows how many rounds are kept' );
cko_assert( ! isset( $hl_tick->to_client()['history'] ), 'history: a tick view does not learn of it' );
$r = cko_ajax( 'cko_history', array( 'id' => $hl->get_tick_id() ) );
cko_assert( empty( $r['success'] ), 'history: the page refuses it to the tick ID' );
$r = cko_ajax( 'cko_history', array( 'id' => $hl->get_public_id() ) );
cko_assert( ! empty( $r['success'] ) && 1 === count( $r['data']['rounds'] ), 'history: the page loads it with the edit ID' );

// Expiry.
$hl->set_value( 'hitm0002', true );
$close_round( $hl, 2 );
cko_assert( 2 === Checkoff_History::count( $hl->get_post_id() ), 'history: rounds add up' );
update_option( Checkoff_History::OPT_DAYS, 1 );
cko_assert( 0 === count( Checkoff_History::rounds( $hl->get_post_id(), time() + 2 * DAY_IN_SECONDS ) ), 'history: expired rounds are not shown' );
cko_assert( 2 === Checkoff_History::prune_all( time() + 2 * DAY_IN_SECONDS ), 'history: the daily run deletes expired rounds' );
cko_assert( 0 === Checkoff_History::count( $hl->get_post_id() ) && '' === get_post_meta( $hl->get_post_id(), Checkoff_History::META_OLDEST, true ), 'history: nothing left, not even the index' );
update_option( Checkoff_History::OPT_DAYS, 90 );

// A round in which nothing was entered is not kept.
$close_round( $hl );
cko_assert( 0 === Checkoff_History::count( $hl->get_post_id() ), 'history: an empty round is not kept' );

// Deleting by the owner, and switching off.
$hl->set_value( 'hitm0002', true );
$close_round( $hl );
cko_assert( 1 === Checkoff_History::count( $hl->get_post_id() ), 'history: a round with entries is kept' );
Checkoff_History::clear( $hl->get_post_id() );
cko_assert( 0 === Checkoff_History::count( $hl->get_post_id() ), 'history: the owner can delete it' );
$hl->set_value( 'hitm0002', true );
$close_round( $hl );
update_option( Checkoff_History::OPT_ENABLED, '' );
cko_assert( 0 === Checkoff_History::count( $hl->get_post_id() ), 'history: switching it off deletes every kept round' );

foreach ( array( Checkoff_History::OPT_ENABLED, Checkoff_History::OPT_DAYS ) as $i => $name ) {
	if ( null === $hist_opt[ $i ] ) {
		delete_option( $name );
	} else {
		update_option( $name, $hist_opt[ $i ] );
	}
}

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

restore_previous_locale();

echo "{$checkoff_passes} passed, {$checkoff_failures} failed\n";

if ( $checkoff_failures > 0 ) {
	exit( 1 );
}
