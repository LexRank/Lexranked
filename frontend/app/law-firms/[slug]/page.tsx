import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { cache } from "react";
import { FirmCard, LawyerCard } from "@/components/cards";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { JsonLd } from "@/components/JsonLd";
import { RankingPositions, ScoreSection, SourcesSection, VerificationSection } from "@/components/profile/sections";
import { ClaimPanel, ClaimedBadge, PremiumPanel } from "@/components/commercial/Commercial";
import { DemoBadge, DemoNotice, Monogram, ScoreRing, StarRating, VerificationBadge } from "@/components/ui";
import { profileEligibility } from "@/lib/content/eligibility";
import { load } from "@/lib/data/loaders";
import { formatDate, formatLocation, isoDate, pluralize } from "@/lib/format";
import { lawFirmJsonLd, type Crumb } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import { getLawFirm, getLawFirms } from "@/lib/wordpress/api";
import type { LawFirmDetail } from "@/types/api";

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

const loadFirm = cache((slug: string) => getLawFirm(slug));

function crumbs(firm: LawFirmDetail): Crumb[] {
  const list: Crumb[] = [
    { name: "Home", path: "/" },
    { name: "Law firms", path: "/law-firms/" },
  ];
  if (firm.location?.citySlug && firm.location.city) list.push({ name: firm.location.city, path: `/cities/${firm.location.citySlug}/` });
  list.push({ name: firm.name, path: firm.path });
  return list;
}

export async function generateMetadata(props: PageProps<"/law-firms/[slug]">): Promise<Metadata> {
  const { slug } = await props.params;
  const firm = await loadFirm(slug);
  if (!firm) return { robots: { index: false } };
  const where = formatLocation(firm.location);
  const practice = firm.practiceAreas[0]?.name;
  return buildMetadata({
    title: [firm.name, practice && where ? `${practice} Law Firm in ${where}` : where].filter(Boolean).join(" – "),
    description: firm.summary ? firm.summary : `${firm.name}${where ? `, ${where}` : ""}: ${pluralize(firm.lawyers.length, "lawyer")} profiled${firm.ranking.score !== null ? `, LexRank score ${firm.ranking.score.toFixed(2)}` : ""}. Verification status, sources and related rankings.`,
    path: firm.path,
    type: "profile",
    noindex: !profileEligibility(firm).indexable,
  });
}

