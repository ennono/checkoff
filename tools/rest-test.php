<?php
/**
 * Assertions for the REST API (checkoff/v1).
 *
 * Dispatches real requests through the WordPress REST server against a real
 * install. Creates its own checklists (titled "T …") and deletes them; mails
 * are caught with pre_wp_mail and never sent.
 *
 *   wp eval-file wp-content/plugins/checkoff/tools/rest-test.php
 *
 * Not distributed (see .distignore).
 *
 * @package CheckOff
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $cko_r_fail, $cko_r_pass, $cko_r_created;

switch_to_locale( 'en_US' );
wp_set_current_user( 0 );

$cko_r_fail    = 0;
$cko_r_pass    = 0;
$cko_r_created = array();

// A visitor of its own, so the rate limits of earlier runs do not count.
$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';

// Mails are caught, never sent.
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'checkoff_mail_rate_limit_per_hour', '__return_zero' );

$cko_r_options = array();
foreach ( array( 'checkoff_rest_access', 'checkoff_rest_enabled', 'checkoff_ticks_per_hour', 'checkoff_edits_per_hour', 'checkoff_create_total_per_hour', 'checkoff_unlock_per_hour', 'checkoff_guests_can_create', 'checkoff_create_per_hour', 'checkoff_failed_lookups_per_hour', 'checkoff_subscriptions_enabled' ) as $name ) {
	$cko_r_options[ $name ] = get_option( $name, null );
}
update_option( 'checkoff_rest_access', 'public' );
update_option( 'checkoff_guests_can_create', 'on' );
update_option( 'checkoff_create_total_per_hour', 0 );
update_option( 'checkoff_create_per_hour', 0 );
update_option( 'checkoff_failed_lookups_per_hour', 0 );

function cko_r( bool $condition, string $message ): void {
	global $cko_r_fail, $cko_r_pass;
	if ( $condition ) {
		++$cko_r_pass;
		return;
	}
	++$cko_r_fail;
	echo "FAIL: {$message}\n";
}

/**
 * Dispatches a request; JSON body for writes.
 *
 * @return array{0:int, 1:mixed}
 */
