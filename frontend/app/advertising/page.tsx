import type { Metadata } from "next";
import Link from "next/link";
import { JsonLd } from "@/components/JsonLd";
import { PageHeader } from "@/components/PageHeader";
import { SITE_NAME } from "@/lib/config/site";
import { rankingPageJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

export const metadata: Metadata = buildMetadata({
  title: "Advertising and Claimed Profiles",
  description: "How LexRanked keeps paid placements separate from its rankings: claimed profiles, premium profiles, featured and sponsored listings, and the rules they follow.",
  path: "/advertising/",
});

const PRODUCTS = [
  {
    name: "Claimed profile",
    price: "Free",
    body: "The lawyer or firm confirms their identity with an editor. The profile shows “Claimed by the lawyer / firm”, and the owner can request corrections — each backed by a public source.",
  },
  {
    name: "Premium profile",
    price: "Paid",
    body: "A short message in the owner’s own words and a contact button on their profile page, labelled “Premium profile · Paid”. The text is not evidence and is not used in the score.",
  },
  {
    name: "Featured profile",
    price: "Paid",
    body: "A labelled card in the “Featured profiles” block of a state, city or practice-area page, after the editorial lists.",
  },
  {
    name: "Sponsored listing",
    price: "Paid",
    body: "A labelled card in the “Sponsored” block of one ranking page, below the ranked list. It has no position number and no score.",
  },
];

const RULES = [
  "Payment never changes a score, a ranking position, a verification status or which profiles are ranked. The ranking code cannot read commercial data at all.",
  "Every paid element is labelled “Paid” with a short disclosure, and is shown outside the ranked list. Paid links carry rel=\"sponsored\".",
  "Only the profile owner can buy placements: the profile must be claimed and the claimant’s identity checked.",
  "Only lawyers in good standing are placed: profiles with an inactive, suspended, retired or disbarred bar status are not eligible.",
  "Placements are relevant to the page: a sponsored lawyer must practise in the ranking’s location and practice area.",
  "The number of placements per page is capped, and they are shown in a fixed order (oldest booking first), never by price or score.",
  "Paid placements are never included in the rankings’ structured data for search engines.",
];

export default function AdvertisingPage() {
  return (
    <>
      <JsonLd
        data={rankingPageJsonLd({
          name: "Advertising and claimed profiles",
          path: "/advertising/",
          description: "How LexRanked keeps paid placements separate from its rankings.",
          dateModified: null,
          reviewedBy: null,
          reviewedAt: null,
        })}
      />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Advertising", path: "/advertising/" },
        ]}
        eyebrow="Trust"
        title="Advertising and claimed profiles"
        lead={`${SITE_NAME} is funded in part by advertising from lawyers and law firms. Here is exactly what they can and cannot buy.`}
      />
      <div className="container section stack">
        <section className="card" aria-labelledby="rules-heading">
          <h2 id="rules-heading" style={{ fontSize: "1.4rem" }}>
            The rules
          </h2>
          <ul className="rules">
            {RULES.map((rule) => (
              <li key={rule}>{rule}</li>
            ))}
          </ul>
        </section>

        <section aria-labelledby="products-heading">
          <h2 id="products-heading">What lawyers and firms can do</h2>
          <div className="grid grid--4">
            {PRODUCTS.map((p) => (
              <div key={p.name} className="card">
                <p className="eyebrow" style={{ marginBottom: "0.35rem" }}>
                  {p.price}
                </p>
                <h3 style={{ fontSize: "1.15rem" }}>{p.name}</h3>
                <p className="muted" style={{ margin: 0, fontSize: "0.92rem" }}>
                  {p.body}
                </p>
              </div>
            ))}
          </div>
        </section>

        <section className="card" aria-labelledby="claim-heading">
          <h2 id="claim-heading" style={{ fontSize: "1.4rem" }}>
            Claiming your profile
          </h2>
          <p>
            Open your profile and choose <strong>Claim this profile</strong>. Confirm your email, and an editor checks your identity against the
            state bar record, your firm’s website or by phone. Claiming is free. Read <Link href="/methodology/">how rankings work</Link> to see
            what does affect a score.
          </p>
        </section>
      </div>
    </>
  );
}
