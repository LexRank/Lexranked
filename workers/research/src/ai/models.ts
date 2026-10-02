/**
 * Model choice: good quality at a low price, picked at startup from the
 * models the API key can use, so a new generation is used as soon as it is
 * added to these lists (no environment change). OPENAI_MODEL /
 * OPENAI_IMAGE_MODEL still override the choice.
 *
 * Order = preference: the current cost-efficient tier first (not the
 * flagship, not the smallest), then older known-good fallbacks. Update these
 * lists when OpenAI releases a new generation.
 */

export const TEXT_MODEL_PREFERENCE = [
  'gpt-6.1-sol',
  'gpt-6-sol',
  'gpt-5.6-terra',
  'gpt-5.5-mini',
  'gpt-5.4-mini',
  'gpt-5-mini',
  'gpt-4.1-mini',
  'gpt-4o-mini',
] as const;

export const IMAGE_MODEL_PREFERENCE = ['gpt-image-2.5-flare', 'gpt-image-1.5', 'gpt-image-1-mini', 'gpt-image-1'] as const;

export class ModelError extends Error {}

export interface ResolveOptions {
  apiKey: string;
  baseUrl?: string;
  /** Explicit choice (OPENAI_MODEL / OPENAI_IMAGE_MODEL); returned as is. */
  override?: string;
  fetchImpl?: typeof fetch;
}

/** The first preferred model the key can use. */
export async function resolveModel(kind: 'text' | 'image', opts: ResolveOptions): Promise<string> {
  if (opts.override) return opts.override;
  const res = await (opts.fetchImpl ?? fetch)(`${(opts.baseUrl ?? 'https://api.openai.com/v1').replace(/\/+$/, '')}/models`, {
    headers: { Authorization: `Bearer ${opts.apiKey}` },
    signal: AbortSignal.timeout(20_000),
  });
  if (!res.ok) throw new ModelError(`Could not list models (HTTP ${res.status}); set ${kind === 'text' ? 'OPENAI_MODEL' : 'OPENAI_IMAGE_MODEL'} explicitly`);
  const ids = new Set(((await res.json()) as { data?: Array<{ id?: string }> }).data?.map((m) => m.id ?? '') ?? []);
  const preference: readonly string[] = kind === 'text' ? TEXT_MODEL_PREFERENCE : IMAGE_MODEL_PREFERENCE;
  const chosen = preference.find((id) => ids.has(id));
  if (!chosen) {
    throw new ModelError(`None of the preferred ${kind} models is available to this key (${preference.join(', ')}); set ${kind === 'text' ? 'OPENAI_MODEL' : 'OPENAI_IMAGE_MODEL'}`);
  }
  return chosen;
}
