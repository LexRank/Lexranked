import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { cache } from "react";
import { FirmCard, LawyerCard } from "@/components/cards";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { JsonLd } from "@/components/JsonLd";
import { RankingPositions, ScoreSection, SourcesSection, VerificationSection } from "@/components/profile/sections";
import { CommercialBadge, DemoBadge, DemoNotice, Monogram, ScoreRing, StarRating, VerificationBadge } from "@/components/ui";
import { profileEligibility } from "@/lib/content/eligibility";
import { load } from "@/lib/data/loaders";
import { formatDate, formatLocation, isoDate } from "@/lib/format";
import { lawyerJsonLd, type Crumb } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";
import { getLawFirms, getLawyer, getLawyers } from "@/lib/wordpress/api";
import type { LawyerDetail } from "@/types/api";

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

const loadLawyer = cache((slug: string) => getLawyer(slug));

function crumbs(lawyer: LawyerDetail): Crumb[] {
  const list: Crumb[] = [{ name: "Home", path: "/" }];
  if (lawyer.location?.stateSlug && lawyer.location.state) list.push({ name: lawyer.location.state, path: `/states/${lawyer.location.stateSlug}/` });
  if (lawyer.location?.citySlug && lawyer.location.city) list.push({ name: lawyer.location.city, path: `/cities/${lawyer.location.citySlug}/` });
  else list.push({ name: "Lawyers", path: "/lawyers/" });
  list.push({ name: lawyer.name, path: lawyer.path });
  return list;
}

export async function generateMetadata(props: PageProps<"/lawyers/[slug]">): Promise<Metadata> {
  const { slug } = await props.params;
  const lawyer = await loadLawyer(slug);
  if (!lawyer) return { robots: { index: false } };
  const where = formatLocation(lawyer.location);
  const practice = lawyer.practiceAreas[0]?.name;
  const title = [lawyer.name, practice && where ? `${practice} Lawyer in ${where}` : where].filter(Boolean).join(" – ");
  const facts = [
    lawyer.firm ? `${lawyer.title ?? "Attorney"} at ${lawyer.firm.name}` : null,
    lawyer.ranking.score !== null ? `LexRank score ${lawyer.ranking.score.toFixed(2)}` : null,
    lawyer.verification.status === "verified" ? "verified profile" : null,
  ].filter(Boolean);
  return buildMetadata({
    title,
    description: lawyer.summary ? lawyer.summary : `${lawyer.name}${where ? `, ${where}` : ""}. ${facts.join(", ")}. Practice areas, credentials, verification status and sources.`,
    path: lawyer.path,
    type: "profile",
    noindex: !profileEligibility(lawyer).indexable,
  });
}

