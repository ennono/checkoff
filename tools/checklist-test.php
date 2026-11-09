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

// A visitor of its own (an IPv6 /64 is one visitor), so quotas of earlier runs do not count.
$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';

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

/**
 * The stored row of an entry in a round (the round in force by default).
 */
function cko_row( Checkoff_Checklist $checklist, string $entry, ?int $round = null ): ?array {
	return Checkoff_Values::rows( $checklist->get_post_id(), $round ?? $checklist->round_index() )[ $entry ] ?? null;
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

/* --- saving structure, revisions ----------------------------------------- */

$list = cko_new( 'T revisions' );
$res  = $list->set_structure( $raw, 1 );
cko_assert( ! is_wp_error( $res ) && 2 === $res['revision'], 'saving with the current revision succeeds and counts up' );
cko_assert( 'Root' === get_the_title( $list->get_post_id() ), 'the post title follows the root title' );

$stale = $list->set_structure( $raw, 1 );
cko_assert( is_wp_error( $stale ) && 'checkoff_conflict' === $stale->get_error_code(), 'saving with a stale revision is a conflict' );
cko_assert( is_wp_error( $list->set_structure( 'nope', 2 ) ), 'a non-array structure is refused' );

// Two saves based on the same revision, in parallel: the table's key lets one through.
$tab_a = new Checkoff_Checklist( $list->get_post_id() );
$tab_b = new Checkoff_Checklist( $list->get_post_id() );
cko_assert( 2 === $tab_a->get_revision() && 2 === $tab_b->get_revision(), 'versions: both tabs read revision 2' );
cko_assert( ! is_wp_error( $tab_a->set_structure( $raw, 2 ) ), 'versions: the first save wins' );
$late_tab = $tab_b->set_structure( $raw, 2 );
cko_assert( is_wp_error( $late_tab ) && 'checkoff_conflict' === $late_tab->get_error_code(), 'versions: the second, based on the same revision, is a conflict' );
cko_assert( 3 === ( new Checkoff_Checklist( $list->get_post_id() ) )->get_revision(), 'versions: the newest row is the list as it is' );
$list = new Checkoff_Checklist( $list->get_post_id() );

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

Checkoff_Values::set_value( $list->get_post_id(), $list->round_index(), $week_item, array( 'days' => array( 1 ), 'week' => '2000-W01' ) );
cko_assert( ! isset( $list->get_values()[ $week_item ] ), 'weekdays: last week\'s ticks read as empty with the weekly reset' );

// Re-saving the structure must not move last week's ticks into this week.
$list->set_structure( $list->get_structure(), $list->get_revision() );
cko_assert( '2000-W01' === ( cko_row( $list, $week_item )['v']['week'] ?? '' ), 'weekdays: re-validation keeps the week a value was set in' );

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
cko_assert( null === cko_row( $list, $item_id ), 'the value of a removed entry is deleted' );

// One row per entry: two visitors ticking different entries do not touch
// each other's row.
$a = new Checkoff_Checklist( $list->get_post_id() );
$b = new Checkoff_Checklist( $list->get_post_id() );
$first_leaf  = array_keys( Checkoff_Checklist::leaf_map( $a->get_structure() ) )[0];
$a->reset_values();
$a->set_value( $first_leaf, true );
$b->set_value( 'numit001', 3 );
$both = ( new Checkoff_Checklist( $list->get_post_id() ) )->get_values();
cko_assert( isset( $both[ $first_leaf ], $both['numit001'] ), 'two writers on different entries both keep their value' );

// Other block types, directly.
$color = Checkoff_Block_Types::get( 'color_scale' );
cko_assert( 2 === $color->sanitize_value( 2, array( 'steps' => 3 ) ), 'colour: a step in range is kept' );
cko_assert( null === $color->sanitize_value( 3, array( 'steps' => 3 ) ), 'colour: a step beyond the scale clears' );
cko_assert( 5 === $color->sanitize_settings( array( 'steps' => 4 ) )['steps'], 'colour: only 3 or 5 steps' );
$cs_plain = $color->sanitize_settings( array( 'steps' => 5 ) );
cko_assert( array( 'steps' => 5, 'labels' => array(), 'reverse' => false ) === $cs_plain, 'colour: by default the site\'s names, not reversed' );
cko_assert( Checkoff_Block_Color_Scale::scale()[5] === Checkoff_Block_Color_Scale::steps_for( $cs_plain ), 'colour: without own settings, the site\'s scale' );
$cs_pain = $color->sanitize_settings( array( 'steps' => 3, 'reverse' => true, 'labels' => "Pain-free\nSome pain\nStrong pain\nignored\n" ) );
$pain    = Checkoff_Block_Color_Scale::steps_for( $cs_pain );
cko_assert( Checkoff_Block_Color_Scale::scale()[3][2]['color'] === $pain[0]['color'] && Checkoff_Block_Color_Scale::scale()[3][0]['color'] === $pain[2]['color'], 'colour: reversed, green comes first' );
cko_assert( array( 'Pain-free', 'Some pain', 'Strong pain' ) === array_column( $pain, 'label' ), 'colour: own names left to right, extra lines ignored for 3 steps' );
cko_assert( 'Strong pain' === $color->describe_value( 2, $cs_pain ), 'colour: a value is worded with the block\'s own name' );
$cs_part = $color->sanitize_settings( array( 'steps' => 5, 'labels' => array( 'Awful', '' ) ) );
cko_assert( array( 'Awful' ) === $cs_part['labels'] && Checkoff_Block_Color_Scale::scale()[5][1]['label'] === Checkoff_Block_Color_Scale::steps_for( $cs_part )[1]['label'], 'colour: missing names fall back to the site\'s' );
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

$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';
cko_assert( ! Checkoff_Rate_Limit::is_exceeded( 'test', 2 ), 'rate limit: fresh visitor is not limited' );
Checkoff_Rate_Limit::hit( 'test' );
Checkoff_Rate_Limit::hit( 'test' );
cko_assert( Checkoff_Rate_Limit::is_exceeded( 'test', 2 ), 'rate limit: limited after the allowed number' );
cko_assert( ! Checkoff_Rate_Limit::is_exceeded( 'test', 0 ), 'rate limit: 0 means unlimited' );

/* --- shortcodes ------------------------------------------------------------ */

$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';
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

/* --- what the page gets ------------------------------------------------------ */

$shared  = cko_new( 'T shared' );
$edit_id = $shared->get_public_id();
$by_edit = Checkoff_Checklist::find( $edit_id );
cko_assert( $by_edit && $by_edit->can_edit(), 'the ID of an open list opens it for editing' );
$edit_client = $by_edit->to_client( 'https://example.org/p/' );
cko_assert( 'open' === $edit_client['level'] && false === $edit_client['listed'] && ! isset( $edit_client['tick_id'] ), 'the edit view learns level and listing, and of no other ID' );
cko_assert( '' !== $edit_client['state'] && is_object( $edit_client['stages'] ), 'the page carries the sync fingerprint and the stages' );

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
$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';

$shared  = new Checkoff_Checklist( $shared->get_post_id() );
$tick_id = Checkoff_Public_Id::format( $shared->get_public_id() );
$leaf    = 'shr00001';
$s       = $shared->get_structure();
$s['items'] = array( array( 'id' => 'shrblk01', 'kind' => 'block', 'type' => 'simple', 'items' => array( array( 'id' => $leaf, 'kind' => 'item', 'label' => 'x' ) ) ) );
$shared->set_structure( $s, $shared->get_revision() );

// A protected list, opened without its password: ticks, nothing more.
$shared->set_level( 'protected', 'Shared 123' );
$_COOKIE = array();

$r = cko_ajax( 'cko_set_value', array( 'id' => $tick_id, 'item' => $leaf, 'value' => 'true' ) );
cko_assert( ! empty( $r['success'] ), 'locked: may tick' );

$r = cko_ajax( 'cko_save_structure', array( 'id' => $tick_id, 'revision' => $shared->get_revision(), 'structure' => wp_json_encode( $s ) ) );
cko_assert( empty( $r['success'] ) && str_contains( (string) ( $r['data']['message'] ?? '' ), 'password' ), 'locked: may not change the structure' );

$r = cko_ajax( 'cko_reset_values', array( 'id' => $tick_id ) );
cko_assert( empty( $r['success'] ), 'locked: may not clear all ticks' );

$r = cko_ajax( 'cko_delete', array( 'id' => $tick_id ) );
cko_assert( empty( $r['success'] ) && null !== get_post( $shared->get_post_id() ), 'locked: may not delete' );

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

$shared->set_level( 'open' );
$shared_id = $shared->get_post_id();
Checkoff_Values::set_value( $shared_id, 5, $leaf, true );
$r = cko_ajax( 'cko_delete', array( 'id' => Checkoff_Public_Id::format( $shared->get_public_id() ) ) );
cko_assert( ! empty( $r['success'] ) && null === get_post( $shared_id ), 'an open list may be deleted by its ID' );
cko_assert( array() === Checkoff_Values::rounds( $shared_id ) && null === Checkoff_Versions::latest( $shared_id ), 'deleting a list deletes its rows in both tables' );

/* --- access levels on the page ----------------------------------------------- */

$lv    = cko_new( 'T levels' );
$lv_id = Checkoff_Public_Id::format( $lv->get_public_id() );
$_COOKIE = array();

$r = cko_ajax( 'cko_set_access', array( 'id' => $lv_id, 'level' => 'protected', 'password' => '' ) );
cko_assert( empty( $r['success'] ), 'levels: protecting an open list needs a password' );
$r = cko_ajax( 'cko_set_access', array( 'id' => $lv_id, 'level' => 'protected', 'password' => 'Level 123' ) );
cko_assert( ! empty( $r['success'] ) && 'protected' === $r['data']['level'], 'levels: protected with a password' );
$r = cko_ajax( 'cko_set_listed', array( 'id' => $lv_id, 'listed' => 1 ) );
cko_assert( ! empty( $r['success'] ) && true === $r['data']['listed'], 'levels: a protected list can be shown publicly' );
$r = cko_ajax( 'cko_set_access', array( 'id' => $lv_id, 'level' => 'private', 'password' => '' ) );
cko_assert( ! empty( $r['success'] ) && 'private' === $r['data']['level'] && false === $r['data']['listed'], 'levels: private keeps the password and leaves the directory' );

$_COOKIE = array();
$r = cko_ajax( 'cko_poll', array( 'id' => $lv_id, 'state' => '', 'revision' => 0 ) );
cko_assert( empty( $r['success'] ) && str_contains( (string) ( $r['data']['message'] ?? '' ), 'private' ), 'levels: a private list answers no poll without the password' );
$r = cko_ajax( 'cko_unlock', array( 'id' => $lv_id, 'password' => 'Level 123' ) );
cko_assert( ! empty( $r['success'] ), 'levels: the prompt unlocks a private list' );
$r = cko_ajax( 'cko_poll', array( 'id' => $lv_id, 'state' => '', 'revision' => 0 ) );
cko_assert( ! empty( $r['success'] ), 'levels: then it answers' );
$r = cko_ajax( 'cko_remove_password', array( 'id' => $lv_id ) );
cko_assert( ! empty( $r['success'] ) && 'open' === $r['data']['level'] && 'open' === get_post_meta( $lv->get_post_id(), Checkoff_Checklist::META_ACCESS, true ), 'levels: removing the password opens the list' );
$_COOKIE = array();

/* --- board ---------------------------------------------------------------- */

$bd = cko_new( 'T board' );
$bs = $bd->get_structure();
$bs['view']   = 'board';
$bs['stages'] = array( 'To do', '', 'Finished <b>' );
$bs['items']  = array(
	array( 'id' => 'bdblk001', 'kind' => 'block', 'type' => 'simple', 'title' => 'Lane', 'settings' => array(), 'items' => array(
		array( 'id' => 'bditm001', 'kind' => 'item', 'label' => 'A' ),
		array( 'id' => 'bditm002', 'kind' => 'item', 'label' => 'B' ),
	) ),
	array( 'id' => 'bdblk002', 'kind' => 'block', 'type' => 'counter', 'title' => 'Count', 'settings' => array( 'target' => 3 ), 'items' => array(
		array( 'id' => 'bditm003', 'kind' => 'item', 'label' => 'C' ),
	) ),
	array( 'id' => 'bdblk003', 'kind' => 'block', 'type' => 'note', 'title' => '', 'settings' => array(), 'items' => array(
		array( 'id' => 'bditm004', 'kind' => 'item', 'label' => 'D' ),
	) ),
);
$bd->set_structure( $bs, $bd->get_revision() );
$bss = $bd->get_structure();
cko_assert( 'board' === $bss['view'] && array( 'To do', '', 'Finished' ) === $bss['stages'], 'board: the view and the column names are kept, cleaned' );
$plain = Checkoff_Checklist::sanitize_structure( array( 'kind' => 'checklist', 'view' => 'gallery', 'stages' => array( '', '', '' ), 'items' => array() ) );
cko_assert( ! isset( $plain['view'] ) && ! isset( $plain['stages'] ), 'board: an unknown view or empty names leave nothing behind' );

$stage = static fn( string $block, string $item ) => Checkoff_Checklist::stage_of(
	array_values( array_filter( $bd->get_structure()['items'], static fn( $b ) => $b['id'] === $block ) )[0],
	array( 'id' => $item ),
	$bd->get_values(),
	$bd->get_stages()
);
cko_assert( 'open' === $stage( 'bdblk001', 'bditm001' ), 'board: nothing entered is open' );
$bd->set_stage( 'bditm001', 'doing' );
cko_assert( 'doing' === $stage( 'bdblk001', 'bditm001' ) && 'doing' === $bd->get_stages()['bditm001'] && ! isset( $bd->get_values()['bditm001'] ), 'board: the stage puts a card in progress, without a value' );
cko_assert( 'doing' === cko_row( $bd, 'bditm001' )['s'] && null === cko_row( $bd, 'bditm001' )['v'], 'board: one row holds value and stage' );
cko_assert( 0 === $bd->progress()['done'] && 3 === $bd->progress()['total'], 'board: the mark is no entry of its own in the progress' );
$bd->set_value( 'bditm001', true );
cko_assert( 'done' === $stage( 'bdblk001', 'bditm001' ), 'board: done by the block\'s rule, mark or not' );
$bd->set_value( 'bditm003', 2 );
cko_assert( 'doing' === $stage( 'bdblk002', 'bditm003' ), 'board: something entered but not done is in progress' );
$bd->set_value( 'bditm004', 'text' );
cko_assert( 'done' === $stage( 'bdblk003', 'bditm004' ), 'board: an entry without a rule is done once filled' );
cko_assert( isset( $bd->get_value_times()['bditm001'] ), 'board: the stage has its time' );
cko_assert( 'doing' === cko_row( $bd, 'bditm001' )['s'] && true === cko_row( $bd, 'bditm001' )['v'], 'board: setting the value keeps the stage beside it' );
$open = Checkoff_Checklist::open_items_of( $bd->get_structure(), $bd->get_values(), null, $bd->get_stages() );
$c_open = array_values( array_filter( $open, static fn( $o ) => 'C' === $o['label'] ) )[0] ?? null;
cko_assert( null !== $c_open && str_starts_with( $c_open['note'], 'In progress' ), 'board: open entries in progress say so (mails)' );
$bd->set_stage( 'bditm002', 'doing' );
$bs2 = $bd->get_structure();
$bs2['items'][0]['items'] = array( $bs2['items'][0]['items'][0] );
$bd->set_structure( $bs2, $bd->get_revision() );
cko_assert( null === cko_row( $bd, 'bditm002' ) && isset( $bd->get_stages()['bditm001'] ), 'board: a removed entry\'s stage goes, the others stay' );
$r = cko_ajax( 'cko_set_value', array( 'id' => Checkoff_Public_Id::format( $bd->get_public_id() ), 'item' => 'bditm001', 'stage' => '' ) );
cko_assert( ! empty( $r['success'] ) && ! isset( $bd->get_stages()['bditm001'] ) && true === ( $bd->get_values()['bditm001'] ?? null ), 'board: the page clears a stage, the value stays' );
cko_assert( is_wp_error( $bd->set_stage( 'nosuchit', 'doing' ) ), 'board: no stage for an unknown entry' );

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
cko_assert( ! str_contains( wp_json_encode( Checkoff_Templates::all() ), $tpl->get_public_id() ), 'the template list does not carry its ID' );

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
cko_assert( str_contains( do_shortcode( '[cko_create]' ), 'tpl:' ), 'the create form shows the templates by default' );
cko_assert( ! str_contains( do_shortcode( '[cko_create templates="no"]' ), 'tpl:' ), 'templates="no" hides them' );
cko_assert( str_contains( do_shortcode( '[cko_create templates="yes"]' ), 'tpl:' ) && ! str_contains( do_shortcode( '[cko_create templates="false"]' ), 'tpl:' ), 'yes and false read as expected' );
$fixed_form = do_shortcode( '[cko_create templates="no" template="' . $entry['key'] . '"]' );
cko_assert( str_contains( $fixed_form, 'type="hidden" name="type" value="tpl:' . $entry['key'] . '"' ) && ! str_contains( $fixed_form, 'type="radio"' ), 'templates="no" with a template: no choice, the form starts from that template' );
cko_assert( str_contains( do_shortcode( '[cko_create templates="no" template="nosuchkey"]' ), 'value="simple"' ), 'an unknown template falls back to an empty list' );
cko_assert( ! str_contains( do_shortcode( '[cko_app templates="no"]' ), 'tpl:' ), '[cko_app] passes templates on to its form' );
update_option( 'checkoff_templates_in_form', '' );
cko_assert( ! str_contains( do_shortcode( '[cko_create]' ), 'tpl:' ) && str_contains( do_shortcode( '[cko_create templates="yes"]' ), 'tpl:' ), 'a switched-off former setting is the default, the attribute wins' );
delete_option( 'checkoff_templates_in_form' );

$gallery = do_shortcode( '[cko_templates]' );
cko_assert( str_contains( $gallery, 'cko-template' ) && str_contains( $gallery, 'Short<br />' ) && str_contains( $gallery, 'tpl:' . $entry['key'] ), 'the gallery shows the template with its description and a button' );

$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';
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

/* --- no switch: every block works the same way ---------------------------- */

cko_assert( ! isset( Checkoff_Block_Types::all()['switch'] ), 'the switch block is gone' );
$sw = cko_new( 'T former switch' );
$ss = $sw->get_structure();
$ss['items'] = array(
	array( 'id' => 'swblk001', 'kind' => 'block', 'type' => 'switch', 'title' => 'Training', 'settings' => array(), 'items' => array(
		array( 'id' => 'swvar001', 'kind' => 'block', 'type' => 'simple', 'title' => 'Studio', 'settings' => array(), 'items' => array(
			array( 'id' => 'swit0001', 'kind' => 'item', 'label' => 'Treadmill' ),
		) ),
		array( 'id' => 'swvar002', 'kind' => 'block', 'type' => 'simple', 'title' => 'Home', 'settings' => array(), 'items' => array(
			array( 'id' => 'swit0003', 'kind' => 'item', 'label' => 'Rope' ),
		) ),
	) ),
);
$sw->set_structure( $ss, $sw->get_revision() );
$x = $sw->get_structure()['items'][0];
cko_assert( 'simple' === $x['type'] && 2 === count( $x['items'] ) && 'block' === $x['items'][1]['kind'], 'a former switch is read as a simple list with its sub-lists' );
cko_assert( is_wp_error( $sw->set_value( 'swblk001', 1 ) ), 'a block holds no value of its own' );
cko_assert( array( 0, 2 ) === array( $sw->progress()['done'], $sw->progress()['total'] ), 'every sub-list counts' );

/* --- ticking ahead: rounds after the one in force ----------------------- */

$up_opt = get_option( Checkoff_Checklist::OPT_UPCOMING, null );
$al     = cko_new( 'T ahead' );
$as     = $al->get_structure();
$as['recurrence'] = array( 'freq' => 'weekly', 'interval' => 1, 'start' => wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), 'end' => 'count', 'count' => 5 );
$as['items']      = array(
	array( 'id' => 'ahblk001', 'kind' => 'block', 'type' => 'simple', 'title' => 'Plan', 'settings' => array(), 'items' => array(
		array( 'id' => 'ahitm001', 'kind' => 'item', 'label' => 'Pack' ),
		array( 'id' => 'ahitm002', 'kind' => 'item', 'label' => 'Book' ),
	) ),
	array( 'id' => 'ahblk002', 'kind' => 'block', 'type' => 'weekdays', 'title' => 'Days', 'settings' => array( 'days' => array( 1, 2, 3, 4, 5, 6, 7 ), 'reset' => 'weekly' ), 'items' => array(
		array( 'id' => 'ahitm003', 'kind' => 'item', 'label' => 'Walk' ),
	) ),
);
$al->set_structure( $as, $al->get_revision() );
$al = Checkoff_Checklist::find( $al->get_public_id() );

