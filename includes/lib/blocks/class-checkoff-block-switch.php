<?php
/**
 * Building block: switch.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Either/or: two (up to MAX_VARIANTS) variants of entries — training at the
 * studio or at home, packing for summer or winter — and a switch that says
 * which one applies. Only the variant in force is shown when ticking and
 * counts towards the progress; the other keeps its entries and ticks.
 *
 * The variants are the block's child blocks (any type; their titles name
 * the switch's positions). The switch's position is a value like an
 * entry's, stored under the block's own ID: the index of the variant, or
 * nothing for the first. So it is set by ticking (tick link included),
 * synced live, kept in the history, and — like every value — starts over
 * with a new round of a repeating checklist.
 */
class Checkoff_Block_Switch extends Checkoff_Block_Type {

	const MIN_VARIANTS = 2;
	const MAX_VARIANTS = 6;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'switch';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Switch', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Either/or: two variants of entries, e.g. at the studio or at home; a switch decides which one applies.', 'checkoff' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return '⇄';
	}

	/**
	 * {@inheritDoc}
	 */
	public function counts_progress(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function holds_value(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function needs_child_level(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only variants: entries placed directly in the switch (e.g. when a list
	 * becomes a switch) move into the first variant, missing variants are
	 * added empty, variants beyond the maximum go. IDs of added variants
	 * derive from the block's, so the same tree gives the same result on
	 * every read.
	 */
	public function prepare_items( array $items, string $block_id ): array {

		$variants = array();
		$loose    = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( 'block' === ( $item['kind'] ?? '' ) ) {
				$variants[] = $item;
			} else {
				$loose[] = $item;
			}
		}

		if ( $loose ) {
			if ( $variants ) {
				$variants[0]['items'] = array_merge( is_array( $variants[0]['items'] ?? null ) ? $variants[0]['items'] : array(), $loose );
			} else {
				$variants[] = self::empty_variant( $block_id, 0, $loose );
			}
		}

		for ( $i = count( $variants ); $i < self::MIN_VARIANTS; $i++ ) {
			$variants[] = self::empty_variant( $block_id, $i );
		}

		return array_slice( $variants, 0, self::MAX_VARIANTS );
	}

	/**
	 * A variant to add: a simple list without title.
	 *
	 * @param string $block_id Switch ID.
	 * @param int    $index    Variant index.
	 * @param array  $items    Entries.
	 * @return array
	 */
	private static function empty_variant( string $block_id, int $index, array $items = array() ): array {
		return array(
			'id'       => 'v' . $index . substr( $block_id, 0, 14 ),
			'kind'     => 'block',
			'type'     => Checkoff_Block_Types::FALLBACK,
			'title'    => '',
			'settings' => array(),
			'items'    => $items,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The variant's index; the first is stored as nothing.
	 */
	public function sanitize_value( mixed $value, array $settings ): mixed {

		if ( null === $value || '' === $value || is_bool( $value ) || ! is_numeric( $value ) ) {
			return null;
		}

		$index = (int) $value;

		return ( $index > 0 && $index < (int) ( $settings['_variants'] ?? self::MIN_VARIANTS ) ) ? $index : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function active_child( mixed $value, int $children ): int {
		$index = is_numeric( $value ) ? (int) $value : 0;
		return ( $index > 0 && $index < $children ) ? $index : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function progress( mixed $value, array $settings ): array {
		return array(
			'done'  => 0,
			'total' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe_value( mixed $value, array $settings ): string {
		return '';
	}
}
