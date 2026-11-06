<?php
/**
 * Building block: checklist with due dates.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries to tick off by a date: moving house, a wedding, the tax return.
 * Open entries past their date are overdue, those within the warning days
 * due soon; the mails name both.
 *
 * The date belongs to the entry, not to its value: it is set while editing
 * and stored in the structure (`data.due`), so a tick link cannot move it
 * and a new round of a repeating list keeps it.
 */
class Checkoff_Block_Deadline extends Checkoff_Block_Simple {

	const MAX_SOON = 30;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'deadline';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Checklist with due dates', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Every entry gets a date by which it should be done; overdue entries are marked.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '⏰';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {
		return array( 'soon' => 2 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'         => 'soon',
				'type'        => 'number',
				'label'       => __( 'Mark as due soon', 'checkoff' ),
				'description' => __( 'days before the date (0 = off)', 'checkoff' ),
				'min'         => 0,
				'max'         => self::MAX_SOON,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();

		return array( 'soon' => self::clamp_int( $settings['soon'] ?? 2, 0, self::MAX_SOON, 2 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The due date, as Y-m-d; anything that is not a real date is dropped.
	 */
	public function sanitize_item_data( mixed $data, array $settings ): array {

		$due = is_array( $data ) && is_string( $data['due'] ?? null ) ? $data['due'] : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return array();
		}

		return array( 'due' => $due );
	}

	/**
	 * {@inheritDoc}
	 *
	 * "overdue since …", "due today", "due on …" for an open entry with a date.
	 */
	public function describe_item( array $item, mixed $value, array $settings, ?int $now = null ): string {

		$due = (string) ( $item['data']['due'] ?? '' );

		if ( null !== $value || '' === $due ) {
			return $this->describe_value( $value, $settings );
		}

		$now   = $now ?? time();
		$today = (string) wp_date( 'Y-m-d', $now );
		$date  = Checkoff_Recurrence::format_date( ( new DateTimeImmutable( $due . ' 12:00', wp_timezone() ) )->getTimestamp() );

		if ( $due < $today ) {
			/* translators: %s: date. */
			return sprintf( __( 'overdue since %s', 'checkoff' ), $date );
		}

		if ( $due === $today ) {
			return __( 'due today', 'checkoff' );
		}

		/* translators: %s: date. */
		return sprintf( __( 'due on %s', 'checkoff' ), $date );
	}
}
