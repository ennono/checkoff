<?php
/**
 * The site's design settings for the checklists.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Colours, corners, size and font of the checklists, set under
 * CheckOff → Settings → Design and written as CheckOff's own custom
 * properties (`--cko-accent`, `--cko-radius`, …) after its stylesheet.
 *
 * With Elementor active, colours and fonts come from the Elementor kit's
 * global colours and fonts (Site Settings), the way the rest of such a site
 * is styled: Primary → accent, Accent → done, Text font → text, Primary
 * font → headings. The plugin's own values are then only the fallback for
 * a kit that leaves one of them out. Corners, size and the warning colour
 * have no counterpart in the kit and stay the plugin's.
 *
 * Only what differs from the defaults is written; with nothing set and no
 * Elementor, no CSS is added at all.
 */
class Checkoff_Design {

	const PREFIX = 'checkoff_design_';

	/**
	 * The defaults, as in frontend.css.
	 */
	const DEFAULTS = array(
		'accent'          => '#2563eb',
		'accent_contrast' => '#ffffff',
		'done'            => '#16a34a',
		'danger'          => '#dc2626',
		'radius'          => 12,
	);

	const SIZES = array(
		'compact' => '2.25rem',
		'normal'  => '2.5rem',
		'large'   => '2.875rem',
	);

	const RADIUS_MAX = 32;

	/**
	 * CheckOff's containers — where its custom properties are set.
	 */
	const CONTAINERS = '.cko-app,.cko-create,.cko-open,.cko-recent,.cko-templates,.cko-lists,.cko-menu,.cko-toasts,.cko-dialog';

	/**
	 * The headings within them.
	 */
	const HEADINGS = '.cko-app h2,.cko-app h3,.cko-app h4,.cko-lists h2,.cko-lists h3,.cko-create__title,.cko-open__title,.cko-recent__title';

	const SYSTEM_FONT = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

	/**
	 * Whether Elementor is active, so its kit styles the site.
	 *
	 * @return bool
	 */
	public static function elementor(): bool {

		$active = defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ) > 0;

