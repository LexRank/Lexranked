"use server";

import { headers } from "next/headers";
import { parseContactForm, type ContactFieldErrors } from "@/lib/contact/validate";
import { clientKey, contactLimiter } from "@/lib/rateLimit";
import { sendContact } from "@/lib/wordpress/api";
import { WordPressApiError, WordPressNotConfiguredError } from "@/lib/wordpress/client";

/**
 * Server action for the contact form. The message is validated, rate-limited
 * per client and relayed to the CMS, which emails it to the editors; it is
 * never stored or logged.
 */

export interface ContactFormState {
  status: "idle" | "error" | "sent";
  message?: string;
  errors?: ContactFieldErrors;
}

const SENT: ContactFormState = {
  status: "sent",
  message: "Thank you, your message has been sent to the LexRanked editors. We reply by email to the address you entered.",
};

export async function sendContactAction(_prev: ContactFormState, form: FormData): Promise<ContactFormState> {
  if (!contactLimiter.take(clientKey(await headers()))) {
    return { status: "error", message: "Too many messages from your connection. Please try again in a few minutes." };
  }
  const parsed = parseContactForm(form);
  if (parsed.ok === "bot") return SENT;
  if (!parsed.ok) return { status: "error", message: "Please check the highlighted fields.", errors: parsed.errors };
  try {
    await sendContact(parsed.data);
    return SENT;
  } catch (error) {
    if (error instanceof WordPressApiError && error.status === 429) {
      return { status: "error", message: "Too many messages from this email today. Please try again tomorrow." };
    }
    if (error instanceof WordPressApiError && error.status === 400) {
      return { status: "error", message: "Please check the form and try again." };
    }
    if (!(error instanceof WordPressNotConfiguredError)) {
      const status = error instanceof WordPressApiError ? error.status : null;
      console.error(JSON.stringify({ level: "error", source: "contact", event: "send_failed", status }));
    }
    return { status: "error", message: "We could not send your message right now. Please try again later." };
  }
}
