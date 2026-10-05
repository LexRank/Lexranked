import { describe, expect, it } from "vitest";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { EmailLine, PhoneLine, profileActive } from "@/components/profile/Contact";
import type { CommercialBlock } from "@/types/api";

const free: CommercialBlock = { status: "free", isPaidPlacement: false, claimed: false, premium: false };
const claimed: CommercialBlock = { status: "claimed", isPaidPlacement: false, claimed: true, premium: false };
const premium: CommercialBlock = { status: "premium", isPaidPlacement: false, claimed: true, premium: true };

describe("profile contact details", () => {
  it("are active only on claimed or premium profiles", () => {
    expect(profileActive(free)).toBe(false);
    expect(profileActive(claimed)).toBe(true);
    expect(profileActive(premium)).toBe(true);
    expect(profileActive({ status: "claimed", isPaidPlacement: false })).toBe(true);
  });

  it("show phone and email as plain text until the profile is active", () => {
    expect(renderToStaticMarkup(createElement(PhoneLine, { phone: "305-371-3666", active: false }))).toBe("<dd><span>305-371-3666</span></dd>");
    expect(renderToStaticMarkup(createElement(EmailLine, { email: "info@firm.test", active: false }))).not.toContain("mailto:");
    expect(renderToStaticMarkup(createElement(PhoneLine, { phone: "(305) 371-3666", active: true }))).toContain('href="tel:3053713666"');
    expect(renderToStaticMarkup(createElement(EmailLine, { email: "info@firm.test", active: true }))).toContain('href="mailto:info@firm.test"');
  });
});
