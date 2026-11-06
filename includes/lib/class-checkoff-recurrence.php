<?php
/**
 * Repetition of a checklist.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When a checklist starts over.
 *
 * A repeating checklist runs in rounds ("Durchgänge"): every `interval`
 * days, weeks or months from `start` a new round begins, and a new round
 * begins empty. The series ends never, after `count` rounds, or with the
 * last round that starts on or before `until`.
 *
 * Rounds are counted, never stored: the round in force follows from the rule
 * and the clock alone. That is what lets a list nobody opened for a month
 * show the right round, and what lets the mail dispatch ask "which round is
 * it" without a cron job having kept count. All dates are in the site's time
 * zone; a round runs from 00:00 on its first day to 00:00 on the first day of
 * the next.
 *
 * Before the start the first round is in force (a list made today for next
 * Monday can be filled in already); after the last round it stays in force,
 * marked finished.
 *
 * The rule is stored on the top-level block of the structure, so it travels
 * with every structure save and its revision.
 */
class Checkoff_Recurrence {

	const FREQ_NONE    = 'none';
	const FREQ_DAILY   = 'daily';
	const FREQ_WEEKLY  = 'weekly';
	const FREQ_MONTHLY = 'monthly';

	const END_NEVER = 'never';
	const END_COUNT = 'count';
	const END_UNTIL = 'until';

	const MAX_INTERVAL = 365;
	const MAX_COUNT    = 1000;

	/**
	 * The validated rule.
	 *
	 * @var array{freq:string,interval:int,start:string,end:string,count:int,until:string}
	 */
	private array $rule;

	/**
	 * Constructor.
	 *
	 * @param mixed $rule Raw rule (validated here).
	 */
	public function __construct( mixed $rule ) {
		$this->rule = self::sanitize( $rule );
	}

	/**
	 * The rule of a checklist that does not repeat.
	 *
	 * @return array
	 */
	public static function none(): array {
		return array(
			'freq'     => self::FREQ_NONE,
			'interval' => 1,
			'start'    => '',
			'end'      => self::END_NEVER,
			'count'    => 0,
			'until'    => '',
		);
	}

	/**
	 * Validates a rule. Anything unusable turns the repetition off rather
	 * than guessing — a list that suddenly empties itself is worse than one
	 * that does not repeat.
	 *
	 * @param mixed $raw Raw rule.
	 * @return array
	 */
	public static function sanitize( mixed $raw ): array {

		if ( ! is_array( $raw ) ) {
			return self::none();
		}

		$freq = (string) ( $raw['freq'] ?? self::FREQ_NONE );

		if ( ! in_array( $freq, array( self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_MONTHLY ), true ) ) {
			return self::none();
		}

		$start = self::sanitize_date( $raw['start'] ?? '' );

		if ( '' === $start ) {
			$start = (string) wp_date( 'Y-m-d' );
		}

		$interval = is_numeric( $raw['interval'] ?? null ) ? (int) $raw['interval'] : 1;
		$interval = max( 1, min( self::MAX_INTERVAL, $interval ) );

		$end   = (string) ( $raw['end'] ?? self::END_NEVER );
		$count = is_numeric( $raw['count'] ?? null ) ? (int) $raw['count'] : 0;
		$until = self::sanitize_date( $raw['until'] ?? '' );

		if ( self::END_COUNT === $end && $count >= 1 ) {
			$count = min( self::MAX_COUNT, $count );
			$until = '';
		} elseif ( self::END_UNTIL === $end && '' !== $until && $until >= $start ) {
			$count = 0;
		} else {
			$end   = self::END_NEVER;
			$count = 0;
			$until = '';
		}

		return array(
			'freq'     => $freq,
			'interval' => $interval,
			'start'    => $start,
			'end'      => $end,
			'count'    => $count,
			'until'    => $until,
		);
	}

	/**
	 * A date as Y-m-d, or '' when it is not a real date.
	 *
	 * @param mixed $date Raw date.
	 * @return string
	 */
	private static function sanitize_date( mixed $date ): string {

		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return '';
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $date : '';
	}

	/**
	 * The validated rule.
	 *
	 * @return array
	 */
	public function rule(): array {
		return $this->rule;
	}

	/**
	 * Whether the checklist repeats at all.
	 *
	 * @return bool
	 */
	public function repeats(): bool {
		return self::FREQ_NONE !== $this->rule['freq'];
	}

	/**
	 * Number of rounds, or null for an endless series (or no repetition).
	 *
	 * @return int|null
	 */
	public function total(): ?int {

		if ( ! $this->repeats() ) {
			return null;
		}

		if ( self::END_COUNT === $this->rule['end'] ) {
			return $this->rule['count'];
		}

		if ( self::END_UNTIL === $this->rule['end'] ) {
			// The last round is the last one starting on or before the date.
			$until = $this->day( $this->rule['until'] );
			$index = $this->index_at( $until->setTime( 23, 59, 59 ) );
			return $index + 1;
		}

		return null;
	}

	/**
	 * Start of round $index (00:00, site time zone).
	 *
	 * @param int $index Round, 0-based.
	 * @return DateTimeImmutable
	 */
	public function round_start( int $index ): DateTimeImmutable {

		$start = $this->day( $this->rule['start'] );
		$step  = $index * $this->rule['interval'];

		switch ( $this->rule['freq'] ) {
			case self::FREQ_DAILY:
				return $start->modify( '+' . $step . ' days' );

			case self::FREQ_WEEKLY:
				return $start->modify( '+' . ( $step * 7 ) . ' days' );

			case self::FREQ_MONTHLY:
				// "+1 month" from 31 January lands in March; keep the day of
				// the month where the month has it, else take its last day.
				$year  = (int) $start->format( 'Y' );
				$month = (int) $start->format( 'n' ) + $step;
				$year += intdiv( $month - 1, 12 );
				$month = ( ( $month - 1 ) % 12 ) + 1;
				$day   = min( (int) $start->format( 'j' ), (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) ) );
				return $start->setDate( $year, $month, $day );
		}

