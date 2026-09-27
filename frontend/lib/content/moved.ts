import "server-only";

import { permanentRedirect } from "next/navigation";
import type { EntityType } from "@/types/api";
import { resolveEntity } from "@/lib/wordpress/api";

/**
 * Before answering 404 for an entity page, ask the registry whether the slug
 * belonged to an entity that was renamed (or merged). If so, send a
 * permanent redirect to its current page, so links and search results keep
 * working after a rename. Identity is the entity ID, never the slug.
 */
export async function redirectIfMoved(type: EntityType, slug: string, currentPath: string): Promise<void> {
  const entity = await resolveEntity(type, slug).catch(() => null);
  if (entity && entity.path !== currentPath) permanentRedirect(entity.path);
}
