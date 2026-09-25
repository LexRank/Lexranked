import type { Metadata } from "next";
import { connection } from "next/server";
import { getConnectionReport } from "@/lib/wordpress/connection";

export const metadata: Metadata = {
  title: "System status",
  robots: { index: false, follow: false },
};

export default async function StatusPage() {
  await connection(); // always live; never prerendered
  const report = await getConnectionReport();
  const allOk = report.configured && report.checks.every((c) => c.ok);

  return (
    <section className="container page">
      <h1>System status</h1>
      <p className={allOk ? "status status--ok" : "status status--error"} role="status">
        {allOk ? "Connected to the LexRanked API." : "The LexRanked API is not fully reachable."}
      </p>

      <h2>Configuration</h2>
      <dl className="kv">
        <dt>WordPress API host</dt>
        <dd>{report.apiHost ?? "not configured (set WORDPRESS_API_URL)"}</dd>
        <dt>API credentials</dt>
        <dd>{report.authConfigured ? "configured" : "not configured (optional; avoids public rate limits)"}</dd>
      </dl>

      {report.configured && (
        <>
          <h2>Checks</h2>
          <table className="table">
            <thead>
              <tr>
                <th scope="col">Check</th>
                <th scope="col">Result</th>
                <th scope="col">Detail</th>
              </tr>
            </thead>
            <tbody>
              {report.checks.map((c) => (
                <tr key={c.name}>
                  <td>{c.name}</td>
                  <td>{c.ok ? "✓ OK" : "✗ Failed"}</td>
                  <td>{c.detail}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      {report.demoDataPresent && (
        <p className="notice">
          Demo data detected. Records marked as demo are mock data for testing and are never indexed.
        </p>
      )}
    </section>
  );
}
