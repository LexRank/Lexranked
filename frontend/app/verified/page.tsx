import type { Metadata } from "next";
import { LawyerCard } from "@/components/cards";
import { PageHeader } from "@/components/PageHeader";
import { DemoNotice, EmptyState, UnavailableNotice, VerificationBadge } from "@/components/ui";
import { allLawyers, load } from "@/lib/data/loaders";
import { buildMetadata } from "@/lib/seo/metadata";

export const revalidate = 300;

export const metadata: Metadata = buildMetadata({
  title: "Verified Lawyer Profiles",
  description: "What a verified LexRanked profile means, which checks are required, and the lawyers whose profiles are currently verified.",
  path: "/verified/",
});

const CHECKS = [
  ["Identity", "The person is who the profile says they are."],
  ["License", "The lawyer holds a license to practice law."],
  ["Bar status", "Bar membership is active according to the official bar or regulator."],
];

export default async function VerifiedPage() {
  const result = await load(() => allLawyers({ orderby: "score", order: "desc" }));
  const verified = result.ok ? result.data.filter((l) => l.verification.status === "verified") : [];
  return (
    <>
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Verification", path: "/verified/" },
        ]}
        eyebrow="Trust"
        title="What “verified” means"
        lead="We only call a profile verified when every required check has passed against an authoritative source - and the result has not expired."
      />
      <div className="container section stack">
        <div className="grid grid--3">
          {CHECKS.map(([title, body]) => (
            <div key={title} className="card">
              <VerificationBadge status="verified" />
              <h3 style={{ fontSize: "1.1rem", marginTop: "0.75rem" }}>{title}</h3>
              <p className="muted" style={{ margin: 0, fontSize: "0.92rem" }}>
                {body}
              </p>
            </div>
          ))}
        </div>
        <p className="muted" style={{ fontSize: "0.92rem" }}>
          Verification is never for sale: a claimed or paid profile is not automatically verified. Checks expire and are repeated on a
          schedule, and each profile shows when its data was last verified.
        </p>

        <h2>Verified lawyers</h2>
        {!result.ok && <UnavailableNotice />}
        {verified.some((l) => l.isDemo) && <DemoNotice />}
        {result.ok && verified.length === 0 ? (
          <EmptyState title="No verified profiles yet">
            <p>Profiles appear here as soon as all of their required checks pass.</p>
          </EmptyState>
        ) : (
          <div className="grid grid--2">
            {verified.map((l) => (
              <LawyerCard key={l.id} lawyer={l} />
            ))}
          </div>
        )}
      </div>
    </>
  );
}
