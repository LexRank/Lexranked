import "server-only";

import { readServerEnv } from "@/lib/config/server-env";
import { getLawFirms, getLawyers, getPracticeAreas, getRankings, getStates, getStatus } from "./api";
import { WordPressApiError } from "./client";

/**
 * Connection diagnostics for the /status/ page. Reports configuration
 * presence (never values) and live API reachability.
 */

export interface CheckResult {
  name: string;
  ok: boolean;
  detail: string;
}

export interface ConnectionReport {
  configured: boolean;
  apiHost: string | null;
  authConfigured: boolean;
  checks: CheckResult[];
  demoDataPresent: boolean;
}

function describe(error: unknown): string {
  if (error instanceof WordPressApiError) {
    return error.status ? `HTTP ${error.status} · ${error.code}` : error.code;
  }
  return "unexpected error";
}

async function check<T>(name: string, run: () => Promise<T>, summarize: (value: T) => string): Promise<[CheckResult, T | null]> {
  try {
    const value = await run();
    return [{ name, ok: true, detail: summarize(value) }, value];
  } catch (error) {
    return [{ name, ok: false, detail: describe(error) }, null];
  }
}

export async function getConnectionReport(): Promise<ConnectionReport> {
  const env = readServerEnv();
  const apiHost = env.wordpressApiUrl ? new URL(env.wordpressApiUrl).host : null;
  const authConfigured = Boolean(env.wordpressUsername && env.wordpressAppPassword);

  if (!env.wordpressApiUrl) {
    return { configured: false, apiHost, authConfigured, checks: [], demoDataPresent: false };
  }

  const fresh = { revalidate: 0 } as const;
  const [status, lawyers, firms, rankings, states, practiceAreas] = await Promise.all([
    check("Plugin status", () => getStatus(), (r) => `lexranked-core ${r.data.pluginVersion} · API ${r.data.apiVersion}`),
    check("Lawyers", () => getLawyers({ per_page: 1 }, fresh), (r) => `${r.total ?? 0} published`),
    check("Law firms", () => getLawFirms({ per_page: 1 }, fresh), (r) => `${r.total ?? 0} published`),
    check("Rankings", () => getRankings({ per_page: 100 }, fresh), (r) => `${r.total ?? 0} published, ${r.data.filter((x) => x.indexable).length} indexable`),
    check("States", () => getStates(fresh), (r) => r.data.map((s) => s.name).join(", ") || "none"),
    check("Practice areas", () => getPracticeAreas(fresh), (r) => r.data.map((p) => p.name).join(", ") || "none"),
  ]);

  const demoDataPresent =
    Boolean(lawyers[1]?.data.some((l) => l.isDemo)) || Boolean(rankings[1]?.data.some((r) => r.isDemo));

  return {
    configured: true,
    apiHost,
    authConfigured,
    checks: [status[0], lawyers[0], firms[0], rankings[0], states[0], practiceAreas[0]],
    demoDataPresent,
  };
}
