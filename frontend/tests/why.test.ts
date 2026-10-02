import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { WhyRankedHere } from "@/components/cards";
import { rankingDetail } from "./fixtures/api";

describe("Why ranked here", () => {
  const base = rankingDetail().entries[1]!;

  it("renders the component-based explanation and the snapshot changes", () => {
    const entry = {
      ...base,
      position: 2,
      why: {
        summary: "Ranks #2 with 84.20: strongest in Review strength (19.1/20); held back by Professional credentials (4/10).",
        strengths: [{ key: "review_strength", label: "Review strength", points: 19.1, max: 20, average: 15.2 }],
        gaps: [{ key: "credentials", label: "Professional credentials", points: 4, max: 10, average: 6.5 }],
        behind: { position: 1, entityId: 1, name: "Avery Example", scoreGap: 3.4, components: [{ key: "credentials", label: "Professional credentials", delta: 3 }] },
        missing: ["review_count"],
      },
      change: {
        previousPosition: 4,
        previousScore: 80.1,
        scoreDelta: 4.1,
        reasons: [
          { type: "component" as const, text: "Review strength +2.10 (17.00 → 19.10 of 20)." },
          { type: "input" as const, text: "Data changed: review count 150 → 212." },
        ],
      },
    };
    const html = renderToStaticMarkup(createElement(WhyRankedHere, { entry }));
    expect(html).toContain("<details");
    expect(html).toContain("Why #2?");
    expect(html).toContain("held back by Professional credentials");
    expect(html).toContain("(ranking avg 15.2)");
    expect(html).toContain("3.40 points behind #1 (Avery Example)");
    expect(html).toContain("Professional credentials −3");
    expect(html).toContain("review count");
    expect(html).toContain("(was #4)");
    expect(html).toContain("Data changed: review count 150 → 212.");
  });

  it("renders nothing without an explanation", () => {
    expect(renderToStaticMarkup(createElement(WhyRankedHere, { entry: { ...base, why: null } }))).toBe("");
  });
});
