import type { Metadata } from "next";
import { SITE_NAME } from "@/lib/config/site";

// Phase 1 placeholder. Real content arrives in Phase 3 and is sourced from
// the LexRanked REST API; no lawyer data is shown until it has been verified.
export const metadata: Metadata = {
  alternates: { canonical: "/" },
};

export default function HomePage() {
  return (
    <section className="container hero">
      <h1>{SITE_NAME}</h1>
      <p className="hero__lead">
        Transparent, source-backed rankings of lawyers and law firms in the
        United States.
      </p>
      <ul className="principles">
        <li>Every ranking is calculated with a published, reproducible methodology.</li>
        <li>Every important fact is traceable to a source.</li>
        <li>Payment never changes an organic ranking score.</li>
      </ul>
      <p className="hero__status">Rankings are being researched and verified. Launching soon.</p>
    </section>
  );
}
