import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { DataQualityPanel } from "@/components/profile/DataQuality";
import type { DataQualityDto } from "@/types/api";

const quality: DataQualityDto = {
  score: 71.6,
  version: "dq-1.0",
  dimensions: [
    { key: "completeness", label: "Completeness", weight: 30, score: 52.4, detail: "8 of 14" },
    { key: "freshness", label: "Freshness", weight: 20, score: 100, detail: "all fresh" },
  ],
  missing: ["years_experience", "languages"],
  unsourced: [],
  stale: [],
  conflicts: [],
  calculatedAt: "2026-09-27T10:00:00Z",
};

describe("Data Quality panel", () => {
  it("shows the score and dimensions, and says it is not a ranking", () => {
    const html = renderToStaticMarkup(createElement(DataQualityPanel, { quality }));
    expect(html).toContain("72%");
    expect(html).toContain("Completeness");
    expect(html).toContain("width:52.4%");
    expect(html).toContain("not part of the ranking");
    expect(html).toContain("Years experience, Languages");
  });

  it("renders nothing without a score", () => {
    expect(renderToStaticMarkup(createElement(DataQualityPanel, { quality: null }))).toBe("");
  });
});