function cko_call( string $method, string $route, ?array $body = null, array $query = array() ): array {
	$request = new WP_REST_Request( $method, '/' . Checkoff_Rest_Api::NAMESPACE . $route );
	if ( $query ) {
		$request->set_query_params( $query );
	}
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

function cko_remember( $data ): void {
	global $cko_r_created;
	if ( is_array( $data ) && isset( $data['id'] ) ) {
		$list = Checkoff_Checklist::find( $data['id'] );
		if ( $list ) {
			$cko_r_created[] = $list->get_post_id();
		}
	}
}

rest_get_server();

/* --- routes ------------------------------------------------------------------ */

$routes = rest_get_server()->get_routes( Checkoff_Rest_Api::NAMESPACE );
cko_r( isset( $routes['/checkoff/v1/checklists'], $routes['/checkoff/v1/blocks'], $routes['/checkoff/v1/templates'] ), 'the routes are registered' );

list( $st, $data ) = cko_call( 'GET', '/blocks' );
cko_r( 200 === $st && isset( $data['blocks']['deadline'], $data['blocks']['choice'] ) && in_array( 'simple', $data['enabled'], true ), 'blocks: definitions and the enabled list' );
cko_r( isset( $data['max_depth'], $data['max_nodes'] ), 'blocks: the limits a structure must keep' );

list( $st, $data ) = cko_call( 'GET', '/templates' );
cko_r( 200 === $st && is_array( $data ), 'templates: a list' );

/* --- create ------------------------------------------------------------------- */

list( $st, $made ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest empty' ) );
cko_remember( $made );
cko_r( 201 === $st && 'edit' === $made['access'] && 'T rest empty' === $made['title'], 'create: an empty checklist, answered with edit access' );
cko_r( ! isset( $made['tick_id'] ) && 'open' === $made['level'] && isset( $made['revision'], $made['state'], $made['progress'] ), 'create: the answer carries ID, level, revision, state and progress — no tick ID since 2.0.0' );
cko_r( ! isset( $made['post_id'] ) && false === strpos( (string) wp_json_encode( $made ), '"ID"' ), 'create: no post ID in the answer' );
cko_r( 1 === count( $made['structure']['items'] ) && 'simple' === $made['structure']['items'][0]['type'], 'create: an empty list starts with one simple block' );

$full = array(
	'kind'        => 'checklist',
	'title'       => 'T rest full',
	'description' => 'Built in one call',
	'items'       => array(
		array(
			'id'       => 'secblk01',
			'kind'     => 'block',
			'type'     => 'status',
			'title'    => 'Docs',
			'settings' => array(),
			'items'    => array(
				array( 'id' => 'passpo01', 'kind' => 'item', 'label' => 'Passport' ),
				array( 'id' => 'visaaa01', 'kind' => 'item', 'label' => 'Visa' ),
			),
		),
		array(
			'id'       => 'secblk02',
			'kind'     => 'block',
			'type'     => 'deadline',
			'title'    => 'Dates',
			'settings' => array( 'soon' => 3 ),
			'items'    => array(
				array( 'id' => 'deadln01', 'kind' => 'item', 'label' => 'Move', 'data' => array( 'due' => '2026-12-01' ) ),
			),
		),
		array(
			'id'       => 'secblk03',
			'kind'     => 'block',
			'type'     => 'measurement',
			'title'    => 'Weight',
			'settings' => array( 'unit' => 'kg', 'min' => 60, 'max' => 70, 'decimals' => 1 ),
			'items'    => array(
				array( 'id' => 'weight01', 'kind' => 'item', 'label' => 'Monday' ),
			),
		),
	),
);
list( $st, $built ) = cko_call( 'POST', '/checklists', array( 'structure' => $full ) );
cko_remember( $built );
cko_r( 201 === $st && 3 === count( $built['structure']['items'] ) && 'T rest full' === $built['title'], 'create: from a whole structure in one call' );
cko_r( '2026-12-01' === ( $built['structure']['items'][1]['items'][0]['data']['due'] ?? '' ), 'create: entry data travels with the structure' );
cko_r( 4 === $built['progress']['total'] && 0 === $built['progress']['done'], 'create: progress counts the entries' );

list( $st, $err ) = cko_call( 'POST', '/checklists', array( 'template' => 'nosuchtpl', 'structure' => $full ) );
cko_r( 400 === $st, 'create: template and structure together are refused' );

list( $st, $err ) = cko_call( 'POST', '/checklists', array( 'template' => 'nosuchtpl' ) );
cko_r( 404 === $st, 'create: an unknown template is 404' );

update_option( 'checkoff_guests_can_create', '' );
list( $st, $err ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest guest' ) );
cko_r( 403 === $st && 'checkoff_login_required' === $err['code'], 'create: guests are refused when the setting says so' );
update_option( 'checkoff_guests_can_create', 'on' );

update_option( 'checkoff_create_per_hour', Checkoff_Rate_Limit::count( 'create' ) + 1 );
list( $st, $limited ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest limit 1' ) );
cko_remember( $limited );
list( $st2, $limited2 ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest limit 2' ) );
cko_remember( $limited2 );
cko_r( 201 === $st && 429 === $st2, 'create: the hourly limit of the website applies' );
update_option( 'checkoff_create_per_hour', 0 );

/* --- read ---------------------------------------------------------------------- */

$edit_id = $built['id'];
// A list from before 2.0.0 keeps the tick link it handed out.
$tick_id = Checkoff_Public_Id::format( Checkoff_Checklist::unique_public_id() );
update_post_meta( Checkoff_Checklist::find( $edit_id )->get_post_id(), Checkoff_Checklist::META_TICK_ID, Checkoff_Public_Id::normalize( $tick_id ) );

list( $st, $read ) = cko_call( 'GET', '/checklists/' . $edit_id );
cko_r( 200 === $st && $read['revision'] === $built['revision'], 'read: by the public ID' );
list( $st, $read ) = cko_call( 'GET', '/checklists/' . strtoupper( str_replace( '-', '', $edit_id ) ) );
cko_r( 200 === $st, 'read: the ID is normalised (case, dashes)' );

list( $st, $tick ) = cko_call( 'GET', '/checklists/' . $tick_id );
cko_r( 200 === $st && 'tick' === $tick['access'], 'read: by the tick ID, with tick access' );
cko_r( ! isset( $tick['tick_id'] ) && ! isset( $tick['tick_url'] ) && $tick['id'] === $tick_id, 'read: a tick view learns no other ID' );
cko_r( false === strpos( (string) wp_json_encode( $tick ), str_replace( '-', '', $edit_id ) ), 'read: the edit ID appears nowhere in a tick view' );

list( $st, $err ) = cko_call( 'GET', '/checklists/zzzz-zzzz-zzzz' );
cko_r( 404 === $st && 'checkoff_not_found' === $err['code'], 'read: an unknown ID is 404' );

/* --- values --------------------------------------------------------------------- */

list( $st, $v ) = cko_call( 'PUT', '/checklists/' . $tick_id . '/values/passpo01', array( 'value' => 'done' ) );
cko_r( 200 === $st && 'done' === $v['value'] && 1 === $v['progress']['done'], 'values: the tick ID may tick, answer carries the progress' );
list( $st, $v ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/values/visaaa01', array( 'value' => 'skip' ) );
cko_r( 'skip' === $v['value'] && 3 === $v['progress']['total'], 'values: a skipped entry leaves the total' );
list( $st, $v ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/values/weight01', array( 'value' => '65,5' ) );
cko_r( 65.5 === $v['value'] && 2 === $v['progress']['done'], 'values: validated by the block (decimal comma, target range)' );
list( $st, $v ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/values/weight01', array( 'value' => 'heavy' ) );
cko_r( 200 === $st && null === $v['value'], 'values: an invalid value clears, as on the page' );
list( $st, $v ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/values/weight01', array( 'value' => 68 ) );
list( $st, $v ) = cko_call( 'DELETE', '/checklists/' . $edit_id . '/values/weight01' );
cko_r( 200 === $st && null === $v['value'] && 1 === $v['progress']['done'], 'values: DELETE clears one entry' );
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/values/nosuchit', array( 'value' => true ) );
cko_r( 404 === $st, 'values: an unknown entry is 404' );
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/values/secblk01', array( 'value' => true ) );
cko_r( 404 === $st, 'values: a block is not an entry' );

/* --- live update ------------------------------------------------------------------ */

list( $st, $s1 ) = cko_call( 'GET', '/checklists/' . $tick_id . '/state' );
cko_r( true === $s1['changed'] && isset( $s1['values'], $s1['structure'], $s1['progress'] ), 'state: without a fingerprint everything comes' );
list( $st, $s2 ) = cko_call( 'GET', '/checklists/' . $tick_id . '/state', null, array( 'state' => $s1['state'], 'revision' => $s1['revision'] ) );
cko_r( false === $s2['changed'] && array( 'state', 'changed' ) === array_keys( $s2 ), 'state: an unchanged fingerprint is answered with itself alone' );
cko_call( 'PUT', '/checklists/' . $tick_id . '/values/deadln01', array( 'value' => true ) );
list( $st, $s3 ) = cko_call( 'GET', '/checklists/' . $tick_id . '/state', null, array( 'state' => $s1['state'], 'revision' => $s1['revision'] ) );
cko_r( true === $s3['changed'] && ! isset( $s3['structure'] ) && true === ( (array) $s3['values'] )['deadln01'], 'state: changed values come, the unchanged structure does not' );

/* --- structure -------------------------------------------------------------------- */

list( $st, $cur ) = cko_call( 'GET', '/checklists/' . $edit_id );
$structure                                = $cur['structure'];
$structure['items'][0]['items'][]         = array( 'id' => 'newent01', 'kind' => 'item', 'label' => 'Tickets' );
$structure['items'][2]['settings']['max'] = 80;

list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $tick_id . '/structure', array( 'structure' => $structure, 'revision' => $cur['revision'] ) );
cko_r( 403 === $st && 'checkoff_tick_only' === $err['code'], 'structure: the tick ID may not change it' );

list( $st, $saved ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/structure', array( 'structure' => $structure, 'revision' => $cur['revision'] ) );
cko_r( 200 === $st && $saved['revision'] === $cur['revision'] + 1 && 3 === count( $saved['structure']['items'][0]['items'] ), 'structure: saved, revision counted up' );

list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/structure', array( 'structure' => $structure, 'revision' => $cur['revision'] ) );
cko_r( 409 === $st && 'checkoff_conflict' === $err['code'], 'structure: a stale revision is 409' );

list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/structure', array( 'revision' => $saved['revision'] ) );
cko_r( 400 === $st, 'structure: required fields are checked' );

$huge             = $saved['structure'];
$huge['description'] = str_repeat( 'x', Checkoff_Shortcodes::MAX_PAYLOAD + 10 );
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $edit_id . '/structure', array( 'structure' => $huge, 'revision' => $saved['revision'] ) );
cko_r( 413 === $st, 'structure: larger than the page may send is 413' );

list( $st, $patched ) = cko_call(
	'PATCH',
	'/checklists/' . $edit_id,
	array(
		'revision'   => $saved['revision'],
		'title'      => 'T rest renamed',
		'recurrence' => array( 'freq' => 'weekly', 'interval' => 1, 'start' => '2026-01-05', 'end' => 'never' ),
	)
);
cko_r( 200 === $st && 'T rest renamed' === $patched['title'] && 'weekly' === $patched['structure']['recurrence']['freq'], 'patch: title and repetition without the whole tree' );
cko_r( $patched['round']['repeats'] && 3 === count( $patched['structure']['items'] ), 'patch: the round is computed, the blocks are untouched' );
list( $st, $err ) = cko_call( 'PATCH', '/checklists/' . $edit_id, array( 'revision' => $saved['revision'], 'title' => 'T stale' ) );
cko_r( 409 === $st, 'patch: a stale revision is 409 too' );
list( $st, $err ) = cko_call( 'PATCH', '/checklists/' . $tick_id, array( 'revision' => $patched['revision'], 'title' => 'T tick' ) );
cko_r( 403 === $st, 'patch: the tick ID may not' );

/* --- subscriptions ----------------------------------------------------------------- */

update_option( 'checkoff_subscriptions_enabled', 'on' );
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $tick_id . '/subscriptions', array( 'email' => 'rest@checkoff.test', 'consent' => false ) );
cko_r( 400 === $st && 'checkoff_consent' === $err['code'], 'subscribe: consent is required' );
list( $st, $ok ) = cko_call( 'POST', '/checklists/' . $tick_id . '/subscriptions', array( 'email' => 'rest@checkoff.test', 'consent' => true ) );
cko_r( 202 === $st && '' !== $ok['message'], 'subscribe: step 1 of the double opt-in, by the tick ID' );
list( $st, $again ) = cko_call( 'POST', '/checklists/' . $tick_id . '/subscriptions', array( 'email' => 'rest@checkoff.test', 'consent' => true ) );
cko_r( 202 === $st && $again === $ok, 'subscribe: the answer never tells whether the address was there' );
$sub_list = Checkoff_Checklist::find( $tick_id );
cko_r( 1 === Checkoff_Subscriptions::count_for_checklist( $sub_list->get_post_id() ), 'subscribe: one pending subscription' );
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $tick_id . '/subscriptions', array( 'email' => 'not-an-address', 'consent' => true ) );
cko_r( 400 === $st, 'subscribe: an invalid address is refused' );
update_option( 'checkoff_subscriptions_enabled', '' );
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $tick_id . '/subscriptions', array( 'email' => 'rest2@checkoff.test', 'consent' => true ) );
cko_r( 403 === $st, 'subscribe: refused while subscriptions are off' );

/* --- history ------------------------------------------------------------------------ */

list( $st, $err ) = cko_call( 'GET', '/checklists/' . $tick_id . '/rounds' );
cko_r( 403 === $st, 'rounds: the tick ID may not see the history' );
list( $st, $h ) = cko_call( 'GET', '/checklists/' . $edit_id . '/rounds' );
cko_r( 200 === $st && array_key_exists( 'enabled', $h ) && is_array( $h['rounds'] ), 'rounds: the edit ID gets the history' );
list( $st, $h ) = cko_call( 'DELETE', '/checklists/' . $edit_id . '/rounds' );
cko_r( 200 === $st && array() === $h['rounds'], 'rounds: DELETE empties it' );
list( $st, $err ) = cko_call( 'DELETE', '/checklists/' . $tick_id . '/rounds' );
cko_r( 403 === $st, 'rounds: the tick ID may not delete it' );

/* --- reset and delete --------------------------------------------------------------- */

list( $st, $err ) = cko_call( 'DELETE', '/checklists/' . $tick_id . '/values' );
cko_r( 403 === $st, 'reset: the tick ID may not' );
list( $st, $reset ) = cko_call( 'DELETE', '/checklists/' . $edit_id . '/values' );
cko_r( 200 === $st && array() === (array) $reset['values'] && 0 === $reset['progress']['done'], 'reset: every value cleared' );

list( $st, $err ) = cko_call( 'DELETE', '/checklists/' . $tick_id );
cko_r( 403 === $st, 'delete: the tick ID may not' );
$post_id = Checkoff_Checklist::find( $edit_id )->get_post_id();
list( $st, $del ) = cko_call( 'DELETE', '/checklists/' . $edit_id );
cko_r( 200 === $st && true === $del['deleted'] && null === get_post( $post_id ), 'delete: the checklist is gone' );
cko_r( 0 === Checkoff_Subscriptions::count_for_checklist( $post_id ), 'delete: its subscriptions are gone with it' );

/* --- lookup limit -------------------------------------------------------------------- */

update_option( 'checkoff_failed_lookups_per_hour', 2 );
cko_call( 'GET', '/checklists/aaaa-bbbb-cccc' );
cko_call( 'GET', '/checklists/aaaa-bbbb-cccd' );
list( $st, $err ) = cko_call( 'GET', '/checklists/' . $made['id'] );
cko_r( 429 === $st && 'checkoff_limited' === $err['code'], 'lookup limit: after too many unknown IDs even a correct one is refused' );
update_option( 'checkoff_failed_lookups_per_hour', 0 );

/* --- password protection ----------------------------------------------------------- */

list( $st, $pw ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest password' ) );
cko_remember( $pw );
$pw_id   = $pw['id'];
// The list predates 2.0.0 and handed out a tick link.
$pw_tick = Checkoff_Public_Id::format( Checkoff_Checklist::unique_public_id() );
update_post_meta( Checkoff_Checklist::find( $pw_id )->get_post_id(), Checkoff_Checklist::META_TICK_ID, Checkoff_Public_Id::normalize( $pw_tick ) );
$pw_list = Checkoff_Checklist::find( $pw_id );
cko_r( false === $pw['protected'], 'password: a new list is not protected' );

list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pw_id . '/password', array( 'password' => 'abc' ) );
cko_r( 400 === $st && 'checkoff_password_length' === $err['code'], 'password: too short is refused' );
list( $st, $ok ) = cko_call( 'PUT', '/checklists/' . $pw_id . '/password', array( 'password' => 'Geheim 123' ) );
cko_r( 200 === $st && true === $ok['protected'], 'password: set by somebody who may edit' );
$hash = get_post_meta( $pw_list->get_post_id(), Checkoff_Checklist::META_PASSWORD, true );
cko_r( '' !== $hash && false === strpos( $hash, 'Geheim' ) && wp_check_password( 'Geheim 123', $hash ), 'password: only WordPress\'s hash is stored' );
cko_r( '' === get_post_field( 'post_password', $pw_list->get_post_id() ), 'password: core\'s plain-text post password is not used' );

// The browser that set it holds the cookie; another one does not.
cko_r( 1 === count( array_filter( array_keys( $_COOKIE ), static fn( $k ) => str_starts_with( $k, Checkoff_Password::COOKIE_PREFIX ) ) ), 'password: the setter is unlocked by a cookie' );
list( $st, $read ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'edit' === $read['access'] && true === $read['protected'], 'password: with the cookie the list opens for editing' );
$saved_cookies = $_COOKIE;
$_COOKIE       = array();

list( $st, $locked ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 200 === $st && 'locked' === $locked['access'] && true === $locked['protected'], 'password: without it the public ID opens locked' );
cko_r( ! isset( $locked['tick_id'] ) && ! isset( $locked['history'] ), 'password: a locked view learns no tick ID' );
cko_r( $locked['id'] === $pw_id, 'password: a locked view keeps its own ID' );
$item = $locked['structure']['items'][0]['id'];
$ls   = $locked['structure'];
$ls['items'][0]['items'] = array( array( 'id' => 'pwitem01', 'kind' => 'item', 'label' => 'X' ) );
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pw_id . '/structure', array( 'structure' => $ls, 'revision' => $locked['revision'] ) );
cko_r( 403 === $st && 'checkoff_locked' === $err['code'], 'password: locked, the structure cannot be changed' );
list( $st, $err ) = cko_call( 'DELETE', '/checklists/' . $pw_id );
cko_r( 403 === $st && 'checkoff_locked' === $err['code'], 'password: locked, the list cannot be deleted' );
list( $st, $err ) = cko_call( 'DELETE', '/checklists/' . $pw_id . '/values' );
cko_r( 403 === $st, 'password: locked, the ticks cannot be cleared' );
list( $st, $err ) = cko_call( 'DELETE', '/checklists/' . $pw_id . '/password' );
cko_r( 403 === $st, 'password: locked, the password cannot be removed' );
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pw_id . '/password', array( 'password' => 'Taken over' ) );
cko_r( 403 === $st, 'password: locked, the password cannot be replaced' );

// Ticking works without the password, with either ID.
$pw_list = Checkoff_Checklist::find( $pw_id );
$pw_s    = $pw_list->get_structure();
$pw_s['items'][0]['items'] = array( array( 'id' => 'pwitem01', 'kind' => 'item', 'label' => 'X' ) );
$pw_list->set_structure( $pw_s, $pw_list->get_revision() );
list( $st, $v ) = cko_call( 'PUT', '/checklists/' . $pw_id . '/values/pwitem01', array( 'value' => true ) );
cko_r( 200 === $st && true === $v['value'], 'password: a locked view still ticks' );
list( $st, $v ) = cko_call( 'PUT', '/checklists/' . $pw_tick . '/values/pwitem01', array( 'value' => null ) );
cko_r( 200 === $st, 'password: the tick ID still ticks' );

// Unlocking.
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'wrong one' ) );
cko_r( 403 === $st && 'checkoff_password_wrong' === $err['code'], 'unlock: a wrong password is refused' );
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $pw_tick . '/unlock', array( 'password' => 'Geheim 123' ) );
cko_r( 403 === $st && 'checkoff_tick_only' === $err['code'], 'unlock: the tick ID stays tick-only, password or not' );
list( $st, $open ) = cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'Geheim 123' ) );
cko_r( 200 === $st && 'edit' === $open['access'] && 'protected' === $open['level'], 'unlock: the right password opens it for editing' );
list( $st, $del ) = cko_call( 'DELETE', '/checklists/' . $pw_id . '/values' );
cko_r( 200 === $st, 'unlock: the cookie carries the unlock to the next request' );
list( $st ) = cko_call( 'DELETE', '/checklists/' . $pw_id . '/unlock' );
list( $st, $relocked ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'locked' === $relocked['access'], 'unlock: locking again forgets it' );

