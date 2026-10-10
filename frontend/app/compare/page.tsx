import type { Metadata } from "next";
import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { DemoBadge, EmptyState, UnavailableNotice, VerificationBadge } from "@/components/ui";
import { COMPARE_MAX, COMPARE_MIN, parseCompareQuery } from "@/lib/content/compare";
import { load } from "@/lib/data/loaders";
import { formatDate, formatLocation, isoDate } from "@/lib/format";
import { getComparison } from "@/lib/wordpress/api";
import type { ComparisonCell, ComparisonDto, ComparisonRow, VerificationState } from "@/types/api";

/**
 * Comparison pages (Etap E). Built on request from structured data for any
 * 2-4 entities, so they are never indexed and never listed in the sitemap.
 */
export const metadata: Metadata = {
  title: "Compare",
  description: "Side-by-side comparison of lawyers or law firms from stored facts, sources and LexRank score snapshots.",
  robots: { index: false, follow: true, googleBot: { index: false, follow: true } },
};

const STATUS_LABEL: Record<ComparisonCell["status"], string | null> = {
  verified: "Verified",
  unverified: "Sourced, not yet verified",
  conflict: "Sources disagree",
  missing: null,
  directory: "LexRanked directory",
  derived: null,
};

function Cell({ cell, row }: { cell: ComparisonCell; row: ComparisonRow }) {
  if (cell.status === "missing") return <span className="compare__missing">Not on record</span>;
  const checked = formatDate(cell.checkedAt);
  const highest = row.highest.includes(cell.id);
  return (
    <>
      <span className={highest ? "compare__value compare__value--highest" : "compare__value"}>
        {cell.status === "conflict" ? "Conflicting sources" : cell.display}
        {highest && <span className="compare__tag">{row.cells.length > 2 ? "Highest" : "Higher"}</span>}
      </span>
      {cell.note && <span className="compare__note">{cell.note}</span>}
      <span className="compare__meta">
        {STATUS_LABEL[cell.status] && <span className={`compare__status compare__status--${cell.status}`}>{STATUS_LABEL[cell.status]}</span>}
        {cell.source?.name && (
          <span>
            {cell.source.url && /^https?:\/\//.test(cell.source.url) ? (
              <a href={cell.source.url} rel="nofollow noopener" target="_blank">
                {cell.source.name}
              </a>
            ) : cell.source.url?.startsWith("/") ? (
              <Link href={cell.source.url}>{cell.source.name}</Link>
            ) : (
              cell.source.name
            )}
            {cell.source.tierLabel && <> · {cell.source.tierLabel}</>}
          </span>
        )}
        {checked && (
          <span className={cell.isStale ? "compare__stale" : undefined}>
            {cell.isStale ? "Stale, checked " : "Checked "}
            <time dateTime={isoDate(cell.checkedAt)}>{checked}</time>
          </span>
        )}
      </span>
    </>
  );
}

function ComparisonTable({ data }: { data: ComparisonDto }) {
  return (
    <div className="compare__scroll" role="region" aria-label="Comparison table" tabIndex={0}>
      <table className="compare__table">
        <caption className="sr-only">
          {data.entities.map((e) => e.name).join(" vs ")}: attribute by attribute
        </caption>
        <thead>
          <tr>
            <th scope="col">Attribute</th>
            {data.entities.map((e) => (
              <th scope="col" key={e.id}>
                <Link href={e.path}>{e.name}</Link>
                <span className="compare__sub">
                  {[e.firm?.name, formatLocation(e.location)].filter(Boolean).join(" · ")}
                </span>
                <span className="compare__badges">
                  <VerificationBadge status={e.verification as VerificationState} />
                  {e.isDemo && <DemoBadge />}
                </span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.rows.map((row) => (
            <tr key={row.key} id={`row-${row.key}`}>
              <th scope="row">
                {row.label}
                {row.note && <span className="compare__note">{row.note}</span>}
              </th>
              {row.cells.map((cell) => (
                <td key={cell.id}>
                  <Cell cell={cell} row={row} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const REASON: Record<string, string> = {
  empty: `Pick ${COMPARE_MIN} to ${COMPARE_MAX} lawyers or law firms to compare. Comparison links appear on profiles and ranking pages.`,
  mixed: "Lawyers are compared with lawyers and law firms with law firms. Open a comparison from a profile or ranking page.",
  invalid: "This comparison link is not valid. Open a comparison from a profile or ranking page.",
  count: `A comparison needs ${COMPARE_MIN} to ${COMPARE_MAX} different lawyers or law firms.`,
};

export default async function ComparePage(props: PageProps<"/compare">) {
  const query = parseCompareQuery(await props.searchParams);
  const result = query.ok ? await load(() => getComparison(query.type, query.ids)) : null;
  const data = result?.ok ? result.data : null;
  const noun = query.ok && query.type === "law_firm" ? "law firms" : "lawyers";

  return (
    <>
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Compare", path: "/compare/" },
        ]}
        eyebrow="Comparison"
        title={data ? data.entities.map((e) => e.name).join(" vs ") : `Compare ${noun}`}
        lead={data ? "Stored facts side by side, each with its source, verification status and check date." : undefined}
      />
      <div className="container section stack">
        {!query.ok && (
          <EmptyState title="Nothing to compare yet">
            <p>{REASON[query.reason]}</p>
            <p>
              <Link href="/rankings/">Browse rankings</Link>
            </p>
          </EmptyState>
        )}
        {result && !result.ok && <UnavailableNotice />}
        {result?.ok && !data && (
          <EmptyState title="Comparison not available">
            <p>At least one of these profiles is not published, or the link mixes lawyers and law firms.</p>
          </EmptyState>
        )}
        {data && (
          <>
            {data.entities.some((e) => e.isDemo) && (
              <p className="notice" role="note" style={{ margin: 0 }}>
                Includes demo profiles with fictional data, shown for demonstration only.
              </p>
            )}
            <section className="overview" aria-labelledby="compare-summary">
              <h2 id="compare-summary" className="panel-title">
                At a glance
              </h2>
              <ul className="compare__summary">
                {data.summary.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            </section>

            <ComparisonTable data={data} />

            {data.sharedRankings.length > 0 && (
              <section className="card" aria-labelledby="compare-rankings">
                <h2 id="compare-rankings" style={{ fontSize: "1.4rem" }}>
                  Rankings they share
                </h2>
                <ul className="weights" style={{ gap: "0.5rem" }}>
                  {data.sharedRankings.map((r) => (
                    <li key={r.id} className="compare__ranking">
                      <Link href={r.path}>{r.title}</Link>
                      <span>
                        {r.positions
                          .map((p) => `${data.entities.find((e) => e.id === p.id)?.name ?? ""} #${p.position}`)
                          .join(" · ")}
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            )}

            <p className="muted" style={{ fontSize: "0.92rem", margin: 0 }}>
              {data.basis} Scores follow the published <Link href="/methodology/">methodology</Link>; paid features never change them.
            </p>
          </>
        )}
      </div>
    </>
  );
}
