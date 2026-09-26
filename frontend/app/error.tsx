"use client";

import Link from "next/link";

// Rendered when a page fails (e.g. the LexRanked API is unreachable).
// No error details are shown to visitors.
export default function ErrorPage({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <section className="container section" style={{ textAlign: "center", maxWidth: "40rem" }}>
      <p className="eyebrow" style={{ justifyContent: "center" }}>
        Temporarily unavailable
      </p>
      <h1>Something went wrong on our side</h1>
      <p className="lead" style={{ margin: "0 auto 2rem" }}>
        We couldn&apos;t load this page right now. Please try again in a moment.
      </p>
      <p style={{ display: "flex", gap: "0.75rem", justifyContent: "center", flexWrap: "wrap" }}>
        <button className="btn btn--navy" type="button" onClick={() => reset()}>
          Try again
        </button>
        <Link className="btn btn--ghost" href="/">
          Go to homepage
        </Link>
      </p>
    </section>
  );
}
