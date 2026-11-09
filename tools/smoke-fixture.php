<?php
/**
 * Fixtures for tools/smoke-test.js, run through wp-cli:
 *
 *   wp eval-file wp-content/plugins/checkoff/tools/smoke-fixture.php          → JSON with the IDs
 *   wp eval-file wp-content/plugins/checkoff/tools/smoke-fixture.php cleanup  → deletes them again
 *
 * Creates two checklists titled "T smoke …": one with every building block,
 * one repeating weekly (for paging and ticking ahead). Sets the live update
 * to 2 seconds and the rounds ahead to 4 while the test runs; cleanup
 * restores both options.
 *
 * @package CheckOff/Tools
 */

// phpcs:ignoreFile -- a test tool, not part of the plugin.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cko_saved = 'checkoff_smoke_saved_options';

if ( in_array( 'cleanup', (array) ( $args ?? array() ), true ) ) {

	foreach ( get_posts( array( 'post_type' => Checkoff_Post_Types::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'T smoke', 'fields' => 'ids' ) ) as $id ) {
		wp_delete_post( $id, true );
	}

	foreach ( (array) get_option( $cko_saved, array() ) as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	delete_option( $cko_saved );

	echo "cleaned\n";
	return;
}

$options = array( 'checkoff_sync_interval' => 2, Checkoff_Checklist::OPT_UPCOMING => 4 );
if ( ! get_option( $cko_saved ) ) {
	$saved = array();
	foreach ( $options as $name => $value ) {
		$saved[ $name ] = get_option( $name, null );
	}
	update_option( $cko_saved, $saved, false );
}
foreach ( $options as $name => $value ) {
	update_option( $name, $value );
}

/**
 * A block with one entry.
 */
$cko_block = static function ( string $id, string $type, array $settings = array(), array $data = array() ): array {
	$item = array( 'id' => 'i' . $id, 'kind' => 'item', 'label' => ucfirst( $type ) );
	if ( $data ) {
		$item['data'] = $data;
	}
	return array( 'id' => 'b' . $id, 'kind' => 'block', 'type' => $type, 'title' => ucfirst( $type ), 'settings' => $settings, 'items' => array( $item ) );
};

$cko_make = static function ( string $title, array $items, array $recurrence = array() ): string {
	$checklist = Checkoff_Checklist::create( $title );
	$structure = $checklist->get_structure();
	$structure['items'] = $items;
	if ( $recurrence ) {
		$structure['recurrence'] = $recurrence;
	}
	$checklist->set_structure( $structure, $checklist->get_revision() );
	return Checkoff_Public_Id::format( $checklist->get_public_id() );
};

$all = $cko_make(
	'T smoke all blocks',
	array(
		$cko_block( 'smk00001', 'simple' ),
		$cko_block( 'smk00002', 'deadline', array(), array( 'due' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ) ),
		$cko_block( 'smk00003', 'status' ),
		$cko_block( 'smk00004', 'weekdays', array( 'days' => array( 1, 3, 5 ) ) ),
		$cko_block( 'smk00005', 'color_scale' ),
		$cko_block( 'smk00006', 'number_scale' ),
		$cko_block( 'smk00007', 'measurement', array( 'min' => 1, 'max' => 5 ) ),
		$cko_block( 'smk00008', 'choice', array( 'options' => "A\nB" ) ),
		$cko_block( 'smk00009', 'counter', array( 'target' => 2 ) ),
		$cko_block( 'smk00010', 'note' ),
	)
);

$weekly = $cko_make(
	'T smoke weekly',
	array(
		$cko_block( 'smk00011', 'simple' ),
		$cko_block( 'smk00012', 'note' ),
	),
	array( 'freq' => 'weekly', 'interval' => 1, 'start' => wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), 'end' => 'never' )
);

echo wp_json_encode(
	array(
		'all'    => $all,
		'weekly' => $weekly,
		'blocks' => count( Checkoff_Block_Types::all() ),
	)
) . "\n";
