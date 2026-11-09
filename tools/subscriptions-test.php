<?php
/**
 * Assertions for repetition, rounds, the mailer and e-mail subscriptions.
 *
 * Runs against a real install and creates its own checklists and
 * subscriptions (titled "T …"), deleting them again. Mails are caught with
 * the pre_wp_mail filter — nothing leaves the machine.
 *
 *   wp eval-file wp-content/plugins/checkoff/tools/subscriptions-test.php
 *
 * @package CheckOff
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $cko_fail, $cko_pass, $cko_mails, $cko_created;

$cko_fail    = 0;
$cko_pass    = 0;
$cko_mails   = array();
$cko_created = array();

function cko_t( bool $ok, string $message ): void {
	global $cko_fail, $cko_pass;
	if ( $ok ) {
		++$cko_pass;
		return;
	}
	++$cko_fail;
	echo "FAIL: {$message}\n";
}

function cko_ts( string $local ): int {
	return ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
}

function cko_list( array $structure ): Checkoff_Checklist {
	global $cko_created;
	$list = Checkoff_Checklist::create( 'T sub', 'simple' );
	$cko_created[] = $list->get_post_id();
	// Written in the short form (one block's fields at the top): one block in the container.
	if ( 'checklist' !== ( $structure['kind'] ?? '' ) ) {
		$structure = array(
			'kind'       => 'checklist',
			'title'      => $structure['title'] ?? '',
			'recurrence' => $structure['recurrence'] ?? null,
			'items'      => array(
				array(
					'id'       => 'blk' . wp_rand( 100000, 999999 ),
					'kind'     => 'block',
					'type'     => $structure['type'] ?? 'simple',
					'title'    => '',
					'settings' => $structure['settings'] ?? array(),
					'items'    => $structure['items'] ?? array(),
				),
			),
		);
	}
	$structure['id'] = $list->get_structure()['id'];
	$list->set_structure( $structure, $list->get_revision() );
	return new Checkoff_Checklist( $list->get_post_id() );
}

// A run cut short leaves its checklists behind, titled "T …"; their active
// subscriptions would be mailed along with this run's and skew every count.
foreach ( get_posts( array( 'post_type' => 'cko_checklist', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'T ' ) ) as $leftover ) {
	if ( str_starts_with( $leftover->post_title, 'T ' ) ) {
		wp_delete_post( $leftover->ID, true );
	}
}

// The assertions read English subjects; run in the source language.
switch_to_locale( 'en_US' );

// A visitor of its own (an IPv6 /64 is one visitor), so quotas of earlier runs do not count.
$_SERVER['REMOTE_ADDR'] = '2001:db8:' . dechex( wp_rand( 1, 65535 ) ) . ':' . dechex( wp_rand( 1, 65535 ) ) . '::1';

// The hourly limit per address would stop repeated test runs.
add_filter( 'checkoff_mail_rate_limit_per_hour', '__return_zero' );

add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) {
		global $cko_mails;
		// Only this run's addresses: subscriptions left on the site by
		// somebody else are mailed by the dispatch too.
		if ( str_ends_with( (string) $atts['to'], '@checkoff.test' ) ) {
			$cko_mails[] = $atts;
		}
		return true;
	},
	10,
	2
);

$saved_options = array();
foreach ( array( 'checkoff_subscriptions_enabled', 'checkoff_mail_rules', 'checkoff_mail_log_enabled', 'checkoff_subscriptions_per_checklist', 'checkoff_mail_log' ) as $opt ) {
	$saved_options[ $opt ] = get_option( $opt, null );
}
update_option( 'checkoff_subscriptions_enabled', 'on' );
delete_option( 'checkoff_mail_rules' );

/* --- repetition ------------------------------------------------------------ */

cko_t( 'none' === Checkoff_Recurrence::sanitize( array( 'freq' => 'hourly' ) )['freq'], 'unknown frequency turns repetition off' );
cko_t( 'none' === Checkoff_Recurrence::sanitize( 'x' )['freq'], 'non-array rule turns repetition off' );

