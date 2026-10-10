import type { NextConfig } from "next";

const isDev = process.env.NODE_ENV !== "production";

/**
 * Content-Security-Policy. Pages are statically generated (ISR), so per-request
 * nonces are not available: inline scripts Next.js needs for hydration are
 * allowed with 'unsafe-inline', while everything else is locked down -
 * same-origin scripts, no plugins, no framing, no form posts elsewhere.
 * JSON-LD blocks are data (type application/ld+json) and are not executed.
 */
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${isDev ? " 'unsafe-eval'" : ""}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: https:",
  "font-src 'self'",
  `connect-src 'self'${isDev ? " ws: http://localhost:*" : ""}`,
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");

const securityHeaders = [
  { key: "Content-Security-Policy", value: csp },
  // Two years; subdomains included. Submit to the preload list only after verifying every subdomain serves HTTPS.
  { key: "Strict-Transport-Security", value: "max-age=63072000; includeSubDomains" },
  { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "X-Frame-Options", value: "DENY" },
  {
    key: "Permissions-Policy",
    value: "camera=(), microphone=(), geolocation=(), interest-cohort=()",
  },
];

const nextConfig: NextConfig = {
  // One canonical form per URL: /lawyers/john-smith/ (see docs/architecture.md).
  trailingSlash: true,
  poweredByHeader: false,
  // Always render <title>, canonical, robots and OpenGraph in <head> (no metadata
  // streaming), so every crawler and link-preview bot sees them without running JS.
  htmlLimitedBots: /.*/,
  reactStrictMode: true,
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
};

export default nextConfig;
