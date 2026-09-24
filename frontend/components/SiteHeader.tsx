import Link from "next/link";
import { SITE_NAME } from "@/lib/config/site";

export function SiteHeader() {
  return (
    <header className="site-header">
      <div className="container site-header__inner">
        <Link href="/" className="site-header__brand">
          {SITE_NAME}
        </Link>
      </div>
    </header>
  );
}