$r = Checkoff_Recurrence::sanitize( array( 'freq' => 'weekly', 'start' => '2026-03-10', 'end' => 'until', 'until' => '2026-03-01' ) );
cko_t( 'never' === $r['end'], 'an end date before the start falls back to never' );

$r = Checkoff_Recurrence::sanitize( array( 'freq' => 'daily', 'start' => '2026-02-30' ) );
cko_t( wp_date( 'Y-m-d' ) === $r['start'], 'an impossible start date becomes today' );

$r = Checkoff_Recurrence::sanitize( array( 'freq' => 'daily', 'interval' => 9999, 'start' => '2026-01-01', 'end' => 'count', 'count' => 5000 ) );
cko_t( 365 === $r['interval'] && 1000 === $r['count'], 'interval and count are clamped' );

$rec = new Checkoff_Recurrence( array( 'freq' => 'daily', 'interval' => 2, 'start' => '2026-01-01' ) );
$c   = $rec->current( cko_ts( '2026-01-05 12:00' ) );
cko_t( 2 === $c['index'] && 3 === $c['number'], 'daily every 2 days: 5 Jan is round 3' );
cko_t( cko_ts( '2026-01-05 00:00' ) === $c['start'] && cko_ts( '2026-01-07 00:00' ) === $c['end'], 'daily every 2 days: round 3 runs 5–6 Jan' );
cko_t( null === $c['total'] && ! $c['finished'], 'an endless series has no total and never finishes' );

$c = $rec->current( cko_ts( '2025-12-20 10:00' ) );
cko_t( 0 === $c['index'] && $c['before_start'], 'before the start the first round is in force' );

$rec = new Checkoff_Recurrence( array( 'freq' => 'weekly', 'start' => '2026-01-01', 'end' => 'count', 'count' => 3 ) );
cko_t( 3 === $rec->total(), 'count: total is the count' );
$c = $rec->current( cko_ts( '2026-01-16 09:00' ) );
cko_t( 2 === $c['index'] && $c['last'] && ! $c['finished'], 'count: third week is the last round, still running' );
$c = $rec->current( cko_ts( '2026-02-20 09:00' ) );
cko_t( 2 === $c['index'] && $c['finished'], 'count: after the last round the series is finished and stays on it' );

$rec = new Checkoff_Recurrence( array( 'freq' => 'weekly', 'start' => '2026-01-01', 'end' => 'until', 'until' => '2026-01-20' ) );
cko_t( 3 === $rec->total(), 'until: rounds starting on or before the date count (1, 8, 15 Jan)' );

$rec = new Checkoff_Recurrence( array( 'freq' => 'monthly', 'start' => '2026-01-31' ) );
cko_t( '2026-02-28' === $rec->round_start( 1 )->format( 'Y-m-d' ), 'monthly from 31 Jan: February round starts on its last day' );
cko_t( '2026-03-31' === $rec->round_start( 2 )->format( 'Y-m-d' ), 'monthly from 31 Jan: March round starts on 31 March' );
cko_t( 1 === $rec->current( cko_ts( '2026-03-30 10:00' ) )['index'], 'monthly: 30 March still belongs to the February round' );
cko_t( 2 === $rec->current( cko_ts( '2026-03-31 00:00' ) )['index'], 'monthly: 31 March starts the March round' );

$rec = new Checkoff_Recurrence( array( 'freq' => 'monthly', 'interval' => 3, 'start' => '2026-01-15' ) );
cko_t( 1 === $rec->current( cko_ts( '2026-06-01 10:00' ) )['index'], 'quarterly: June is in the second round' );

cko_t( '' !== ( new Checkoff_Recurrence( array( 'freq' => 'weekly', 'start' => '2026-01-01', 'end' => 'count', 'count' => 6 ) ) )->describe(), 'a rule can be put in words' );

/* --- rounds on a checklist ---------------------------------------------- */

