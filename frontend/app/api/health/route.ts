import { NextResponse } from "next/server";
import { apiRequest } from "@/lib/wordpress/client";

/**
 * Health endpoint for uptime monitoring: checks that the CMS answers and
 * relays the status of its operational checks (names and levels only -
 * details stay in wp-admin / `wp lexranked health`). 503 when the CMS is
 * unreachable or a check is critical.
 */
export const dynamic = "force-dynamic";

interface CmsHealth {
  status: "ok" | "warning" | "critical";
  checks: { key: string; status: string }[];
  apiVersion: string;
}

export async function GET() {
  const started = Date.now();
  let cms: { reachable: boolean; status: string; apiVersion: string | null; checks: { key: string; status: string }[] } = {
    reachable: false,
    status: "unknown",
    apiVersion: null,
    checks: [],
  };
  try {
    const res = await apiRequest<CmsHealth>("health", { revalidate: 0, timeoutMs: 5000 });
    cms = { reachable: true, status: res.data.status, apiVersion: res.data.apiVersion, checks: res.data.checks.map(({ key, status }) => ({ key, status })) };
  } catch (error) {
    // A 503 from /health still means the CMS answered: its body is the report.
    const code = error instanceof Error && "status" in error ? (error as { status: number | null }).status : null;
    cms = { ...cms, reachable: code !== null && code !== 0, status: code === 503 ? "critical" : "unreachable" };
  }
  const status = !cms.reachable ? "down" : cms.status === "critical" ? "down" : cms.status === "warning" ? "degraded" : "ok";
  return NextResponse.json(
    { status, cms, latencyMs: Date.now() - started, time: new Date().toISOString() },
    { status: status === "down" ? 503 : 200, headers: { "Cache-Control": "no-store", "X-Robots-Tag": "noindex" } },
  );
}
