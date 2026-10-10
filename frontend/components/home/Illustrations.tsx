/**
 * Home page illustrations: original inline SVG in the LexRanked palette
 * (navy, brass, paper). Decorative only (aria-hidden); they show what a
 * ranked profile looks like, never real or invented data: no names, no
 * scores, no counts. No image files or external requests.
 */

const NAVY = "#0b1f3a";
const NAVY_700 = "#1b4478";
const BRASS = "#b08d57";
const BRASS_300 = "#d9c29a";
const LINE = "#e4e0d8";
const SKELETON = "#e9edf3";
const GREEN = "#1d7a4c";

/** Hero: an example ranking entry with its evidence around it. */
export function HeroArt({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 560 470" aria-hidden="true" focusable="false">
      <defs>
        <radialGradient id="ha-glow" cx="50%" cy="45%" r="55%">
          <stop offset="0%" stopColor={BRASS_300} stopOpacity="0.35" />
          <stop offset="100%" stopColor={BRASS_300} stopOpacity="0" />
        </radialGradient>
        <linearGradient id="ha-brass" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="#efdcb4" />
          <stop offset="50%" stopColor="#d2b27c" />
          <stop offset="100%" stopColor="#b89259" />
        </linearGradient>
        <filter id="ha-shadow" x="-20%" y="-20%" width="140%" height="160%">
          <feDropShadow dx="0" dy="18" stdDeviation="18" floodColor="#020a14" floodOpacity="0.45" />
        </filter>
        <filter id="ha-shadow-sm" x="-30%" y="-40%" width="160%" height="200%">
          <feDropShadow dx="0" dy="8" stdDeviation="9" floodColor="#020a14" floodOpacity="0.35" />
        </filter>
      </defs>

      <circle cx="290" cy="235" r="228" fill="url(#ha-glow)" />

      {/* Scales of justice, drawn large behind the cards. */}
      <g stroke={BRASS_300} strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" fill="none" opacity="0.28">
        <path d="M290 30v380M190 90h200M240 410h100" />
        <path d="m190 90-50 110h100zM390 90l-50 110h100z" />
        <path d="M140 200a50 22 0 0 0 100 0M340 200a50 22 0 0 0 100 0" />
        <circle cx="290" cy="30" r="9" />
      </g>

      {/* Card #2, behind. */}
      <g transform="translate(118 262) rotate(-4)" filter="url(#ha-shadow)" opacity="0.92">
        <rect width="360" height="118" rx="20" fill="#f7f6f2" />
        <text x="26" y="66" className="art-serif" fontSize="34" fontWeight="600" fill={NAVY_700} opacity="0.55">
          #2
        </text>
        <circle cx="104" cy="56" r="22" fill={SKELETON} />
        <rect x="140" y="40" width="130" height="12" rx="6" fill={SKELETON} />
        <rect x="140" y="62" width="90" height="10" rx="5" fill={SKELETON} />
        <circle cx="318" cy="58" r="22" fill="none" stroke={SKELETON} strokeWidth="7" />
        <path d="M318 36a22 22 0 1 1 -20.9 28.8" fill="none" stroke={BRASS_300} strokeWidth="7" strokeLinecap="round" />
      </g>

      {/* Card #1, the example entry. */}
      <g transform="translate(78 92)" filter="url(#ha-shadow)">
        <rect width="400" height="214" rx="22" fill="#fff" />
        <rect width="400" height="5" rx="2.5" fill="url(#ha-brass)" />
        <text x="28" y="40" fontSize="11" fontWeight="700" letterSpacing="1.6" fill="#8a94a3">
          EXAMPLE RANKING ENTRY
        </text>
        <text x="26" y="98" className="art-serif" fontSize="44" fontWeight="600" fill="url(#ha-brass)">
          #1
        </text>
        <circle cx="116" cy="86" r="27" fill={NAVY} />
        <g stroke={BRASS_300} strokeWidth="2" strokeLinecap="round" fill="none">
          <path d="M116 72v26M105 77h22M110 98h12" />
          <path d="m105 77-4.5 9h9zM127 77l-4.5 9h9z" />
        </g>
        <rect x="158" y="68" width="138" height="14" rx="7" fill="#d5dce6" />
        <rect x="158" y="90" width="96" height="10" rx="5" fill={SKELETON} />
        <g transform="translate(158 110)">
          <rect width="84" height="24" rx="12" fill="#e3f3ea" />
          <path d="m12 12 4 4 7-8" stroke={GREEN} strokeWidth="2.2" fill="none" strokeLinecap="round" strokeLinejoin="round" />
          <text x="30" y="16.5" fontSize="11" fontWeight="700" fill={GREEN}>
            Verified
          </text>
        </g>
        <g transform="translate(250 110)">
          <rect width="62" height="24" rx="12" fill="#f6efe2" />
          <text x="31" y="16.5" textAnchor="middle" fontSize="11" fontWeight="700" fill="#9a7640">
            Sourced
          </text>
        </g>

        {/* Score ring (no number: an illustration, not a score). */}
        <circle cx="348" cy="92" r="30" fill="none" stroke="#eef1f5" strokeWidth="8" />
        <path d="M348 62a30 30 0 1 1 -28.5 39.3" fill="none" stroke="url(#ha-brass)" strokeWidth="8" strokeLinecap="round" />
        <text x="348" y="96" textAnchor="middle" fontSize="9" fontWeight="700" letterSpacing="0.8" fill={NAVY}>
          SCORE
        </text>

        {/* Factor bars. */}
        <line x1="28" y1="152" x2="372" y2="152" stroke={LINE} />
        <g fontSize="10" fontWeight="600" fill="#5b6675">
          <text x="28" y="176">
            Reputation
          </text>
          <text x="148" y="176">
            Experience
          </text>
          <text x="268" y="176">
            Credentials
          </text>
        </g>
        <rect x="28" y="184" width="100" height="7" rx="3.5" fill="#eef1f5" />
        <rect x="28" y="184" width="82" height="7" rx="3.5" fill={NAVY_700} />
        <rect x="148" y="184" width="100" height="7" rx="3.5" fill="#eef1f5" />
        <rect x="148" y="184" width="64" height="7" rx="3.5" fill={NAVY_700} />
        <rect x="268" y="184" width="104" height="7" rx="3.5" fill="#eef1f5" />
        <rect x="268" y="184" width="92" height="7" rx="3.5" fill={NAVY_700} />
      </g>

      {/* Floating evidence badges. */}
      <g className="hero-art__float hero-art__float--1" filter="url(#ha-shadow-sm)">
        <g transform="translate(360 36)">
          <rect width="178" height="46" rx="23" fill="#fff" />
          <circle cx="23" cy="23" r="15" fill={NAVY} />
          <path
            d="M23 13.5s6 2.2 6 2.2v4.8c0 4.4-6 7.5-6 7.5s-6-3.1-6-7.5v-4.8z"
            fill="none"
            stroke={BRASS_300}
            strokeWidth="1.8"
            strokeLinejoin="round"
          />
          <path d="m20.3 20.6 2 2 3.6-3.6" fill="none" stroke={BRASS_300} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          <text x="46" y="20" fontSize="12" fontWeight="700" fill={NAVY}>
            License checked
          </text>
          <text x="46" y="34" fontSize="10" fill="#5b6675">
            against official records
          </text>
        </g>
      </g>
      <g className="hero-art__float hero-art__float--2" filter="url(#ha-shadow-sm)">
        <g transform="translate(4 330)">
          <rect width="150" height="46" rx="23" fill="#fff" />
          <circle cx="23" cy="23" r="15" fill="#f6efe2" />
          <path d="M19 15.5h6l3.5 3.5v10h-9.5z" fill="none" stroke="#9a7640" strokeWidth="1.7" strokeLinejoin="round" />
          <path d="M21.5 22.5h4.5M21.5 25.5h3" stroke="#9a7640" strokeWidth="1.5" strokeLinecap="round" />
          <text x="46" y="20" fontSize="12" fontWeight="700" fill={NAVY}>
            Every fact
          </text>
          <text x="46" y="34" fontSize="10" fill="#5b6675">
            has a source
          </text>
        </g>
      </g>
      <g className="hero-art__float hero-art__float--3" filter="url(#ha-shadow-sm)">
        <g transform="translate(330 398)">
          <rect width="196" height="46" rx="23" fill="#fff" />
          <circle cx="23" cy="23" r="15" fill="#fdecec" />
          <text x="23" y="28" textAnchor="middle" className="art-serif" fontSize="15" fontWeight="700" fill="#a33a3a">
            $
          </text>
          <path d="M13 33 33 13" stroke="#a33a3a" strokeWidth="1.8" strokeLinecap="round" />
          <text x="46" y="20" fontSize="12" fontWeight="700" fill={NAVY}>
            No paid positions
          </text>
          <text x="46" y="34" fontSize="10" fill="#5b6675">
            payment never changes a rank
          </text>
        </g>
      </g>
    </svg>
  );
}