$start = wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
$list  = cko_list(
	array(
		'type'       => 'simple',
		'title'      => 'T rounds',
		'recurrence' => array( 'freq' => 'daily', 'start' => $start ),
		'items'      => array(
			array( 'id' => 'rnd00001', 'kind' => 'item', 'label' => 'A' ),
			array( 'id' => 'rnd00002', 'kind' => 'item', 'label' => 'B' ),
			array(
				'id'       => 'rnd00003',
				'kind'     => 'block',
				'type'     => 'weekdays',
				'title'    => 'Sub',
				'settings' => array( 'days' => array( 1, 2 ), 'reset' => 'none' ),
				'items'    => array( array( 'id' => 'rnd00004', 'kind' => 'item', 'label' => 'C' ) ),
			),
		),
	)
);

cko_t( 'daily' === $list->get_structure()['recurrence']['freq'], 'the rule is stored on the top level' );
cko_t( 3 === $list->round_info()['number'], 'checklist started two days ago, daily: round 3' );

$list->set_value( 'rnd00001', true );
$list->set_value( 'rnd00004', array( 'days' => array( 1 ) ) );
$p = $list->progress();
cko_t( 2 === $p['done'] && 4 === $p['total'], 'server progress: 1 tick + 1 of 2 weekdays of 4 steps' );
cko_t( 50 === $p['percent'], 'server progress: percent' );
$open = $list->open_items();
cko_t( 2 === count( $open ) && 'B' === $open[0]['label'] && 'Sub' === $open[1]['path'], 'open entries carry the list they are in' );

// What is ticked ahead for the next round goes into the mails.
cko_t( '' === Checkoff_Subscriptions::mail_vars( Checkoff_Subscriptions::checklist_data( $list ), '' )['ahead_html'], 'nothing ticked ahead: no ahead block' );
$list->set_value( 'rnd00002', true, null, $list->round_info()['number'] + 1 );
$vars = Checkoff_Subscriptions::mail_vars( Checkoff_Subscriptions::checklist_data( $list ), '' );
cko_t( str_contains( $vars['ahead_html'], '<li>B' ) && '1' === $vars['ahead_count'] && ! str_contains( $vars['ahead_html'], '<li>A' ), 'the mail lists what is ticked ahead for the next round' );
cko_t( str_contains( Checkoff_Mailer::render_template( 'reminder.html', $vars ), '<li>B' ), 'the reminder shows it' );
Checkoff_Values::clear( $list->get_post_id(), $list->round_index() + 1 );

// Pretend the values are from yesterday's round: they move one round back.
Checkoff_Values::move( $list->get_post_id(), $list->round_index(), $list->round_index() - 1 );
$list = new Checkoff_Checklist( $list->get_post_id() );
cko_t( array() === $list->get_values(), 'a new round reads as empty: it has no rows yet' );
$last = $list->get_last_round();
cko_t( 2 === $last['number'] && 2 === $last['done'] && 4 === $last['total'] && 2 === count( $last['open'] ), '"last time" is computed from the round before' );
cko_t( isset( Checkoff_Values::rows( $list->get_post_id(), $list->round_index() - 1 )['rnd00001'] ), 'the rows of the round before stay where they are' );

$client = $list->to_client();
cko_t( 2 === $client['last']['number'] && ! isset( $client['last']['open'] ), 'the browser gets the summary numbers, not the open entries' );

// Changing the rule keeps what is ticked now.
$list->set_value( 'rnd00002', true );
$s                         = $list->get_structure();
$s['recurrence']['freq']   = 'weekly';
$list->set_structure( $s, $list->get_revision() );
$list = new Checkoff_Checklist( $list->get_post_id() );
cko_t( isset( $list->get_values()['rnd00002'] ), 'changing the rule keeps the current ticks' );

