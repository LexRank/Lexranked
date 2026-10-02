import type { Metadata } from "next";
import Link from "next/link";
import { ConfirmForm } from "@/components/commercial/ConfirmForm";
import { PageHeader } from "@/components/PageHeader";
import { isPlausibleToken } from "@/lib/claims/validate";
import { buildMetadata } from "@/lib/seo/metadata";

export const metadata: Metadata = buildMetadata({
  title: "Confirm your profile claim",
  description: "Confirm the email address you used to claim a LexRanked profile.",
  path: "/claim/confirm/",
  noindex: true,
});

export default async function ConfirmClaimPage(props: PageProps<"/claim/confirm">) {
  const { token } = await props.searchParams;
  const value = Array.isArray(token) ? token[0] : token;
  return (
    <>
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Confirm claim", path: "/claim/confirm/" },
        ]}
        eyebrow="Profile claim"
        title="Confirm your email address"
        lead="One click and your claim goes to an editor, who checks your identity before approving it."
      />
      <div className="container section" style={{ maxWidth: "44rem" }}>
        <div className="card stack">
          {isPlausibleToken(value) ? (
            <ConfirmForm token={value} />
          ) : (
            <div className="notice notice--error" role="alert">
              <p style={{ margin: 0 }}>
                This link is incomplete. Open the link from the email again, or start a new claim from the profile page.
              </p>
            </div>
          )}
          <p className="muted" style={{ fontSize: "0.85rem", margin: 0 }}>
            Did not ask to claim a profile? Ignore the email; nothing happens without this confirmation. <Link href="/">Back to LexRanked</Link>
          </p>
        </div>
      </div>
    </>
  );
}
