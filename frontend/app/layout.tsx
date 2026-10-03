import type { Metadata, Viewport } from "next";
import localFont from "next/font/local";
import { SiteFooter } from "@/components/SiteFooter";
import { SiteHeader } from "@/components/SiteHeader";
import { SITE_DESCRIPTION, SITE_NAME, siteUrl } from "@/lib/config/site";
import { JsonLd } from "@/components/JsonLd";
import { organizationJsonLd, websiteJsonLd } from "@/lib/seo/jsonld";
import "./globals.css";

// Self-hosted variable fonts (SIL OFL, files and licences in app/fonts/): no
// third-party requests from visitors' browsers, and builds never depend on
// downloading fonts from Google.
const inter = localFont({
  src: "./fonts/Inter-latin-wght.woff2",
  weight: "100 900",
  style: "normal",
  display: "swap",
  variable: "--font-inter",
  fallback: ["system-ui", "Arial", "sans-serif"],
});
const serif = localFont({
  src: "./fonts/SourceSerif4-latin-wght.woff2",
  weight: "200 900",
  style: "normal",
  display: "swap",
  variable: "--font-serif-display",
  fallback: ["Georgia", "Times New Roman", "serif"],
});

export const metadata: Metadata = {
  metadataBase: new URL(siteUrl),
  title: {
    default: `${SITE_NAME} — Data-driven lawyer rankings`,
    template: `%s | ${SITE_NAME}`,
  },
  description: SITE_DESCRIPTION,
  applicationName: SITE_NAME,
  openGraph: { siteName: SITE_NAME, locale: "en_US", type: "website" },
  twitter: { card: "summary_large_image" },
  formatDetection: { telephone: false },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#0b1f3a",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en-US" className={`${inter.variable} ${serif.variable}`}>
      <body>
        <a href="#main" className="skip-link">
          Skip to content
        </a>
        <SiteHeader />
        <main id="main">{children}</main>
        <SiteFooter />
        {/* Site-wide nodes that every page's isPartOf / publisher references resolve to. */}
        <JsonLd data={[organizationJsonLd(), websiteJsonLd()]} />
      </body>
    </html>
  );
}
