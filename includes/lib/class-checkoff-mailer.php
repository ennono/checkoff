<?php
/**
 * Mailer class file.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Checkoff_Mailer
 *
 * Thin wrapper around wp_mail(), built like Mixmymeals_Mailer: templates with
 * {{ placeholders }}, a shared layout, theme overrides, a configurable sender
 * and footer.
 *
 * Templates are plain .html files, read as text and never executed. They are
 * looked up in this order, so a theme can restyle every mail without touching
 * the plugin:
 *   <child theme>/checkoff/emails/<name>
 *   <parent theme>/checkoff/emails/<name>
 *   <plugin>/templates/emails/<name>
 * In each directory a subfolder named after the site language (de_DE/) is
 * asked first: a mail is a file, not a string in the catalogue, so this is how
 * it is translated. The English files are the source and the fallback.
 *
 * Placeholders are escaped by their name: "_html" → wp_kses_post(),
 * "_url" → esc_url(), everything else → esc_html(). Unknown placeholders
 * disappear.
 *
 * Data protection:
 *  - One recipient per mail; addresses are never put side by side.
 *  - No tracking, no external resources.
 *  - Every subscription mail carries List-Unsubscribe and
 *    List-Unsubscribe-Post (one click, RFC 8058).
 *  - The send log (Checkoff_Mail_Log) is opt-in and stores masked addresses.
 */
class Checkoff_Mailer {

	const OPTION_FROM_EMAIL = 'checkoff_mail_from_email';
	const OPTION_FROM_NAME  = 'checkoff_mail_from_name';
	const OPTION_FOOTER     = 'checkoff_mail_footer';

	/**
	 * Mails one address may receive per hour (all kinds together).
	 */
	const RATE_LIMIT_PER_HOUR = 20;

	/**
	 * Not instantiable — a stateless service.
	 */
	private function __construct() {
	}

