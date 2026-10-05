"use client";

import { useActionState } from "react";
import { confirmReviewAction, type ReviewConfirmState } from "@/app/reviews/actions";

const INITIAL: ReviewConfirmState = { status: "idle" };

/** Confirmation is a button, not the link itself: mail scanners open links, and a GET must never act. */
export function ReviewConfirmForm({ token }: { token: string }) {
  const [state, action, pending] = useActionState(confirmReviewAction, INITIAL);
  if (state.status === "confirmed") {
    return (
      <div className="notice notice--info" role="status">
        <p style={{ margin: 0 }}>{state.message}</p>
      </div>
    );
  }
  return (
    <form action={action} className="stack" style={{ gap: "1rem" }}>
      {state.status === "error" && (
        <div className="notice notice--error" role="alert">
          <p style={{ margin: 0 }}>{state.message}</p>
        </div>
      )}
      <input type="hidden" name="token" value={token} />
      <div>
        <button type="submit" className="btn btn--primary" disabled={pending}>
          {pending ? "Confirming…" : "Confirm my review"}
        </button>
      </div>
    </form>
  );
}
