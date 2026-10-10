<?php
/**
 * Contact form: messages are emailed to the site's editors and never stored.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Contact;

use LexRanked\Core\Security\AuditLog;

/**
 * Validates a contact message and emails it to the editors.
 */
final class ContactService {

	/** Topics a visitor can choose. */
	public const TOPICS = array(
		'general'    => 'General question',
		'correction' => 'Correction to a profile or ranking',
		'lawyer'     => 'I am a lawyer (my profile)',
		'privacy'    => 'Privacy request',
		'press'      => 'Press or data use',
	);

	/** Option: address that receives contact messages (default: the site admin email). */
	public const RECIPIENT_OPTION = 'lexranked_contact_email';

	/** Messages accepted per sender email per day. */
	public const MAX_PER_EMAIL_DAY = 5;

	/**
	 * Validate input; throws ContactException with field errors.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{name: string, email: string, topic: string, page: string, message: string}
	 * @throws ContactException On invalid input.
	 */
	public static function validate( array $input ): array {
		$text    = static fn( mixed $v ): string => trim( (string) preg_replace( '/\s+/u', ' ', is_string( $v ) ? $v : '' ) );
		$name    = $text( $input['name'] ?? '' );
		$email   = strtolower( $text( $input['email'] ?? '' ) );
		$topic   = $text( $input['topic'] ?? '' );
		$page    = $text( $input['page'] ?? '' );
		$message = trim( str_replace( array( "\r\n", "\r" ), "\n", is_string( $input['message'] ?? null ) ? $input['message'] : '' ) );
		$errors  = array();
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 80 ) {
			$errors['name'] = 'Enter your name.';
		}
		if ( 1 !== preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email ) || strlen( $email ) > 254 ) {
			$errors['email'] = 'Enter a valid email address.';
		}
		if ( ! array_key_exists( $topic, self::TOPICS ) ) {
			$errors['topic'] = 'Choose a topic.';
		}
		if ( '' !== $page && ( strlen( $page ) > 300 || 1 !== preg_match( '#^(https?://[^\s]+|/[^\s]*)$#', $page ) ) ) {
			$errors['page'] = 'Enter the page address, for example /lawyers/jane-doe/.';
		}
		if ( mb_strlen( $message ) < 20 || mb_strlen( $message ) > 5000 ) {
			$errors['message'] = 'Write your message in 20 to 5,000 characters.';
		}
		if ( array() !== $errors ) {
			throw new ContactException( 'lexranked_invalid_contact', 'Please check the highlighted fields.', 400, $errors );
		}
		return array(
			'name'    => $name,
			'email'   => $email,
			'topic'   => $topic,
			'page'    => $page,
			'message' => $message,
		);
	}

	/**
	 * Validate and send a message.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{status: string}
	 * @throws ContactException On invalid input, too many messages or a mail failure.
	 */
	public function send( array $input ): array {
		$data = self::validate( $input );
		$key  = 'lexranked_contact_' . substr( hash( 'sha256', $data['email'] ), 0, 32 );
		$sent = (int) get_transient( $key );
		if ( $sent >= self::MAX_PER_EMAIL_DAY ) {
			throw new ContactException( 'lexranked_contact_limit', 'Too many messages from this email today. Please try again tomorrow.', 429 );
		}
		$to      = (string) get_option( self::RECIPIENT_OPTION, '' );
		$to      = is_email( $to ) ? $to : (string) get_option( 'admin_email' );
		$subject = sprintf( '[LexRanked contact] %s: %s', self::TOPICS[ $data['topic'] ], $data['name'] );
		$body    = sprintf(
			"Topic: %s\nFrom: %s <%s>\nPage: %s\n\n%s\n\n--\nSent from the LexRanked contact form. Reply to this email to answer the sender. The message is not stored on the site.\n",
			self::TOPICS[ $data['topic'] ],
			$data['name'],
			$data['email'],
			'' === $data['page'] ? '-' : $data['page'],
			$data['message']
		);
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>' ), '', $data['name'] ) . ' <' . $data['email'] . '>',
		);
		if ( ! wp_mail( $to, $subject, $body, $headers ) ) {
			AuditLog::log( 'contact.mail_failed', 'contact', 0, array( 'topic' => $data['topic'] ) );
			throw new ContactException( 'lexranked_contact_failed', 'The message could not be sent.', 503 );
		}
		set_transient( $key, $sent + 1, DAY_IN_SECONDS );
		AuditLog::log( 'contact.sent', 'contact', 0, array( 'topic' => $data['topic'] ) );
		return array( 'status' => 'sent' );
	}
}