// API clients: the header instead of a cookie.
$_SERVER['HTTP_X_CHECKOFF_PASSWORD'] = 'Geheim 123';
list( $st, $hdr ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'edit' === $hdr['access'], 'header: X-CheckOff-Password unlocks one request' );
$_SERVER['HTTP_X_CHECKOFF_PASSWORD'] = 'nope';
list( $st, $hdr ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'locked' === $hdr['access'], 'header: a wrong one leaves it locked' );
unset( $_SERVER['HTTP_X_CHECKOFF_PASSWORD'] );

// A forged or stale cookie does not unlock; a new password ends every unlock.
cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'Geheim 123' ) );
$cookie_name = array_values( array_filter( array_keys( $_COOKIE ), static fn( $k ) => str_starts_with( $k, Checkoff_Password::COOKIE_PREFIX ) ) )[0] ?? '';
$good        = $_COOKIE[ $cookie_name ] ?? '';
$_COOKIE[ $cookie_name ] = ( time() + DAY_IN_SECONDS ) . '|' . str_repeat( 'a', 32 );
list( $st, $f ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'locked' === $f['access'], 'cookie: a forged signature does not unlock' );
$_COOKIE[ $cookie_name ] = $good;
list( $st, $f ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'edit' === $f['access'], 'cookie: the real one does' );
$other = Checkoff_Checklist::find( $pw_id );
$other->set_password( 'A new secret' );
list( $st, $f ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'locked' === $f['access'], 'cookie: a changed password ends the old unlock' );