// Without repetition nothing rolls.
$plain = cko_list( array( 'type' => 'simple', 'title' => 'T plain', 'items' => array( array( 'id' => 'pln00001', 'kind' => 'item', 'label' => 'x' ) ) ) );
cko_t( ! $plain->round_info()['repeats'] && null === $plain->get_last_round() && 0 === $plain->round_index(), 'a checklist without repetition has no rounds' );

/* --- mailer ---------------------------------------------------------------- */

$out = Checkoff_Mailer::render(
	'{{ a }}|{{ b_html }}|{{ c_url }}|{{ missing }}',
	array(
		'a'      => '<b>x</b>',
		'b_html' => '<b>y</b><script>z</script>',
		'c_url'  => 'javascript:alert(1)',
	)
);
cko_t( '&lt;b&gt;x&lt;/b&gt;|<b>y</b>z||' === $out, 'placeholders are escaped by their suffix, unknown ones vanish: ' . $out );

cko_t( 'jo***@ex***.de' === Checkoff_Mailer::mask_email( 'john@example.de' ), 'addresses are masked' );
cko_t( '' === Checkoff_Mailer::locate_template( '../wp-config.php' ), 'template names cannot leave the folder' );
cko_t( ! in_array( 'layout.html', Checkoff_Mailer::list_templates(), true ) && in_array( 'reminder.html', Checkoff_Mailer::list_templates(), true ), 'rules can choose templates but not the layout' );

switch_to_locale( 'de_DE' );
cko_t( str_contains( Checkoff_Mailer::locate_template( 'confirm.html' ), '/de_DE/' ), 'the German template is used on a German site' );
restore_previous_locale();

$html = Checkoff_Mailer::render_template( 'reminder.html', array( 'checklist' => 'X' ), array( 'layout' => 'layout.html', 'unsubscribe_url' => 'https://example.org/u' ) );
cko_t( ! str_contains( (string) $html, '<!--' ), 'template comments are not sent' );
cko_t( str_contains( (string) $html, 'https://example.org/u' ), 'the layout shows the unsubscribe link' );

/* --- rules ----------------------------------------------------------------- */

$rules = Checkoff_Subscriptions::sanitize_rules(
	array(
		array( 'trigger' => 'nope', 'time' => '08:00', 'template' => 'reminder.html' ),
		array( 'trigger' => 'on_confirm', 'time' => '08:00', 'template' => 'welcome.html', 'only_open' => 'on' ),
		array( 'trigger' => 'each_day', 'time' => '7:5', 'template' => 'reminder.html' ),
		array( 'trigger' => 'each_day', 'time' => '7:05', 'template' => 'layout.html' ),
		array( 'trigger' => 'round_end', 'time' => '09:00', 'template' => 'round-summary.html', 'only_open' => 'on' ),
		array( 'trigger' => 'each_day', 'time' => '7:05', 'template' => 'reminder.html', 'only_open' => 'on', 'subject' => '' ),
	)
);
cko_t( 3 === count( $rules ), 'unknown triggers, bad times and the layout are refused' );
cko_t( '' === $rules[0]['time'] && '' === $rules[0]['only_open'], 'on_confirm has no time and no condition' );
cko_t( '' === $rules[1]['only_open'], '"only if open" is dropped where it means nothing' );
cko_t( '07:05' === $rules[2]['time'] && 'on' === $rules[2]['only_open'] && '{{ checklist }}' === $rules[2]['subject'], 'times are normalised, empty subjects get a default' );

/* --- subscribing ------------------------------------------------------------- */

$now   = time();
$today = wp_date( 'Y-m-d', $now );
$sub_list = cko_list(
	array(
		'type'       => 'simple',
		'title'      => 'T subscribed',
		'recurrence' => array( 'freq' => 'daily', 'start' => wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ), 'end' => 'count', 'count' => 3 ),
		'items'      => array(
			array( 'id' => 'sub00001', 'kind' => 'item', 'label' => 'One' ),
			array( 'id' => 'sub00002', 'kind' => 'item', 'label' => 'Two' ),
		),
	)
);

