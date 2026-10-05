import type { Metadata } from "next";
import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { ReviewConfirmForm } from "@/components/profile/ReviewConfirmForm";
import { isPlausibleToken } from "@/lib/claims/validate";
import { buildMetadata } from "@/lib/seo/metadata";

export const metadata: Metadata = buildMetadata({
  title: "Confirm your review",
  description: "Confirm the email address you used to review a lawyer on LexRanked.",
  path: "/reviews/confirm/",
  noindex: true,
});

export default async function ConfirmReviewPage(props: PageProps<"/reviews/confirm">) {
  const { token } = await props.searchParams;
  const value = Array.isArray(token) ? token[0] : token;
  return (
    <>
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Confirm review", path: "/reviews/confirm/" },
        ]}
        eyebrow="Client review"
        title="Confirm your review"
        lead="One click and your review goes to an editor, who reads it before it is published."
      />
      <div className="container section" style={{ maxWidth: "44rem" }}>
        <div className="card stack">
          {isPlausibleToken(value) ? (
            <ReviewConfirmForm token={value} />
          ) : (
            <div className="notice notice--error" role="alert">
              <p style={{ margin: 0 }}>This link is incomplete. Open the link from the email again, or write the review again from the profile page.</p>
            </div>
          )}
          <p className="muted" style={{ fontSize: "0.85rem", margin: 0 }}>
            Did not write a review? Ignore the email; nothing is published without this confirmation. <Link href="/">Back to LexRanked</Link>
          </p>
        </div>
      </div>
    </>
  );
}
