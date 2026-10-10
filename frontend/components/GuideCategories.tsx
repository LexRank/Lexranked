import Link from "next/link";
import type { CategoryCount } from "@/lib/content/articles";

/** Category chips for the guides index and category pages. */
export function GuideCategories({ categories, current }: { categories: CategoryCount[]; current?: string }) {
  if (categories.length === 0) return null;
  return (
    <nav aria-label="Guide categories">
      <ul className="chips">
        <li>
          <Link className="chip" href="/articles/" aria-current={current ? undefined : "page"}>
            All guides
          </Link>
        </li>
        {categories.map((c) => (
          <li key={c.slug}>
            <Link className="chip" href={`/articles/category/${c.slug}/`} aria-current={c.slug === current ? "page" : undefined}>
              {c.name} <span className="muted">({c.count})</span>
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
