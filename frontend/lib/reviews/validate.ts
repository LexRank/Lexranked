import type { ReviewSubmission } from "@/lib/wordpress/api";

/**
 * Validation of the public review form (mirrors ReviewRequest.php, which
 * re-validates everything). Pure: used by the server action and tests.
 */

export const REVIEW_HONEYPOT = "website_url";
export const MIN_BODY = 40;
export const MAX_BODY = 2000;

export type ReviewFieldErrors = Partial<Record<"rating" | "title" | "body" | "name" | "email" | "serviceYear" | "client", string>>;

export type ParsedReview = { ok: true; data: ReviewSubmission } | { ok: false; errors: ReviewFieldErrors } | { ok: "bot" };

const text = (v: FormDataEntryValue | null | undefined): string => (typeof v === "string" ? v : "").replace(/\s+/g, " ").trim();
const multiline = (v: FormDataEntryValue | null | undefined): string =>
  (typeof v === "string" ? v : "").replace(/\r\n?/g, "\n").replace(/[^\S\n]+/g, " ").replace(/\n{3,}/g, "\n\n").trim();

export function parseReviewForm(form: FormData, now: Date = new Date()): ParsedReview {
  if (text(form.get(REVIEW_HONEYPOT)) !== "") return { ok: "bot" };

  const entityType = text(form.get("entityType"));
  const entityId = Number.parseInt(text(form.get("entityId")), 10);
  const rating = Number.parseInt(text(form.get("rating")), 10);
  const title = text(form.get("title"));
  const body = multiline(form.get("body"));
  const name = text(form.get("name"));
  const email = text(form.get("email")).toLowerCase();
  const serviceYear = Number.parseInt(text(form.get("serviceYear")), 10);
  const client = form.get("client") === "on" || form.get("client") === "true";

  const errors: ReviewFieldErrors = {};
  if (!Number.isInteger(rating) || rating < 1 || rating > 5) errors.rating = "Choose a rating from 1 to 5 stars.";
  if (title.length > 120) errors.title = "Keep the title under 120 characters.";
  if (body.length < MIN_BODY) errors.body = `Describe your experience in at least ${MIN_BODY} characters.`;
  if (body.length > MAX_BODY) errors.body = `Keep the review under ${MAX_BODY.toLocaleString("en-US")} characters.`;
  if (/https?:\/\/|www\./i.test(`${body} ${title}`)) errors.body = "Please do not include links.";
  if (name.length < 2 || name.length > 80) errors.name = "Enter your name (only your first name and last initial are shown).";
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) || email.length > 254) errors.email = "Enter a valid email address.";
  if (!Number.isInteger(serviceYear) || serviceYear < 1950 || serviceYear > now.getUTCFullYear()) errors.serviceYear = "Choose the year the lawyer worked for you.";
  if (!client) errors.client = "Please confirm you were a client.";

  if ((entityType !== "lawyer" && entityType !== "law_firm") || !Number.isInteger(entityId) || entityId < 1) {
    return { ok: false, errors: { ...errors, rating: errors.rating ?? "This profile cannot be reviewed. Reload the page and try again." } };
  }
  if (Object.keys(errors).length > 0) return { ok: false, errors };
  return { ok: true, data: { entityType, entityId, rating, title, body, name, email, serviceYear, client: true } };
}

/** A Google Maps search for the profile: a plain link, no API key, nothing stored. */
export function googleReviewsUrl(name: string, city?: string | null, state?: string | null): string {
  const query = [name.replace(/\s*\(Demo\)\s*$/, ""), city, state].filter(Boolean).join(" ");
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`;
}
