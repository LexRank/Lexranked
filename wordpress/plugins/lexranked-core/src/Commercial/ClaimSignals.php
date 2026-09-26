<?php
/**
 * Evidence that a claimant is who they say they are.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

/**
 * Pure comparisons shown to the reviewing editor. They never approve a
 * claim on their own: an editor decides, and records how identity was checked.
 */
final class ClaimSignals {

	public const IDENTITY_METHODS = array(
		'bar_record'     => 'Bar number and name checked against the state bar website',
		'phone_callback' => 'Called back on the phone number listed by the bar or firm website',
		'firm_email'     => 'Confirmed email on the firm website domain',
		'document'       => 'Checked a document (bar card, firm letterhead)',
	);

	private const FREE_MAIL = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'yandex.com', 'zoho.com' );

	/**
	 * Compare the claimed bar number with the profile's.
	 *
	 * @param string $claimed_state  Claimed state code.
	 * @param string $claimed_number Claimed bar number.
	 * @param string $profile_state  Profile bar state.
	 * @param string $profile_number Profile bar number.
	 * @return string match|mismatch|unknown
	 */
	public static function bar( string $claimed_state, string $claimed_number, string $profile_state, string $profile_number ): string {
		$norm = static fn( string $n ): string => ltrim( (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $n ) ), '0' );
		if ( '' === $norm( $claimed_number ) || '' === $norm( $profile_number ) ) {
			return 'unknown';
		}
		return strtoupper( $claimed_state ) === strtoupper( $profile_state ) && $norm( $claimed_number ) === $norm( $profile_number ) ? 'match' : 'mismatch';
	}

	/**
	 * Compare the claimant's email domain with the profile website.
	 *
	 * @param string $email   Claimant email.
	 * @param string $website Profile (or firm) website.
	 * @return string match|no_match|free_mail|unknown
	 */
	public static function email_domain( string $email, string $website ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return 'unknown';
		}
		$domain = strtolower( substr( $email, $at + 1 ) );
		if ( in_array( $domain, self::FREE_MAIL, true ) ) {
			return 'free_mail';
		}
		$host = strtolower( (string) parse_url( $website, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-independent by design.
		$host = (string) preg_replace( '/^www\./', '', $host );
		if ( '' === $host ) {
			return 'unknown';
		}
		return $domain === $host || str_ends_with( $domain, '.' . $host ) || str_ends_with( $host, '.' . $domain ) ? 'match' : 'no_match';
	}
}