/** Step 1: public records and documents collected. */
export function CollectArt({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 160 110" aria-hidden="true" focusable="false">
      <rect x="30" y="18" width="62" height="78" rx="8" fill="#fff" stroke={LINE} strokeWidth="1.5" transform="rotate(-8 61 57)" />
      <g>
        <rect x="46" y="14" width="62" height="80" rx="8" fill="#fff" stroke="#cfc9bd" strokeWidth="1.5" />
        <rect x="56" y="26" width="30" height="6" rx="3" fill={NAVY_700} />
        <rect x="56" y="40" width="42" height="5" rx="2.5" fill={SKELETON} />
        <rect x="56" y="51" width="36" height="5" rx="2.5" fill={SKELETON} />
        <rect x="56" y="62" width="40" height="5" rx="2.5" fill={SKELETON} />
        <rect x="56" y="73" width="24" height="5" rx="2.5" fill={SKELETON} />
      </g>
      <circle cx="108" cy="66" r="18" fill="rgb(217 194 154 / 25%)" stroke={BRASS} strokeWidth="4" />
      <path d="m121 79 14 14" stroke={NAVY} strokeWidth="7" strokeLinecap="round" />
    </svg>
  );
}

/** Step 2: credentials verified. */
export function VerifyArt({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 160 110" aria-hidden="true" focusable="false">
      <rect x="18" y="30" width="92" height="60" rx="9" fill="#fff" stroke="#cfc9bd" strokeWidth="1.5" />
      <rect x="18" y="30" width="92" height="14" rx="9" fill={NAVY} />
      <rect x="18" y="38" width="92" height="6" fill={NAVY} />
      <circle cx="40" cy="65" r="11" fill={SKELETON} />
      <rect x="58" y="58" width="40" height="5" rx="2.5" fill="#d5dce6" />
      <rect x="58" y="69" width="28" height="5" rx="2.5" fill={SKELETON} />
      <path
        d="M118 20s20 7 20 7v17c0 15-20 25-20 25s-20-10-20-25V27z"
        fill={NAVY}
        stroke={BRASS_300}
        strokeWidth="2"
        strokeLinejoin="round"
      />
      <path d="m109 44 6.5 6.5L128 38" fill="none" stroke={BRASS_300} strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/** Step 3: a ranking calculated by the published methodology. */
export function RankArt({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 160 110" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id="ra-brass" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#efdcb4" />
          <stop offset="100%" stopColor="#b89259" />
        </linearGradient>
      </defs>
      <rect x="22" y="56" width="36" height="40" rx="5" fill={NAVY_700} />
      <rect x="62" y="34" width="36" height="62" rx="5" fill="url(#ra-brass)" />
      <rect x="102" y="68" width="36" height="28" rx="5" fill={NAVY} />
      <g className="art-serif" fontSize="16" fontWeight="600" textAnchor="middle">
        <text x="40" y="80" fill="#fff">
          2
        </text>
        <text x="80" y="60" fill={NAVY}>
          1
        </text>
        <text x="120" y="88" fill="#fff">
          3
        </text>
      </g>
      <path d="m80 12 3.5 7 7.7 1.1-5.6 5.4 1.3 7.7L80 29.6l-6.9 3.6 1.3-7.7-5.6-5.4 7.7-1.1z" fill={BRASS} />
      <line x1="14" y1="96" x2="146" y2="96" stroke="#cfc9bd" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}
