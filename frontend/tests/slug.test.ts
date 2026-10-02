import { describe, expect, it } from "vitest";
import { isValidSlug, slugify } from "@/lib/slug";

describe("slugify", () => {
  it.each([
    ["John Smith", "john-smith"],
    ["  Smith & Jones, P.A. ", "smith-and-jones-p-a"],
    ["Personal Injury", "personal-injury"],
    ["Miami-Dade County", "miami-dade-county"],
    ["José Martínez", "jose-martinez"],
    ["Patrick O'Brien", "patrick-obrien"],
    ["St. Petersburg", "st-petersburg"],
    ["---", ""],
  ])("slugify(%j) === %j", (input, expected) => {
    expect(slugify(input)).toBe(expected);
  });

  it("is deterministic", () => {
    expect(slugify("Smith Law Group")).toBe(slugify("Smith Law Group"));
  });

  it("truncates long input on a word boundary", () => {
    const slug = slugify("word ".repeat(40));
    expect(slug.length).toBeLessThanOrEqual(96);
    expect(slug.endsWith("-")).toBe(false);
    expect(slug.split("-").every((part) => part === "word")).toBe(true);
  });

  it("always produces valid slugs for non-empty output", () => {
    for (const input of ["A  B", "Ünïcödé Law", "100% Legal!!", "x".repeat(200)]) {
      expect(isValidSlug(slugify(input))).toBe(true);
    }
  });
});

describe("isValidSlug", () => {
  it("rejects malformed slugs", () => {
    for (const bad of ["", "Upper", "a--b", "-a", "a-", "a_b", "a/b"]) {
      expect(isValidSlug(bad)).toBe(false);
    }
  });
});
