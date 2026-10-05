import type { Metadata } from "next";
import Link from "next/link";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { buildMetadata } from "@/lib/seo/metadata";

const PATH = "/terms/";
const DESCRIPTION =
  "The terms for using LexRanked: what the site provides, the rules for reviews and profile claims, how you may reuse our content, and the limits of our responsibility.";

export const metadata: Metadata = buildMetadata({ title: "Terms of Use", description: DESCRIPTION, path: PATH });

export default function TermsPage() {
  const sections: TrustSection[] = [
    {
      id: "agreement",
      heading: "Do these terms apply to me?",
      answer: "Yes, if you use LexRanked; by using the site you agree to these terms, and if you do not agree, please do not use it.",
      children: (
        <p>
          These terms work together with our <Link href="/privacy/">privacy policy</Link>, <Link href="/disclaimer/">legal disclaimer</Link>,{" "}
          <Link href="/editorial-policy/">editorial policy</Link> and <Link href="/advertising/">advertising policy</Link>.
        </p>
      ),
    },
    {
      id: "service",
      heading: "What does LexRanked provide?",
      answer: "Information: rankings, lawyer profiles and guides built from public and official records; LexRanked does not provide legal services or legal advice.",
      children: (
        <ul>
          <li>Rankings are calculated by the formula published on <Link href="/methodology/">how rankings work</Link>.</li>
          <li>We do not take part in any agreement between you and a lawyer, and we receive no fee when you hire one.</li>
          <li>We may add, change or remove features, pages, rankings and profiles at any time.</li>
        </ul>
      ),
    },
    {
      id: "accuracy",
      heading: "Is the information guaranteed to be accurate?",
      answer: "No. We check facts against official sources and show where each one comes from, but records change and errors are possible, so confirm important facts with the source before you rely on them.",
      children: (
        <p>
          Before hiring a lawyer, check their license with the state bar. If you find an error, <Link href="/contact/?topic=correction">tell
          us</Link> and we will correct it.
        </p>
      ),
    },
    {
      id: "reviews",
      heading: "What are the rules for client reviews?",
      answer: "Only real clients may review a lawyer or firm, about their own experience, honestly and without confidential details; every review is checked by an editor before it is published.",
      children: (
        <>
          <p>A review is not published, or is removed, if it:</p>
          <ul>
            <li>is not based on your own experience as a client, or was written by or for the lawyer or a competitor;</li>
            <li>was offered payment, a discount or another benefit in return;</li>
            <li>contains false statements of fact, threats, hate speech, personal attacks or private information about anyone;</li>
            <li>contains confidential details of a legal matter, links or advertising.</li>
          </ul>
          <p>
            <strong>By submitting a review you confirm it is truthful and you allow LexRanked to publish it</strong>, with your first name and
            last initial, on the profile and in related pages. You keep ownership of your text and can ask us to remove it at any time.
          </p>
        </>
      ),
    },
    {
      id: "claims",
      heading: "Who may claim a profile?",
      answer: "Only the lawyer named on the profile, or a person authorized to act for the law firm; claiming is free and an editor checks your identity first.",
      children: (
        <p>
          A claim lets you request corrections backed by a public source. It does not let you edit facts yourself, remove the profile from
          rankings or change a position. Submitting a false claim is not allowed and the claim will be rejected.
        </p>
      ),
    },
    {
      id: "use",
      heading: "How may I use LexRanked content and data?",
      answer: "You may read, share links to and quote short parts of our pages with credit to LexRanked and a link to the page; copying substantial parts or the data itself requires our written permission.",
      children: (
        <>
          <p>You may not:</p>
          <ul>
            <li>copy or republish rankings, profiles or guides in bulk, or build a competing database from them;</li>
            <li>access the site with automated tools in a way that harms its operation or ignores our robots.txt rules;</li>
            <li>try to break or bypass the site&apos;s security, rate limits or forms;</li>
            <li>use the site for anything unlawful, or to harass or impersonate anyone.</li>
          </ul>
          <p>
            Facts from official records belong to no one; the LexRanked name, page text, design and the selection and arrangement of data are
            ours. For press or data requests, use the <Link href="/contact/?topic=press">contact form</Link>.
          </p>
        </>
      ),
    },
    {
      id: "links",
      heading: "Are other websites linked from LexRanked covered?",
      answer: "No. Links to lawyers' websites, official records and other sites are provided for convenience; we do not control them and are not responsible for their content.",
    },
    {
      id: "paid",
      heading: "How are paid placements handled?",
      answer: "Paid placements are always labelled and never change a ranking; the full rules are in our advertising policy.",
      children: (
        <p>
          Read the <Link href="/advertising/">advertising policy</Link>.
        </p>
      ),
    },
    {
      id: "liability",
      heading: "What is LexRanked responsible for?",
      answer: "LexRanked is provided “as is”; to the extent the law allows, we are not liable for decisions you make based on the site, including the choice of a lawyer.",
      children: (
        <p>
          We make no promise that the site will always be available or free of errors. Nothing in these terms limits any right you have that
          cannot be limited by law.
        </p>
      ),
    },
    {
      id: "changes",
      heading: "Can these terms change?",
      answer: "Yes. We will update this page and its date when we change the terms; using the site after a change means you accept the new terms.",
    },
    {
      id: "contact",
      heading: "How do I ask a question about these terms?",
      answer: "Use the contact form; the editors reply by email.",
      children: (
        <p>
          <Link href="/contact/">Contact LexRanked</Link>.
        </p>
      ),
    },
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Terms"
      title="Terms of use"
      lead="The rules for using LexRanked, writing reviews, claiming profiles and reusing our content."
      description={DESCRIPTION}
      updated="2026-10-05"
      sections={sections}
    />
  );
}
