import type { ClaimSubmission } from "@/lib/wordpress/api";

/**
 * Validation of the public claim form (mirrors ClaimRequest.php, which
 * re-validates everything). Pure: used by the server action and tests.
 */

export const US_STATE_CODES = [
  "AL", "AK", "AZ", "AR", "CA", "CO", "CT", "DE", "DC", "FL", "GA", "HI", "ID", "IL", "IN", "IA", "KS", "KY", "LA", "ME", "MD", "MA",
  "MI", "MN", "MS", "MO", "MT", "NE", "NV", "NH", "NJ", "NM", "NY", "NC", "ND", "OH", "OK", "OR", "PA", "RI", "SC", "SD", "TN", "TX",
  "UT", "VT", "VA", "WA", "WV", "WI", "WY",
] as const;

/** Hidden field real people never fill in. */
export const HONEYPOT_FIELD = "company_website";

export type ClaimFieldErrors = Partial<Record<"name" | "email" | "phone" | "role" | "barNumber" | "barState" | "message" | "consent", string>>;

export type ParsedClaim = { ok: true; data: ClaimSubmission } | { ok: false; errors: ClaimFieldErrors } | { ok: "bot" };

const text = (v: FormDataEntryValue | null | undefined): string => (typeof v === "string" ? v : "").replace(/\s+/g, " ").trim();
const multiline = (v: FormDataEntryValue | null | undefined): string =>
  (typeof v === "string" ? v : "").replace(/\r\n?/g, "\n").replace(/[^\S\n]+/g, " ").replace(/\n{3,}/g, "\n\n").trim();

export function parseClaimForm(form: FormData): ParsedClaim {
  if (text(form.get(HONEYPOT_FIELD)) !== "") return { ok: "bot" };

  const entityType = text(form.get("entityType"));
  const entityId = Number.parseInt(text(form.get("entityId")), 10);
  const name = text(form.get("name"));
  const email = text(form.get("email")).toLowerCase();
  const phone = text(form.get("phone"));
  const role = text(form.get("role"));
  const barState = text(form.get("barState")).toUpperCase();
  const barNumber = text(form.get("barNumber"));
  const message = multiline(form.get("message"));
  const consent = form.get("consent") === "on" || form.get("consent") === "true";

  const errors: ClaimFieldErrors = {};
  if (name.length < 2 || name.length > 120) errors.name = "Enter your full name.";
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) || email.length > 254) errors.email = "Enter a valid email address.";
  const digits = phone.replace(/\D/g, "");
  if (phone !== "" && (!/^\+?[\d\s().-]+$/.test(phone) || digits.length < 7 || digits.length > 15)) errors.phone = "Enter a valid phone number or leave it empty.";
  if (role !== "self" && role !== "firm_representative") errors.role = "Choose who you are.";
  if (entityType === "law_firm" && role === "self") errors.role = "A firm profile is claimed by a firm representative.";
  if (barState !== "" && !(US_STATE_CODES as readonly string[]).includes(barState)) errors.barState = "Choose a state.";
  if (barNumber.length > 40) errors.barNumber = "The bar number is too long.";
  if (role === "self" && (barState === "" || barNumber === "")) errors.barNumber = "Your bar number and state are required to claim your own profile.";
  if (message.length > 1000) errors.message = "Keep the message under 1,000 characters.";
  if (/https?:\/\/|www\./i.test(message)) errors.message = "Please do not include links.";
  if (!consent) errors.consent = "Please confirm.";

  if ((entityType !== "lawyer" && entityType !== "law_firm") || !Number.isInteger(entityId) || entityId < 1) {
    return { ok: false, errors: { ...errors, name: errors.name ?? "This profile cannot be claimed. Reload the page and try again." } };
  }
  if (Object.keys(errors).length > 0) return { ok: false, errors };

  return {
    ok: true,
    data: {
      entityType,
      entityId,
      name,
      email,
      phone,
      role: role as ClaimSubmission["role"],
      barState,
      barNumber,
      message,
      consent: true,
    },
  };
}

/** Confirmation tokens are URL-safe base64 of 32 random bytes. */
export function isPlausibleToken(token: string | undefined | null): token is string {
  return typeof token === "string" && /^[A-Za-z0-9_-]{40,64}$/.test(token);
}