		return $start;
	}

	/**
	 * Index of the round running at a moment, ignoring the end of the series
	 * (0 before the start).
	 *
	 * @param DateTimeImmutable $at Moment.
	 * @return int
	 */
	private function index_at( DateTimeImmutable $at ): int {

		$start = $this->day( $this->rule['start'] );

		if ( $at < $start ) {
			return 0;
		}

		$interval = $this->rule['interval'];

		switch ( $this->rule['freq'] ) {
			case self::FREQ_DAILY:
				$days = (int) $start->diff( $at )->days;
				return intdiv( $days, $interval );

			case self::FREQ_WEEKLY:
				$days = (int) $start->diff( $at )->days;
				return intdiv( $days, 7 * $interval );

			case self::FREQ_MONTHLY:
				$months = ( (int) $at->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12
					+ ( (int) $at->format( 'n' ) - (int) $start->format( 'n' ) );
				$index  = max( 0, intdiv( $months, $interval ) );
				// The estimate may be one round ahead within the month.
				while ( $index > 0 && $this->round_start( $index ) > $at ) {
					--$index;
				}
				return $index;
		}

		return 0;
	}

	/**
	 * The round in force at a moment.
	 *
	 * @param int|null $timestamp Unix time, now by default.
	 * @return array{index:int, number:int, total:int|null, start:int, end:int|null, before_start:bool, finished:bool, last:bool}
	 *         `end` is the start of the following round (exclusive), null for a
	 *         checklist that does not repeat.
	 */
	public function current( ?int $timestamp = null ): array {

		if ( ! $this->repeats() ) {
			return array(
				'index'        => 0,
				'number'       => 1,
				'total'        => null,
				'start'        => 0,
				'end'          => null,
				'before_start' => false,
				'finished'     => false,
				'last'         => false,
			);
		}

		$at    = $this->moment( $timestamp ?? time() );
		$total = $this->total();
		$index = $this->index_at( $at );

		$finished = false;

		if ( null !== $total && $index >= $total ) {
			$index    = $total - 1;
			$finished = true;
		}

		$start = $this->round_start( $index );
		$end   = $this->round_start( $index + 1 );

		return array(
			'index'        => $index,
			'number'       => $index + 1,
			'total'        => $total,
			'start'        => $start->getTimestamp(),
			'end'          => $end->getTimestamp(),
			'before_start' => $at < $this->day( $this->rule['start'] ),
			'finished'     => $finished || ( null !== $total && $index === $total - 1 && $at >= $end ),
			'last'         => null !== $total && $index === $total - 1,
		);
	}

	/**
	 * Midnight of a Y-m-d date in the site's time zone.
	 *
	 * @param string $ymd Date.
	 * @return DateTimeImmutable
	 */
	private function day( string $ymd ): DateTimeImmutable {
		return new DateTimeImmutable( $ymd . ' 00:00:00', wp_timezone() );
	}

	/**
	 * A timestamp in the site's time zone.
	 *
	 * @param int $timestamp Unix time.
	 * @return DateTimeImmutable
	 */
	private function moment( int $timestamp ): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
	}

	/**
	 * The repetition in words, e.g. "Every 2 weeks, 10 times".
	 *
	 * @return string
	 */
	public function describe(): string {

		if ( ! $this->repeats() ) {
			return __( 'Does not repeat', 'checkoff' );
		}

		$n = $this->rule['interval'];

		switch ( $this->rule['freq'] ) {
			case self::FREQ_DAILY:
				/* translators: %s: number of days. */
				$text = 1 === $n ? __( 'Daily', 'checkoff' ) : sprintf( _n( 'Every %s day', 'Every %s days', $n, 'checkoff' ), number_format_i18n( $n ) );
				break;
			case self::FREQ_WEEKLY:
				/* translators: %s: number of weeks. */
				$text = 1 === $n ? __( 'Weekly', 'checkoff' ) : sprintf( _n( 'Every %s week', 'Every %s weeks', $n, 'checkoff' ), number_format_i18n( $n ) );
				break;
			default:
				/* translators: %s: number of months. */
				$text = 1 === $n ? __( 'Monthly', 'checkoff' ) : sprintf( _n( 'Every %s month', 'Every %s months', $n, 'checkoff' ), number_format_i18n( $n ) );
		}

		if ( self::END_COUNT === $this->rule['end'] ) {
			/* translators: 1: repetition, e.g. "Weekly", 2: number of rounds. */
			$text = sprintf( _n( '%1$s, %2$s time', '%1$s, %2$s times', $this->rule['count'], 'checkoff' ), $text, number_format_i18n( $this->rule['count'] ) );
		} elseif ( self::END_UNTIL === $this->rule['end'] ) {
			/* translators: 1: repetition, e.g. "Weekly", 2: date. */
			$text = sprintf( __( '%1$s until %2$s', 'checkoff' ), $text, self::format_date( $this->day( $this->rule['until'] )->getTimestamp() ) );
		}

		return $text;
	}

	/**
	 * A date in the site's format.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function format_date( int $timestamp ): string {
		return (string) wp_date( (string) get_option( 'date_format' ), $timestamp );
	}
}
