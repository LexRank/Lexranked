/**
 * Presentation formatting (en-US). Pure functions; no data is invented —
 * null/undefined inputs render as null so callers can omit the element.
 */

const dateFormatter = new Intl.DateTimeFormat("en-US", {
  year: "numeric",
  month: "long",
  day: "numeric",
  timeZone: "UTC",
});

const numberFormatter = new Intl.NumberFormat("en-US");

/** "September 23, 2026" (UTC, so server and client agree). */
export function formatDate(iso: string | null | undefined): string | null {
  if (!iso) return null;
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? null : dateFormatter.format(date);
}

/** Machine-readable date for <time dateTime>. */
export function isoDate(iso: string | null | undefined): string | undefined {
  if (!iso) return undefined;
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? undefined : date.toISOString();
}

export function formatScore(score: number | null | undefined): string | null {
  return typeof score === "number" && Number.isFinite(score) ? score.toFixed(2) : null;
}

export function formatRating(rating: number | null | undefined): string | null {
  return typeof rating === "number" && Number.isFinite(rating) ? rating.toFixed(1) : null;
}

export function formatCount(n: number | null | undefined): string | null {
  return typeof n === "number" && Number.isFinite(n) ? numberFormatter.format(n) : null;
}

export function pluralize(n: number, singular: string, plural = `${singular}s`): string {
  return `${numberFormatter.format(n)} ${n === 1 ? singular : plural}`;
}

/** "bar_status" → "Bar status". */
export function humanize(key: string): string {
  const text = key.replace(/[_-]+/g, " ").trim();
  return text.charAt(0).toUpperCase() + text.slice(1);
}

const shortDateFormatter = new Intl.DateTimeFormat("en-US", {
  year: "numeric",
  month: "short",
  day: "numeric",
  timeZone: "UTC",
});

/** "Sep 23, 2026" for dense tables. */
export function formatShortDate(iso: string | null | undefined): string | null {
  if (!iso) return null;
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? null : shortDateFormatter.format(date);
}

/**
 * Up to two initials for monogram avatars. People use first + last name
 * ("Avery Example" → "AE"); organisations use the first two significant
 * words ("Bayside Sample Legal Group" → "BS").
 */
export function initials(name: string, kind: "person" | "organization" = "person"): string {
  const words = name
    .replace(/\(.*?\)/g, "")
    .split(/\s+/)
    .map((w) => w.replace(/[^A-Za-z]/g, ""))
    .filter((w) => w.length > 0 && !/^(and|of|the|law|llp|pa|pllc|llc|group)$/i.test(w));
  const letters =
    words.length < 2 ? words.slice(0, 1) : kind === "organization" ? words.slice(0, 2) : [words[0], words[words.length - 1]];
  return letters.map((w) => w[0]!.toUpperCase()).join("") || "?";
}

/** "Miami, FL" / "Florida" / null. */
export function formatLocation(
  location: { city: string | null; state: string | null; stateCode: string | null } | null,
): string | null {
  if (!location) return null;
  if (location.city && location.stateCode) return `${location.city}, ${location.stateCode}`;
  if (location.city && location.state) return `${location.city}, ${location.state}`;
  return location.state ?? location.city ?? null;
}

/** A name used mid-sentence: lowercase, but acronyms stay ("Condo and HOA" → "condo and HOA", "DUI" → "DUI"). */
export function inSentence(name: string): string {
  return name.replace(/[A-Za-z']+/g, (w) => (/^[A-Z]{2,}$/.test(w) ? w : w.toLowerCase()));
}
