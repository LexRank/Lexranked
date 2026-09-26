"use client";

/**
 * Last-resort error boundary (root layout failed). Keeps the page minimal
 * and self-contained: no data, no secrets, a way back home.
 */
export default function GlobalError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <html lang="en-US">
      <body style={{ fontFamily: "system-ui, sans-serif", margin: 0, padding: "4rem 1.5rem", background: "#f7f5f0", color: "#141c26" }}>
        <main style={{ maxWidth: "36rem", margin: "0 auto" }}>
          <h1 style={{ fontSize: "1.75rem" }}>Something went wrong</h1>
          <p>We could not load this page. Please try again in a moment.</p>
          <p style={{ display: "flex", gap: "1rem" }}>
            <button type="button" onClick={reset}>
              Try again
            </button>
            {/* A full page load on purpose: the app shell itself failed. */}
            {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
            <a href="/">Go to the home page</a>
          </p>
        </main>
      </body>
    </html>
  );
}
