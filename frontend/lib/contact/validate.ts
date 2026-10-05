import type { ContactSubmission } from "@/lib/wordpress/api";

/**
 * Validation of the public contact form (mirrors ContactService.php, which
 * re-validates everything). Pure: used by the server action and tests.
 */

export const CONTACT_HONEYPOT = "company_website";
export const MIN_MESSAGE = 20;
export const MAX_MESSAGE = 5000;

export const CONTACT_TOPICS = {
  general: "General question",
  correction: "Correction to a profile or ranking",
  lawyer: "I am a lawyer (my profile)",
  privacy: "Privacy request",
  press: "Press or data use",
} as const;

export type ContactTopic = keyof typeof CONTACT_TOPICS;
export type ContactFieldErrors = Partial<Record<"name" | "email" | "topic" | "page" | "message", string>>;
export type ParsedContact = { ok: true; data: ContactSubmission } | { ok: false; errors: ContactFieldErrors } | { ok: "bot" };

const text = (v: FormDataEntryValue | null | undefined): string => (typeof v === "string" ? v : "").replace(/\s+/g, " ").trim();

export function isContactTopic(value: string): value is ContactTopic {
  return Object.prototype.hasOwnProperty.call(CONTACT_TOPICS, value);
}

export function parseContactForm(form: FormData): ParsedContact {
  if (text(form.get(CONTACT_HONEYPOT)) !== "") return { ok: "bot" };
  const name = text(form.get("name"));
  const email = text(form.get("email")).toLowerCase();
  const topic = text(form.get("topic"));
  const page = text(form.get("page"));
  const raw = form.get("message");
  const message = (typeof raw === "string" ? raw : "").replace(/\r\n?/g, "\n").trim();

  const errors: ContactFieldErrors = {};
  if (name.length < 2 || name.length > 80) errors.name = "Enter your name.";
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) || email.length > 254) errors.email = "Enter a valid email address.";
  if (!isContactTopic(topic)) errors.topic = "Choose a topic.";
  if (page !== "" && (page.length > 300 || !/^(https?:\/\/\S+|\/\S*)$/.test(page))) errors.page = "Enter the page address, for example /lawyers/jane-doe/.";
  if (message.length < MIN_MESSAGE || message.length > MAX_MESSAGE) errors.message = `Write your message in ${MIN_MESSAGE} to ${MAX_MESSAGE.toLocaleString("en-US")} characters.`;
  if (Object.keys(errors).length > 0 || !isContactTopic(topic)) return { ok: false, errors };
  return { ok: true, data: { name, email, topic, page, message } };
}
