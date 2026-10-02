"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import type { FinderOption } from "@/lib/content/rankings";

/**
 * Location → practice area → ranking. Options come only from rankings that
 * exist, so every choice leads to a real page. Without JavaScript the
 * "Browse all rankings" link still works.
 */
export function RankingFinder({ options, variant = "card" }: { options: FinderOption[]; variant?: "card" | "bar" }) {
  const router = useRouter();
  const locations = useMemo(() => {
    const seen = new Map<string, string>();
    for (const o of options) if (!seen.has(o.locationKey)) seen.set(o.locationKey, o.locationLabel);
    return [...seen.entries()];
  }, [options]);

  const [location, setLocation] = useState(locations[0]?.[0] ?? "");
  const practices = options.filter((o) => o.locationKey === location);
  const [path, setPath] = useState(practices[0]?.path ?? "");

  if (options.length === 0) {
    return (
      <div className={variant === "bar" ? "finder finder--bar" : "finder"}>
        <h2>Rankings are being researched</h2>
        <p>We publish a ranking only once enough verified data exists. Check back soon.</p>
        <Link className="btn btn--navy" href="/methodology/">
          How rankings work
        </Link>
      </div>
    );
  }

  return (
    <form
      className={variant === "bar" ? "finder finder--bar" : "finder"}
      onSubmit={(e) => {
        e.preventDefault();
        if (path) router.push(path);
      }}
    >
      <h2>Find top-rated lawyers</h2>
      <p>Choose a location and practice area to see the current ranking.</p>
      <div className="finder__fields">
        <div className="field">
          <label htmlFor="finder-location">Location</label>
          <select
            id="finder-location"
            value={location}
            onChange={(e) => {
              setLocation(e.target.value);
              setPath(options.find((o) => o.locationKey === e.target.value)?.path ?? "");
            }}
          >
            {locations.map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="finder-practice">Practice area</label>
          <select id="finder-practice" value={path} onChange={(e) => setPath(e.target.value)}>
            {practices.map((o) => (
              <option key={o.path} value={o.path}>
                {o.practiceLabel}
              </option>
            ))}
          </select>
        </div>
        <button className="btn btn--primary" type="submit">
          See the ranking
        </button>
      </div>
      <p className="finder__foot">
        <Link href="/rankings/">Browse all rankings</Link>
      </p>
    </form>
  );
}