update_option( Checkoff_Checklist::OPT_UPCOMING, 2 );
cko_assert( array( 2, 3 ) === array_column( $al->upcoming(), 'number' ), 'ahead: as many rounds as the setting allows' );
update_option( Checkoff_Checklist::OPT_UPCOMING, 10 );
cko_assert( array( 2, 3, 4, 5 ) === array_column( $al->upcoming(), 'number' ), 'ahead: never beyond the end of the series' );
cko_assert( 4 === count( $al->to_client()['upcoming'] ), 'ahead: the page gets the rounds to page to' );

cko_assert( true === $al->set_value( 'ahitm001', true, null, 3 ), 'ahead: a tick in round 3' );
cko_assert( ! isset( $al->get_values()['ahitm001'] ), 'ahead: the round in force stays as it is' );
cko_assert( null !== cko_row( $al, 'ahitm001', 2 ), 'ahead: the row lies in round 3 (index 2)' );
$view = $al->upcoming_view( 3 );
cko_assert( true === ( (array) $view['values'] )['ahitm001'] && 1 === $view['progress']['done'], 'ahead: the round shows its tick and progress' );
cko_assert( 'doing' === $al->set_stage( 'ahitm002', 'doing', null, 3 ) && 'doing' === ( (array) $al->upcoming_view( 3 )['stages'] )['ahitm002'], 'ahead: board stages work there too' );

