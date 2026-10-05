import { describe, expect, it } from "vitest";
import { CONTACT_HONEYPOT, parseContactForm } from "@/lib/contact/validate";

function form(values: Record<string, string>): FormData {
  const f = new FormData();
  for (const [k, v] of Object.entries(values)) f.set(k, v);
  return f;
}

const VALID = {
  name: "  Jane   Doe ",
  email: "Jane@Example.com",
  topic: "correction",
  page: "/lawyers/jane-doe/",
  message: "My years in practice are wrong; I was admitted in 2004 (see the Florida Bar record).",
};

describe("contact form", () => {
  it("accepts and normalises a valid message", () => {
    const parsed = parseContactForm(form(VALID));
    expect(parsed).toEqual({ ok: true, data: { name: "Jane Doe", email: "jane@example.com", topic: "correction", page: "/lawyers/jane-doe/", message: VALID.message } });
  });

  it("reports every invalid field", () => {
    const parsed = parseContactForm(form({ name: "J", email: "x", topic: "sales", page: "javascript:alert(1)", message: "short" }));
    expect(parsed.ok).toBe(false);
    if (parsed.ok === false) expect(Object.keys(parsed.errors).sort()).toEqual(["email", "message", "name", "page", "topic"]);
  });

  it("treats a filled honeypot as a bot", () => {
    expect(parseContactForm(form({ ...VALID, [CONTACT_HONEYPOT]: "http://spam.test" }))).toEqual({ ok: "bot" });
  });

  it("allows an empty page address", () => {
    const parsed = parseContactForm(form({ ...VALID, page: "" }));
    expect(parsed.ok).toBe(true);
  });
});
