import { revalidatePath, revalidateTag } from "next/cache";
import { NextResponse } from "next/server";
import { parsePayload, SIGNATURE_HEADER, verifySignature } from "@/lib/revalidate/signature";

/**
 * On-demand revalidation webhook, called by WordPress (lexranked-core
 * Revalidator) when public data changes. Signed with REVALIDATE_SECRET;
 * anything unsigned, stale or malformed is rejected without side effects.
 */
export const dynamic = "force-dynamic";

const noStore = { "Cache-Control": "no-store" };

export async function POST(request: Request) {
  const secret = process.env.REVALIDATE_SECRET ?? "";
  if (secret.length === 0) {
    return NextResponse.json({ ok: false, error: "not_configured" }, { status: 503, headers: noStore });
  }
  const body = await request.text();
  if (body.length > 20_000 || !verifySignature(secret, request.headers.get(SIGNATURE_HEADER), body, Math.floor(Date.now() / 1000))) {
    return NextResponse.json({ ok: false, error: "invalid_signature" }, { status: 401, headers: noStore });
  }
  const payload = parsePayload(body);
  if (!payload) {
    return NextResponse.json({ ok: false, error: "invalid_payload" }, { status: 400, headers: noStore });
  }
  for (const tag of payload.tags) revalidateTag(tag, { expire: 0 });
  if (payload.paths.includes("/")) {
    revalidatePath("/", "layout"); // Rankings, hubs and listings all depend on the changed data.
  } else {
    for (const path of payload.paths) revalidatePath(path);
  }
  console.info(JSON.stringify({ level: "info", source: "revalidate", tags: payload.tags, paths: payload.paths }));
  return NextResponse.json({ ok: true, tags: payload.tags, paths: payload.paths }, { headers: noStore });
}
