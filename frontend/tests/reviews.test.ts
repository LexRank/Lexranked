import { describe, expect, it } from "vitest";
import { googleReviewsUrl, parseReviewForm, REVIEW_HONEYPOT } from "@/lib/reviews/validate";

function form(over: Record<string, string> = {}): FormData {
  const f = new FormData();
  const base: Record<string, string> = {
    entityType: "lawyer",
    entityId: "12",
    rating: "4",
    title: "Clear and responsive",
    body: "She explained every step of my case and returned my calls the same day.",
    name: "Maria Gonzalez",
    email: "Maria@Example.com",
    serviceYear: "2024",
    client: "on",
  };
  for (const [k, v] of Object.entries({ ...base, ...over })) f.set(k, v);
  return f;
}

const NOW = new Date("2026-10-05T00:00:00Z");

describe("review form", () => {
  it("accepts a complete review and normalises the email", () => {
    const r = parseReviewForm(form(), NOW);
    expect(r.ok).toBe(true);
    if (r.ok === true) {
      expect(r.data).toMatchObject({ entityType: "lawyer", entityId: 12, rating: 4, email: "maria@example.com", serviceYear: 2024, client: true });
    }
  });

  it("flags each invalid field", () => {
    const r = parseReviewForm(form({ rating: "6", body: "Too short.", email: "nope", serviceYear: "2030", client: "" }), NOW);
    expect(r.ok).toBe(false);
    if (r.ok === false) expect(Object.keys(r.errors).sort()).toEqual(["body", "client", "email", "rating", "serviceYear"]);
  });

  it("rejects links", () => {
    const r = parseReviewForm(form({ body: "Great lawyer, very responsive and kind. See www.example.com for details." }), NOW);
    expect(r.ok).toBe(false);
  });

  it("treats a filled honeypot as a bot", () => {
    expect(parseReviewForm(form({ [REVIEW_HONEYPOT]: "spam" }), NOW).ok).toBe("bot");
  });

  it("links to a Google Maps search without an API key", () => {
    const url = googleReviewsUrl("Jordan Lee (Demo)", "Miami", "Florida");
    expect(url).toBe("https://www.google.com/maps/search/?api=1&query=Jordan%20Lee%20Miami%20Florida");
    expect(url).not.toContain("key=");
  });
});
