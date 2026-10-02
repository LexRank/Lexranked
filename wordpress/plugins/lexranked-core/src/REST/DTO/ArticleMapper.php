<?php
/**
 * Article DTOs.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST\DTO;

use LexRanked\Core\Eligibility\PageEligibility;
use LexRanked\Core\PostTypes\Article;

/**
 * Pure mapping from an article record (plus resolved author, image and
 * related ranking) to the public DTO.
 */
final class ArticleMapper {

	/**
	 * Words in HTML.
	 *
	 * @param string $html HTML.
	 */
	public static function word_count( string $html ): int {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-independent by design.
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) ?: array() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
	}

	/**
	 * Plain-text excerpt: the manual excerpt, else the first ~40 words.
	 *
	 * @param string $excerpt Manual excerpt.
	 * @param string $html    Body HTML.
	 */
	public static function excerpt( string $excerpt, string $html ): string {
		$source = '' !== trim( $excerpt ) ? $excerpt : $html;
		$text   = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $source ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-independent by design.
		$words  = preg_split( '/\s+/u', $text ) ?: array(); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		return count( $words ) > 40 ? implode( ' ', array_slice( $words, 0, 40 ) ) . '…' : $text;
	}

	/**
	 * Summary DTO.
	 *
	 * @param array<string, mixed>                          $record     Record from EntityRepository.
	 * @param string                                        $excerpt    Manual excerpt.
	 * @param string                                        $body_html  Sanitized body HTML.
	 * @param array{name: string}                           $author     Author byline.
	 * @param array<string, mixed>|null                     $image      {url, width, height, alt}.
	 * @param array<int, array{slug: string, name: string}> $categories Categories.
	 * @return array<string, mixed>
	 */
	public static function summary( array $record, string $excerpt, string $body_html, array $author, ?array $image, array $categories ): array {
		$words = self::word_count( $body_html );
		return array(
			'id'               => $record['id'],
			'slug'             => $record['slug'],
			'path'             => '/articles/' . $record['slug'] . '/',
			'title'            => $record['title'],
			'excerpt'          => self::excerpt( $excerpt, $body_html ),
			'author'           => $author,
			'publishedAt'      => $record['created_at'],
			'updatedAt'        => $record['updated_at'],
			'reviewedBy'       => $record['fields']['reviewed_by'] ?? null,
			'reviewedAt'       => $record['fields']['reviewed_at'] ?? null,
			'categories'       => $categories,
			'image'            => $image,
			'wordCount'        => $words,
			'readingMinutes'   => max( 1, (int) round( $words / 220 ) ),
			'isThin'           => $words < Article::MIN_INDEXABLE_WORDS,
			'relatedRankingId' => $record['fields']['related_ranking'] ?? null,
			'isDemo'           => (bool) ( $record['fields']['is_demo'] ?? false ),
			'eligibility'      => PageEligibility::compact(
				PageEligibility::evaluate(
					'article',
					array(
						'real'  => empty( $record['fields']['is_demo'] ),
						'words' => $words,
					)
				)
			),
		);
	}

	/**
	 * Detail DTO.
	 *
	 * @param array<string, mixed>      $summary        Summary DTO.
	 * @param string                    $body_html      Sanitized body HTML.
	 * @param array<string, mixed>|null $related_ranking {id, title, path} of a published ranking.
	 * @return array<string, mixed>
	 */
	public static function detail( array $summary, string $body_html, ?array $related_ranking ): array {
		return $summary + array(
			'body'           => $body_html,
			'relatedRanking' => $related_ranking,
		);
	}
}