	/**
	 * Sends one mail.
	 *
	 * Supported $args:
	 *  - 'html'            bool   Body is HTML. Default false.
	 *  - 'trusted_html'    bool   Skip wp_kses_post() (template output whose
	 *                             values are already escaped). Default false.
	 *  - 'unsubscribe_url' string Adds List-Unsubscribe headers.
	 *  - 'force'           bool   Ignore the hourly limit (confirmation mails
	 *                             the visitor has just asked for). Default false.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $message Body.
	 * @param array  $args    Options.
	 * @return bool
	 */
	public static function send( string $to, string $subject, string $message, array $args = array() ): bool {

		$to = strtolower( (string) sanitize_email( $to ) );

		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		if ( empty( $args['force'] ) && ! self::passes_rate_limit( $to ) ) {
			return false;
		}

		$is_html = ! empty( $args['html'] );
		$subject = self::clean_header( $subject );

		if ( $is_html && empty( $args['trusted_html'] ) ) {
			$message = wp_kses_post( $message );
		}

		$headers = array(
			'From: ' . self::clean_header( self::from_name() ) . ' <' . self::from_email() . '>',
			'Content-Type: ' . ( $is_html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8',
		);

		$unsubscribe = isset( $args['unsubscribe_url'] ) ? esc_url_raw( (string) $args['unsubscribe_url'] ) : '';

		if ( '' !== $unsubscribe ) {
			$headers[] = 'List-Unsubscribe: <' . $unsubscribe . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		$sent = (bool) wp_mail( $to, $subject, $message, $headers );

		if ( $sent ) {
			self::record_send( $to );
		}

		Checkoff_Mail_Log::record( $to, $subject, $sent );

		return $sent;
	}

	/**
	 * Renders a template (optionally inside the layout) and sends it.
	 *
	 * Additional $args besides those of send():
	 *  - 'inline' bool   $template is markup, not a file name.
	 *  - 'layout' string Layout file to wrap the content in ('' = none).
	 *
	 * @param string $to       Recipient.
	 * @param string $subject  Subject (may contain placeholders).
	 * @param string $template Template file name, or markup with 'inline'.
	 * @param array  $vars     Placeholder values.
	 * @param array  $args     Options.
	 * @return bool False also when the template does not exist.
	 */
	public static function send_template( string $to, string $subject, string $template, array $vars = array(), array $args = array() ): bool {

		$body = self::render_template( $template, $vars, $args );

		if ( null === $body ) {
			return false;
		}

		$vars    = array_merge( self::default_vars(), $vars );
		$subject = self::render( $subject, $vars, false );

		unset( $args['inline'], $args['layout'] );

		$args['html']         = true;
		$args['trusted_html'] = true;

		return self::send( $to, $subject, $body, $args );
	}

	/**
	 * The finished HTML of a templated mail, or null when the template is
	 * missing.
	 *
	 * @param string $template Template file name, or markup with 'inline'.
	 * @param array  $vars     Placeholder values.
	 * @param array  $args     'inline', 'layout', 'unsubscribe_url'.
	 * @return string|null
	 */
	public static function render_template( string $template, array $vars = array(), array $args = array() ): ?string {

		$vars = array_merge( self::default_vars(), $vars );

		$unsubscribe = (string) ( $vars['unsubscribe_url'] ?? ( $args['unsubscribe_url'] ?? '' ) );

		if ( ! isset( $vars['unsubscribe_html'] ) ) {
			$vars['unsubscribe_html'] = '' !== $unsubscribe
				? '<p style="margin:6px 0 0;"><a href="' . esc_url( $unsubscribe ) . '" style="color:#787c82;">' . esc_html__( 'Unsubscribe from these e-mails', 'checkoff' ) . '</a></p>'
				: '';
		}

		$raw = ! empty( $args['inline'] ) ? $template : self::load_template( $template );

		// Comments document a template for whoever edits it, not the reader.
		$raw = (string) preg_replace( '/<!--.*?-->\s*/s', '', $raw );

		if ( '' === trim( $raw ) ) {
			return null;
		}

		$inner  = self::render( $raw, $vars, true );
		$layout = (string) ( $args['layout'] ?? '' );

		if ( '' === $layout ) {
			return $inner;
		}

		$shell = (string) preg_replace( '/<!--.*?-->\s*/s', '', self::load_template( $layout ) );

		if ( '' === trim( $shell ) ) {
			return $inner;
		}

		// The content is already rendered and escaped; insert it verbatim,
		// then fill the layout's own placeholders.
		$shell = (string) preg_replace_callback(
			'/\{\{\s*content\s*\}\}/',
			static function () use ( $inner ) {
				return $inner;
			},
			$shell
		);

		return self::render( $shell, $vars, true );
	}

	/**
	 * Replaces {{ key }} placeholders, escaping by key suffix for HTML.
	 *
	 * @param string $content Template content.
	 * @param array  $vars    Values.
	 * @param bool   $is_html Escape for HTML.
	 * @return string
	 */
	public static function render( string $content, array $vars, bool $is_html = true ): string {

		$map = array();

		foreach ( $vars as $key => $value ) {

			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}

			$key   = (string) $key;
			$value = (string) $value;

			if ( $is_html ) {
				if ( str_ends_with( $key, '_html' ) ) {
					$value = wp_kses_post( $value );
				} elseif ( str_ends_with( $key, '_url' ) ) {
					$value = esc_url( $value );
				} else {
					$value = esc_html( $value );
				}
			}

			$map[ $key ] = $value;
		}

		return (string) preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
			static function ( array $m ) use ( $map ) {
				return $map[ $m[1] ] ?? '';
			},
			$content
		);
	}

