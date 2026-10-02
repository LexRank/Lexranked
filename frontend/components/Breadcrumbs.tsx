import Link from "next/link";
import { breadcrumbJsonLd, type Crumb } from "@/lib/seo/jsonld";
import { JsonLd } from "./JsonLd";

/** Visible breadcrumb trail + matching BreadcrumbList JSON-LD. The last crumb is the current page. */
export function Breadcrumbs({ crumbs }: { crumbs: Crumb[] }) {
  return (
    <nav className="breadcrumbs" aria-label="Breadcrumb">
      <ol>
        {crumbs.map((crumb, i) =>
          i === crumbs.length - 1 ? (
            <li key={crumb.path} aria-current="page">
              {crumb.name}
            </li>
          ) : (
            <li key={crumb.path}>
              <Link href={crumb.path}>{crumb.name}</Link>
            </li>
          ),
        )}
      </ol>
      <JsonLd data={breadcrumbJsonLd(crumbs)} />
    </nav>
  );
}