		/**
		 * Whether the checklists take their colours and fonts from Elementor.
		 *
		 * @param bool $active Elementor is active.
		 */
		return (bool) apply_filters( 'checkoff_design_use_elementor', $active );
	}

	/**
	 * The stored settings, checked: colours as hex or '', radius as an int
	 * or null (not set), size and font as one of their keys.
	 *
	 * @return array{accent:string, accent_contrast:string, done:string, danger:string, radius:int|null, size:string, font:string}
	 */
	public static function values(): array {

		$color = static function ( string $key ): string {
			return (string) sanitize_hex_color( (string) get_option( self::PREFIX . $key, '' ) );
		};

		$radius = get_option( self::PREFIX . 'radius', '' );
		$size   = (string) get_option( self::PREFIX . 'size', 'normal' );
		$font   = (string) get_option( self::PREFIX . 'font', 'theme' );

		return array(
			'accent'          => $color( 'accent' ),
			'accent_contrast' => $color( 'accent_contrast' ),
			'done'            => $color( 'done' ),
			'danger'          => $color( 'danger' ),
			'radius'          => '' === $radius || null === $radius ? null : max( 0, min( self::RADIUS_MAX, (int) $radius ) ),
			'size'            => isset( self::SIZES[ $size ] ) ? $size : 'normal',
			'font'            => in_array( $font, array( 'theme', 'system' ), true ) ? $font : 'theme',
		);
	}

	/**
	 * The CSS for the settings in force, or '' when everything is default.
	 *
	 * @return string
	 */
	public static function css(): string {

		$values    = self::values();
		$elementor = self::elementor();
		$props     = array();

		// Colours: the kit's where Elementor styles the site, the plugin's as fallback.
		$accent = '' !== $values['accent'] ? $values['accent'] : self::DEFAULTS['accent'];
		$done   = '' !== $values['done'] ? $values['done'] : self::DEFAULTS['done'];

		if ( $elementor ) {
			$props['--cko-accent'] = 'var(--e-global-color-primary, ' . $accent . ')';
			$props['--cko-done']   = 'var(--e-global-color-accent, ' . $done . ')';
		} else {
			if ( '' !== $values['accent'] ) {
				$props['--cko-accent'] = $values['accent'];
			}
			if ( '' !== $values['done'] ) {
				$props['--cko-done'] = $values['done'];
			}
		}

		if ( '' !== $values['accent_contrast'] ) {
			$props['--cko-accent-contrast'] = $values['accent_contrast'];
		}
		if ( '' !== $values['danger'] ) {
			$props['--cko-danger'] = $values['danger'];
		}
		if ( null !== $values['radius'] ) {
			$props['--cko-radius']    = $values['radius'] . 'px';
			$props['--cko-radius-sm'] = (int) round( $values['radius'] * 2 / 3 ) . 'px';
		}
		if ( 'normal' !== $values['size'] ) {
			$props['--cko-control'] = self::SIZES[ $values['size'] ];
		}

		$fallback_font = 'system' === $values['font'] ? self::SYSTEM_FONT : '';

		if ( $elementor ) {
			$props['font-family'] = 'var(--e-global-typography-text-font-family, ' . ( '' !== $fallback_font ? $fallback_font : 'inherit' ) . ')';
		} elseif ( '' !== $fallback_font ) {
			$props['font-family'] = $fallback_font;
		}

		$css = '';

		if ( $props ) {
			$css .= self::CONTAINERS . '{';
			foreach ( $props as $name => $value ) {
				$css .= $name . ':' . $value . ';';
			}
			$css .= '}';
		}

		if ( $elementor ) {
			$css .= self::HEADINGS . '{font-family:var(--e-global-typography-primary-font-family, ' . ( '' !== $fallback_font ? $fallback_font : 'inherit' ) . ');}';
		}

		return $css;
	}

	/**
	 * The fields of the settings tab "Design".
	 *
	 * @param object $settings The settings object, for its sanitize callbacks.
	 * @return array
	 */
	public static function fields( object $settings ): array {

		$elementor = self::elementor();

		$from_kit = static function ( string $name ) use ( $elementor ): string {
			return $elementor
				/* translators: %s: name of an Elementor global colour or font. */
				? ' ' . sprintf( __( 'With Elementor: the kit\'s "%s" is used where it is set.', 'checkoff' ), $name )
				: '';
		};

		return array(
			array(
				'id'          => 'design_accent',
				'label'       => __( 'Accent colour', 'checkoff' ),
				'description' => __( 'Buttons, the active switch, progress bars and links. Empty: blue.', 'checkoff' ) . $from_kit( __( 'Primary', 'checkoff' ) ),
				'type'        => 'color',
				'default'     => '',
				'placeholder' => self::DEFAULTS['accent'],
				'callback'    => array( $settings, 'color_or_empty_callback' ),
			),
			array(
				'id'          => 'design_accent_contrast',
				'label'       => __( 'Text on the accent colour', 'checkoff' ),
				'description' => __( 'Text and icons on accent-coloured buttons. With a light accent colour choose a dark one. Empty: white.', 'checkoff' ),
				'type'        => 'color',
				'default'     => '',
				'placeholder' => self::DEFAULTS['accent_contrast'],
				'callback'    => array( $settings, 'color_or_empty_callback' ),
			),
			array(
				'id'          => 'design_done',
				'label'       => __( 'Colour of what is done', 'checkoff' ),
				'description' => __( 'Ticks, finished progress bars, the done column of the board. Empty: green.', 'checkoff' ) . $from_kit( __( 'Accent', 'checkoff' ) ),
				'type'        => 'color',
				'default'     => '',
				'placeholder' => self::DEFAULTS['done'],
				'callback'    => array( $settings, 'color_or_empty_callback' ),
			),
			array(
				'id'          => 'design_danger',
				'label'       => __( 'Warning colour', 'checkoff' ),
				'description' => __( 'Deleting, errors, overdue dates. Empty: red.', 'checkoff' ),
				'type'        => 'color',
				'default'     => '',
				'placeholder' => self::DEFAULTS['danger'],
				'callback'    => array( $settings, 'color_or_empty_callback' ),
			),
			array(
				'id'          => 'design_radius',
				'label'       => __( 'Corner radius', 'checkoff' ),
				'description' => sprintf(
					/* translators: 1: maximum, 2: default. */
					__( 'Pixels, 0 (square) to %1$s. Empty: %2$s.', 'checkoff' ),
					self::RADIUS_MAX,
					self::DEFAULTS['radius']
				),
				'type'        => 'number',
				'min'         => 0,
				'max'         => self::RADIUS_MAX,
				'default'     => '',
				'callback'    => array( $settings, 'radius_callback' ),
			),
			array(
				'id'       => 'design_size',
				'label'    => __( 'Size of buttons and fields', 'checkoff' ),
				'type'     => 'select',
				'options'  => array(
					'compact' => __( 'Compact', 'checkoff' ),
					'normal'  => __( 'Normal', 'checkoff' ),
					'large'   => __( 'Large (easier to tap)', 'checkoff' ),
				),
				'default'  => 'normal',
				'callback' => array( $settings, 'text_callback' ),
			),
			array(
				'id'          => 'design_font',
				'label'       => __( 'Font', 'checkoff' ),
				'description' => $elementor ? __( 'With Elementor: the kit\'s fonts are used where they are set; this is the fallback.', 'checkoff' ) : '',
				'type'        => 'select',
				'options'     => array(
					'theme'  => __( 'The theme\'s font', 'checkoff' ),
					'system' => __( 'The device\'s system font', 'checkoff' ),
				),
				'default'     => 'theme',
				'callback'    => array( $settings, 'text_callback' ),
			),
		);
	}

	/**
	 * The notice on the settings tab while Elementor styles the site, or null.
	 *
	 * @return array{title:string, text:string}|null
	 */
	public static function notice(): ?array {

		if ( ! self::elementor() ) {
			return null;
		}

		return array(
			'title' => __( 'Elementor is active', 'checkoff' ),
			'text'  => __( 'The checklists take their colours and fonts from the global colours and fonts of the Elementor kit (Elementor → Site Settings): the global colour Primary is the accent, Accent the colour of what is done; the Text font is used for text, the Primary font for headings. Change them there. The colours and font below only apply where the kit leaves one out; corners, size and the warning colour always apply.', 'checkoff' ),
		);
	}
}