$walk = $al->set_value( 'ahitm003', array( 'days' => array( 1, 2 ) ), null, 2 );
$week = wp_date( 'o-\WW', $al->upcoming()[0]['start'] );
cko_assert( $week === $walk['week'], 'ahead: a weekday tick belongs to the week of its round' );
cko_assert( isset( ( (array) $al->upcoming_view( 2 )['values'] )['ahitm003'] ), 'ahead: and is shown in it' );

cko_assert( is_wp_error( $al->set_value( 'ahitm001', true, null, 9 ) ), 'ahead: a round beyond the series is refused' );
cko_assert( is_wp_error( $al->set_value( 'ahitm001', true, null, 0 + $al->round_index() ) ) || 0 === $al->round_index(), 'ahead: a past round is refused' );
update_option( Checkoff_Checklist::OPT_UPCOMING, 0 );
cko_assert( array() === $al->upcoming() && is_wp_error( $al->set_value( 'ahitm001', true, null, 3 ) ), 'ahead: 0 switches it off' );
update_option( Checkoff_Checklist::OPT_UPCOMING, 4 );

$r = cko_ajax( 'cko_set_value', array( 'id' => $al->get_public_id(), 'item' => 'ahitm002', 'value' => 'true', 'round' => 4 ) );
cko_assert( ! empty( $r['success'] ) && null !== cko_row( $al, 'ahitm002', 3 ), 'ahead: AJAX writes with round' );
$r = cko_ajax( 'cko_upcoming_round', array( 'id' => $al->get_public_id(), 'number' => 4 ) );
cko_assert( ! empty( $r['success'] ) && 4 === $r['data']['number'] && true === $r['data']['values']['ahitm002'], 'ahead: AJAX loads a round ahead' );
$r = cko_ajax( 'cko_upcoming_round', array( 'id' => $al->get_public_id(), 'number' => 9 ) );
cko_assert( empty( $r['success'] ), 'ahead: AJAX refuses a round that cannot be ticked' );

