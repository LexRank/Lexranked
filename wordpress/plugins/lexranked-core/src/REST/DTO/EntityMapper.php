<?php
/**
 * Lawyer and law firm DTO mapping.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST\DTO;

use LexRanked\Core\Domain\CommercialStatus;

/**
 * Maps entity records to stable public DTOs (docs/api.md).
 *
 * Pure functions of their inputs: no WordPress calls, no clock reads.
 * `ranking` (organic) and `commercial` are separate sibling objects; the
 * commercial status never influences anything under `ranking`.
 */
final class EntityMapper {

	/**
	 * Lawyer summary (list items, embedded references).
	 *
	 * @param array<string, mixed>      $record       Lawyer record.
	 * @param array<string, mixed>|null $firm         Firm record or null.
	 * @param array<string, mixed>      $verification Evaluated verification {status, verified_at, types}.
	 * @return array<string, mixed>
	 */
	public static function lawyer_summary( array $record, ?array $firm, array $verification ): array {
		$f = $record['fields'];
		return array(
			'id'            => $record['id'],
			'entityId'      => $record['entity_id'] ?? null,
			'type'          => 'lawyer',
			'slug'          => $record['slug'],
			'path'          => '/lawyers/' . $record['slug'] . '/',
			'name'          => $record['title'],
			'firstName'     => $f['first_name'],
			'lastName'      => $f['last_name'],
			'title'         => $f['title'],
			'firm'          => null === $firm ? null : self::reference( $firm, '/law-firms/' ),
			'location'      => LocationMapper::from_terms( $record['locations'] ),
			'practiceAreas' => self::practice_areas( $record['practice_areas'] ),
			'rating'        => $f['rating'],
			'reviewCount'   => $f['review_count'],
			'ranking'       => self::ranking( $f ),
			'commercial'    => self::commercial( $f ),
			'verification'  => self::verification( $verification ),
			'isDemo'        => (bool) $f['is_demo'],
			'updatedAt'     => $record['updated_at'],
		);
	}

	/**
	 * Lawyer detail.
	 *
	 * @param array<string, mixed>             $record       Lawyer record.
	 * @param array<string, mixed>|null        $firm         Firm record or null.
	 * @param array<string, mixed>             $verification Evaluated verification.
	 * @param array<string, mixed>             $freshness    Freshness evaluation.
	 * @param array<int, array<string, mixed>> $sources      Source DTOs for the entity's claims.
	 * @param string                           $bio_html     Sanitized biography HTML.
	 * @param bool                             $include_private Include private fields (edit context).
	 * @return array<string, mixed>
	 */
	public static function lawyer_detail( array $record, ?array $firm, array $verification, array $freshness, array $sources, string $bio_html, bool $include_private = false ): array {
		$f   = $record['fields'];
		$dto = self::lawyer_summary( $record, $firm, $verification ) + array(
			'contact'      => array(
				'website' => $f['website'],
				'phone'   => $f['phone'],
			),
			'address'      => array(
				'zipCode' => $f['zip_code'],
				'country' => $f['country'],
			),
			'professional' => array(
				'yearsExperience' => $f['years_experience'],
				'barState'        => $f['bar_state'],
				'barNumber'       => $f['bar_number'],
				'barStatus'       => $f['bar_status'],
				'education'       => $f['education'],
				'awards'          => $f['awards'],
				'languages'       => $f['languages'],
				'caseTypes'       => $f['case_types'] ?? array(),
				'clientTypes'     => $f['client_types'] ?? array(),
			),
			'summary'      => $f['summary'] ?? null,
			'bio'          => $bio_html,
			'freshness'    => $freshness,
			'sources'      => $sources,
			'createdAt'    => $record['created_at'],
		);
		if ( $include_private ) {
			$dto['private'] = array( 'email' => $f['email'] );
		}
		return $dto;
	}

	/**
	 * Law firm summary.
	 *
	 * @param array<string, mixed> $record       Firm record.
	 * @param array<string, mixed> $verification Evaluated verification.
	 * @param int                  $lawyer_count Published lawyers at the firm.
	 * @return array<string, mixed>
	 */
	public static function firm_summary( array $record, array $verification, int $lawyer_count ): array {
		$f = $record['fields'];
		return array(
			'id'            => $record['id'],
			'entityId'      => $record['entity_id'] ?? null,
			'type'          => 'law_firm',
			'slug'          => $record['slug'],
			'path'          => '/law-firms/' . $record['slug'] . '/',
			'name'          => $record['title'],
			'location'      => LocationMapper::from_terms( $record['locations'] ),
			'practiceAreas' => self::practice_areas( $record['practice_areas'] ),
			'rating'        => $f['rating'],
			'reviewCount'   => $f['review_count'],
			'lawyerCount'   => $lawyer_count,
			'ranking'       => self::ranking( $f ),
			'commercial'    => self::commercial( $f ),
			'verification'  => self::verification( $verification ),
			'isDemo'        => (bool) $f['is_demo'],
			'updatedAt'     => $record['updated_at'],
		);
	}

