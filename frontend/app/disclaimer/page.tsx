import type { Metadata } from "next";
import Link from "next/link";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { buildMetadata } from "@/lib/seo/metadata";

const PATH = "/disclaimer/";
const DESCRIPTION =
  "LexRanked is not a law firm and does not give legal advice. What our rankings, profiles, reviews and guides mean, and what they do not mean.";

export const metadata: Metadata = buildMetadata({ title: "Legal Disclaimer", description: DESCRIPTION, path: PATH });

export default function DisclaimerPage() {
  const sections: TrustSection[] = [
    {
      id: "not-advice",
      heading: "Is LexRanked legal advice?",
      answer: "No. LexRanked is an information service, not a law firm; nothing on the site is legal advice, and you should speak to a licensed lawyer about your situation.",
      children: (
        <p>
          Our guides explain the law in general terms. Your rights and deadlines can depend on facts that a general guide cannot know, and{" "}
          <strong>missing a legal deadline can end a claim</strong>, so do not wait for research to finish before you get advice.
        </p>
      ),
    },
    {
      id: "relationship",
      heading: "Does using LexRanked make me a client of a lawyer?",
      answer: "No. Using the site, writing a review or contacting us does not create a lawyer-client relationship with LexRanked or with any lawyer listed.",
      children: (
        <p>
          Do not send confidential information about a legal matter through our forms. A lawyer-client relationship starts only when you and a
          lawyer agree on it, usually in a written engagement or fee agreement.
        </p>
      ),
    },
    {
      id: "referral",
      heading: "Is LexRanked a lawyer referral service?",
      answer: "No. We do not refer you to a lawyer, assign cases or take any fee or share of fees when you hire a lawyer.",
      children: (
        <p>
          You choose whether and whom to contact. Lawyers do not pay to be ranked or to change their position; paid placements are labelled and
          shown outside the rankings, as set out in our <Link href="/advertising/">advertising policy</Link>.
        </p>
      ),
    },
    {
      id: "rankings",
      heading: "What does a LexRanked ranking mean?",
      answer: "A ranking orders lawyers by a score calculated from documented facts; it is not a guarantee of results, an endorsement or a statement that one lawyer is right for your case.",
      children: (
        <ul>
          <li>The score uses only facts with a cited source, by the formula on <Link href="/methodology/">how rankings work</Link>.</li>
          <li>Many good lawyers are not ranked, for example because their records are incomplete or their city is not yet covered.</li>
          <li>Past results and credentials do not guarantee a similar outcome in your case.</li>
        </ul>
      ),
    },
    {
      id: "certification",
      heading: "What does “board certified” mean on a profile?",
      answer: "It means the lawyer holds a board certification from the state bar's certification program, as shown in the bar's official record; it is not a LexRanked award.",
      children: (
        <p>
          In Florida, certification is granted by The Florida Bar&apos;s Board of Legal Specialization and Education after review of the
          lawyer&apos;s experience, peer review and an examination. Each profile links to the official record.
        </p>
      ),
    },
    {
      id: "reviews",
      heading: "Do client reviews reflect LexRanked's opinion?",
      answer: "No. Reviews are the opinions of the clients who wrote them; we check each one before publishing it but cannot verify every statement in it.",
      children: (
        <p>
          Reviews are shown on profiles and are not part of the ranking score. The rules for reviews are in our <Link href="/terms/">terms of use</Link>.
        </p>
      ),
    },
    {
      id: "accuracy",
      heading: "Is every fact on LexRanked current?",
      answer: "We check facts against official sources and show the date each was checked, but records change; confirm a lawyer's license with the state bar before you hire them.",
      children: (
        <p>
          For Florida lawyers, search{" "}
          <a href="https://www.floridabar.org/directories/find-mbr/" rel="noopener">The Florida Bar&apos;s member directory</a> by name or bar
          number. If you find an error, <Link href="/contact/?topic=correction">report it</Link>.
        </p>
      ),
    },
    {
      id: "advertising",
      heading: "Is this page lawyer advertising?",
      answer: "LexRanked's editorial pages are not advertising by any lawyer; paid elements are marked “Paid” and are the advertiser's responsibility.",
    },
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Disclaimer"
      title="Legal disclaimer"
      lead="LexRanked is not a law firm, does not give legal advice and is not a lawyer referral service."
      description={DESCRIPTION}
      updated="2026-10-05"
      sections={sections}
    />
  );
}
