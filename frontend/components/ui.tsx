import type { CSSProperties, ReactNode } from "react";
import Link from "next/link";
import type { VerificationState } from "@/types/api";
import { formatCount, formatRating, formatScore, initials } from "@/lib/format";
import { AlertIcon, ClockIcon, InfoIcon, ShieldCheckIcon, XCircleIcon } from "./icons";

/** LexRank score ring. Null scores render an explicit "Not scored" state - never a guessed value. */
export function ScoreRing({ score, size = "md", label = "LexRank" }: { score: number | null; size?: "sm" | "md" | "lg"; label?: string }) {
  const value = formatScore(score);
  const sizeClass = size === "md" ? "" : ` score--${size}`;
  if (value === null) {
    return (
      <div className={`score score--empty${sizeClass}`} role="img" aria-label="Not yet scored">
        <span className="score__value">Not scored</span>
      </div>
    );
  }
  const pct = Math.max(0, Math.min(100, score as number));
  return (
    <div className={`score${sizeClass}`} style={{ "--pct": pct } as CSSProperties} role="img" aria-label={`${label} score ${value} out of 100`}>
      <span className="score__value" aria-hidden="true">
        {size === "sm" ? Math.round(pct * 10) / 10 : value}
        <span className="score__label">{label}</span>
      </span>
    </div>
  );
}

/** Star rating with review count. Rendered only when both values are known. */
export function StarRating({ rating, count }: { rating: number | null; count: number | null }) {
  const r = formatRating(rating);
  if (r === null) return null;
  const pct = `${Math.max(0, Math.min(5, rating as number)) * 20}%`;
  const reviews = formatCount(count);
  return (
    <span className="rating">
      <span className="stars" style={{ "--pct": pct } as CSSProperties} aria-hidden="true" />
      <span>
        <strong>{r}</strong>
        <span className="sr-only"> out of 5</span>
        {reviews !== null && <span className="muted"> · {reviews} {count === 1 ? "review" : "reviews"}</span>}
      </span>
    </span>
  );
}

const VERIFICATION_LABEL: Record<VerificationState, string> = {
  verified: "Verified",
  pending: "Verification pending",
  failed: "Verification failed",
  expired: "Verification expired",
  unverified: "Not verified",
};

export function VerificationBadge({ status }: { status: VerificationState }) {
  const Icon = status === "verified" ? ShieldCheckIcon : status === "pending" ? ClockIcon : status === "unverified" ? InfoIcon : XCircleIcon;
  return (
    <span className={`badge badge--${status}`}>
      <Icon />
      {VERIFICATION_LABEL[status] ?? status}
    </span>
  );
}


export function DemoBadge() {
  return <span className="badge badge--demo">Demo data</span>;
}

export function Monogram({ name, size, square }: { name: string; size?: "lg"; square?: boolean }) {
  return (
    <span className={`monogram${size ? ` monogram--${size}` : ""}${square ? " monogram--square" : ""}`} aria-hidden="true">
      {initials(name, square ? "organization" : "person")}
    </span>
  );
}

export function DemoNotice() {
  return (
    <div className="notice notice--demo" role="note">
      <AlertIcon />
      <p style={{ margin: 0 }}>
        <strong>Sample data.</strong> This page shows clearly-labelled demo records used to test LexRanked. They do not describe
        real lawyers or firms, and demo pages are hidden from search engines.
      </p>
    </div>
  );
}

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="empty">
      <h2>{title}</h2>
      {children}
    </div>
  );
}

export function UnavailableNotice() {
  return (
    <div className="notice notice--error" role="status">
      <AlertIcon />
      <p style={{ margin: 0 }}>This information is temporarily unavailable. Please try again in a few minutes.</p>
    </div>
  );
}

export function Pagination({ basePath, page, totalPages }: { basePath: string; page: number; totalPages: number }) {
  if (totalPages <= 1) return null;
  const href = (p: number) => (p === 1 ? basePath : `${basePath}?page=${p}`);
  return (
    <nav className="pagination" aria-label="Pagination">
      {page > 1 && <Link href={href(page - 1)} rel="prev">‹ Prev</Link>}
      {Array.from({ length: totalPages }, (_, i) => i + 1)
        .filter((p) => Math.abs(p - page) <= 2 || p === 1 || p === totalPages)
        .map((p) =>
          p === page ? (
            <span key={p} aria-current="page">
              {p}
            </span>
          ) : (
            <Link key={p} href={href(p)}>
              {p}
            </Link>
          ),
        )}
      {page < totalPages && <Link href={href(page + 1)} rel="next">Next ›</Link>}
    </nav>
  );
}