cko_t( is_wp_error( Checkoff_Subscriptions::subscribe( $sub_list, 'not-an-address' ) ), 'an invalid address is refused' );

$cko_mails = array();
cko_t( true === Checkoff_Subscriptions::subscribe( $sub_list, 'Tester@Checkoff.test' ), 'a valid address subscribes' );
$sub_id = Checkoff_Subscriptions::find( $sub_list->get_post_id(), 'tester@checkoff.test' );
cko_t( $sub_id > 0, 'the subscription is found by its address, lower-cased' );
cko_t( 1 === count( $cko_mails ) && str_contains( $cko_mails[0]['message'], 'cko_sub=confirm' ), 'a confirmation mail with a confirm link is sent' );
cko_t( 'tester@checkoff.test' === $cko_mails[0]['to'], 'it goes to that address only' );

$meta_keys = array_keys( get_post_meta( $sub_id ) );
sort( $meta_keys );
cko_t( array( '_cko_sub_checklist', '_cko_sub_email', '_cko_sub_status' ) === $meta_keys, 'a pending subscription stores address, checklist and status — nothing else: ' . implode( ',', $meta_keys ) );
cko_t( 'te***@ch***.test' === get_the_title( $sub_id ), 'the title is the masked address' );
cko_t( ! str_contains( (string) get_post_field( 'post_content', $sub_id ), '@' ), 'the address is nowhere in the post itself' );

$cko_mails = array();
cko_t( true === Checkoff_Subscriptions::subscribe( $sub_list, 'tester@checkoff.test' ), 'subscribing again answers the same' );
cko_t( 1 === Checkoff_Subscriptions::count_for_checklist( $sub_list->get_post_id() ), 'subscribing again creates nothing' );
cko_t( 1 === count( $cko_mails ), 'a pending subscription gets its confirmation again' );

update_option( 'checkoff_subscriptions_per_checklist', 1 );
cko_t( is_wp_error( Checkoff_Subscriptions::subscribe( $sub_list, 'other@checkoff.test' ) ), 'the limit per checklist holds' );
delete_option( 'checkoff_subscriptions_per_checklist' );

// Tokens.
$link = Checkoff_Subscriptions::link( $sub_id, 'confirm' );
cko_t( str_contains( $link, 's=' . $sub_id ) && str_contains( $link, 't=' . Checkoff_Subscriptions::token( $sub_id, 'confirm' ) ), 'the link carries subscription and token' );
cko_t( Checkoff_Subscriptions::token( $sub_id, 'confirm' ) !== Checkoff_Subscriptions::token( $sub_id, 'unsubscribe' ), 'confirm and unsubscribe tokens differ' );
cko_t( 32 === strlen( Checkoff_Subscriptions::token( $sub_id, 'confirm' ) ), 'tokens are 128 bits' );

// Confirmation: at 00:05 today.
$cko_mails = array();
Checkoff_Subscriptions::confirm( $sub_id, cko_ts( $today . ' 00:05' ) );
cko_t( 'active' === get_post_meta( $sub_id, '_cko_sub_status', true ), 'confirming activates' );
cko_t( cko_ts( $today . ' 00:05' ) === (int) get_post_meta( $sub_id, '_cko_sub_confirmed', true ), 'the time of consent is kept' );
cko_t( 1 === count( $cko_mails ) && str_contains( $cko_mails[0]['subject'], 'T subscribed' ), 'the welcome mail goes out on confirmation' );
cko_t( preg_grep( '/^List-Unsubscribe-Post: List-Unsubscribe=One-Click$/', $cko_mails[0]['headers'] ) !== array(), 'subscription mails offer one-click unsubscribe' );

/* --- dispatch -------------------------------------------------------------- */

$subs = new Checkoff_Subscriptions();

$cko_mails = array();
$subs->dispatch( cko_ts( $today . ' 07:00' ) );
cko_t( 0 === count( $cko_mails ), 'nothing is due before 08:00' );