// Guessing is limited.
update_option( 'checkoff_unlock_per_hour', Checkoff_Rate_Limit::count( Checkoff_Quota::UNLOCK ) + 2 );
cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'guess 1' ) );
cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'guess 2' ) );
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'A new secret' ) );
cko_r( 429 === $st && 'checkoff_quota_unlock' === $err['code'], 'guessing: after too many wrong passwords even the right one is refused' );
update_option( 'checkoff_unlock_per_hour', 0 );
$_SERVER['REMOTE_ADDR'] = '2001:db8:eeee:' . dechex( wp_rand( 1, 65535 ) ) . '::9';
$list_bucket = 'unlock_list_' . $other->get_post_id();
for ( $i = Checkoff_Rate_Limit::count( $list_bucket, true ); $i < Checkoff_Password::UNLOCK_PER_LIST; $i++ ) {
	Checkoff_Rate_Limit::hit( $list_bucket, true );
}
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'A new secret' ) );
cko_r( 429 === $st, 'guessing: a list attacked from many addresses refuses every unlock for the hour' );

// Removing it (by somebody unlocked; in wp-admin, by the administrator).
delete_transient( 'checkoff_rl_' . substr( hash_hmac( 'sha256', $list_bucket . '|*', wp_salt( 'nonce' ) ), 0, 32 ) );
cko_call( 'POST', '/checklists/' . $pw_id . '/unlock', array( 'password' => 'A new secret' ) );
list( $st, $rm ) = cko_call( 'DELETE', '/checklists/' . $pw_id . '/password' );
cko_r( 200 === $st && false === $rm['protected'], 'password: removed by somebody unlocked' );
$_COOKIE = array();
list( $st, $free ) = cko_call( 'GET', '/checklists/' . $pw_id );
cko_r( 'edit' === $free['access'] && false === $free['protected'] && 'open' === $free['level'], 'password: without it everybody with the ID edits again' );
$_COOKIE = $saved_cookies;