export default async function LawFirmPage(props: PageProps<"/law-firms/[slug]">) {
  const { slug } = await props.params;
  const firm = await loadFirm(slug);
  if (!firm) notFound();
  if (firm.slug !== slug) permanentRedirect(firm.path);

  const city = firm.location?.citySlug ?? undefined;
  const others = await load(async () => (city ? (await getLawFirms({ city, per_page: 5 })).data.filter((f) => f.id !== firm.id).slice(0, 4) : []));
  const where = formatLocation(firm.location);

  return (
    <>
      <JsonLd data={lawFirmJsonLd(firm)} />
      <header className="page-header">
        <div className="container">
          <Breadcrumbs crumbs={crumbs(firm)} />
          <div className="profile-head" style={{ marginTop: "1.5rem" }}>
            <div className="profile-head__id">
              <Monogram name={firm.name} size="lg" square />
              <div>
                <p className="eyebrow" style={{ margin: 0 }}>
                  {firm.practiceAreas.map((a) => a.name).join(" · ") || "Law firm"}
                </p>
                <h1>{firm.name}</h1>
                <p className="profile-head__sub">
                  {[where, firm.lawyers.length > 0 ? pluralize(firm.lawyers.length, "lawyer") : null].filter(Boolean).join(" · ")}
                </p>
                <div className="profile-head__badges">
                  <VerificationBadge status={firm.verification.status} />
                  <ClaimedBadge commercial={firm.commercial} entityType="law_firm" />
                  {firm.isDemo && <DemoBadge />}
                </div>
              </div>
            </div>
            <ScoreRing score={firm.ranking.score} size="lg" />
          </div>
        </div>
      </header>

      <div className="container section layout-sidebar">
        <div className="stack">
          {firm.isDemo && <DemoNotice />}
          {firm.summary && (
            <section className="overview" aria-label="Profile summary">
              <p className="overview__summary" style={{ margin: 0 }}>
                {firm.summary}
              </p>
            </section>
          )}
          {firm.rating !== null && (
            <dl className="facts">
              <div>
                <dt>Client rating</dt>
                <dd>
                  <StarRating rating={firm.rating} count={firm.reviewCount} />
                </dd>
              </div>
              <div>
                <dt>Lawyers profiled</dt>
                <dd>{firm.lawyers.length}</dd>
              </div>
            </dl>
          )}
          <ScoreSection score={firm.ranking.score} scoreVersion={firm.ranking.scoreVersion} calculatedAt={firm.ranking.calculatedAt} breakdown={firm.ranking.breakdown} />
          <RankingPositions rankings={firm.rankings} />
          {firm.description && (
            <section aria-labelledby="about-heading">
              <h2 id="about-heading" style={{ fontSize: "1.4rem" }}>
                About the firm
              </h2>
              <div className="prose" dangerouslySetInnerHTML={{ __html: firm.description }} />
            </section>
          )}
          <PremiumPanel content={firm.premiumContent} name={firm.name} />
          {firm.lawyers.length > 0 && (
            <section>
              <h2 style={{ fontSize: "1.4rem" }}>Lawyers at {firm.name}</h2>
              <div className="grid grid--2">
                {firm.lawyers.map((l) => (
                  <LawyerCard key={l.id} lawyer={l} />
                ))}
              </div>
            </section>
          )}
          <VerificationSection verification={firm.verification} freshness={firm.freshness} />
          <SourcesSection sources={firm.sources} />
          {others.ok && others.data.length > 0 && (
            <section>
              <h2 style={{ fontSize: "1.4rem" }}>Other law firms{firm.location?.city ? ` in ${firm.location.city}` : ""}</h2>
              <div className="grid grid--2">
                {others.data.map((f) => (
                  <FirmCard key={f.id} firm={f} />
                ))}
              </div>
            </section>
          )}
        </div>
        <aside className="stack">
          <div className="card">
            <p className="panel-title">Contact</p>
            <dl className="kv" style={{ gridTemplateColumns: "1fr" }}>
              {firm.address.street && (
                <dd>
                  {firm.address.street}
                  {where ? <><br />{where}{firm.address.zipCode ? ` ${firm.address.zipCode}` : ""}</> : null}
                </dd>
              )}
              {firm.contact.website && (
                <dd>
                  <a href={firm.contact.website} rel="nofollow noopener noreferrer" target="_blank">
                    Visit website
                  </a>
                </dd>
              )}
              {firm.contact.phone && (
                <dd>
                  <a href={`tel:${firm.contact.phone.replace(/[^\d+]/g, "")}`}>{firm.contact.phone}</a>
                </dd>
              )}
            </dl>
          </div>
          <div className="card">
            <p className="panel-title">Data freshness</p>
            <p style={{ margin: 0, fontSize: "0.92rem" }}>
              {firm.verification.verifiedAt ? (
                <>
                  Data verified{" "}
                  <strong>
                    <time dateTime={isoDate(firm.verification.verifiedAt)}>{formatDate(firm.verification.verifiedAt)}</time>
                  </strong>
                </>
              ) : (
                "Not yet verified."
              )}
            </p>
          </div>
          {firm.location?.citySlug && (
            <div className="card">
              <p className="panel-title">Explore</p>
              <ul className="chips">
                <li>
                  <Link className="chip" href={`/cities/${firm.location.citySlug}/`}>
                    Lawyers in {firm.location.city}
                  </Link>
                </li>
                {firm.practiceAreas.map((a) => (
                  <li key={a.slug}>
                    <Link className="chip chip--brass" href={`/practice-areas/${a.slug}/`}>
                      {a.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}
          <ClaimPanel commercial={firm.commercial} entityType="law_firm" slug={firm.slug} />
        </aside>
      </div>
    </>
  );
}