// Structure changes reach the rounds ahead; deleting the history does not.
$as = $al->get_structure();
array_pop( $as['items'][0]['items'] );
$al->set_structure( $as, $al->get_revision() );
cko_assert( null === cko_row( $al, 'ahitm002', 2 ) && null !== cko_row( $al, 'ahitm001', 2 ), 'ahead: ticks of a removed entry go, the rest stay' );
Checkoff_History::clear( $al );
cko_assert( null !== cko_row( $al, 'ahitm001', 2 ), 'ahead: deleting the history keeps the rounds ahead' );
cko_assert( array() === Checkoff_History::index( $al ), 'ahead: rounds ahead are no history' );

if ( null === $up_opt ) {
	delete_option( Checkoff_Checklist::OPT_UPCOMING );
} else {
	update_option( Checkoff_Checklist::OPT_UPCOMING, $up_opt );
}

/* --- history of closed rounds (virtual: rows of earlier rounds) ------------ */

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
$hl_id = $hl->get_post_id();
$now_i = $hl->round_index();

// The list existed before the rounds this test pretends: its versions are moved back.
global $wpdb;
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Checkoff_Versions::table() . ' SET saved_at = %s WHERE checklist_id = %d', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), $hl_id ) ); // phpcs:ignore
Checkoff_Versions::forget();
$hl = new Checkoff_Checklist( $hl_id );

