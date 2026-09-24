import { SITE_NAME } from "@/lib/config/site";

export function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="container">
        <p>
          {SITE_NAME} rankings are calculated with a published, deterministic
          methodology. Payment never changes a ranking score.
        </p>
        <p className="site-footer__legal">
          &copy; {new Date().getFullYear()} {SITE_NAME}. Rankings are informational
          and are not legal advice or a lawyer referral service.
        </p>
      </div>
    </footer>
  );
}