$subs->dispatch( cko_ts( $today . ' 08:10' ) );
cko_t( 1 === count( $cko_mails ) && str_contains( $cko_mails[0]['subject'], 'new round' ), 'the round-start mail goes out after 08:00' );
cko_t( str_contains( $cko_mails[0]['message'], 'cko_sub=unsubscribe' ), 'it carries the unsubscribe link' );

$cko_mails = array();
$subs->dispatch( cko_ts( $today . ' 08:25' ) );
cko_t( 0 === count( $cko_mails ), 'nothing is sent twice' );

// Reminder at 18:00 on the last day of the round (daily: today), only if open.
$sub_list = new Checkoff_Checklist( $sub_list->get_post_id() );
$sub_list->set_value( 'sub00001', true );
$subs->dispatch( cko_ts( $today . ' 18:05' ) );
cko_t( 1 === count( $cko_mails ) && str_contains( $cko_mails[0]['message'], 'Two' ) && ! str_contains( $cko_mails[0]['message'], '>One<' ), 'the reminder lists what is still open' );

// Next day: everything done in the new round → no reminder, but settled.
$tomorrow  = wp_date( 'Y-m-d', $now + DAY_IN_SECONDS );
$cko_mails = array();
$subs->dispatch( cko_ts( $tomorrow . ' 08:05' ) );
cko_t( 1 === count( $cko_mails ), 'the next morning: the third round starts' );
cko_t( str_contains( $cko_mails[0]['message'], '1 of 2' ) || str_contains( $cko_mails[0]['message'], '1 von 2' ), 'the round-start mail says how the last round went' );

// Pretend the dispatch had run tomorrow: the values now belong to round 3.
$sub_list = new Checkoff_Checklist( $sub_list->get_post_id() );
$tomorrow_round = $sub_list->round_index( cko_ts( $tomorrow . ' 12:00' ) );
Checkoff_Values::set_value( $sub_list->get_post_id(), $tomorrow_round, 'sub00001', true );
Checkoff_Values::set_value( $sub_list->get_post_id(), $tomorrow_round, 'sub00002', true );
$cko_mails = array();
$subs->dispatch( cko_ts( $tomorrow . ' 18:05' ) );
cko_t( 0 === count( $cko_mails ), 'no reminder when everything is done: ' . implode( ' / ', array_column( $cko_mails, 'subject' ) ) );
$sent_map = get_post_meta( $sub_id, '_cko_sub_sent', true );
cko_t( count( array_filter( array_keys( $sent_map ), static fn( $k ) => str_starts_with( $k, 'round_last_day' ) ) ) >= 2, 'a skipped reminder is settled, not retried' );

// The series is over after the third round: the day after tomorrow at 09:00.
$after     = wp_date( 'Y-m-d', $now + 2 * DAY_IN_SECONDS );
$cko_mails = array();
$subs->dispatch( cko_ts( $after . ' 09:05' ) );
cko_t( 1 === count( $cko_mails ) && str_contains( $cko_mails[0]['subject'], 'Done' ), 'the series-end mail goes out once the last round is over' );
$cko_mails = array();
$subs->dispatch( cko_ts( $after . ' 10:05' ) );
cko_t( 0 === count( $cko_mails ), 'and only once' );

// Grace: a mail more than a day late is skipped.
update_option(
	'checkoff_mail_rules',
	array( array( 'trigger' => 'each_day', 'time' => '06:00', 'subject' => 'Daily', 'template' => 'reminder.html' ) )
);
$cko_mails = array();
$subs->dispatch( cko_ts( $after . ' 05:00' ) + 2 * DAY_IN_SECONDS );
cko_t( 0 === count( $cko_mails ), 'nothing is sent for days missed long ago, and today\'s is not due yet' );
$subs->dispatch( cko_ts( $after . ' 06:30' ) + 2 * DAY_IN_SECONDS );
cko_t( 1 === count( $cko_mails ), 'today\'s daily mail goes out' );
delete_option( 'checkoff_mail_rules' );

