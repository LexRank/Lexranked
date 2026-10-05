"use server";

import { headers } from "next/headers";
import { isPlausibleToken } from "@/lib/claims/validate";
import { reviewLimiter, clientKey } from "@/lib/rateLimit";
import { parseReviewForm, type ReviewFieldErrors } from "@/lib/reviews/validate";
import { WordPressApiError, WordPressNotConfiguredError } from "@/lib/wordpress/client";
import { confirmReview, submitReview } from "@/lib/wordpress/api";

/**
 * Server actions for client reviews. Public POST endpoints: everything is
 * validated, rate-limited per client, and nothing reveals whether an email
 * has reviewed before. Personal data goes to the CMS and is never logged.
 */

export interface ReviewFormState {
  status: "idle" | "error" | "sent";
  message?: string;
  errors?: ReviewFieldErrors;
}

export interface ReviewConfirmState {
  status: "idle" | "error" | "confirmed";
  message?: string;
}

const SENT: ReviewFormState = {
  status: "sent",
  message: "Check your inbox: we sent you a link to confirm your email address. After you confirm, an editor reads your review before it is published.",
};

function log(event: string, error: unknown): void {
  const status = error instanceof WordPressApiError ? error.status : null;
  const code = error instanceof WordPressApiError ? error.code : "unknown";
  console.error(JSON.stringify({ level: "error", source: "reviews", event, status, code }));
}

export async function submitReviewAction(_prev: ReviewFormState, form: FormData): Promise<ReviewFormState> {
  if (!reviewLimiter.take(clientKey(await headers()))) {
    return { status: "error", message: "Too many attempts from your connection. Please try again in a few minutes." };
  }
  const parsed = parseReviewForm(form);
  if (parsed.ok === "bot") return SENT;
  if (!parsed.ok) return { status: "error", message: "Please check the highlighted fields.", errors: parsed.errors };
  try {
    await submitReview(parsed.data);
    return SENT;
  } catch (error) {
    if (error instanceof WordPressApiError && error.status === 429) {
      return { status: "error", message: "Too many reviews from this email or for this profile today. Please try again tomorrow." };
    }
    if (error instanceof WordPressApiError && error.status === 400) {
      return { status: "error", message: `Please check your review: ${error.message}` };
    }
    if (!(error instanceof WordPressNotConfiguredError)) log("submit_failed", error);
    return { status: "error", message: "We could not send your review right now. Please try again later." };
  }
}

export async function confirmReviewAction(_prev: ReviewConfirmState, form: FormData): Promise<ReviewConfirmState> {
  if (!reviewLimiter.take(clientKey(await headers()))) {
    return { status: "error", message: "Too many attempts from your connection. Please try again in a few minutes." };
  }
  const token = form.get("token");
  if (typeof token !== "string" || !isPlausibleToken(token)) {
    return { status: "error", message: "This confirmation link is incomplete. Open the link from the email again." };
  }
  try {
    await confirmReview(token);
    return { status: "confirmed", message: "Thank you, your email is confirmed. An editor will read your review, usually within two business days, before it appears on the profile." };
  } catch (error) {
    if (error instanceof WordPressApiError && error.status === 400) {
      return { status: "error", message: "This confirmation link is invalid or has expired. Please submit the review again." };
    }
    log("confirm_failed", error);
    return { status: "error", message: "We could not confirm your email right now. Please try again later." };
  }
}