/* --- private: the password guards viewing too ----------------------------------------- */

list( $st, $pv ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest private' ) );
cko_remember( $pv );
$pv_id   = $pv['id'];
$pv_list = Checkoff_Checklist::find( $pv_id );
$pv_s    = $pv_list->get_structure();
$pv_s['items'][0]['items'] = array( array( 'id' => 'pvitem01', 'kind' => 'item', 'label' => 'Secret entry' ) );
$pv_list->set_structure( $pv_s, $pv_list->get_revision() );
$pv_tick = Checkoff_Public_Id::format( Checkoff_Checklist::unique_public_id() );
update_post_meta( $pv_list->get_post_id(), Checkoff_Checklist::META_TICK_ID, Checkoff_Public_Id::normalize( $pv_tick ) );

list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pv_id . '/access', array( 'level' => 'private' ) );
cko_r( 400 === $st && 'checkoff_password_length' === $err['code'], 'private: an open list needs a password to become private' );
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pv_id . '/access', array( 'level' => 'secret' ) );
cko_r( 400 === $st, 'private: an unknown level is refused' );
list( $st, $acc ) = cko_call( 'PUT', '/checklists/' . $pv_id . '/access', array( 'level' => 'private', 'password' => 'Privat 123' ) );
cko_r( 200 === $st && 'private' === $acc['level'] && true === $acc['protected'] && false === $acc['listed'], 'private: set with a password' );
list( $st, $read ) = cko_call( 'GET', '/checklists/' . $pv_id );
cko_r( 200 === $st && 'edit' === $read['access'] && 'private' === $read['level'], 'private: the setter stays unlocked' );

