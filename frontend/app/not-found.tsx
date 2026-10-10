import Link from "next/link";

export default function NotFound() {
  return (
    <section className="container section" style={{ textAlign: "center", maxWidth: "40rem" }}>
      <p className="eyebrow" style={{ justifyContent: "center" }}>
        Page not found
      </p>
      <h1>We couldn&apos;t find that page</h1>
      <p className="lead" style={{ margin: "0 auto 2rem" }}>
        It may have moved, or we haven&apos;t published it yet - we only publish rankings and location pages once enough verified data
        exists.
      </p>
      <p style={{ display: "flex", gap: "0.75rem", justifyContent: "center", flexWrap: "wrap" }}>
        <Link className="btn btn--navy" href="/rankings/">
          Browse rankings
        </Link>
        <Link className="btn btn--ghost" href="/">
          Go to homepage
        </Link>
      </p>
    </section>
  );
}
