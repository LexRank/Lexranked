import { describe, expect, it } from "vitest";
import { groupRankingSources } from "@/lib/content/rankingSources";
import type { RankingSourceDto } from "@/types/api";

const bar = (id: number, facts: number): RankingSourceDto => ({
  id,
  name: "floridabar.org",
  url: `https://www.floridabar.org/directories/find-mbr/profile/?num=${id}`,
  publisher: null,
  type: "bar_association",
  tier: 1,
  tierLabel: "Official / regulatory",
  facts,
  entities: 1,
});

describe("ranking sources", () => {
  it("groups profile pages of one site into one source", () => {
    const groups = groupRankingSources([bar(1, 9), bar(2, 9), bar(3, 8)], 3);
    expect(groups).toEqual([
      { key: "floridabar.org|bar_association", name: "floridabar.org", url: "https://www.floridabar.org/", tier: 1, tierLabel: "Official / regulatory", pages: 3, facts: 26, entities: 3 },
    ]);
  });

  it("keeps a single page's own URL, separates source types and sorts by tier", () => {
    const site: RankingSourceDto = { id: 9, name: "smith-law.com", url: "https://smith-law.com/", publisher: null, type: "official_website", tier: 2, tierLabel: "Primary", facts: 4, entities: 1 };
    const groups = groupRankingSources([site, bar(1, 9)], 2);
    expect(groups.map((g) => [g.name, g.url, g.pages])).toEqual([
      ["floridabar.org", "https://www.floridabar.org/directories/find-mbr/profile/?num=1", 1],
      ["smith-law.com", "https://smith-law.com/", 1],
    ]);
  });

  it("never claims more entities than the ranking has", () => {
    expect(groupRankingSources([bar(1, 9), { ...bar(2, 9), entities: 2 }], 2)[0]!.entities).toBe(2);
  });
});