$saved_cookies = $_COOKIE;
$_COOKIE       = array();
list( $st, $err ) = cko_call( 'GET', '/checklists/' . $pv_id );
cko_r( 403 === $st && 'checkoff_private' === $err['code'], 'private: without the password the ID shows nothing' );
cko_r( false === strpos( (string) wp_json_encode( $err ), 'Secret entry' ) && false === strpos( (string) wp_json_encode( $err ), 'T rest private' ), 'private: the refusal names neither title nor entries' );
list( $st ) = cko_call( 'GET', '/checklists/' . $pv_id . '/state' );
cko_r( 403 === $st, 'private: no live update without the password' );
list( $st ) = cko_call( 'PUT', '/checklists/' . $pv_id . '/values/pvitem01', array( 'value' => true ) );
cko_r( 403 === $st, 'private: no ticking without the password' );
$subs_were = get_option( 'checkoff_subscriptions_enabled', '' );
update_option( 'checkoff_subscriptions_enabled', 'on' );
list( $st, $err ) = cko_call( 'POST', '/checklists/' . $pv_id . '/subscriptions', array( 'email' => 'x@checkoff.test', 'consent' => true ) );
cko_r( 403 === $st && 'checkoff_private' === $err['code'], 'private: no subscribing without the password' );
update_option( 'checkoff_subscriptions_enabled', $subs_were );
list( $st, $err ) = cko_call( 'GET', '/checklists/' . $pv_tick );
cko_r( 403 === $st && 'checkoff_private' === $err['code'], 'private: an earlier tick link is sealed too' );

$page = do_shortcode( '[cko_checklist id="' . $pv_id . '"]' );
cko_r( str_contains( $page, '"access":"sealed"' ) && ! str_contains( $page, 'Secret entry' ) && ! str_contains( $page, 'T rest private' ), 'private: the page carries the prompt and nothing of the list' );

$_SERVER['HTTP_X_CHECKOFF_PASSWORD'] = 'Privat 123';
list( $st, $hdr ) = cko_call( 'GET', '/checklists/' . $pv_id );
cko_r( 200 === $st && 'edit' === $hdr['access'], 'private: the header opens it for API clients' );
list( $st, $hdr ) = cko_call( 'GET', '/checklists/' . $pv_tick );
cko_r( 200 === $st && 'tick' === $hdr['access'], 'private: the tick link with the password still only ticks' );
unset( $_SERVER['HTTP_X_CHECKOFF_PASSWORD'] );

