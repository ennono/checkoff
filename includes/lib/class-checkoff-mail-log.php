<?php
/**
 * Mail log class file.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An optional log of sent mails, to answer "did the mail go out?".
 *
 * Off by default. When on, it keeps time, masked recipient ("jo***@ex***.de"),
 * subject and success — never the full address and never the body — for a
 * limited number of days, and at most MAX_ENTRIES entries. Old entries are
 * dropped on every write and by the daily cleanup.
 */
class Checkoff_Mail_Log {

	const OPTION           = 'checkoff_mail_log';
	const OPTION_ENABLED   = 'checkoff_mail_log_enabled';
	const OPTION_RETENTION = 'checkoff_mail_log_days';

	const MAX_ENTRIES       = 200;
	const DEFAULT_RETENTION = 14;

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'on' === (string) get_option( self::OPTION_ENABLED, '' );
	}

	/**
	 * Days an entry is kept.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		$days = (int) get_option( self::OPTION_RETENTION, self::DEFAULT_RETENTION );
		return max( 1, min( 365, $days ) );
	}

	/**
	 * Records one mail.
	 *
	 * @param string $recipient Address (stored masked).
	 * @param string $subject   Subject.
	 * @param bool   $success   Whether wp_mail() accepted it.
	 * @return void
	 */
	public static function record( string $recipient, string $subject, bool $success ): void {

		if ( ! self::is_enabled() ) {
			return;
		}

		$entries = self::drop_aged_out( self::get_entries() );

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'to'      => Checkoff_Mailer::mask_email( $recipient ),
				'subject' => sanitize_text_field( $subject ),
				'status'  => $success ? 'sent' : 'failed',
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Entries, newest first.
	 *
	 * @return array[]
	 */
	public static function get_entries(): array {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Drops entries older than the retention; called by the daily cleanup.
	 *
	 * @return int Number dropped.
	 */
	public static function prune(): int {

		$entries = self::get_entries();
		$kept    = self::drop_aged_out( $entries );

		if ( count( $kept ) !== count( $entries ) || ! self::is_enabled() ) {
			// Switched off: nothing is kept at all.
			$kept = self::is_enabled() ? $kept : array();
			update_option( self::OPTION, $kept, false );
		}

		return count( $entries ) - count( $kept );
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Entries within the retention.
	 *
	 * @param array $entries Entries.
	 * @return array
	 */
	private static function drop_aged_out( array $entries ): array {

		$cutoff = time() - self::retention_days() * DAY_IN_SECONDS;

		return array_values(
			array_filter(
				$entries,
				static fn( $entry ) => is_array( $entry ) && (int) ( $entry['time'] ?? 0 ) >= $cutoff
			)
		);
	}
}
