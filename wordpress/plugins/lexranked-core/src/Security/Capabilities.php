<?php
/**
 * Roles and capabilities.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Security;

/**
 * - Editors manage lawyers, firms, rankings, sources and verification (post caps).
 * - Research jobs and settings are administrator-only.
 * - The `lexranked_api` role is a least-privilege account for the Next.js
 *   frontend: it can read the API without rate limiting and nothing else.
 * - The `lexranked_api` role may also submit profile claims for visitors
 *   (they only enter the private review queue).
 * - Claim review: editors and administrators. Paid placements: administrators.
 * - The `lexranked_worker` role is for research workers: it may use the
 *   research endpoints (claim jobs, submit candidates/claims) and nothing in
 *   wp-admin. Everything it submits is validated server-side and only lands
 *   in drafts or the editorial review queue.
 */
final class Capabilities {

	public const API_ROLE    = 'lexranked_api';
	public const API_READ    = 'lexranked_api_read';
	public const WORKER_ROLE = 'lexranked_worker';
	public const RESEARCH    = 'lexranked_research';
	public const MANAGE      = 'manage_options';
	/** Frontend server submits claim requests on behalf of visitors. */
	public const SUBMIT_CLAIMS = 'lexranked_submit_claims';
	/** Review profile claims (editors and administrators). */
	public const REVIEW_CLAIMS = 'lexranked_review_claims';
	/** Create and change paid placements (administrators). */
	public const MANAGE_COMMERCIAL = 'lexranked_manage_commercial';
	public const RESEARCH_CAP      = array(
		'edit_lr_research_jobs',
		'edit_others_lr_research_jobs',
		'edit_private_lr_research_jobs',
		'edit_published_lr_research_jobs',
		'publish_lr_research_jobs',
		'read_private_lr_research_jobs',
		'delete_lr_research_jobs',
		'delete_others_lr_research_jobs',
		'delete_private_lr_research_jobs',
		'delete_published_lr_research_jobs',
	);

	/**
	 * Add roles/caps. Idempotent.
	 */
	public static function install(): void {
		if ( null === get_role( self::API_ROLE ) ) {
			add_role(
				self::API_ROLE,
				'LexRanked API',
				array(
					'read'         => true,
					self::API_READ => true,
				)
			);
		}
		if ( null === get_role( self::WORKER_ROLE ) ) {
			add_role(
				self::WORKER_ROLE,
				'LexRanked Research Worker',
				array(
					'read'         => true,
					self::API_READ => true,
					self::RESEARCH => true,
				)
			);
		}
		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			foreach ( array_merge( self::RESEARCH_CAP, array( self::API_READ, self::RESEARCH, self::REVIEW_CLAIMS, self::MANAGE_COMMERCIAL ) ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		get_role( 'editor' )?->add_cap( self::REVIEW_CLAIMS );
		// Existing roles gain new capabilities on upgrade (add_role() is a no-op for them).
		get_role( self::API_ROLE )?->add_cap( self::SUBMIT_CLAIMS );
	}

	/**
	 * Whether the current request comes from a trusted API client (bypasses rate limits).
	 */
	public static function is_trusted_client(): bool {
		return current_user_can( self::API_READ ) || current_user_can( 'edit_posts' );
	}
}