// Writes values into a round that has ended, ticked at that day.
$past = static function ( Checkoff_Checklist $list, int $back, array $values ): void {
	foreach ( $values as $entry => $value ) {
		Checkoff_Values::set_value( $list->get_post_id(), $list->round_index() - $back, $entry, $value, time() - $back * DAY_IN_SECONDS );
	}
};

update_option( Checkoff_History::OPT_ENABLED, '' );
$past( $hl, 1, array( 'hitm0001' => true ) );
$past( $hl, 2, array( 'hitm0001' => true ) );
cko_assert( array() === Checkoff_History::index( $hl ), 'history: off by default, nothing to page through' );
$last = $hl->get_last_round();
cko_assert( null !== $last && $now_i === $last['number'] && 1 === $last['done'] && 3 === $last['total'] && 2 === count( $last['open'] ), 'history: "last time" is computed from the round before' );
cko_assert( array() === $hl->get_values(), 'history: the round in force starts empty, nothing written' );
$hl->set_value( 'hitm0002', true );
$kept_rounds = Checkoff_Values::rounds( $hl_id );
cko_assert( ! isset( $kept_rounds[ $now_i - 2 ] ) && isset( $kept_rounds[ $now_i - 1 ] ), 'history off: a write deletes the rounds before the last one' );
$hl->set_value( 'hitm0002', null );

update_option( Checkoff_History::OPT_ENABLED, 'on' );
$past( $hl, 2, array( 'hitm0001' => true, 'hitm0003' => 72.5 ) );
$rounds = Checkoff_History::rounds( $hl );
cko_assert( 2 === count( $rounds ) && $now_i === $rounds[0]['number'] && $now_i - 1 === $rounds[1]['number'], 'history: the past rounds, newest first' );
cko_assert( 2 === $rounds[1]['done'] && 3 === $rounds[1]['total'], 'history: a round with its progress' );
cko_assert( 'Chores' === $rounds[1]['sections'][0]['title'] && true === $rounds[1]['sections'][0]['entries'][0]['done'], 'history: entries with their state' );
cko_assert( 72.5 === $rounds[1]['sections'][1]['entries'][0]['value'] && '72.5 kg' === str_replace( ',', '.', $rounds[1]['sections'][1]['entries'][0]['text'] ), 'history: values and their wording' );
$two_days = wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
cko_assert( $two_days === $rounds[1]['start'] && $two_days === $rounds[1]['end'], 'history: the round\'s dates, computed' );

