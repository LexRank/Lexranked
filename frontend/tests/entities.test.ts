import { beforeEach, describe, expect, it, vi } from "vitest";

const resolveEntity = vi.fn();
const permanentRedirect = vi.fn((path: string) => {
  throw new Error(`redirect:${path}`);
});
vi.mock("@/lib/wordpress/api", () => ({ resolveEntity: (...a: unknown[]) => resolveEntity(...a) }));
vi.mock("next/navigation", () => ({ permanentRedirect: (p: string) => permanentRedirect(p) }));

const { redirectIfMoved } = await import("@/lib/content/moved");

describe("renamed entities keep their links", () => {
  beforeEach(() => {
    resolveEntity.mockReset();
    permanentRedirect.mockClear();
  });

  it("redirects a former slug to the entity's current page", async () => {
    resolveEntity.mockResolvedValue({ entityId: 18372, entityType: "law_firm", path: "/law-firms/smith-law-group/" });
    await expect(redirectIfMoved("law_firm", "smith-law", "/law-firms/smith-law/")).rejects.toThrow("redirect:/law-firms/smith-law-group/");
    expect(resolveEntity).toHaveBeenCalledWith("law_firm", "smith-law");
  });

  it("does nothing for unknown slugs, the same path, or API errors", async () => {
    resolveEntity.mockResolvedValueOnce(null);
    await redirectIfMoved("lawyer", "nobody", "/lawyers/nobody/");
    resolveEntity.mockResolvedValueOnce({ entityId: 1, entityType: "lawyer", path: "/lawyers/jane/" });
    await redirectIfMoved("lawyer", "jane", "/lawyers/jane/");
    resolveEntity.mockRejectedValueOnce(new Error("network"));
    await redirectIfMoved("lawyer", "x", "/lawyers/x/");
    expect(permanentRedirect).not.toHaveBeenCalled();
  });
});
