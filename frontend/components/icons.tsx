/**
 * Inline SVG icons (decorative unless labelled). No icon font or external
 * requests.
 */

type IconProps = { className?: string; title?: string };

function Svg({ className, title, children, viewBox = "0 0 24 24" }: IconProps & { children: React.ReactNode; viewBox?: string }) {
  return (
    <svg
      className={className}
      viewBox={viewBox}
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden={title ? undefined : true}
      role={title ? "img" : undefined}
    >
      {title && <title>{title}</title>}
      {children}
    </svg>
  );
}

/** Brand mark: balanced scales inside a shield-like laurel ring. */
export function BrandMark({ className }: IconProps) {
  return (
    <svg className={className} viewBox="0 0 40 40" aria-hidden="true">
      <circle cx="20" cy="20" r="19" fill="#0b1f3a" />
      <circle cx="20" cy="20" r="16.5" fill="none" stroke="#b08d57" strokeWidth="1" opacity="0.8" />
      <g stroke="#d9c29a" strokeWidth="1.6" strokeLinecap="round" fill="none">
        <path d="M20 10v19" />
        <path d="M12 14h16" />
        <path d="M15 29h10" />
        <path d="M12 14l-3.2 7h6.4z" />
        <path d="M28 14l-3.2 7h6.4z" />
      </g>
      <circle cx="20" cy="10" r="1.6" fill="#d9c29a" />
    </svg>
  );
}

export const CheckIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M20 6 9 17l-5-5" />
  </Svg>
);

export const ShieldCheckIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
    <path d="m9 12 2 2 4-4" />
  </Svg>
);

export const ClockIcon = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="10" />
    <path d="M12 6v6l4 2" />
  </Svg>
);

export const AlertIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
    <path d="M12 9v4M12 17h.01" />
  </Svg>
);

export const InfoIcon = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="10" />
    <path d="M12 16v-4M12 8h.01" />
  </Svg>
);

export const SearchIcon = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="11" cy="11" r="7" />
    <path d="m21 21-4.3-4.3" />
  </Svg>
);

export const XCircleIcon = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="10" />
    <path d="m15 9-6 6M9 9l6 6" />
  </Svg>
);

export const MapPinIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z" />
    <circle cx="12" cy="10" r="2.5" />
  </Svg>
);

export const ScaleIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M12 3v18M7 21h10M4 7h16" />
    <path d="m4 7-3 7a4 4 0 0 0 6 0zM20 7l-3 7a4 4 0 0 0 6 0z" />
  </Svg>
);

export const DocumentIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
    <path d="M14 2v6h6M8 13h8M8 17h5" />
  </Svg>
);
