import type { ReactNode } from "react";
import type { Crumb } from "@/lib/seo/jsonld";
import { Breadcrumbs } from "./Breadcrumbs";

export function PageHeader({
  crumbs,
  eyebrow,
  title,
  lead,
  children,
}: {
  crumbs: Crumb[];
  eyebrow?: string;
  title: string;
  lead?: ReactNode;
  children?: ReactNode;
}) {
  return (
    <header className="page-header">
      <div className="container">
        <Breadcrumbs crumbs={crumbs} />
        {eyebrow && <p className="eyebrow">{eyebrow}</p>}
        <h1>{title}</h1>
        {lead && <p className="lead">{lead}</p>}
        {children}
      </div>
    </header>
  );
}
