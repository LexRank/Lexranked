import type { Metadata } from "next";
import Link from "next/link";
import { TrustPage, type TrustSection } from "@/components/TrustPage";
import { buildMetadata } from "@/lib/seo/metadata";

const PATH = "/privacy/";
const DESCRIPTION =
  "What personal information LexRanked collects from visitors, reviewers, lawyers and people who contact us, why, how long it is kept and how to exercise your rights.";

export const metadata: Metadata = buildMetadata({ title: "Privacy Policy", description: DESCRIPTION, path: PATH });

const DATA: Array<[string, string, string, string]> = [
  [
    "Browsing the site",
    "No account, no cookies, no analytics or advertising trackers. Our hosting providers keep standard server logs (IP address, browser, page requested, time) for security and operation.",
    "Running and protecting the website",
    "As kept by the hosting provider for security logs",
  ],
  [
    "Writing a client review",
    "Name, email address, rating, review text, the year the lawyer worked for you and your confirmation that you were a client.",
    "Confirming your email, checking the review and publishing it",
    "Email address: deleted after an editor approves or rejects the review; reviews never confirmed are deleted after 7 days. A one-way code of the email address is kept to prevent duplicate and abusive reviews. Published reviews stay until withdrawn.",
  ],
  [
    "Claiming a lawyer or firm profile",
    "Name, email, phone, your role, bar state and bar number, and your message.",
    "Checking that you are the lawyer or represent the firm",
    "While the profile is claimed; personal details of rejected or expired claims are erased 30 days after the claim closes.",
  ],
  [
    "Using the contact form",
    "Name, email address, topic, page address and your message.",
    "Answering you",
    "Not stored on the website: the message is emailed to the editors and kept in their mailbox only as long as needed to handle it.",
  ],
  [
    "Lawyer profiles",
    "Professional information published in official records: name, bar number, license status, admission date, office address, practice areas, certifications, education and languages.",
    "Publishing source-backed profiles and rankings",
    "While the lawyer holds a license and is listed; corrected when the official record changes.",
  ],
];

export default function PrivacyPage() {
  const sections: TrustSection[] = [
    {
      id: "summary",
      heading: "What is the short version?",
      answer:
        "You can use LexRanked without giving any personal information; we do not use cookies, analytics or advertising trackers, and we never sell or share personal information for advertising.",
      children: (
        <p>
          We collect personal information only when you choose to write a review, claim a profile or contact us, and when we publish
          lawyers&apos; professional information from official records. The table below lists each case.
        </p>
      ),
    },
    {
      id: "what",
      heading: "What information does LexRanked collect, and for how long?",
      answer: "Only what each form needs, kept only as long as its purpose requires; email addresses from reviews are deleted after moderation.",
      children: (
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>What we collect</th>
              <th>Why</th>
              <th>How long</th>
            </tr>
          </thead>
          <tbody>
            {DATA.map(([when, what, why, how]) => (
              <tr key={when}>
                <td>{when}</td>
                <td>{what}</td>
                <td>{why}</td>
                <td>{how}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ),
    },
    {
      id: "public",
      heading: "What appears publicly?",
      answer: "With a published review, only your first name and last initial, your rating, your text and the year you were a client; never your email address.",
      children: (
        <p>
          Lawyer profiles show professional information from official records, with the source of each fact. Claim requests and contact
          messages are never published.
        </p>
      ),
    },
    {
      id: "cookies",
      heading: "Does LexRanked use cookies?",
      answer: "No. The public website sets no cookies and loads no third-party analytics, advertising or social media scripts.",
      children: (
        <p>
          Fonts are served from our own website. A profile may link to Google Maps; Google receives information only if you click that link
          and leave our site.
        </p>
      ),
    },
    {
      id: "sharing",
      heading: "Who receives my information?",
      answer: "Only the service providers that run the website for us, such as our hosting and email delivery providers, and only to provide that service.",
      children: (
        <ul>
          <li>
            We <strong>do not sell</strong> personal information and <strong>do not share</strong> it for cross-context behavioral
            advertising.
          </li>
          <li>Lawyers do not receive the email address or other details of people who review them.</li>
          <li>We may disclose information if the law requires it, for example in response to a valid court order.</li>
          <li>Our providers may process information in the United States.</li>
        </ul>
      ),
    },
    {
      id: "basis",
      heading: "On what basis do you use my information?",
      answer: "To do what you asked us to do (publish your review, check your claim, answer your message) and our legitimate interest in publishing accurate, source-backed information about licensed lawyers.",
      children: (
        <p>
          Where the law requires a legal basis, such as in the European Union or United Kingdom, these are the bases we rely on. You can object
          to processing based on legitimate interest, as explained below.
        </p>
      ),
    },
    {
      id: "rights",
      heading: "What are my rights?",
      answer: "You can ask to see, correct, delete or receive a copy of your personal information, and object to how we use it; we answer every request, wherever you live.",
      children: (
        <>
          <ul>
            <li>
              <strong>Access and copy:</strong> ask what we hold about you.
            </li>
            <li>
              <strong>Correction:</strong> lawyers can correct profile facts by sending the official source; reviewers can ask us to fix their
              review.
            </li>
            <li>
              <strong>Deletion:</strong> ask us to remove your review or your personal information.
            </li>
            <li>
              <strong>Objection:</strong> object to processing based on our legitimate interest.
            </li>
            <li>
              <strong>Complaint:</strong> you can complain to your data protection or consumer protection authority.
            </li>
          </ul>
          <p>
            Send a request through the <Link href="/contact/?topic=privacy">contact form</Link> with the topic “Privacy request”. We may ask you
            to confirm your identity before we act on it, and we will not treat you differently for exercising your rights.
          </p>
        </>
      ),
    },
    {
      id: "lawyers",
      heading: "I am a lawyer. Why is my profile on LexRanked?",
      answer: "Because your license and professional details are public in the official bar record; we publish them, with their source, so people can compare lawyers on facts.",
      children: (
        <p>
          You can <Link href="/advertising/">claim your profile</Link> for free to request corrections, or contact us to object. Claiming or
          objecting never changes a ranking position by itself; positions come only from the published methodology.
        </p>
      ),
    },
    {
      id: "security",
      heading: "How is my information protected?",
      answer: "The site uses encrypted connections (HTTPS), confirmation links are stored only as one-way codes, and access to forms data is limited to editors.",
      children: (
        <p>No method of storage or transmission is completely secure, but we keep only what we need and delete it on the schedule above.</p>
      ),
    },
    {
      id: "children",
      heading: "Is LexRanked meant for children?",
      answer: "No. LexRanked is not directed to children under 16, and we do not knowingly collect their personal information.",
    },
    {
      id: "changes",
      heading: "Will this policy change?",
      answer: "If we change how we handle personal information, we will update this page and its date before the change takes effect.",
    },
  ];

  return (
    <TrustPage
      path={PATH}
      crumb="Privacy"
      title="Privacy policy"
      lead="What personal information we collect, why, how long we keep it and how to exercise your rights."
      description={DESCRIPTION}
      updated="2026-10-05"
      sections={sections}
    />
  );
}
