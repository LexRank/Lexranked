import type { ReactNode } from "react";
import { PageHeader } from "./PageHeader";
import { DemoNotice, EmptyState, Pagination, UnavailableNotice } from "./ui";
import type { Crumb } from "@/lib/seo/jsonld";

/** Shared shell for paginated lawyer / firm listings. */
export function ListingPage({
  crumbs,
  eyebrow,
  title,
  lead,
  ok,
  hasDemo,
  empty,
  basePath,
  page,
  totalPages,
  nav,
  children,
}: {
  crumbs: Crumb[];
  eyebrow: string;
  title: string;
  lead: string;
  ok: boolean;
  hasDemo: boolean;
  empty: boolean;
  basePath: string;
  page: number;
  totalPages: number;
  /** Optional navigation above the list (e.g. guide categories). */
  nav?: ReactNode;
  children: ReactNode;
}) {
  return (
    <>
      <PageHeader crumbs={crumbs} eyebrow={eyebrow} title={title} lead={lead} />
      <div className="container section stack">
        {!ok && <UnavailableNotice />}
        {hasDemo && <DemoNotice />}
        {nav}
        {ok && empty ? (
          <EmptyState title="Nothing published yet">
            <p>Profiles appear here once they have been researched and verified.</p>
          </EmptyState>
        ) : (
          <div className="grid grid--2">{children}</div>
        )}
        <Pagination basePath={basePath} page={page} totalPages={totalPages} />
      </div>
    </>
  );
}

/** Parse ?page= safely (1-1000). */
export function parsePage(value: string | string[] | undefined): number {
  const n = Number.parseInt(Array.isArray(value) ? (value[0] ?? "1") : (value ?? "1"), 10);
  return Number.isFinite(n) && n >= 1 && n <= 1000 ? n : 1;
}