// The past stays as it was when the structure changes later.
$hs2                                  = $hl->get_structure();
$hs2['items'][0]['items'][0]['label'] = 'Renamed';
$hl->set_structure( $hs2, $hl->get_revision() );
cko_assert( 'Dishes' === Checkoff_History::rounds( $hl )[0]['sections'][0]['entries'][0]['label'], 'history: a later rename does not change the past (the version in force then)' );
cko_assert( 'Renamed' === $hl->get_structure()['items'][0]['items'][0]['label'], 'history: the list itself has the new name' );

// One round as the page shows it: structure then, values, times.
$round_no = $now_i - 1;
$view     = Checkoff_History::round_view( $hl, $round_no );
cko_assert( null !== $view && 'Dishes' === $view['structure']['items'][0]['items'][0]['label'] && true === ( (array) $view['values'] )['hitm0001'], 'times: a kept round shows as the list it was' );
$t1 = (int) ( ( (array) $view['times'] )['hitm0001'] ?? 0 );
cko_assert( abs( $t1 - ( time() - 2 * DAY_IN_SECONDS ) ) < 120, 'times: with the time each value was set' );
cko_assert( array( $now_i - 1, $now_i ) === array_column( Checkoff_History::index( $hl ), 'number' ), 'times: the index lists the kept rounds to page through, oldest first' );
cko_assert( null === Checkoff_History::round_view( $hl, $now_i + 1 ) && null === Checkoff_History::round_view( $hl, 99 ), 'times: the round in force and rounds without rows are no history' );

$before_set = time();
$hl->set_value( 'hitm0002', true );
$times = $hl->get_value_times();
cko_assert( isset( $times['hitm0002'] ) && $times['hitm0002'] >= $before_set, 'times: a tick records its time' );

// A tick sent late (offline browser) keeps the time it was made …
$late = cko_new( 'T late tick' );
$ls   = $late->get_structure();
$ls['items'][0]['items'] = array( array( 'id' => 'late0001', 'kind' => 'item', 'label' => 'Late' ) );
$late->set_structure( $ls, $late->get_revision() );
$late->set_value( 'late0001', true, time() - HOUR_IN_SECONDS );
cko_assert( abs( ( $late->get_value_times()['late0001'] ?? 0 ) - ( time() - HOUR_IN_SECONDS ) ) < 5, 'late tick: keeps the time it was made' );
$late->set_value( 'late0001', true, time() + DAY_IN_SECONDS );
cko_assert( ( $late->get_value_times()['late0001'] ?? 0 ) <= time(), 'late tick: a time ahead is now' );
$late->set_value( 'late0001', true, time() - 30 * DAY_IN_SECONDS );
cko_assert( ( $late->get_value_times()['late0001'] ?? 0 ) >= time() - Checkoff_Checklist::LATE_TICK_MAX - 5, 'late tick: believed a week back at most' );
// … but not into a round that has ended since.
$hl->set_value( 'hitm0002', null );
$late_err = $hl->set_value( 'hitm0002', true, time() - 3 * DAY_IN_SECONDS );
cko_assert( is_wp_error( $late_err ) && 'checkoff_round_over' === $late_err->get_error_code() && ! isset( $hl->get_values()['hitm0002'] ), 'late tick: refused when its round has ended' );
cko_assert( ! is_wp_error( $hl->set_value( 'hitm0002', true, time() - 60 ) ) && null === $hl->set_value( 'hitm0002', null ), 'late tick: accepted within the round' );

Checkoff_Values::set_value( $hl_id, $now_i, 'hitm0002', true, 1000 );
$rev_struct          = $hl->get_structure();
$rev_struct['title'] = 'T history renamed';
$hl->set_structure( $rev_struct, $hl->get_revision() );
cko_assert( 1000 === ( $hl->get_value_times()['hitm0002'] ?? 0 ), 'times: a structure save keeps the time of a value' );
$hl->set_value( 'hitm0002', null );
cko_assert( ! isset( $hl->get_value_times()['hitm0002'] ), 'times: a cleared value has no time' );

// Only whoever may edit learns of times and history.
$hl_edit = Checkoff_Checklist::find( $hl->get_public_id() );
cko_assert( 2 === ( $hl_edit->to_client()['history']['rounds'] ?? null ) && isset( $hl_edit->to_client()['times'] ), 'history: the edit view gets times, index and count' );
$hl->set_level( 'protected', 'History 123' );
$_COOKIE   = array();
$hl_locked = Checkoff_Checklist::find( $hl->get_public_id() );
Checkoff_Password::apply( $hl_locked );
cko_assert( ! isset( $hl_locked->to_client()['history'] ) && ! isset( $hl_locked->to_client()['times'] ), 'history: a locked view does not learn of it' );
$r = cko_ajax( 'cko_history', array( 'id' => $hl->get_public_id() ) );
cko_assert( empty( $r['success'] ), 'history: the page refuses it without the password' );
$r = cko_ajax( 'cko_history_round', array( 'id' => $hl->get_public_id(), 'number' => $round_no ) );
cko_assert( empty( $r['success'] ), 'times: one round not without the password' );
$hl->set_level( 'open' );
$r = cko_ajax( 'cko_history', array( 'id' => $hl->get_public_id() ) );
cko_assert( ! empty( $r['success'] ) && 2 === count( $r['data']['rounds'] ) && 2 === count( $r['data']['index'] ), 'history: the page loads it with edit access' );
$r = cko_ajax( 'cko_history_round', array( 'id' => $hl->get_public_id(), 'number' => $round_no ) );
cko_assert( ! empty( $r['success'] ) && $round_no === $r['data']['number'] && isset( $r['data']['times']['hitm0001'] ), 'times: the page loads one round with its times' );
$r = cko_ajax( 'cko_history_round', array( 'id' => $hl->get_public_id(), 'number' => 99 ) );
cko_assert( empty( $r['success'] ), 'times: a round not kept is refused' );

