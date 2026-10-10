"use client";

import { useActionState } from "react";
import { sendContactAction, type ContactFormState } from "@/app/contact/actions";
import { CONTACT_HONEYPOT, CONTACT_TOPICS, MAX_MESSAGE, MIN_MESSAGE } from "@/lib/contact/validate";

const INITIAL: ContactFormState = { status: "idle" };

/** Contact form. Works without JavaScript (progressive enhancement). */
export function ContactForm({ topic, page }: { topic?: string; page?: string }) {
  const [state, action, pending] = useActionState(sendContactAction, INITIAL);
  const err = state.errors ?? {};

  if (state.status === "sent") {
    return (
      <div className="notice notice--info" role="status">
        <p style={{ margin: 0 }}>
          <strong>Message sent.</strong> {state.message}
        </p>
      </div>
    );
  }

  const fieldError = (key: keyof typeof err) =>
    err[key] ? (
      <p className="field__error" id={`contact-${key}-error`}>
        {err[key]}
      </p>
    ) : null;
  const described = (key: keyof typeof err) => (err[key] ? { "aria-invalid": true as const, "aria-describedby": `contact-${key}-error` } : {});

  return (
    <form action={action} className="claim-form">
      {state.status === "error" && state.message && (
        <div className="notice notice--error" role="alert">
          <p style={{ margin: 0 }}>{state.message}</p>
        </div>
      )}
      <div className="claim-form__hp" aria-hidden="true">
        <label htmlFor="contact-hp">Leave this field empty</label>
        <input id="contact-hp" type="text" name={CONTACT_HONEYPOT} tabIndex={-1} autoComplete="off" />
      </div>
      <div className="grid grid--2" style={{ gap: "0 1rem" }}>
        <div className="field">
          <label htmlFor="contact-name">Your name</label>
          <input id="contact-name" name="name" required maxLength={80} autoComplete="name" {...described("name")} />
          {fieldError("name")}
        </div>
        <div className="field">
          <label htmlFor="contact-email">Your email</label>
          <input id="contact-email" name="email" type="email" required maxLength={254} autoComplete="email" {...described("email")} />
          {fieldError("email")}
        </div>
      </div>
      <div className="field">
        <label htmlFor="contact-topic">Topic</label>
        <select id="contact-topic" name="topic" required defaultValue={topic && topic in CONTACT_TOPICS ? topic : ""} {...described("topic")}>
          <option value="" disabled>
            Choose a topic
          </option>
          {Object.entries(CONTACT_TOPICS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
        {fieldError("topic")}
      </div>
      <div className="field">
        <label htmlFor="contact-page">Page address (optional)</label>
        <input id="contact-page" name="page" maxLength={300} defaultValue={page} placeholder="/lawyers/jane-doe/" {...described("page")} />
        {fieldError("page")}
      </div>
      <div className="field">
        <label htmlFor="contact-message">Message</label>
        <textarea id="contact-message" name="message" rows={7} required minLength={MIN_MESSAGE} maxLength={MAX_MESSAGE} {...described("message")} />
        <p className="muted" style={{ fontSize: "0.82rem", margin: "0.25rem 0 0" }}>
          For a correction, say what is wrong and link the public source that shows the right information. Do not send confidential details
          about a legal matter: LexRanked cannot give legal advice.
        </p>
        {fieldError("message")}
      </div>
      <button type="submit" className="btn btn--primary" disabled={pending} style={{ marginTop: "1rem" }}>
        {pending ? "Sending…" : "Send message"}
      </button>
    </form>
  );
}
