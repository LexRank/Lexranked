"use client";

import { useActionState, useState } from "react";
import { submitReviewAction, type ReviewFormState } from "@/app/reviews/actions";
import { MAX_BODY, MIN_BODY, REVIEW_HONEYPOT } from "@/lib/reviews/validate";

const INITIAL: ReviewFormState = { status: "idle" };

/** Public review form. Works without JavaScript (progressive enhancement). */
export function ReviewForm({ entityType, entityId, entityName }: { entityType: "lawyer" | "law_firm"; entityId: number; entityName: string }) {
  const [state, action, pending] = useActionState(submitReviewAction, INITIAL);
  const [rating, setRating] = useState(0);
  const err = state.errors ?? {};
  const thisYear = new Date().getFullYear();

  if (state.status === "sent") {
    return (
      <div className="notice notice--info" role="status">
        <p style={{ margin: 0 }}>
          <strong>Almost done.</strong> {state.message}
        </p>
      </div>
    );
  }

  const fieldError = (key: keyof typeof err) =>
    err[key] ? (
      <p className="field__error" id={`review-${key}-error`}>
        {err[key]}
      </p>
    ) : null;
  const described = (key: keyof typeof err) => (err[key] ? { "aria-invalid": true as const, "aria-describedby": `review-${key}-error` } : {});

  return (
    <form action={action} className="claim-form review-form">
      {state.status === "error" && state.message && (
        <div className="notice notice--error" role="alert">
          <p style={{ margin: 0 }}>{state.message}</p>
        </div>
      )}
      <input type="hidden" name="entityType" value={entityType} />
      <input type="hidden" name="entityId" value={entityId} />
      <div className="claim-form__hp" aria-hidden="true">
        <label htmlFor="review-hp">Leave this field empty</label>
        <input id="review-hp" type="text" name={REVIEW_HONEYPOT} tabIndex={-1} autoComplete="off" />
      </div>

      <fieldset className="star-input" {...described("rating")}>
        <legend>Your rating of {entityName}</legend>
        {[1, 2, 3, 4, 5].map((n) => (
          <label key={n} className={n <= rating ? "is-on" : undefined}>
            <input type="radio" name="rating" value={n} required onChange={() => setRating(n)} />
            <span aria-hidden="true">★</span>
            <span className="sr-only">
              {n} {n === 1 ? "star" : "stars"}
            </span>
          </label>
        ))}
      </fieldset>
      {fieldError("rating")}

      <div className="field">
        <label htmlFor="review-title">Title (optional)</label>
        <input id="review-title" name="title" maxLength={120} {...described("title")} />
        {fieldError("title")}
      </div>
      <div className="field">
        <label htmlFor="review-body">Your experience</label>
        <textarea id="review-body" name="body" rows={6} required minLength={MIN_BODY} maxLength={MAX_BODY} {...described("body")} />
        <p className="muted" style={{ fontSize: "0.82rem", margin: "0.25rem 0 0" }}>
          Describe the service: communication, how the case was handled, fees. Do not include confidential details or links.
        </p>
        {fieldError("body")}
      </div>

      <div className="grid grid--2" style={{ gap: "0 1rem" }}>
        <div className="field">
          <label htmlFor="review-name">Your name</label>
          <input id="review-name" name="name" required maxLength={80} autoComplete="name" {...described("name")} />
          {fieldError("name")}
        </div>
        <div className="field">
          <label htmlFor="review-email">Email (never shown)</label>
          <input id="review-email" name="email" type="email" required maxLength={254} autoComplete="email" {...described("email")} />
          {fieldError("email")}
        </div>
        <div className="field">
          <label htmlFor="review-year">Year the lawyer worked for you</label>
          <select id="review-year" name="serviceYear" required defaultValue="" {...described("serviceYear")}>
            <option value="">-</option>
            {Array.from({ length: 30 }, (_, i) => thisYear - i).map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </select>
          {fieldError("serviceYear")}
        </div>
      </div>

      <label className="choice">
        <input type="checkbox" name="client" required {...described("client")} />
        <span>
          I was a client of {entityName}, this review describes my own experience, and I have no business or family relationship with
          them.
        </span>
      </label>
      {fieldError("client")}

      <button type="submit" className="btn btn--primary" disabled={pending} style={{ marginTop: "1rem" }}>
        {pending ? "Sending…" : "Submit review"}
      </button>
      <p className="muted" style={{ fontSize: "0.82rem", margin: "0.75rem 0 0" }}>
        We email you a confirmation link. An editor reads every review before it is published; only your first name and last initial are
        shown, and your email address is deleted after moderation.
      </p>
    </form>
  );
}