// Expiry.
update_option( Checkoff_History::OPT_DAYS, 1 );
cko_assert( 0 === count( Checkoff_History::rounds( $hl, time() + 3 * DAY_IN_SECONDS ) ), 'history: expired rounds are not shown' );
cko_assert( Checkoff_History::prune_all( time() + 3 * DAY_IN_SECONDS ) >= 2, 'history: the daily run deletes expired rounds' );
cko_assert( ! isset( Checkoff_Values::rounds( $hl_id )[ $now_i - 2 ] ) && ! isset( Checkoff_Values::rounds( $hl_id )[ $now_i - 1 ] ), 'history: their rows are gone' );
update_option( Checkoff_History::OPT_DAYS, 90 );

// Deleting by the owner, and switching off.
$past( $hl, 1, array( 'hitm0002' => true ) );
$past( $hl, 3, array( 'hitm0002' => true ) );
$hl->set_value( 'hitm0001', true );
cko_assert( 2 === count( Checkoff_History::index( $hl ) ), 'history: rounds with entries are kept' );
Checkoff_History::clear( $hl );
cko_assert( array() === Checkoff_History::index( $hl ) && true === ( $hl->get_values()['hitm0001'] ?? null ), 'history: the owner deletes it, the round in force stays' );
$past( $hl, 1, array( 'hitm0002' => true ) );
$past( $hl, 3, array( 'hitm0002' => true ) );
update_option( Checkoff_History::OPT_ENABLED, '' );
$kept_rounds = Checkoff_Values::rounds( $hl_id );
cko_assert( ! isset( $kept_rounds[ $now_i - 3 ] ) && isset( $kept_rounds[ $now_i - 1 ] ), 'history: switching it off deletes every round before the last' );

// A changed rule: the round in force keeps its ticks, the old rule's past goes.
$rule_struct               = $hl->get_structure();
$rule_struct['recurrence'] = array( 'freq' => 'weekly', 'interval' => 1, 'start' => wp_date( 'Y-m-d', time() - 20 * DAY_IN_SECONDS ), 'end' => 'never' );
$hl->set_structure( $rule_struct, $hl->get_revision() );
cko_assert( true === ( $hl->get_values()['hitm0001'] ?? null ) && array( $hl->round_index() ) === array_keys( Checkoff_Values::rounds( $hl_id ) ), 'history: a changed rule moves the ticks into the new round and drops the old past' );

foreach ( array( Checkoff_History::OPT_ENABLED, Checkoff_History::OPT_DAYS ) as $i => $name ) {
	if ( null === $hist_opt[ $i ] ) {
		delete_option( $name );
	} else {
		update_option( $name, $hist_opt[ $i ] );
	}
}

/* --- visitors and quotas --------------------------------------------------- */

