import type { Metadata } from "next";
import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState, Monogram, UnavailableNotice } from "@/components/ui";
import { load } from "@/lib/data/loaders";
import { formatLocation } from "@/lib/format";
import { searchEntities } from "@/lib/wordpress/api";

// Search result pages are never indexed (spec §20) and are excluded in robots.txt.
export const metadata: Metadata = {
  title: "Search",
  robots: { index: false, follow: true },
};

function readQuery(value: string | string[] | undefined): string {
  const q = (Array.isArray(value) ? value[0] : value) ?? "";
  return q.trim().slice(0, 100);
}

export default async function SearchPage(props: PageProps<"/search">) {
  const q = readQuery((await props.searchParams).q);
  const valid = q.length >= 2;
  const result = valid ? await load(async () => (await searchEntities(q)).data) : null;

  return (
    <>
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Search", path: "/search/" },
        ]}
        eyebrow="Search"
        title={valid ? `Results for “${q}”` : "Search lawyers and law firms"}
      >
        <form action="/search/" method="get" role="search" style={{ display: "flex", gap: "0.5rem", marginTop: "1.25rem", maxWidth: "36rem" }}>
          <label htmlFor="search-q" className="sr-only">
            Search by name
          </label>
          <input
            id="search-q"
            type="search"
            name="q"
            defaultValue={q}
            minLength={2}
            maxLength={100}
            required
            placeholder="Lawyer or firm name"
            style={{ flex: 1, minWidth: 0, height: "3rem", padding: "0 1rem", borderRadius: "999px", border: "0", font: "inherit" }}
          />
          <button className="btn btn--primary" type="submit">
            Search
          </button>
        </form>
      </PageHeader>
      <div className="container section stack">
        {result && !result.ok && <UnavailableNotice />}
        {!valid && <p className="muted">Enter at least two characters of a lawyer&apos;s or firm&apos;s name.</p>}
        {result?.ok && result.data.length === 0 && (
          <EmptyState title="No matches">
            <p>
              Try a different spelling, or browse <Link href="/rankings/">rankings</Link> and <Link href="/states/">locations</Link>.
            </p>
          </EmptyState>
        )}
        {result?.ok && result.data.length > 0 && (
          <ul className="grid grid--2" style={{ listStyle: "none", padding: 0, margin: 0 }}>
            {result.data.map((r) => (
              <li key={`${r.type}-${r.id}`}>
                <Link href={r.path} className="card card--link" style={{ display: "flex", gap: "1rem", alignItems: "center" }}>
                  <Monogram name={r.name} square={r.type === "law_firm"} />
                  <span>
                    <strong style={{ display: "block", color: "var(--navy-900)" }}>{r.name}</strong>
                    <span className="muted" style={{ fontSize: "0.9rem" }}>
                      {r.type === "law_firm" ? "Law firm" : "Lawyer"}
                      {formatLocation(r.location) ? ` · ${formatLocation(r.location)}` : ""}
                    </span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}
