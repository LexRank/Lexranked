#!/usr/bin/env node
/**
 * lexranked-images --post <id> [--post <id> …] [--force] [--scenes scenes.json]
 *
 * scenes.json (optional) maps a post ID to art direction for its image:
 * {"974": "The owl at a desk in a Miami office at sunset, weighing coins on a scale"}.
 *
 * Generates an illustration for each article and sets it as its featured
 * image. Needs OPENAI_API_KEY (the image model is picked from ai/models.ts
 * unless OPENAI_IMAGE_MODEL overrides it), plus a WordPress user that can upload files and edit posts
 * (LEXRANKED_API_URL, LEXRANKED_WORKER_USER, LEXRANKED_WORKER_APP_PASSWORD).
 *
 * Exit codes: 0 ok · 1 configuration error · 2 at least one post failed
 */

import { readFile } from 'node:fs/promises';
import { resolveModel } from './ai/models.js';
import { addFeaturedImage, OpenAIImageGenerator, WordPressMedia } from './images/featuredImage.js';

export function parseArgs(argv: string[]): { posts: number[]; force: boolean; scenes?: string } {
  const posts: number[] = [];
  let scenes: string | undefined;
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--post' && /^\d+$/.test(argv[i + 1] ?? '')) posts.push(Number(argv[++i]));
    else if (argv[i] === '--scenes' && argv[i + 1]) scenes = argv[++i];
  }
  return { posts, force: argv.includes('--force'), ...(scenes ? { scenes } : {}) };
}

/** `…/wp-json/lexranked/v1` → `…/wp-json`. */
export function wpJsonBase(apiUrl: string): string {
  return apiUrl.replace(/\/+$/, '').replace(/\/lexranked\/v1$/, '');
}

async function main(argv: string[]): Promise<number> {
  const { posts, force, scenes: scenesPath } = parseArgs(argv);
  const scenes: Record<string, string> = scenesPath ? (JSON.parse(await readFile(scenesPath, 'utf8')) as Record<string, string>) : {};
  const env = process.env;
  const missing = ['OPENAI_API_KEY', 'LEXRANKED_API_URL', 'LEXRANKED_WORKER_USER', 'LEXRANKED_WORKER_APP_PASSWORD'].filter((k) => !env[k]);
  if (missing.length > 0 || posts.length === 0) {
    process.stderr.write(`Usage: lexranked-images --post <id> [--force]. Missing: ${missing.join(', ') || 'post ID'}\n`);
    return 1;
  }
  const wp = new WordPressMedia({ wpJsonUrl: wpJsonBase(env.LEXRANKED_API_URL as string), user: env.LEXRANKED_WORKER_USER as string, appPassword: env.LEXRANKED_WORKER_APP_PASSWORD as string });
  const baseUrl = env.OPENAI_BASE_URL ? { baseUrl: env.OPENAI_BASE_URL } : {};
  const model = await resolveModel('image', { apiKey: env.OPENAI_API_KEY as string, override: env.OPENAI_IMAGE_MODEL, ...baseUrl });
  process.stdout.write(JSON.stringify({ imageModel: model }) + '\n');
  const images = new OpenAIImageGenerator({ apiKey: env.OPENAI_API_KEY as string, model, ...baseUrl });
  // The LexRanked owl, kept consistent across images (override: LEXRANKED_BRAND_IMAGE).
  const refPath = env.LEXRANKED_BRAND_IMAGE ?? new URL('../assets/lexranked-owl.png', import.meta.url).pathname;
  const reference = { bytes: new Uint8Array(await readFile(refPath)), filename: 'lexranked-owl.png', type: 'image/png' };
  let failed = 0;
  for (const id of posts) {
    try {
      const scene = scenes[String(id)];
      const outcome = await addFeaturedImage(id, { wp, images, force, reference, ...(scene ? { scene } : {}) });
      process.stdout.write(JSON.stringify({ post: id, ...outcome }) + '\n');
    } catch (err) {
      failed++;
      process.stdout.write(JSON.stringify({ post: id, status: 'failed', error: (err as Error).message }) + '\n');
    }
  }
  return failed > 0 ? 2 : 0;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main(process.argv.slice(2)).then(
    (code) => process.exit(code),
    (err: unknown) => {
      process.stderr.write(`Fatal: ${(err as Error).message}\n`);
      process.exit(1);
    },
  );
}
