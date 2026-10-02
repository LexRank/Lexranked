"use client";

import { useActionState } from "react";
import { confirmClaimAction, type ConfirmState } from "@/app/claim/actions";

const INITIAL: ConfirmState = { status: "idle" };

/**
 * Confirmation is a button, not the link itself: email scanners open links
 * automatically, and a GET must never change anything.
 */
export function ConfirmForm({ token }: { token: string }) {
  const [state, action, pending] = useActionState(confirmClaimAction, INITIAL);
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
          {pending ? "Confirming…" : "Confirm my email address"}
        </button>
      </div>
    </form>
  );
}
