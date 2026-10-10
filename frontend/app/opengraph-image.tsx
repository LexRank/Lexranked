import { ImageResponse } from "next/og";

export const alt = "LexRanked - Data-driven lawyer rankings";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

/** Default social-sharing image (generated at build time, no external assets). */
export default function OpenGraphImage() {
  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          justifyContent: "space-between",
          padding: "72px",
          background: "linear-gradient(160deg, #0b1f3a 0%, #061426 100%)",
          color: "#ffffff",
          fontFamily: "serif",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: "20px" }}>
          <div
            style={{
              width: "64px",
              height: "64px",
              borderRadius: "32px",
              border: "3px solid #b08d57",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              color: "#d9c29a",
              fontSize: "36px",
            }}
          >
            ⚖
          </div>
          <div style={{ fontSize: "44px", display: "flex" }}>
            Lex<span style={{ color: "#b08d57" }}>Ranked</span>
          </div>
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
          <div style={{ fontSize: "68px", lineHeight: 1.1, maxWidth: "980px" }}>Top-rated lawyers, ranked by data - not by ads.</div>
          <div style={{ fontSize: "28px", color: "#d9c29a", fontFamily: "sans-serif" }}>
            Transparent methodology · Every fact sourced · Payment never changes a ranking
          </div>
        </div>
      </div>
    ),
    size,
  );
}
