import Link from "next/link";
import type { CommercialBlock, LawFirmSummary, LawyerSummary, PlacementDto, PremiumContentDto } from "@/types/api";
import { formatLocation, pluralize } from "@/lib/format";
import { DemoBadge, Monogram, VerificationBadge } from "../ui";

/**
 * Commercial UI (Phase 9). Everything here is visibly separate from the
 * organic ranking: its own labelled block, no position, no score, a
 * disclosure line and a link to the advertising policy.
 */

export const ADVERTISING_PATH = "/advertising/";

export function PaidBadge({ label }: { label: string }) {
  return <span className="badge badge--paid">{`${label} · Paid`}</span>;
}

/** "Claimed" is free and says who controls the profile; it is not an endorsement. */
export function ClaimedBadge({ commercial, entityType }: { commercial: CommercialBlock; entityType: "lawyer" | "law_firm" }) {
  const claimed = commercial.claimed ?? (commercial.status === "claimed" || commercial.status === "premium");
  if (!claimed) return null;
  return (
    <span className="badge badge--claimed" title="The profile owner confirmed their identity with LexRanked. Claiming is free and does not affect the ranking.">
      {`Claimed by the ${entityType === "law_firm" ? "firm" : "lawyer"}`}
    </span>
  );
}

function PlacementCard({ placement }: { placement: PlacementDto }) {
  const e = placement.entity;
  const isLawyer = e.type === "lawyer";
  const where = formatLocation(e.location);
  const meta = isLawyer
    ? [(e as LawyerSummary).title, (e as LawyerSummary).firm?.name].filter(Boolean).join(" · ") || "Attorney"
    : (e as LawFirmSummary).lawyerCount > 0
      ? pluralize((e as LawFirmSummary).lawyerCount, "lawyer")
      : "Law firm";
  return (
    <li className="placement-card">
      <Monogram name={e.name} square={!isLawyer} />
      <div style={{ minWidth: 0, flex: 1 }}>
        <p className="placement-card__label">
          <PaidBadge label={placement.label} />
        </p>
        <h3>
          <Link href={e.path}>{e.name}</Link>
        </h3>
        <p className="card__meta" style={{ margin: "0 0 0.5rem" }}>
          {meta}
          {where && <> · {where}</>}
        </p>
        <div className="entry__facts">
          <VerificationBadge status={e.verification.status} />
          {e.isDemo && <DemoBadge />}
        </div>
      </div>
    </li>
  );
}

/**
 * Sponsored (ranking pages) or Featured (hub pages) block. Rendered after the
 * organic list, never inside it, and never in structured data.
 */
export function PlacementBlock({ placements, product }: { placements: PlacementDto[]; product: "sponsored" | "featured" }) {
  if (placements.length === 0) return null;
  const title = product === "sponsored" ? "Sponsored" : "Featured profiles";
  const id = `${product}-placements`;
  return (
    <aside className="placements" aria-labelledby={id} data-placements={product}>
      <div className="placements__head">
        <h2 id={id}>{title}</h2>
        <p>
          {placements[0]?.disclosure ?? "Paid placement. Not part of the LexRanked ranking."}{" "}
          <Link href={ADVERTISING_PATH}>How advertising works</Link>
        </p>
      </div>
      <ul className="placements__list">
        {placements.map((p) => (
          <PlacementCard key={p.id} placement={p} />
        ))}
      </ul>
    </aside>
  );
}

/** Premium profile content: the owner's own words, labelled as paid. */
export function PremiumPanel({ content, name }: { content: PremiumContentDto | null | undefined; name: string }) {
  if (!content || (!content.message && !content.ctaUrl)) return null;
  return (
    <section className="card premium-panel" aria-labelledby="premium-heading">
      <p className="placement-card__label">
        <PaidBadge label={content.label} />
      </p>
      <h2 id="premium-heading" style={{ fontSize: "1.25rem" }}>
        From {name}
      </h2>
      {content.message && <p style={{ whiteSpace: "pre-line" }}>{content.message}</p>}
      {content.ctaUrl && (
        <p>
          <a className="btn btn--primary" href={content.ctaUrl} rel="sponsored noopener" target="_blank">
            Contact {name}
          </a>
        </p>
      )}
      <p className="muted" style={{ fontSize: "0.82rem", margin: 0 }}>
        {content.disclosure} <Link href={ADVERTISING_PATH}>Advertising policy</Link>
      </p>
    </section>
  );
}

/** Claim call-to-action on a profile. */
export function ClaimPanel({ commercial, entityType, slug }: { commercial: CommercialBlock; entityType: "lawyer" | "law_firm"; slug: string }) {
  const claimed = commercial.claimed ?? commercial.status !== "free";
  const kind = entityType === "law_firm" ? "law-firm" : "lawyer";
  return (
    <div className="card">
      <p className="panel-title">{claimed ? "Claimed profile" : "Is this your profile?"}</p>
      <p className="muted" style={{ fontSize: "0.9rem" }}>
        {claimed
          ? "The profile owner has confirmed their identity with LexRanked. Corrections still need a public source, and claiming never changes the ranking."
          : `Claim it for free to request corrections. An editor checks your identity first. Claiming never changes a score or position.`}
      </p>
      {!claimed && (
        <Link className="link-arrow" href={`/claim/${kind}/${slug}/`} rel="nofollow">
          Claim this profile
        </Link>
      )}
    </div>
  );
}
