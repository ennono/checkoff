<?php
/**
 * Privacy integration.
 *
 * @package CheckOff/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggests a section for the site's privacy policy.
 *
 * There is nothing to export or erase per person: a checklist is tied to a
 * random ID, not to a name or an address. The suggested text says exactly
 * what is stored and for how long.
 */
class Checkoff_Privacy {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Registers the suggested policy text.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {

		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( 'CheckOff', wp_kses_post( wpautop( self::policy_text(), false ) ) );
	}

	/**
	 * The suggested policy text, following the settings in force.
	 *
	 * @return string
	 */
	public static function policy_text(): string {

		$paragraphs = array(
			__( 'You can create checklists on this website. A checklist is stored on our server together with everything you enter or tick in it and the date and time of each entry. It is not linked to your name or e-mail address but to a randomly generated ID. Without a password, anyone who knows this ID can open and change the checklist, so only pass it on to people you trust.', 'checkoff' ),
			__( 'If you are logged in when you create a checklist, it is additionally assigned to your user account.', 'checkoff' ),
			__( 'To prevent misuse, we count per visitor and hour how often checklists are created, changed and ticked, how often subscriptions are requested and how many unknown IDs are tried. For this purpose, an encrypted value derived from your IP address (for IPv6 addresses, from its network part) is stored for at most one hour; the IP address itself is not stored.', 'checkoff' ),
			__( 'The checklists you opened recently are remembered in your browser\'s local storage on your device only (private checklists without their title). They are not sent to us. You can remove them in the list or by clearing your browser data. Ticks you make without an internet connection wait there too, until they can be sent.', 'checkoff' ),
			__( 'A checklist can be protected by a password — so that changing it, or with the setting "private" also viewing it, needs the password. We store only an encrypted value (hash) of it, never the password itself. When you unlock a checklist with its password, a cookie is set in your browser for ten days that only says that this checklist is unlocked on this device; it contains no personal data and is necessary for the function you asked for. You can delete it by locking the checklist again or by clearing your cookies.', 'checkoff' ),
		);

		if ( Checkoff_Directory::is_enabled() ) {
			$paragraphs[] = __( 'The owner of a password-protected checklist can choose to show it publicly. It then appears in a directory on this website with its title, description and progress, and everybody can open, view and tick it; changing it still needs the password. Do not enter personal data in a checklist you show publicly. You can end this at any time.', 'checkoff' );
		}

		$paragraphs[] = Checkoff_History::is_enabled()
			? sprintf(
				/* translators: %s: number of days. */
				__( 'When a repeating checklist starts a new round, the ticks and values of the round that ended are kept with their dates and times for %s days and then deleted automatically; only somebody who may edit the checklist can see them, and can delete them at any time. Only a short summary of the last round is kept longer.', 'checkoff' ),
				number_format_i18n( Checkoff_History::days() )
			)
			: __( 'When a repeating checklist starts a new round, the ticks and values of the round that ended are deleted; only a short summary of the last round is kept (how much was done and which entries stayed open).', 'checkoff' );

		if ( Checkoff_Subscriptions::is_enabled() ) {

			$paragraphs[] = sprintf(
				/* translators: %s: number of hours. */
				__( 'You can subscribe to a checklist by e-mail. For this we store your e-mail address, the checklist it belongs to and the time you confirmed the subscription — nothing else: no name and no IP address. We first send you an e-mail with a confirmation link (double opt-in); without confirmation nothing else is sent, and the address is deleted after %s hours. The legal basis is your consent (Art. 6(1)(a) GDPR).', 'checkoff' ),
				number_format_i18n( Checkoff_Subscriptions::pending_hours() )
			);

			$paragraphs[] = __( 'Every e-mail contains a link to unsubscribe. When you unsubscribe, your subscription and your e-mail address are deleted immediately; they are also deleted when the checklist is deleted. You can withdraw your consent at any time with effect for the future.', 'checkoff' );

			if ( Checkoff_Mail_Log::is_enabled() ) {
				$paragraphs[] = sprintf(
					/* translators: %s: number of days. */
					__( 'To be able to check that e-mails were delivered, we keep a log of sent e-mails for %s days: time, subject and a shortened form of the address (e.g. jo***@ex***.de), never the full address or the content.', 'checkoff' ),
					number_format_i18n( Checkoff_Mail_Log::retention_days() )
				);
			}

			$paragraphs[] = __( 'E-mails are sent through the e-mail service of this website. They contain no tracking pixels and no links that record whether you opened them.', 'checkoff' );
		}

		$days = Checkoff_Cleanup::inactive_days();

		$paragraphs[] = $days > 0
			? sprintf(
				/* translators: %s: number of days. */
				__( 'Checklists that have not been opened for %s days are deleted automatically. You can delete a checklist yourself at any time.', 'checkoff' ),
				number_format_i18n( $days )
			)
			: __( 'Checklists are stored until you delete them yourself.', 'checkoff' );

		return implode( "\n\n", $paragraphs );
	}
}
