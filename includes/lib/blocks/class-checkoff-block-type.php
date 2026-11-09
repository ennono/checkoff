<?php
/**
 * Base class of all building blocks.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A building block a checklist is assembled from.
 *
 * Every block has a title, an optional description, its own settings and a
 * list of entries. What an entry *is* depends on the block: a box to tick, a
 * row of weekdays, a rating, a counter, a text field. Blocks that support
 * children may additionally hold other blocks of any type among their
 * entries — that is how lists are nested.
 *
 * A block type is one class and the only place that knows its rules: which
 * settings it has and how they are validated, and which values an entry may
 * hold. The frontend draws the blocks, but it receives their definitions
 * from {@see Checkoff_Block_Types::to_client()}, so the builder can never
 * offer a setting the server would refuse.
 */
abstract class Checkoff_Block_Type {

	/**
	 * The moment values are sanitized and shown for, while a round other
	 * than the one in force is written or read (a round ahead): null = now.
	 *
	 * @var int|null
	 */
	private static ?int $clock = null;

	/**
	 * Now — or the moment of the round being written or read.
	 *
	 * @return int
	 */
	public static function now(): int {
		return self::$clock ?? time();
	}

	/**
	 * Runs a callback as if it were a given moment (null: now), so a value
	 * that depends on the date — the week of a weekday tick — belongs to the
	 * round it is written in.
	 *
	 * @param int|null $time     Unix time, or null.
	 * @param callable $callback Callback.
	 * @return mixed What the callback returns.
	 */
	public static function at_time( ?int $time, callable $callback ): mixed {

		$previous    = self::$clock;
		self::$clock = $time;

		try {
			return $callback();
		} finally {
			self::$clock = $previous;
		}
	}


	/**
	 * Machine name, stored in the checklist structure. Never change it for an
	 * existing type: stored checklists refer to it.
	 *
	 * @return string
	 */
	abstract public function key(): string;

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * One sentence describing what the block is for.
	 *
	 * @return string
	 */
	abstract public function description(): string;

	/**
	 * A short symbol shown beside the name in the block picker.
	 *
	 * @return string
	 */
	public function icon(): string {
		return '•';
	}

	/**
	 * Whether this block may contain sub-blocks among its entries. Every
	 * built-in block may; an add-on block can say no.
	 *
	 * @return bool
	 */
	public function supports_children(): bool {
		return true;
	}

	/**
	 * Whether an entry of this block counts towards the progress of the list.
	 *
	 * @return bool
	 */
	public function counts_progress(): bool {
		return true;
	}

	/**
	 * Settings of a new block of this type.
	 *
	 * @return array
	 */
	public function default_settings(): array {
		return array();
	}

	/**
	 * Description of the settings for the builder.
	 *
	 * Each field: key, type (number|select|days|checkbox), label, and
	 * min/max/step or options as the type needs.
	 *
	 * @return array<int, array>
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Validates settings. Unknown keys are dropped, missing or invalid ones
	 * fall back to the defaults.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array
	 */
	public function sanitize_settings( mixed $settings ): array {

		$settings = is_array( $settings ) ? $settings : array();
		$clean    = array();

		foreach ( $this->default_settings() as $key => $default ) {
			$clean[ $key ] = array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
		}

		return $clean;
	}

	/**
	 * Validates the value of one entry.
	 *
	 * @param mixed $value    Raw value.
	 * @param array $settings The block's (sanitized) settings.
	 * @return mixed The value to store, or null to clear the entry.
	 */
	abstract public function sanitize_value( mixed $value, array $settings ): mixed;

	/**
	 * Validates the data an entry of this block carries in the structure
	 * besides its label — set while editing, like the label, so a tick link
	 * cannot change it (see the due date of the deadline block). Most blocks
	 * carry none; data of another type is dropped when the type changes.
	 *
	 * @param mixed $data     Raw data.
	 * @param array $settings The block's (sanitized) settings.
	 * @return array The data to store; empty for none.
	 */
	public function sanitize_item_data( mixed $data, array $settings ): array {
		return array();
	}

	/**
	 * Validates a value that is already stored, e.g. after the block's
	 * settings changed. Unlike sanitize_value() this must not add anything
	 * the client could not have sent — by default the two are the same.
	 *
	 * @param mixed $value    Stored value.
	 * @param array $settings The block's (sanitized) settings.
	 * @return mixed The value to keep, or null to drop it.
	 */
	public function revalidate_value( mixed $value, array $settings ): mixed {
		return $this->sanitize_value( $value, $settings );
	}

	/**
	 * The stored value as it is to be shown now. A value can expire without
	 * being deleted — see the weekly reset of the weekday block.
	 *
	 * @param mixed $value    Stored value.
	 * @param array $settings The block's (sanitized) settings.
	 * @return mixed The value, or null when it no longer applies.
	 */
	public function value_for_display( mixed $value, array $settings ): mixed {
		return $value;
	}

	/**
	 * How much of one entry is done: {done, total}. Every block counts its
	 * entries as one each by default; an entry with a value is done.
	 *
	 * This is the server's answer (mails, admin). The frontend draws its
	 * progress bar with the same rule in assets/js/frontend.js
	 * (Checklist.prototype.progress) — change both together.
	 *
	 * @param mixed $value    Value as shown (see value_for_display()), or null.
	 * @param array $settings The block's (sanitized) settings.
	 * @return array{done:int, total:int}
	 */
	public function progress( mixed $value, array $settings ): array {

		if ( ! $this->counts_progress() ) {
			return array(
				'done'  => 0,
				'total' => 0,
			);
		}

		return array(
			'done'  => null === $value ? 0 : 1,
			'total' => 1,
		);
	}

	/**
	 * A value in words, for mails and the admin overview ('' when empty).
	 *
	 * @param mixed $value    Value as shown, or null.
	 * @param array $settings The block's (sanitized) settings.
	 * @return string
	 */
	public function describe_value( mixed $value, array $settings ): string {
		return null === $value || ! is_scalar( $value ) ? '' : (string) $value;
	}

	/**
	 * An entry's state in words, for mails and the admin overview: its value
	 * and whatever the entry's own data adds (a due date). '' when there is
	 * nothing to say.
	 *
	 * @param array    $item     The entry (id, label, data).
	 * @param mixed    $value    Value as shown, or null.
	 * @param array    $settings The block's (sanitized) settings.
	 * @param int|null $now      Unix time, now by default.
	 * @return string
	 */
	public function describe_item( array $item, mixed $value, array $settings, ?int $now = null ): string {
		return $this->describe_value( $value, $settings );
	}

	/**
	 * The block's definition for the frontend builder.
	 *
	 * @return array
	 */
	public function to_client(): array {
		return array(
			'key'         => $this->key(),
			'label'       => $this->label(),
			'description' => $this->description(),
			'icon'        => $this->icon(),
			'children'    => $this->supports_children(),
			'progress'    => $this->counts_progress(),
			'defaults'    => (object) $this->default_settings(),
			'schema'      => $this->settings_schema(),
			'data'        => (object) $this->client_data(),
		);
	}

	/**
	 * What the block's module in the browser needs besides its settings
	 * (the site's colour scale, the current week). Nothing by default.
	 *
	 * @return array
	 */
	public function client_data(): array {
		return array();
	}

	/**
	 * Clamps a number into a range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @param int   $fallback Value for anything that is not numeric.
	 * @return int
	 */
	protected static function clamp_int( mixed $value, int $min, int $max, int $fallback ): int {

		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) round( (float) $value ) ) );
	}
}
