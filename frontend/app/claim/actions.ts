"use server";

import { headers } from "next/headers";
import { isPlausibleToken, parseClaimForm, type ClaimFieldErrors } from "@/lib/claims/validate";
import { claimLimiter, clientKey } from "@/lib/rateLimit";
import { WordPressApiError, WordPressNotConfiguredError } from "@/lib/wordpress/client";
import { confirmClaim, submitClaim } from "@/lib/wordpress/api";

/**
 * Server actions for profile claims. They are public POST endpoints, so they
 * validate everything, rate-limit per client and never reveal whether a
 * profile is already claimed or an email was used before. Personal data is
 * forwarded to the CMS and never logged.
 */

export interface ClaimFormState {
  status: "idle" | "error" | "sent";
  message?: string;
  errors?: ClaimFieldErrors;
}

export interface ConfirmState {
  status: "idle" | "error" | "confirmed";
  message?: string;
}

const SENT: ClaimFormState = {
  status: "sent",
  message: "Check your inbox: we sent you a link to confirm your email address. It is valid for 48 hours.",
};

function log(event: string, error: unknown): void {
  const status = error instanceof WordPressApiError ? error.status : null;
  const code = error instanceof WordPressApiError ? error.code : "unknown";
  console.error(JSON.stringify({ level: "error", source: "claims", event, status, code }));
}

export async function submitClaimAction(_prev: ClaimFormState, form: FormData): Promise<ClaimFormState> {
  if (!claimLimiter.take(clientKey(await headers()))) {
    return { status: "error", message: "Too many attempts from your connection. Please try again in a few minutes." };
  }
  const parsed = parseClaimForm(form);
  if (parsed.ok === "bot") return SENT;
  if (!parsed.ok) return { status: "error", message: "Please check the highlighted fields.", errors: parsed.errors };

  try {
    await submitClaim(parsed.data);
    return SENT;
  } catch (error) {
    if (error instanceof WordPressApiError && error.status === 429) {
      return { status: "error", message: "Too many claim requests for this profile or email today. Please try again tomorrow." };
    }
    if (error instanceof WordPressApiError && error.status === 400) {
      return { status: "error", message: `Please check your details: ${error.message}` };
    }
    if (error instanceof WordPressApiError && error.status === 503) {
      return { status: "error", message: "Profile claims are temporarily closed. Please try again later." };
    }
    if (!(error instanceof WordPressNotConfiguredError)) log("submit_failed", error);
    return { status: "error", message: "We could not send your claim right now. Please try again later." };
  }
}

export async function confirmClaimAction(_prev: ConfirmState, form: FormData): Promise<ConfirmState> {
  if (!claimLimiter.take(clientKey(await headers()))) {
    return { status: "error", message: "Too many attempts from your connection. Please try again in a few minutes." };
  }
  const token = form.get("token");
  if (typeof token !== "string" || !isPlausibleToken(token)) {
    return { status: "error", message: "This confirmation link is incomplete. Open the link from the email again." };
  }
  try {
    await confirmClaim(token);
    return {
      status: "confirmed",
      message: "Thank you, your email is confirmed. An editor will now check your identity, usually within two business days, and email you the result.",
    };
  } catch (error) {
    if (error instanceof WordPressApiError && error.status === 400) {
      return { status: "error", message: "This confirmation link is invalid or has expired. Please submit the claim again." };
    }
    log("confirm_failed", error);
    return { status: "error", message: "We could not confirm your email right now. Please try again later." };
  }
}