export default async function LawyerPage(props: PageProps<"/lawyers/[slug]">) {
  const { slug } = await props.params;
  const lawyer = await loadLawyer(slug);
  if (!lawyer) notFound();
  if (lawyer.slug !== slug) permanentRedirect(lawyer.path); // numeric IDs → canonical slug URL

  const city = lawyer.location?.citySlug ?? undefined;
  const practice = lawyer.practiceAreas[0]?.slug;
  const [related, firms] = await Promise.all([
    load(async () => (await getLawyers({ city, practice_area: practice, per_page: 7 })).data.filter((l) => l.id !== lawyer.id).slice(0, 4)),
    load(async () => (city ? (await getLawFirms({ city, per_page: 4 })).data.filter((f) => f.id !== lawyer.firm?.id).slice(0, 3) : [])),
  ]);

  const where = formatLocation(lawyer.location);
  const p = lawyer.professional;
  const bestPosition = lawyer.rankings.filter((r) => r.path).sort((a, b) => a.position - b.position)[0];

  return (
    <>
      <JsonLd data={lawyerJsonLd(lawyer)} />
      <header className="page-header">
        <div className="container">
          <Breadcrumbs crumbs={crumbs(lawyer)} />
          <div className="profile-head" style={{ marginTop: "1.5rem" }}>
            <div className="profile-head__id">
              <Monogram name={lawyer.name} size="lg" />
              <div>
                <p className="eyebrow" style={{ margin: 0 }}>
                  {lawyer.practiceAreas.map((a) => a.name).join(" · ") || "Attorney"}
                </p>
                <h1>{lawyer.name}</h1>
                <p className="profile-head__sub">
                  {lawyer.title ?? "Attorney"}
                  {lawyer.firm && (
                    <>
                      {" at "}
                      <Link href={lawyer.firm.path}>{lawyer.firm.name}</Link>
                    </>
                  )}
                  {where && <> · {where}</>}
                </p>
                <div className="profile-head__badges">
                  <VerificationBadge status={lawyer.verification.status} />
                  <CommercialBadge commercial={lawyer.commercial} />
                  {lawyer.isDemo && <DemoBadge />}
                  {bestPosition && (
                    <Link
                      href={bestPosition.path as string}
                      className="badge badge--paid"
                      style={{ background: "rgb(255 255 255 / 10%)", color: "var(--brass-300)", borderColor: "rgb(217 194 154 / 40%)", textDecoration: "none" }}
                    >
                      #{bestPosition.position} · {bestPosition.title}
                    </Link>
                  )}
                </div>
              </div>
            </div>
            <ScoreRing score={lawyer.ranking.score} size="lg" />
          </div>
        </div>
      </header>

      <div className="container section layout-sidebar">
        <div className="stack">
          {lawyer.isDemo && <DemoNotice />}
          {lawyer.summary && (
            <section className="overview" aria-label="Profile summary">
              <p className="overview__summary" style={{ margin: 0 }}>
                {lawyer.summary}
              </p>
            </section>
          )}

          <dl className="facts">
            {lawyer.rating !== null && (
              <div>
                <dt>Client rating</dt>
                <dd>
                  <StarRating rating={lawyer.rating} count={lawyer.reviewCount} />
                </dd>
              </div>
            )}
            {p.yearsExperience !== null && (
              <div>
                <dt>Experience</dt>
                <dd>{p.yearsExperience} years</dd>
              </div>
            )}
            {p.barStatus && (
              <div>
                <dt>Bar status</dt>
                <dd style={{ textTransform: "capitalize" }}>
                  {p.barStatus}
                  {p.barState ? ` · ${p.barState}` : ""}
                </dd>
              </div>
            )}
            {p.languages.length > 0 && (
              <div>
                <dt>Languages</dt>
                <dd>{p.languages.join(", ")}</dd>
              </div>
            )}
          </dl>

          <ScoreSection
            score={lawyer.ranking.score}
            scoreVersion={lawyer.ranking.scoreVersion}
            calculatedAt={lawyer.ranking.calculatedAt}
            breakdown={lawyer.ranking.breakdown}
          />

          <RankingPositions rankings={lawyer.rankings} />

          {lawyer.bio && (
            <section aria-labelledby="about-heading">
              <h2 id="about-heading" style={{ fontSize: "1.4rem" }}>
                About {lawyer.firstName ?? lawyer.name}
              </h2>
              <div className="prose" dangerouslySetInnerHTML={{ __html: lawyer.bio }} />
            </section>
          )}

          <section className="card" aria-labelledby="professional-heading">
            <h2 id="professional-heading" style={{ fontSize: "1.4rem" }}>
              Professional information
            </h2>
            <dl className="kv">
              <dt>Practice areas</dt>
              <dd>
                <ul className="chips">
                  {lawyer.practiceAreas.map((a) => (
                    <li key={a.slug}>
                      <Link className="chip chip--brass" href={`/practice-areas/${a.slug}/`}>
                        {a.name}
                      </Link>
                    </li>
                  ))}
                </ul>
              </dd>
              {p.barState && (
                <>
                  <dt>Bar admission</dt>
                  <dd>
                    {p.barState}
                    {p.barNumber ? ` · No. ${p.barNumber}` : ""}
                  </dd>
                </>
              )}
              {p.education.length > 0 && (
                <>
                  <dt>Education</dt>
                  <dd>
                    {p.education.map((e, i) => (
                      <div key={i}>{[e.degree, e.institution, e.year].filter(Boolean).join(", ")}</div>
                    ))}
                  </dd>
                </>
              )}
              {p.awards.length > 0 && (
                <>
                  <dt>Awards</dt>
                  <dd>
                    {p.awards.map((a, i) => (
                      <div key={i}>{[a.name, a.issuer, a.year].filter(Boolean).join(", ")}</div>
                    ))}
                  </dd>
                </>
              )}
            </dl>
          </section>

          <VerificationSection verification={lawyer.verification} freshness={lawyer.freshness} />
          <SourcesSection sources={lawyer.sources} />

          {related.ok && related.data.length > 0 && (
            <section>
              <h2 style={{ fontSize: "1.4rem" }}>Related lawyers{lawyer.location?.city ? ` in ${lawyer.location.city}` : ""}</h2>
              <div className="grid grid--2">
                {related.data.map((l) => (
                  <LawyerCard key={l.id} lawyer={l} />
                ))}
              </div>
            </section>
          )}
          {firms.ok && firms.data.length > 0 && (
            <section>
              <h2 style={{ fontSize: "1.4rem" }}>Law firms{lawyer.location?.city ? ` in ${lawyer.location.city}` : ""}</h2>
              <div className="grid grid--2">
                {firms.data.map((f) => (
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
              {lawyer.contact.website && (
                <dd>
                  <a href={lawyer.contact.website} rel="nofollow noopener noreferrer" target="_blank">
                    Visit website
                  </a>
                </dd>
              )}
              {lawyer.contact.phone && (
                <dd>
                  <a href={`tel:${lawyer.contact.phone.replace(/[^\d+]/g, "")}`}>{lawyer.contact.phone}</a>
                </dd>
              )}
              {!lawyer.contact.website && !lawyer.contact.phone && <dd className="muted">No verified contact details yet.</dd>}
            </dl>
          </div>
          <div className="card">
            <p className="panel-title">Data freshness</p>
            <p style={{ margin: 0, fontSize: "0.92rem" }}>
              {lawyer.verification.verifiedAt ? (
                <>
                  Data verified{" "}
                  <strong>
                    <time dateTime={isoDate(lawyer.verification.verifiedAt)}>{formatDate(lawyer.verification.verifiedAt)}</time>
                  </strong>
                </>
              ) : (
                "Not yet verified."
              )}
            </p>
            {lawyer.updatedAt && (
              <p className="muted" style={{ margin: "0.5rem 0 0", fontSize: "0.85rem" }}>
                Profile updated {formatDate(lawyer.updatedAt)}
              </p>
            )}
          </div>
          <div className="card">
            <p className="panel-title">Explore</p>
            <ul className="chips">
              {lawyer.firm && (
                <li>
                  <Link className="chip" href={lawyer.firm.path}>
                    {lawyer.firm.name}
                  </Link>
                </li>
              )}
              {lawyer.location?.citySlug && (
                <li>
                  <Link className="chip" href={`/cities/${lawyer.location.citySlug}/`}>
                    Lawyers in {lawyer.location.city}
                  </Link>
                </li>
              )}
              {lawyer.location?.stateSlug && (
                <li>
                  <Link className="chip" href={`/states/${lawyer.location.stateSlug}/`}>
                    {lawyer.location.state}
                  </Link>
                </li>
              )}
            </ul>
          </div>
          <p className="muted" style={{ fontSize: "0.8rem" }}>
            Rankings are based on the LexRank methodology and publicly available, verified information. They are not an endorsement
            and not legal advice.
          </p>
        </aside>
      </div>
    </>
  );
}
