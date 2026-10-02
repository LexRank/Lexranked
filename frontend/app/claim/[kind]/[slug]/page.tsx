import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import { ClaimForm } from "@/components/commercial/ClaimForm";
import { ADVERTISING_PATH } from "@/components/commercial/Commercial";
import { PageHeader } from "@/components/PageHeader";
import { DemoNotice } from "@/components/ui";
import { buildMetadata } from "@/lib/seo/metadata";
import { getLawFirm, getLawyer } from "@/lib/wordpress/api";

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

const KINDS = { lawyer: "lawyer", "law-firm": "law_firm" } as const;

const loadProfile = cache(async (kind: string, slug: string) => {
  const type = KINDS[kind as keyof typeof KINDS];
  if (!type) return null;
  const profile = type === "lawyer" ? await getLawyer(slug) : await getLawFirm(slug);
  return profile ? { type, profile } : null;
});

export async function generateMetadata(props: PageProps<"/claim/[kind]/[slug]">): Promise<Metadata> {
  const { kind, slug } = await props.params;
  const data = await loadProfile(kind, slug);
  if (!data) return { robots: { index: false } };
  return buildMetadata({
    title: `Claim the profile of ${data.profile.name}`,
    description: `Are you ${data.profile.name}? Claim this LexRanked profile for free to request corrections. Claiming never changes a ranking position.`,
    path: `/claim/${kind}/${slug}/`,
    noindex: true,
  });
}

const STEPS = [
  ["Send the form", "Tell us who you are. Lawyers claiming their own profile give their state bar number."],
  ["Confirm your email", "We email you a link. Open it and press the button to confirm your address (valid for 48 hours)."],
  ["Identity check", "An editor checks your identity against the state bar record, the firm website or by phone before approving."],
];

export default async function ClaimPage(props: PageProps<"/claim/[kind]/[slug]">) {
  const { kind, slug } = await props.params;
  const data = await loadProfile(kind, slug);
  if (!data) notFound();
  const { type, profile } = data;
  const claimed = profile.commercial.claimed ?? profile.commercial.status !== "free";

  return (
    <>
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: profile.name, path: profile.path },
          { name: "Claim profile", path: `/claim/${kind}/${slug}/` },
        ]}
        eyebrow="For lawyers and firms"
        title={`Claim the profile of ${profile.name}`}
        lead="Claiming is free. It lets you request corrections and, if you wish, buy clearly labelled advertising. It never changes a score or ranking position."
      />
      <div className="container section layout-sidebar">
        <div className="stack">
          {profile.isDemo && <DemoNotice />}
          {claimed && (
            <div className="notice notice--info">
              <p style={{ margin: 0 }}>
                This profile has already been claimed. If you believe that is a mistake, send the form anyway and explain in the message; an
                editor will review it.
              </p>
            </div>
          )}
          <section className="card" aria-labelledby="claim-form-heading">
            <h2 id="claim-form-heading" style={{ fontSize: "1.4rem" }}>
              Your details
            </h2>
            <ClaimForm entityType={type} entityId={profile.id} entityName={profile.name} />
          </section>
        </div>
        <aside className="stack">
          <div className="card">
            <p className="panel-title">How it works</p>
            <ol className="steps">
              {STEPS.map(([title, body]) => (
                <li key={title}>
                  <strong>{title}</strong>
                  <span>{body}</span>
                </li>
              ))}
            </ol>
          </div>
          <div className="card">
            <p className="panel-title">What claiming does not do</p>
            <p className="muted" style={{ fontSize: "0.9rem", margin: 0 }}>
              It does not raise your score, change your position or mark the profile verified. Corrections still need a public source. Read
              our <Link href={ADVERTISING_PATH}>advertising policy</Link> and the <Link href="/methodology/">methodology</Link>.
            </p>
          </div>
          <p className="muted" style={{ fontSize: "0.82rem" }}>
            We use your details only to handle this claim. If the claim is rejected or not confirmed, we erase them after 30 days.
          </p>
        </aside>
      </div>
    </>
  );
}
