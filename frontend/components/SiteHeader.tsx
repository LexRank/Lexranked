import Link from "next/link";
import { BrandMark, SearchIcon } from "./icons";

const NAV = [
  { href: "/rankings/", label: "Rankings" },
  { href: "/lawyers/", label: "Lawyers" },
  { href: "/law-firms/", label: "Law Firms" },
  { href: "/states/", label: "Locations" },
  { href: "/practice-areas/", label: "Practice Areas" },
  { href: "/articles/", label: "Guides" },
  { href: "/methodology/", label: "Methodology" },
];

export function Brand() {
  return (
    <Link href="/" className="brand" aria-label="LexRanked home">
      <BrandMark className="brand__mark" />
      <span className="brand__name">
        Lex<span>Ranked</span>
      </span>
    </Link>
  );
}

/** Server-rendered header; the mobile menu uses <details> so it works without JavaScript. */
export function SiteHeader() {
  return (
    <>
      <div className="trustbar">
        <div className="trustbar__inner">
          <span>Independent lawyer rankings</span>
          <span>Every fact traceable to a source</span>
          <span>Payment never changes a ranking</span>
        </div>
      </div>
      <header className="site-header">
        <div className="container site-header__inner">
          <Brand />
          <nav className="nav" aria-label="Main">
            {NAV.map((item) => (
              <Link key={item.href} href={item.href}>
                {item.label}
              </Link>
            ))}
          </nav>
          <form className="header-search" action="/search/" method="get" role="search">
            <SearchIcon />
            <label htmlFor="header-q" className="sr-only">
              Search lawyers and law firms
            </label>
            <input id="header-q" type="search" name="q" placeholder="Search by name" minLength={2} maxLength={100} required />
          </form>
          <details className="menu">
            <summary>Menu</summary>
            <div className="menu__panel">
              <nav aria-label="Mobile">
                {NAV.map((item) => (
                  <Link key={item.href} href={item.href}>
                    {item.label}
                  </Link>
                ))}
              </nav>
              <form action="/search/" method="get" role="search">
                <label htmlFor="menu-q" className="sr-only">
                  Search lawyers and law firms
                </label>
                <input id="menu-q" type="search" name="q" placeholder="Search lawyers or firms" minLength={2} maxLength={100} required />
                <button className="btn btn--navy" type="submit">
                  Search
                </button>
              </form>
            </div>
          </details>
        </div>
      </header>
    </>
  );
}
