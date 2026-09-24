/**
 * URL slug utilities shared by routes, sitemap and internal linking.
 *
 * Slugs are lowercase ASCII, hyphen-separated, and stable: the same input
 * always yields the same slug.
 */

const MAX_SLUG_LENGTH = 96;

export function slugify(input: string): string {
  const slug = input
    .normalize("NFKD")
    .replace(/[̀-ͯ]/g, "") // strip combining diacritics
    .toLowerCase()
    .replace(/&/g, " and ")
    .replace(/['’]/g, "") // "O'Brien" -> "obrien"
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

  if (slug.length <= MAX_SLUG_LENGTH) return slug;

  // Truncate on a word boundary so slugs never end mid-word.
  const cut = slug.slice(0, MAX_SLUG_LENGTH);
  if (slug[MAX_SLUG_LENGTH] === "-") return cut;
  const lastHyphen = cut.lastIndexOf("-");
  return lastHyphen > 0 ? cut.slice(0, lastHyphen) : cut;
}

export function isValidSlug(value: string): boolean {
  return (
    value.length > 0 &&
    value.length <= MAX_SLUG_LENGTH &&
    /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value)
  );
}
