/**
 * A line icon for a practice area, chosen by keywords in its slug, with the
 * scales as the fallback. Decorative (aria-hidden).
 */

const ICONS: Array<[RegExp, React.ReactNode]> = [
  [
    /injur|accident|wrongful/,
    <>
      <rect x="3" y="3" width="18" height="18" rx="4" />
      <path d="M12 8v8M8 12h8" />
    </>,
  ],
  [
    /malpractice|medical/,
    <>
      <path d="M6 3v6a4 4 0 0 0 8 0V3" />
      <path d="M10 13v2a5 5 0 0 0 10 0v-2" />
      <circle cx="20" cy="11" r="2" />
    </>,
  ],
  [
    /divorce|family|custody/,
    <>
      <circle cx="8" cy="6" r="2.5" />
      <circle cx="16" cy="6" r="2.5" />
      <circle cx="12" cy="13" r="2" />
      <path d="M4 21v-6a4 4 0 0 1 4-4M20 21v-6a4 4 0 0 0-4-4M9.5 21v-2a2.5 2.5 0 0 1 5 0v2" />
    </>,
  ],
  [
    /criminal|dui|dwi|defense/,
    <>
      <path d="m14 5 5 5M11 8l5 5M12.5 6.5l3.5-3.5 5 5-3.5 3.5M9.5 9.5 6 13l5 5 3.5-3.5M3 21l6-6" />
    </>,
  ],
  [
    /immigra|visa/,
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
    </>,
  ],
  [
    /estate|probate|will|trust/,
    <>
      <path d="M7 3h10v18H7z" />
      <path d="M10 7h4M10 11h4M10 15h2" />
      <path d="M4 6v14M20 6v14" />
    </>,
  ],
  [
    /business|corporate|commercial/,
    <>
      <rect x="3" y="7" width="18" height="13" rx="2" />
      <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18" />
    </>,
  ],
  [
    /employ|labor|workers/,
    <>
      <rect x="4" y="3" width="16" height="18" rx="2" />
      <circle cx="12" cy="10" r="3" />
      <path d="M8 17a4 4 0 0 1 8 0" />
    </>,
  ],
  [
    /bankrupt|debt|tax/,
    <>
      <ellipse cx="12" cy="6" rx="7" ry="3" />
      <path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6" />
    </>,
  ],
  [
    /real-estate|property|landlord/,
    <>
      <path d="m3 11 9-7 9 7" />
      <path d="M5 10v10h14V10M10 20v-6h4v6" />
    </>,
  ],
  [
    /intellectual|patent|trademark/,
    <>
      <path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z" />
    </>,
  ],
];

const FALLBACK = (
  <>
    <path d="M12 3v18M7 21h10M4 7h16" />
    <path d="m4 7-3 7a4 4 0 0 0 6 0zM20 7l-3 7a4 4 0 0 0 6 0z" />
  </>
);

export function PracticeIcon({ slug, className }: { slug: string; className?: string }) {
  const icon = ICONS.find(([re]) => re.test(slug))?.[1] ?? FALLBACK;
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {icon}
    </svg>
  );
}