list( $st, $err ) = cko_call( 'POST', '/checklists/' . $pv_id . '/unlock', array( 'password' => 'wrong one' ) );
cko_r( 403 === $st && 'checkoff_password_wrong' === $err['code'], 'private: a wrong password is refused' );
list( $st, $open ) = cko_call( 'POST', '/checklists/' . $pv_id . '/unlock', array( 'password' => 'Privat 123' ) );
cko_r( 200 === $st && 'edit' === $open['access'] && 'Secret entry' === $open['structure']['items'][0]['items'][0]['label'], 'private: the password opens it' );

list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pv_id . '/listed' );
cko_r( 400 === $st && 'checkoff_listed_level' === $err['code'], 'private: cannot be shown publicly' );

// Back to protected, keeping the password: the address views again.
list( $st, $acc ) = cko_call( 'PUT', '/checklists/' . $pv_id . '/access', array( 'level' => 'protected' ) );
cko_r( 200 === $st && 'protected' === $acc['level'], 'levels: private to protected keeps the password' );
$_COOKIE = array();
list( $st, $read ) = cko_call( 'GET', '/checklists/' . $pv_id );
cko_r( 200 === $st && 'locked' === $read['access'], 'levels: protected opens locked without the password' );
$_COOKIE = $saved_cookies;

/* --- the public directory ---------------------------------------------------------- */

$in_lists = static function ( string $id ): ?array {
	list( , $dir ) = cko_call( 'GET', '/lists', null, array( 'limit' => 100 ) );
	foreach ( (array) ( $dir['lists'] ?? array() ) as $entry ) {
		if ( $entry['id'] === $id ) {
			return $entry;
		}
	}
	return null;
};

list( $st, $pub ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest public' ) );
cko_remember( $pub );
$pub_id = $pub['id'];
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pub_id . '/listed' );
cko_r( 400 === $st && 'checkoff_listed_level' === $err['code'], 'directory: an open list cannot be shown (anybody could delete it)' );
cko_call( 'PUT', '/checklists/' . $pub_id . '/access', array( 'level' => 'protected', 'password' => 'Public 123' ) );
list( $st, $acc ) = cko_call( 'PUT', '/checklists/' . $pub_id . '/listed' );
cko_r( 200 === $st && true === $acc['listed'], 'directory: a protected list is shown' );
$entry = $in_lists( $pub_id );
cko_r( null !== $entry && 'T rest public' === $entry['title'] && isset( $entry['progress']['total'], $entry['url'] ), 'directory: listed with title, progress and address' );
cko_r( ! isset( $entry['post_id'] ) && false === strpos( (string) wp_json_encode( $entry ), 'tick' ), 'directory: no post ID, no other ID' );
cko_r( str_contains( do_shortcode( '[cko_lists]' ), 'T rest public' ), 'directory: [cko_lists] shows it' );

update_option( 'checkoff_lists_enabled', '' );
list( $st, $dir ) = cko_call( 'GET', '/lists' );
cko_r( false === $dir['enabled'] && array() === $dir['lists'] && '' === do_shortcode( '[cko_lists]' ), 'directory: switched off, it is empty' );
delete_option( 'checkoff_lists_enabled' );
cko_r( null !== $in_lists( $pub_id ), 'directory: switched on again, the list is back' );

list( $st, $acc ) = cko_call( 'PUT', '/checklists/' . $pub_id . '/access', array( 'level' => 'private' ) );
cko_r( 'private' === $acc['level'] && false === $acc['listed'] && null === $in_lists( $pub_id ), 'directory: a list made private leaves it' );
cko_call( 'PUT', '/checklists/' . $pub_id . '/access', array( 'level' => 'protected' ) );
cko_r( null === $in_lists( $pub_id ), 'directory: and does not come back by itself' );
cko_call( 'PUT', '/checklists/' . $pub_id . '/listed' );
list( $st, $acc ) = cko_call( 'DELETE', '/checklists/' . $pub_id . '/listed' );
cko_r( 200 === $st && false === $acc['listed'] && null === $in_lists( $pub_id ), 'directory: the owner takes it out' );
cko_call( 'PUT', '/checklists/' . $pub_id . '/listed' );
cko_call( 'PUT', '/checklists/' . $pub_id . '/access', array( 'level' => 'open' ) );
cko_r( null === $in_lists( $pub_id ) && '' === get_post_meta( Checkoff_Checklist::find( $pub_id )->get_post_id(), Checkoff_Checklist::META_LISTED, true ), 'directory: a list made open leaves it for good' );

cko_call( 'PUT', '/checklists/' . $pub_id . '/access', array( 'level' => 'protected', 'password' => 'Public 123' ) );
$_COOKIE = array();
list( $st, $err ) = cko_call( 'PUT', '/checklists/' . $pub_id . '/listed' );
cko_r( 403 === $st && 'checkoff_locked' === $err['code'], 'directory: showing a list needs the password' );
$_COOKIE = $saved_cookies;

/* --- quotas ------------------------------------------------------------------------- */