	/**
	 * Law firm detail.
	 *
	 * @param array<string, mixed>             $record       Firm record.
	 * @param array<string, mixed>             $verification Evaluated verification.
	 * @param array<int, array<string, mixed>> $lawyers      Lawyer summary DTOs.
	 * @param array<string, mixed>             $freshness    Freshness evaluation.
	 * @param array<int, array<string, mixed>> $sources      Source DTOs.
	 * @param string                           $description_html Sanitized description HTML.
	 * @return array<string, mixed>
	 */
	public static function firm_detail( array $record, array $verification, array $lawyers, array $freshness, array $sources, string $description_html ): array {
		$f = $record['fields'];
		return self::firm_summary( $record, $verification, count( $lawyers ) ) + array(
			'contact'     => array(
				'website' => $f['website'],
				'phone'   => $f['phone'],
				'email'   => $f['email'],
			),
			'address'     => array(
				'street'  => $f['address'],
				'zipCode' => $f['zip_code'],
				'country' => $f['country'],
			),
			'lawyers'     => $lawyers,
			'caseTypes'   => $f['case_types'] ?? array(),
			'clientTypes' => $f['client_types'] ?? array(),
			'summary'     => $f['summary'] ?? null,
			'description' => $description_html,
			'freshness'   => $freshness,
			'sources'     => $sources,
			'createdAt'   => $record['created_at'],
		);
	}

	/**
	 * Minimal reference to another entity.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $base   Path base.
	 * @return array{id: int, slug: string, name: string, path: string}
	 */
	public static function reference( array $record, string $base ): array {
		return array(
			'id'   => $record['id'],
			'slug' => $record['slug'],
			'name' => $record['title'],
			'path' => $base . $record['slug'] . '/',
		);
	}

	/**
	 * Organic ranking block. Only engine-written fields; never commercial data.
	 *
	 * @param array<string, mixed> $fields Entity fields.
	 * @return array{score: float|null, scoreVersion: string|null, calculatedAt: string|null}
	 */
	public static function ranking( array $fields ): array {
		return array(
			'score'        => $fields['score'],
			'scoreVersion' => $fields['score_version'],
			'calculatedAt' => $fields['score_calculated_at'],
		);
	}

	/**
	 * Explainable score breakdown from stored snapshot components.
	 *
	 * @param array<int, array<string, mixed>> $components Snapshot components.
	 * @return array<int, array{key: string, label: string, points: float, max: float, explanation: string, missing: array<int, string>}>
	 */
	public static function breakdown( array $components ): array {
		return array_map(
			static fn( array $c ): array => array(
				'key'         => (string) $c['key'],
				'label'       => (string) $c['label'],
				'points'      => round( (float) $c['points'], 2 ),
				'max'         => (float) $c['weight'],
				'explanation' => (string) $c['explanation'],
				'missing'     => array_values( array_map( 'strval', (array) ( $c['missing'] ?? array() ) ) ),
			),
			$components
		);
	}

	/**
	 * Commercial block, separate from ranking.
	 *
	 * Since API 1.7 the status is derived from claims and placements and is one
	 * of free|claimed|premium. Featured and sponsored placements are delivered
	 * separately (GET /placements), never as a profile status.
	 *
	 * @param array<string, mixed> $fields Entity fields.
	 * @return array{status: string, isPaidPlacement: bool, claimed: bool, premium: bool}
	 */
	public static function commercial( array $fields ): array {
		$status = CommercialStatus::tryFrom( (string) $fields['commercial_status'] ) ?? CommercialStatus::Free;
		return array(
			'status'          => $status->value,
			'isPaidPlacement' => $status->is_paid_placement(),
			'claimed'         => CommercialStatus::Free !== $status,
			'premium'         => CommercialStatus::Premium === $status,
		);
	}

	/**
	 * Verification block.
	 *
	 * @param array<string, mixed> $verification Evaluated verification.
	 * @return array{status: string, verifiedAt: string|null, checks: array<string, string>}
	 */
	public static function verification( array $verification ): array {
		return array(
			'status'     => (string) $verification['status'],
			'verifiedAt' => $verification['verified_at'] ?? null,
			'checks'     => $verification['types'] ?? array(),
		);
	}

	/**
	 * Practice areas.
	 *
	 * @param array<int, array{id: int, slug: string, name: string}> $terms Terms.
	 * @return array<int, array{slug: string, name: string}>
	 */
	public static function practice_areas( array $terms ): array {
		return array_map(
			static fn( array $t ): array => array(
				'slug' => $t['slug'],
				'name' => $t['name'],
			),
			$terms
		);
	}
}