	/**
	 * Placeholders every mail has.
	 *
	 * @return array
	 */
	private static function default_vars(): array {

		$footer = trim( (string) get_option( self::OPTION_FOOTER, '' ) );

		/**
		 * Filters the placeholders every mail has.
		 *
		 * @param array $vars Values.
		 */
		return (array) apply_filters(
			'checkoff_mail_default_vars',
			array(
				'site_name'   => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'site_url'    => home_url( '/' ),
				'year'        => (string) wp_date( 'Y' ),
				'footer_html' => '' === $footer ? '' : wpautop( $footer ),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------
	 */

	/**
	 * Contents of a template file, or ''.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function load_template( string $name ): string {

		$file = self::locate_template( $name );

		if ( '' === $file ) {
			return '';
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template, read as text.

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Absolute path of a template, theme before plugin, or ''.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function locate_template( string $name ): string {

		if ( ! self::is_template_name( $name ) ) {
			return '';
		}

		$locale = determine_locale();

		foreach ( self::template_dirs() as $dir ) {
			foreach ( array( $dir . '/' . $locale . '/' . $name, $dir . '/' . $name ) as $path ) {
				if ( is_readable( $path ) ) {
					return $path;
				}
			}
		}

		return '';
	}

	/**
	 * The template directories in lookup order.
	 *
	 * @return string[]
	 */
	public static function template_dirs(): array {

		$dirs = array(
			get_stylesheet_directory() . '/checkoff/emails',
			get_template_directory() . '/checkoff/emails',
			dirname( __DIR__, 2 ) . '/templates/emails',
		);

		return array_values( array_unique( $dirs ) );
	}

	/**
	 * Every template a sending rule can choose (all .html files except the
	 * layout and the confirmation mail), theme files included.
	 *
	 * @return string[] File names, sorted.
	 */
	public static function list_templates(): array {

		$names = array();

		$locale = determine_locale();

		foreach ( self::template_dirs() as $dir ) {
			foreach ( array_merge( (array) glob( $dir . '/*.html' ), (array) glob( $dir . '/' . $locale . '/*.html' ) ) as $file ) {
				$name = basename( (string) $file );
				if ( self::is_template_name( $name ) && ! in_array( $name, array( 'layout.html', 'confirm.html' ), true ) ) {
					$names[ $name ] = $name;
				}
			}
		}

		ksort( $names );

		return array_values( $names );
	}

	/**
	 * Whether a string is a safe template file name (no paths).
	 *
	 * @param string $name Candidate.
	 * @return bool
	 */
	public static function is_template_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_-]+\.html$/', $name );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sender
	 * ------------------------------------------------------------------
	 */

	/**
	 * Sender address: the setting, else the site's admin address.
	 *
	 * @return string
	 */
	public static function from_email(): string {
		$email = sanitize_email( (string) get_option( self::OPTION_FROM_EMAIL, '' ) );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Sender name: the setting, else the site name.
	 *
	 * @return string
	 */
	public static function from_name(): string {
		$name = trim( (string) get_option( self::OPTION_FROM_NAME, '' ) );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * A header value without line breaks (no header injection).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function clean_header( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
	}

	/**
	 * Whether the address may receive another mail this hour.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	private static function passes_rate_limit( string $email ): bool {

		$limit = (int) apply_filters( 'checkoff_mail_rate_limit_per_hour', self::RATE_LIMIT_PER_HOUR, $email );

		return $limit <= 0 || (int) get_transient( self::rate_key( $email ) ) < $limit;
	}

	/**
	 * Counts a sent mail for the hourly limit.
	 *
	 * @param string $email Address.
	 * @return void
	 */
	private static function record_send( string $email ): void {
		$key = self::rate_key( $email );
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key of the hourly counter: a keyed hash, never the address.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	private static function rate_key( string $email ): string {
		return 'checkoff_mail_rl_' . substr( wp_hash( strtolower( $email ) ), 0, 32 );
	}

	/**
	 * An address masked for display, e.g. "jo***@ex***.de".
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function mask_email( string $email ): string {

		$at = strpos( $email, '@' );

		if ( false === $at ) {
			return '***';
		}

		$mask = static function ( string $part ): string {
			// Always three stars: the length of the hidden part is not told.
			return ( strlen( $part ) <= 2 ? '' : substr( $part, 0, 2 ) ) . '***';
		};

		$domain = substr( $email, $at + 1 );
		$dot    = strrpos( $domain, '.' );
		$tld    = false === $dot ? '' : substr( $domain, $dot );
		$host   = false === $dot ? $domain : substr( $domain, 0, $dot );

		return $mask( substr( $email, 0, $at ) ) . '@' . $mask( $host ) . $tld;
	}
}
