import Link from "next/link";
import type { FaqItem, RankingDetail } from "@/types/api";
import type { RankingFacts } from "@/lib/content/rankingFacts";
import { formatCount, formatDate, isoDate } from "@/lib/format";
import { METHODOLOGY_VERSION } from "@/lib/methodology";
import { faqJsonLd } from "@/lib/seo/jsonld";
import { JsonLd } from "../JsonLd";

/**
 * Editorial blocks around a ranking (SEO + GEO):
 * - above the list: an answer-first summary built from data, the optional
 *   editorial summary and "at a glance" facts — kept short so the ranking
 *   stays near the top;
 * - below the list: long-form editorial body, FAQ and "about this ranking".
 */

export function RankingOverview({ answer, summary, facts, noun }: { answer: string; summary: string | null; facts: RankingFacts; noun: string }) {
  const tiles = [
    { label: `${noun[0]!.toUpperCase()}${noun.slice(1)} ranked`, value: formatCount(facts.count) },
    { label: "Fully verified", value: `${facts.verifiedCount} of ${facts.count}` },
    facts.averageRating !== null ? { label: "Avg. client rating", value: `${facts.averageRating.toFixed(1)} ★` } : null,
    facts.totalReviews > 0 ? { label: "Reviews analyzed", value: formatCount(facts.totalReviews) } : null,
  ].filter((t): t is { label: string; value: string | null } => t !== null);

  return (
    <section className="overview" aria-labelledby="overview-heading">
      <h2 id="overview-heading" className="sr-only">
        Summary
      </h2>
      {answer && <p className="overview__answer">{answer}</p>}
      {summary && <p className="overview__summary">{summary}</p>}
      <dl className="overview__facts">
        {tiles.map((t) => (
          <div key={t.label}>
            <dt>{t.label}</dt>
            <dd>{t.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

export function EditorialBody({ html }: { html: string }) {
  if (!html.trim()) return null;
  return (
    <section id="guide" className="card editorial" aria-label="Guide">
      <div className="prose editorial__body" dangerouslySetInnerHTML={{ __html: html }} />
    </section>
  );
}

export function FaqSection({ items }: { items: FaqItem[] }) {
  if (items.length === 0) return null;
  const ld = faqJsonLd(items);
  return (
    <section id="faq" aria-labelledby="faq-heading">
      <h2 id="faq-heading" style={{ fontSize: "1.5rem" }}>
        Frequently asked questions
      </h2>
      <div className="faq">
        {items.map((item, i) => (
          <details key={i} className="faq__item" open={i === 0}>
            <summary>{item.question}</summary>
            <p>{item.answer}</p>
          </details>
        ))}
      </div>
      {ld && <JsonLd data={ld} />}
    </section>
  );
}

export function AboutRanking({ ranking, facts }: { ranking: RankingDetail; facts: RankingFacts }) {
  const updated = formatDate(ranking.updatedAt);
  const reviewed = formatDate(ranking.editorial.reviewedAt);
  return (
    <section id="about" className="card about" aria-labelledby="about-heading">
      <h2 id="about-heading" style={{ fontSize: "1.25rem" }}>
        About this ranking
      </h2>
      <dl className="kv">
        {updated && (
          <>
            <dt>Last updated</dt>
            <dd>
              <time dateTime={isoDate(ranking.updatedAt)}>{updated}</time>
            </dd>
          </>
        )}
        <dt>Methodology</dt>
        <dd>
          <Link href="/methodology/">{METHODOLOGY_VERSION}</Link> — deterministic, reproducible scoring
        </dd>
        <dt>Entries</dt>
        <dd>
          {facts.count} ranked, {facts.verifiedCount} fully verified
        </dd>
        {ranking.editorial.reviewedBy && (
          <>
            <dt>Editorial review</dt>
            <dd>
              {ranking.editorial.reviewedBy}
              {reviewed && (
                <>
                  , <time dateTime={isoDate(ranking.editorial.reviewedAt)}>{reviewed}</time>
                </>
              )}
            </dd>
          </>
        )}
        <dt>Independence</dt>
        <dd>Positions are never sold. Paid placements, if any, are labelled and do not affect scores.</dd>
      </dl>
    </section>
  );
}

export function OnThisPage({ links }: { links: Array<{ href: string; label: string }> }) {
  return (
    <nav className="card toc" aria-label="On this page">
      <p className="panel-title">On this page</p>
      <ol>
        {links.map((l) => (
          <li key={l.href}>
            <a href={l.href}>{l.label}</a>
          </li>
        ))}
      </ol>
    </nav>
  );
}