// Subscriptions switched off: nothing is sent.
update_option( 'checkoff_subscriptions_enabled', '' );
$cko_mails = array();
$subs->dispatch( cko_ts( $after . ' 23:00' ) + 5 * DAY_IN_SECONDS );
cko_t( 0 === count( $cko_mails ), 'nothing is sent while subscriptions are off' );
update_option( 'checkoff_subscriptions_enabled', 'on' );

/* --- deleting ---------------------------------------------------------------- */

Checkoff_Subscriptions::unsubscribe( $sub_id );
cko_t( null === get_post( $sub_id ), 'unsubscribing deletes the subscription with its address' );
cko_t( 0 === Checkoff_Subscriptions::find( $sub_list->get_post_id(), 'tester@checkoff.test' ), 'the address is gone' );

Checkoff_Subscriptions::subscribe( $sub_list, 'gone@checkoff.test' );
$gone = Checkoff_Subscriptions::find( $sub_list->get_post_id(), 'gone@checkoff.test' );
wp_delete_post( $sub_list->get_post_id(), true );
cko_t( null === get_post( $gone ), 'deleting the checklist deletes its subscriptions' );

$other = cko_list( array( 'type' => 'simple', 'title' => 'T other', 'items' => array() ) );
Checkoff_Subscriptions::subscribe( $other, 'late@checkoff.test' );
$late = Checkoff_Subscriptions::find( $other->get_post_id(), 'late@checkoff.test' );
wp_update_post( array( 'ID' => $late, 'post_date' => wp_date( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
cko_t( 1 === Checkoff_Subscriptions::delete_expired_pending( time() ) && null === get_post( $late ), 'unconfirmed subscriptions expire' );

Checkoff_Subscriptions::subscribe( $other, 'erase@checkoff.test' );
$result = $subs->erase( 'erase@checkoff.test' );
cko_t( 1 === $result['items_removed'] && 0 === Checkoff_Subscriptions::find( $other->get_post_id(), 'erase@checkoff.test' ), 'the privacy eraser deletes by address' );

Checkoff_Subscriptions::subscribe( $other, 'trash@checkoff.test' );
$trash = Checkoff_Subscriptions::find( $other->get_post_id(), 'trash@checkoff.test' );
wp_trash_post( $trash );
cko_t( null === get_post( $trash ), 'a subscription moved to the trash is deleted for good' );

/* --- send log -------------------------------------------------------------- */

update_option( 'checkoff_mail_log_enabled', 'on' );
Checkoff_Mail_Log::clear();
Checkoff_Mailer::send( 'logged@checkoff.test', 'Hello', 'Body' );
$entry = Checkoff_Mail_Log::get_entries()[0] ?? array();
cko_t( 'lo***@ch***.test' === ( $entry['to'] ?? '' ) && ! isset( $entry['body'] ), 'the log keeps a masked address and no body' );
update_option( 'checkoff_mail_log_enabled', '' );
Checkoff_Mail_Log::prune();
cko_t( array() === Checkoff_Mail_Log::get_entries(), 'switching the log off empties it' );

/* --- privacy text ---------------------------------------------------------- */

switch_to_locale( 'en_US' );
cko_t( str_contains( Checkoff_Privacy::policy_text(), 'double opt-in' ), 'the privacy text describes the subscriptions while they are on' );
update_option( 'checkoff_subscriptions_enabled', '' );
cko_t( ! str_contains( Checkoff_Privacy::policy_text(), 'double opt-in' ), 'and leaves them out while they are off' );
restore_previous_locale();

/* --- done ------------------------------------------------------------------ */

foreach ( $cko_created as $post_id ) {
	wp_delete_post( $post_id, true );
}

foreach ( $saved_options as $opt => $value ) {
	null === $value ? delete_option( $opt ) : update_option( $opt, $value );
}

restore_previous_locale();

echo "{$cko_pass} passed, {$cko_fail} failed\n";

if ( $cko_fail > 0 ) {
	exit( 1 );
}
