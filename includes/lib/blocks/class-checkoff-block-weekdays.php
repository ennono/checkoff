<?php
/**
 * Building block: checklist with weekdays.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every entry is ticked per weekday — a habit tracker, a cleaning rota.
 *
 * Which weekdays a block offers is a setting. So is whether the ticks belong
 * to the current week only: with the weekly reset, a value carries the ISO
 * week it was set in, and a value from an earlier week reads as empty. Nothing
 * is deleted for that; the next tick simply overwrites it.
 */
class Checkoff_Block_Weekdays extends Checkoff_Block_Type {

	const RESET_NONE   = 'none';
	const RESET_WEEKLY = 'weekly';

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'weekdays';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Checklist with weekdays', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Tick every entry per weekday, e.g. for habits or a cleaning rota.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '📅';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_settings(): array {
		return array(
			'days'  => array( 1, 2, 3, 4, 5, 6, 7 ),
			'reset' => self::RESET_WEEKLY,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'   => 'days',
				'type'  => 'days',
				'label' => __( 'Weekdays', 'checkoff' ),
			),
			array(
				'key'     => 'reset',
				'type'    => 'select',
				'label'   => __( 'Ticks', 'checkoff' ),
				'options' => array(
					self::RESET_WEEKLY => __( 'Start empty every week', 'checkoff' ),
					self::RESET_NONE   => __( 'Keep', 'checkoff' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();
		$defaults = $this->default_settings();

		$days = self::sanitize_days( $settings['days'] ?? $defaults['days'] );

		// A block without a single day would be a list nobody can tick.
		if ( empty( $days ) ) {
			$days = $defaults['days'];
		}

		$reset = (string) ( $settings['reset'] ?? $defaults['reset'] );

		if ( ! in_array( $reset, array( self::RESET_NONE, self::RESET_WEEKLY ), true ) ) {
			$reset = $defaults['reset'];
		}

		return array(
			'days'  => $days,
			'reset' => $reset,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Stored as { days: [1, 3], week: "2026-W45" }; the week only with the
	 * weekly reset, and always the server's current week — a client cannot
	 * date a tick into another week.
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		$value = is_array( $value ) ? $value : array();
		$days  = array_values( array_intersect( self::sanitize_days( $value['days'] ?? array() ), $settings['days'] ) );

		if ( empty( $days ) ) {
			return null;
		}

		$clean = array( 'days' => $days );

		if ( self::RESET_WEEKLY === $settings['reset'] ) {
			$clean['week'] = self::current_week();
		}

		return $clean;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Keeps the week a value was set in: re-validating after a change of
	 * settings must not move last week's ticks into this week.
	 */
	public function revalidate_value( mixed $value, array $settings ): mixed {

		$value = is_array( $value ) ? $value : array();
		$days  = array_values( array_intersect( self::sanitize_days( $value['days'] ?? array() ), $settings['days'] ) );

		if ( empty( $days ) ) {
			return null;
		}

		$clean = array( 'days' => $days );

		if ( self::RESET_WEEKLY === $settings['reset'] ) {
			$week          = (string) ( $value['week'] ?? '' );
			$clean['week'] = preg_match( '/^\d{4}-W\d{2}$/', $week ) ? $week : self::current_week();
		}

		return $clean;
	}

	/**
	 * {@inheritDoc}
	 *
	 * With the weekly reset, ticks from an earlier week read as empty.
	 */
	public function value_for_display( mixed $value, array $settings ): mixed {

		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( self::RESET_WEEKLY === $settings['reset'] && ( $value['week'] ?? '' ) !== self::current_week() ) {
			return null;
		}

		return $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every offered weekday is one step.
	 */
	public function progress( mixed $value, array $settings ): array {

		$days = is_array( $value ) ? (array) ( $value['days'] ?? array() ) : array();

		return array(
			'done'  => count( array_intersect( $days, $settings['days'] ) ),
			'total' => count( $settings['days'] ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe_value( mixed $value, array $settings ): string {

		if ( ! is_array( $value ) || empty( $value['days'] ) ) {
			return '';
		}

		$names = self::weekday_names( true );

		return '✓ ' . implode( ', ', array_map( static fn( $day ) => $names[ $day ] ?? '', (array) $value['days'] ) );
	}

	/**
	 * A list of ISO weekday numbers (1 = Monday … 7 = Sunday), unique and sorted.
	 *
	 * @param mixed $days Raw list.
	 * @return int[]
	 */
	public static function sanitize_days( mixed $days ): array {

		if ( ! is_array( $days ) ) {
			return array();
		}

		$clean = array();

		foreach ( $days as $day ) {
			if ( is_numeric( $day ) && (int) $day >= 1 && (int) $day <= 7 ) {
				$clean[ (int) $day ] = (int) $day;
			}
		}

		ksort( $clean );

		return array_values( $clean );
	}

	/**
	 * The current week, for the browser's weekday buttons.
	 *
	 * @return array
	 */
	public function client_data(): array {
		return array( 'current_week' => self::current_week() );
	}

	/**
	 * The current ISO week in the site's time zone, e.g. "2026-W45" — of
	 * the round being written or read ({@see Checkoff_Block_Type::now()}).
	 *
	 * @return string
	 */
	public static function current_week(): string {
		return (string) wp_date( 'o-\WW', self::now() );
	}

	/**
	 * Weekday names keyed by ISO number (1 = Monday), in the site's language.
	 *
	 * @param bool $abbreviated Short names.
	 * @return array<int, string>
	 */
	public static function weekday_names( bool $abbreviated ): array {

		global $wp_locale;

		$names = array();

		for ( $day = 1; $day <= 7; $day++ ) {

			// WP_Locale counts from Sunday = 0.
			$name = $wp_locale ? $wp_locale->get_weekday( $day % 7 ) : gmdate( 'l', strtotime( 'Sunday +' . $day . ' days' ) );

			$names[ $day ] = $abbreviated && $wp_locale ? $wp_locale->get_weekday_abbrev( $name ) : $name;
		}

		return $names;
	}
}
