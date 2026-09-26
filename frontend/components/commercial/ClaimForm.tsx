"use client";

import { useActionState, useState } from "react";
import { submitClaimAction, type ClaimFormState } from "@/app/claim/actions";
import { HONEYPOT_FIELD, US_STATE_CODES } from "@/lib/claims/validate";

const INITIAL: ClaimFormState = { status: "idle" };

/** Public claim form. Works without JavaScript (progressive enhancement). */
export function ClaimForm({ entityType, entityId, entityName }: { entityType: "lawyer" | "law_firm"; entityId: number; entityName: string }) {
  const [state, action, pending] = useActionState(submitClaimAction, INITIAL);
  const [role, setRole] = useState<"self" | "firm_representative">(entityType === "lawyer" ? "self" : "firm_representative");
  const err = state.errors ?? {};

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
      <p className="field__error" id={`claim-${key}-error`}>
        {err[key]}
      </p>
    ) : null;
  const described = (key: keyof typeof err) => (err[key] ? { "aria-invalid": true as const, "aria-describedby": `claim-${key}-error` } : {});

  return (
    <form action={action} className="claim-form">
      {state.status === "error" && state.message && (
        <div className="notice notice--error" role="alert">
          <p style={{ margin: 0 }}>{state.message}</p>
        </div>
      )}
      <input type="hidden" name="entityType" value={entityType} />
      <input type="hidden" name="entityId" value={entityId} />
      <div className="claim-form__hp" aria-hidden="true">
        <label htmlFor="claim-hp">Leave this field empty</label>
        <input id="claim-hp" type="text" name={HONEYPOT_FIELD} tabIndex={-1} autoComplete="off" />
      </div>

      <fieldset className="claim-form__roles">
        <legend>Who are you?</legend>
        {entityType === "lawyer" && (
          <label className="choice">
            <input type="radio" name="role" value="self" checked={role === "self"} onChange={() => setRole("self")} />I am {entityName}
          </label>
        )}
        <label className="choice">
          <input type="radio" name="role" value="firm_representative" checked={role === "firm_representative"} onChange={() => setRole("firm_representative")} />
          {entityType === "lawyer" ? "I represent this lawyer’s firm" : `I represent ${entityName}`}
        </label>
        {fieldError("role")}
      </fieldset>

      <div className="grid grid--2" style={{ gap: "0 1rem" }}>
        <div className="field">
          <label htmlFor="claim-name">Full name</label>
          <input id="claim-name" name="name" required maxLength={120} autoComplete="name" {...described("name")} />
          {fieldError("name")}
        </div>
        <div className="field">
          <label htmlFor="claim-email">Work email</label>
          <input id="claim-email" name="email" type="email" required maxLength={254} autoComplete="email" {...described("email")} />
          {fieldError("email")}
        </div>
        <div className="field">
          <label htmlFor="claim-phone">Phone (optional)</label>
          <input id="claim-phone" name="phone" type="tel" maxLength={40} autoComplete="tel" {...described("phone")} />
          {fieldError("phone")}
        </div>
        <div className="field">
          <label htmlFor="claim-bar-state">Bar state{role === "self" ? "" : " (optional)"}</label>
          <select id="claim-bar-state" name="barState" required={role === "self"} defaultValue="" {...described("barState")}>
            <option value="">—</option>
            {US_STATE_CODES.map((code) => (
              <option key={code} value={code}>
                {code}
              </option>
            ))}
          </select>
          {fieldError("barState")}
        </div>
        <div className="field">
          <label htmlFor="claim-bar-number">Bar number{role === "self" ? "" : " (optional)"}</label>
          <input id="claim-bar-number" name="barNumber" maxLength={40} required={role === "self"} {...described("barNumber")} />
          {fieldError("barNumber")}
        </div>
      </div>

      <div className="field">
        <label htmlFor="claim-message">Anything we should know? (optional)</label>
        <textarea id="claim-message" name="message" rows={4} maxLength={1000} {...described("message")} />
        {fieldError("message")}
      </div>

      <label className="choice">
        <input type="checkbox" name="consent" required {...described("consent")} />
        <span>
          I confirm the information is accurate and that I am authorised to claim this profile. LexRanked may use these details to verify
          my identity and contact me about this claim.
        </span>
      </label>
      {fieldError("consent")}

      <button type="submit" className="btn btn--primary" disabled={pending} style={{ marginTop: "1rem" }}>
        {pending ? "Sending…" : "Claim this profile"}
      </button>
    </form>
  );
}