list( $st, $q ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest quota' ) );
cko_remember( $q );
$q_item = 'quotait1';
$q_s    = $q['structure'];
$q_s['items'][0]['items'] = array( array( 'id' => $q_item, 'kind' => 'item', 'label' => 'Q' ) );
list( $st, $q ) = cko_call( 'PUT', '/checklists/' . $q['id'] . '/structure', array( 'structure' => $q_s, 'revision' => $q['revision'] ) );

update_option( 'checkoff_ticks_per_hour', Checkoff_Rate_Limit::count( Checkoff_Quota::TICK ) + 2 );
list( $st1 ) = cko_call( 'PUT', '/checklists/' . $q['id'] . '/values/' . $q_item, array( 'value' => true ) );
list( $st2 ) = cko_call( 'PUT', '/checklists/' . $q['id'] . '/values/' . $q_item, array( 'value' => null ) );
$req  = new WP_REST_Request( 'PUT', '/checkoff/v1/checklists/' . $q['id'] . '/values/' . $q_item );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_body( '{"value":true}' );
$resp = rest_get_server()->dispatch( $req );
$resp = apply_filters( 'rest_post_dispatch', rest_ensure_response( $resp ), rest_get_server(), $req );
cko_r( 200 === $st1 && 200 === $st2 && 429 === $resp->get_status(), 'quota: ticks are refused once the hourly quota is used up' );
cko_r( (int) ( $resp->get_headers()['Retry-After'] ?? 0 ) > 0, 'quota: the refusal says when to retry' );
cko_r( 'checkoff_quota_tick' === $resp->get_data()['code'], 'quota: the refusal names the quota' );
list( $st, $read ) = cko_call( 'GET', '/checklists/' . $q['id'] );
cko_r( 200 === $st, 'quota: reading is not counted against ticking' );
update_option( 'checkoff_ticks_per_hour', 0 );

update_option( 'checkoff_edits_per_hour', Checkoff_Rate_Limit::count( Checkoff_Quota::EDIT ) );
list( $st, $err ) = cko_call( 'PATCH', '/checklists/' . $q['id'], array( 'revision' => $q['revision'], 'title' => 'T q' ) );
cko_r( 429 === $st && 'checkoff_quota_edit' === $err['code'], 'quota: structure changes have their own quota' );
list( $st, $err ) = cko_call( 'DELETE', '/checklists/' . $q['id'] );
cko_r( 429 === $st, 'quota: deleting counts as a change' );
update_option( 'checkoff_edits_per_hour', 0 );

update_option( 'checkoff_create_total_per_hour', Checkoff_Rate_Limit::count( Checkoff_Quota::CREATE, true ) + 1 );
list( $st, $t1 ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest total 1' ) );
cko_remember( $t1 );
$_SERVER['REMOTE_ADDR'] = '2001:db8:ffff:' . dechex( wp_rand( 1, 65535 ) ) . '::7';
list( $st2, $t2 ) = cko_call( 'POST', '/checklists', array( 'title' => 'T rest total 2' ) );
cko_remember( $t2 );
cko_r( 201 === $st && 429 === $st2, 'quota: the total for all visitors stops a second address too' );
update_option( 'checkoff_create_total_per_hour', 0 );

/* --- headers ---------------------------------------------------------------------------- */

$req  = new WP_REST_Request( 'GET', '/checkoff/v1/checklists/' . $q['id'] );
$resp = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_get_server()->dispatch( $req ) ), rest_get_server(), $req );
$hdr  = $resp->get_headers();
cko_r( false !== strpos( $hdr['Cache-Control'] ?? '', 'no-store' ), 'headers: answers are never cached' );
cko_r( 'no-referrer' === ( $hdr['Referrer-Policy'] ?? '' ) && false !== strpos( $hdr['X-Robots-Tag'] ?? '', 'noindex' ), 'headers: no referrer, no index' );

/* --- access ----------------------------------------------------------------------------- */

update_option( 'checkoff_rest_access', 'users' );
list( $st, $err ) = cko_call( 'GET', '/checklists/' . $q['id'] );
cko_r( 401 === $st && 'checkoff_rest_login' === $err['code'], 'access: logged-in users only refuses a guest' );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
list( $st, $read ) = cko_call( 'GET', '/checklists/' . $q['id'] );
cko_r( 200 === $st, 'access: a logged-in user gets through' );
wp_set_current_user( 0 );
update_option( 'checkoff_rest_access', 'public' );

delete_option( 'checkoff_rest_access' );
update_option( 'checkoff_rest_enabled', '' );
cko_r( 'off' === Checkoff_Rest_Api::access(), 'access: the 1.6 switch is still honoured until the new setting is saved' );
update_option( 'checkoff_rest_enabled', 'on' );
cko_r( 'public' === Checkoff_Rest_Api::access(), 'access: 1.6 "on" reads as everybody' );

/* --- switched off --------------------------------------------------------------------- */

update_option( 'checkoff_rest_access', 'off' );
$GLOBALS['wp_rest_server'] = null;
cko_r( ! isset( rest_get_server()->get_routes()['/checkoff/v1/checklists'] ), 'switched off: no routes' );
update_option( 'checkoff_rest_access', 'public' );
$GLOBALS['wp_rest_server'] = null;
rest_get_server();

/* --- done ------------------------------------------------------------------------------ */

foreach ( $cko_r_created as $post_id ) {
	if ( get_post( $post_id ) ) {
		( new Checkoff_Checklist( $post_id ) )->delete();
	}
}

foreach ( $cko_r_options as $name => $value ) {
	if ( null === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

restore_previous_locale();

echo "{$cko_r_pass} passed, {$cko_r_fail} failed\n";

if ( $cko_r_fail > 0 ) {
	exit( 1 );
}
