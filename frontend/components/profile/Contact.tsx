import type { CommercialBlock } from "@/types/api";

/**
 * A profile is "active" once its owner claimed it (identity confirmed by an
 * editor) or it is premium. Only active profiles get click-to-call and
 * click-to-email links; on other profiles the number and address are plain
 * text. The website link is always available.
 */
export function profileActive(commercial: CommercialBlock): boolean {
  return Boolean(commercial.claimed || commercial.premium || commercial.status === "claimed" || commercial.status === "premium");
}

export function PhoneLine({ phone, active }: { phone: string; active: boolean }) {
  return <dd>{active ? <a href={`tel:${phone.replace(/[^\d+]/g, "")}`}>{phone}</a> : <span>{phone}</span>}</dd>;
}

export function EmailLine({ email, active }: { email: string; active: boolean }) {
  return <dd>{active ? <a href={`mailto:${email}`}>{email}</a> : <span>{email}</span>}</dd>;
}