$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb:1:2:3:4';
$v1 = Checkoff_Rate_Limit::visitor();
$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb:ffff:ffff:ffff:ffff';
cko_assert( $v1 === Checkoff_Rate_Limit::visitor(), 'visitors: one IPv6 /64 is one visitor' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbc::1';
cko_assert( $v1 !== Checkoff_Rate_Limit::visitor(), 'visitors: another /64 is another visitor' );
$_SERVER['REMOTE_ADDR']          = '192.0.2.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';
cko_assert( '192.0.2.10' === Checkoff_Rate_Limit::visitor(), 'visitors: a forwarding header is ignored unless configured' );
update_option( 'checkoff_ip_source', 'x_forwarded_for' );
cko_assert( '198.51.100.7' === Checkoff_Rate_Limit::visitor(), 'visitors: behind a proxy, the first forwarded address' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not an address';
cko_assert( '' === Checkoff_Rate_Limit::visitor(), 'visitors: an invalid header is no address' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
cko_assert( '192.0.2.10' === Checkoff_Rate_Limit::visitor(), 'visitors: a missing header falls back to the connection' );
update_option( 'checkoff_ip_source', 'nonsense' );
cko_assert( 'remote_addr' === Checkoff_Rate_Limit::ip_source(), 'visitors: an unknown source is the connection' );
delete_option( 'checkoff_ip_source' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';

$quota_opt = get_option( 'checkoff_ticks_per_hour', null );
update_option( 'checkoff_ticks_per_hour', Checkoff_Rate_Limit::count( Checkoff_Quota::TICK ) + 1 );
cko_assert( true === Checkoff_Quota::take( Checkoff_Quota::TICK ), 'quota: taken while there is room' );
$refused = Checkoff_Quota::take( Checkoff_Quota::TICK );
cko_assert( is_wp_error( $refused ) && 429 === $refused->get_error_data()['status'] && $refused->get_error_data()['retry_after'] > 0, 'quota: refused with 429 and a wait time when used up' );
$ql = cko_new( 'T quota page' );
$r  = cko_ajax( 'cko_set_value', array( 'id' => $ql->get_public_id(), 'item' => array_keys( Checkoff_Checklist::leaf_map( $ql->get_structure() ) )[0] ?? 'x', 'value' => 'true' ) );
cko_assert( empty( $r['success'] ), 'quota: the page is refused as well' );
null === $quota_opt ? delete_option( 'checkoff_ticks_per_hour' ) : update_option( 'checkoff_ticks_per_hour', $quota_opt );

/* --- wp-admin access ---------------------------------------------------------- */

$editor = wp_insert_user( array( 'user_login' => 'cko_t_editor_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
wp_set_current_user( $editor );
cko_assert( ! current_user_can( get_post_type_object( Checkoff_Post_Types::POST_TYPE )->cap->edit_posts ), 'admin: an editor does not get the CheckOff menu' );
cko_assert( ! current_user_can( 'edit_post', $ql->get_post_id() ), 'admin: an editor cannot edit a checklist (and read its IDs)' );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
cko_assert( current_user_can( 'edit_post', $ql->get_post_id() ), 'admin: an administrator can' );
cko_assert( ! current_user_can( get_post_type_object( Checkoff_Post_Types::POST_TYPE )->cap->create_posts ), 'admin: nobody creates checklists in wp-admin' );

// Bulk edit: the template choice, applied per checklist through save_post.
$bulk_a = cko_new( 'T bulk a' );
$bulk_b = cko_new( 'T bulk b' );
update_post_meta( $bulk_a->get_post_id(), Checkoff_Templates::META_DESC, 'Kept' );
$bulk_run = static function ( string $choice, bool $with_nonce = true ) use ( $bulk_a, $bulk_b ): void {
	$_REQUEST = array( 'bulk_edit' => 'Update', 'cko_bulk_template' => $choice );
	if ( $with_nonce ) {
		$_REQUEST[ Checkoff_Post_Meta::BULK_NONCE ] = wp_create_nonce( Checkoff_Post_Meta::BULK_ACTION );
	}
	foreach ( array( $bulk_a, $bulk_b ) as $c ) {
		wp_update_post( array( 'ID' => $c->get_post_id() ) );
	}
	$_REQUEST = array();
};
$bulk_run( '1', false );
cko_assert( ! Checkoff_Templates::is_template( $bulk_a->get_post_id() ), 'bulk edit: nothing changes without its nonce' );
$bulk_run( '1' );
cko_assert( Checkoff_Templates::is_template( $bulk_a->get_post_id() ) && Checkoff_Templates::is_template( $bulk_b->get_post_id() ), 'bulk edit: "offer" marks every selected checklist' );
cko_assert( 'Kept' === get_post_meta( $bulk_a->get_post_id(), Checkoff_Templates::META_DESC, true ) && '' !== get_post_meta( $bulk_b->get_post_id(), Checkoff_Templates::META_KEY, true ), 'bulk edit: each keeps its description and gets a key' );
$bulk_run( '' );
cko_assert( Checkoff_Templates::is_template( $bulk_a->get_post_id() ), 'bulk edit: "no change" leaves the flag alone' );
$bulk_run( '0' );
cko_assert( ! Checkoff_Templates::is_template( $bulk_a->get_post_id() ) && ! Checkoff_Templates::is_template( $bulk_b->get_post_id() ), 'bulk edit: "do not offer" unmarks them' );
wp_set_current_user( 0 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $editor );

/* --- checklist page: chosen, else found ------------------------------------- */

$page_opt = get_option( 'checkoff_checklist_page', null );
delete_option( 'checkoff_checklist_page' );
// Only the pages made here count: no template matches, earlier pages carry a later menu order.
add_filter( 'checkoff_checklist_page_templates', '__return_empty_array' );
$cp_pages = array();
$cp_new   = static function ( string $title, string $content, int $order, array $meta = array() ) use ( &$cp_pages ): int {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content, 'menu_order' => $order, 'meta_input' => $meta ) );
	$cp_pages[] = $id;
	return $id;
};
global $wpdb;
$cp_others = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( post_content LIKE '%[cko_app%' OR post_content LIKE '%[cko_checklist%' )" );
foreach ( $cp_others as $other ) {
	wp_update_post( array( 'ID' => (int) $other, 'menu_order' => 99 ) );
}
$fixed = $cp_new( 'T fixed list', '[cko_checklist id="abcd-efgh-jkmn"]', -30 );
Checkoff_Checklist_Page::forget();
cko_assert( ! in_array( Checkoff_Checklist_Page::detected_id(), array( $fixed ), true ), 'checklist page: a shortcode with a fixed ID does not count' );
$list_page = $cp_new( 'T list page', '[cko_checklist]', -20 );
$elementor = $cp_new( 'T elementor page', '', -10, array( '_elementor_data' => wp_slash( '[{"elType":"widget","widgetType":"shortcode","settings":{"shortcode":"[cko_app]"}}]' ) ) );
cko_assert( $elementor === Checkoff_Checklist_Page::detected_id(), 'checklist page: [cko_app] in Elementor data is found, and wins over [cko_checklist] (saving a page forgot the cache)' );
cko_assert( Checkoff_Checklist::url_for( 'abcdefghjkmn' ) === add_query_arg( 'cko', 'abcd-efgh-jkmn', get_permalink( $elementor ) ), 'checklist page: links lead to the page found, not the front page' );
wp_trash_post( $elementor );
cko_assert( $list_page === Checkoff_Checklist_Page::detected_id(), 'checklist page: trashing it finds the next one' );
update_option( 'checkoff_checklist_page', $fixed );
cko_assert( $fixed === Checkoff_Checklist_Page::id(), 'checklist page: a chosen page always wins' );
delete_option( 'checkoff_checklist_page' );
remove_filter( 'checkoff_checklist_page_templates', '__return_empty_array' );
foreach ( $cp_pages as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $cp_others as $other ) {
	wp_update_post( array( 'ID' => (int) $other, 'menu_order' => 0 ) );
}
null === $page_opt ? delete_option( 'checkoff_checklist_page' ) : update_option( 'checkoff_checklist_page', $page_opt );
Checkoff_Checklist_Page::forget();

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
