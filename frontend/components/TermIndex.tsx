import Link from "next/link";
import { pluralize } from "@/lib/format";
import { MIN_LAWYERS_FOR_HUB_PAGE } from "@/lib/content/eligibility";
import { PageHeader } from "./PageHeader";
import { EmptyState, UnavailableNotice } from "./ui";
import type { Crumb } from "@/lib/seo/jsonld";

export interface TermItem {
  id: number;
  name: string;
  path: string;
  sub?: string | null;
  lawyerCount: number;
  lawFirmCount: number;
}

/**
 * Index of locations / practice areas. Terms below the hub threshold are
 * listed without a link, because their page intentionally does not exist yet.
 */
export function TermIndex({ crumbs, eyebrow, title, lead, ok, items }: { crumbs: Crumb[]; eyebrow: string; title: string; lead: string; ok: boolean; items: TermItem[] }) {
  return (
    <>
      <PageHeader crumbs={crumbs} eyebrow={eyebrow} title={title} lead={lead} />
      <div className="container section stack">
        {!ok && <UnavailableNotice />}
        {ok && items.length === 0 && (
          <EmptyState title="Nothing published yet">
            <p>Pages appear here once enough verified data exists.</p>
          </EmptyState>
        )}
        <div className="grid grid--3">
          {items.map((item) => {
            const body = (
              <>
                {item.sub && <p className="eyebrow" style={{ marginBottom: "0.4rem" }}>{item.sub}</p>}
                <h3>{item.name}</h3>
                <p className="card__meta" style={{ margin: 0 }}>
                  {pluralize(item.lawyerCount, "lawyer")} · {pluralize(item.lawFirmCount, "law firm")}
                </p>
              </>
            );
            return item.lawyerCount >= MIN_LAWYERS_FOR_HUB_PAGE ? (
              <Link key={item.id} href={item.path} className="card card--link">
                {body}
              </Link>
            ) : (
              <div key={item.id} className="card" aria-disabled="true" style={{ opacity: 0.7 }}>
                {body}
                <p className="muted" style={{ margin: "0.5rem 0 0", fontSize: "0.85rem" }}>
                  Research in progress
                </p>
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
}
