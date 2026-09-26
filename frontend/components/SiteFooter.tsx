import Link from "next/link";
import { SITE_NAME } from "@/lib/config/site";
import { Brand } from "./SiteHeader";

export function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="container site-footer__grid">
        <div>
          <Brand />
          <p style={{ marginTop: "1rem", maxWidth: "22rem" }}>
            Data-driven, source-backed rankings of lawyers and law firms across the United States.
          </p>
        </div>
        <div>
          <h2>Explore</h2>
          <ul>
            <li><Link href="/rankings/">Rankings</Link></li>
            <li><Link href="/lawyers/">Lawyers</Link></li>
            <li><Link href="/law-firms/">Law firms</Link></li>
            <li><Link href="/practice-areas/">Practice areas</Link></li>
          </ul>
        </div>
        <div>
          <h2>Locations</h2>
          <ul>
            <li><Link href="/states/">States</Link></li>
            <li><Link href="/cities/">Cities</Link></li>
          </ul>
        </div>
        <div>
          <h2>Trust</h2>
          <ul>
            <li><Link href="/methodology/">How rankings work</Link></li>
            <li><Link href="/verified/">Verification</Link></li>
          </ul>
        </div>
      </div>
      <div className="container site-footer__legal">
        <p>
          &copy; {new Date().getUTCFullYear()} {SITE_NAME}. Rankings are calculated with a published, deterministic methodology;
          payment never changes an organic score. {SITE_NAME} is an information service — it is not a law firm, does not provide
          legal advice and is not a lawyer referral service.
        </p>
      </div>
    </footer>
  );
}
