import { serializeJsonLd, type JsonLdObject } from "@/lib/seo/jsonld";

/** Renders schema.org data; serializeJsonLd escapes characters that could break out of the tag. */
export function JsonLd({ data }: { data: JsonLdObject | JsonLdObject[] }) {
  return <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: serializeJsonLd(data) }} />;
}
