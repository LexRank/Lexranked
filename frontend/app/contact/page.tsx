import type { Metadata } from "next";
import Link from "next/link";
import { ContactForm } from "@/components/ContactForm";
import { JsonLd } from "@/components/JsonLd";
import { PageHeader } from "@/components/PageHeader";
import { rankingPageJsonLd } from "@/lib/seo/jsonld";
import { buildMetadata } from "@/lib/seo/metadata";

const PATH = "/contact/";
const DESCRIPTION =
  "Contact the LexRanked editors: report an error on a profile or ranking, ask about your lawyer profile, make a privacy request or ask about using our data.";

export const metadata: Metadata = buildMetadata({ title: "Contact LexRanked", description: DESCRIPTION, path: PATH });

const TOPICS: Array<[string, string]> = [
  ["Report an error", "Give the page address, what is wrong and a link to the public source with the correct information, such as the state bar record."],
  ["Your lawyer profile", "Lawyers can also claim their profile for free from the profile page to request corrections; claiming never changes a position."],
  ["Privacy request", "Ask to see, correct or delete your personal information, or remove a review you wrote. We may ask you to confirm your identity."],
  ["Press or data use", "Ask to quote or reuse LexRanked data beyond short quotes with a link."],
];

export default async function ContactPage(props: PageProps<"/contact">) {
  const params = await props.searchParams;
  const topic = typeof params.topic === "string" ? params.topic : undefined;
  const page = typeof params.page === "string" && params.page.startsWith("/") ? params.page.slice(0, 300) : undefined;
  return (
    <>
      <JsonLd data={rankingPageJsonLd({ name: "Contact LexRanked", path: PATH, description: DESCRIPTION, dateModified: "2026-10-05", reviewedBy: null, reviewedAt: null })} />
      <PageHeader
        crumbs={[
          { name: "Home", path: "/" },
          { name: "Contact", path: PATH },
        ]}
        eyebrow="Trust"
        title="Contact LexRanked"
        lead="Your message goes to the LexRanked editors, who reply by email."
      />
      <div className="container section stack">
        <div className="prose editorial__body">
          <h2>How do I contact LexRanked?</h2>
          <p>
            <strong>Fill in the form below; we read every message and reply to the email address you enter.</strong> The message is emailed to
            the editors and is not stored on the website.
          </p>
          <h2>What should I include?</h2>
          <p>
            <strong>Choose the topic that fits and, for a correction, add the page address and a link to the source that shows the right
            information.</strong>
          </p>
          <table>
            <thead>
              <tr>
                <th>Topic</th>
                <th>What to include</th>
              </tr>
            </thead>
            <tbody>
              {TOPICS.map(([name, body]) => (
                <tr key={name}>
                  <td>{name}</td>
                  <td>{body}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <h2>Can LexRanked help with my legal problem?</h2>
          <p>
            <strong>No. LexRanked cannot give legal advice or recommend a lawyer for your case, so please do not send details of a legal
            matter.</strong> To find a lawyer, browse the <Link href="/rankings/">rankings</Link> and check the lawyer&apos;s license with the
            state bar before you hire them. If you need free legal help, contact your local legal aid office.
          </p>
        </div>
        <section className="card" aria-labelledby="contact-form-heading" style={{ maxWidth: "46rem" }}>
          <h2 id="contact-form-heading" style={{ fontSize: "1.3rem" }}>
            Send a message
          </h2>
          <ContactForm topic={topic} page={page} />
          <p className="muted" style={{ fontSize: "0.85rem", marginTop: "1rem" }}>
            We use your name and email only to answer you. See the <Link href="/privacy/">privacy policy</Link>.
          </p>
        </section>
      </div>
    </>
  );
}
