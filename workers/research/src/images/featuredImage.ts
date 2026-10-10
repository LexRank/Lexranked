/**
 * Featured images for editorial articles: an OpenAI image generated from the
 * article's title and excerpt, uploaded to the WordPress media library with
 * alt text and set as the post's featured image.
 *
 * Rules:
 * - the LexRanked owl (docs/brand/lexranked-owl.png) is the character of
 *   every image, passed to the API as a reference so it stays consistent;
 * - simple scenes, no text of any kind, no human people (a lawyer-ranking
 *   site must not suggest real people or endorsements);
 * - posts that already have a featured image are skipped unless forced;
 * - the API key is only ever placed in the Authorization header.
 */

export class ImageError extends Error {}

export interface ArticleInput {
  title: string;
  excerpt: string;
  /** Optional art direction for this post: the setting and what the owl is doing. */
  scene?: string;
}

const STYLE =
  'Featured image for a legal-information guide: a rich, detailed editorial illustration that tells a small story. ' +
  'The main character is the LexRanked owl from the reference image: a blue owl with round gold glasses, a navy suit and white shirt. ' +
  'Keep the character exactly as in the reference (same colours, glasses, suit, proportions), shown actively doing something that represents the topic. ' +
  'Place the owl in a complete, believable setting with a detailed background and depth (foreground, middle ground, background), ' +
  'for example a Florida street, a courthouse hallway, a law library or an office at golden hour, with objects that belong to the topic. ' +
  'Cinematic composition, warm directional light and soft shadows, textured painterly-digital style, navy, blue and warm gold accents, landscape format, ' +
  'with a calm area on one side so the image reads well as a banner. ' +
  'Absolutely no text, letters, numbers, words, logos, labels, signs or captions anywhere in the image (blank documents, book spines, screens and signs). ' +
  'No human people; other animals may appear only as background figures.';

function plain(text: string): string {
  return text
    .replace(/<[^>]*>/g, ' ')
    .replace(/&[a-z#0-9]+;/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Prompt from the article's own title and excerpt plus the fixed style and safety rules. */
export function buildImagePrompt(article: ArticleInput): string {
  const title = plain(article.title).slice(0, 200);
  const excerpt = plain(article.excerpt).slice(0, 400);
  const scene = article.scene ? plain(article.scene).slice(0, 600) : '';
  return `${STYLE}\n\nThe scene represents this article: "${title}". ${excerpt ? `Context: ${excerpt}` : ''}${scene ? `\n\nScene: ${scene}` : ''}`.trim();
}

/** Alt text that describes what the image is for, not what a model claims it shows. */
export function altText(article: ArticleInput): string {
  return `The LexRanked owl illustrating “${plain(article.title).slice(0, 150)}”`;
}

export interface ImageGeneratorOptions {
  apiKey: string;
  model: string;
  baseUrl?: string;
  size?: string;
  timeoutMs?: number;
  retries?: number;
  fetchImpl?: typeof fetch;
  sleep?: (ms: number) => Promise<void>;
}

const RETRYABLE = new Set([408, 429, 500, 502, 503, 504]);

export class OpenAIImageGenerator {
  private readonly fetchImpl: typeof fetch;
  private readonly sleep: (ms: number) => Promise<void>;

  constructor(private readonly opts: ImageGeneratorOptions) {
    this.fetchImpl = opts.fetchImpl ?? fetch;
    this.sleep = opts.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
  }

  /** PNG bytes for the prompt; with a reference image the character is kept from it (images/edits). */
  async generate(prompt: string, reference?: { bytes: Uint8Array; filename: string; type: string }): Promise<Uint8Array> {
    const base = (this.opts.baseUrl ?? 'https://api.openai.com/v1').replace(/\/+$/, '');
    const size = this.opts.size ?? '1536x1024';
    const url = `${base}/images/${reference ? 'edits' : 'generations'}`;
    const makeBody = (): string | FormData => {
      if (!reference) return JSON.stringify({ model: this.opts.model, prompt, size, n: 1 });
      const form = new FormData();
      form.append('model', this.opts.model);
      form.append('prompt', prompt);
      form.append('size', size);
      form.append('n', '1');
      form.append('image[]', new Blob([reference.bytes], { type: reference.type }), reference.filename);
      return form;
    };
    const retries = this.opts.retries ?? 2;
    for (let attempt = 0; ; attempt++) {
      let res: Response;
      try {
        res = await this.fetchImpl(url, {
          method: 'POST',
          // FormData sets its own multipart boundary header.
          headers: reference ? { Authorization: `Bearer ${this.opts.apiKey}` } : { Authorization: `Bearer ${this.opts.apiKey}`, 'Content-Type': 'application/json' },
          body: makeBody(),
          signal: AbortSignal.timeout(this.opts.timeoutMs ?? 120_000),
        });
      } catch (err) {
        if (attempt < retries) {
          await this.sleep(2000 * 2 ** attempt);
          continue;
        }
        throw new ImageError(`Image request failed: ${(err as Error).message}`);
      }
      if (RETRYABLE.has(res.status) && attempt < retries) {
        await this.sleep(2000 * 2 ** attempt);
        continue;
      }
      if (!res.ok) {
        throw new ImageError(`Image API returned HTTP ${res.status}`);
      }
      const json = (await res.json()) as { data?: Array<{ b64_json?: string }> };
      const b64 = json.data?.[0]?.b64_json;
      if (!b64) throw new ImageError('Image API returned no image data');
      return new Uint8Array(Buffer.from(b64, 'base64'));
    }
  }
}

export interface MediaOptions {
  /** WordPress REST base, e.g. https://cms.example.com/wp-json */
  wpJsonUrl: string;
  user: string;
  appPassword: string;
  fetchImpl?: typeof fetch;
}

export interface WpPost {
  id: number;
  title: string;
  excerpt: string;
  featuredMedia: number;
}

/** The few /wp/v2 calls this needs: read a post, upload media, set the featured image. */
export class WordPressMedia {
  private readonly fetchImpl: typeof fetch;
  private readonly auth: string;
  private readonly base: string;

  constructor(opts: MediaOptions) {
    this.fetchImpl = opts.fetchImpl ?? fetch;
    this.auth = 'Basic ' + Buffer.from(`${opts.user}:${opts.appPassword}`).toString('base64');
    this.base = opts.wpJsonUrl.replace(/\/+$/, '');
  }

  private async call<T>(path: string, init: RequestInit): Promise<T> {
    const res = await this.fetchImpl(`${this.base}${path}`, { ...init, headers: { Authorization: this.auth, ...(init.headers ?? {}) } });
    if (!res.ok) throw new ImageError(`WordPress ${path} returned HTTP ${res.status}`);
    return (await res.json()) as T;
  }

  async post(id: number): Promise<WpPost> {
    const p = await this.call<{ id: number; title: { raw?: string; rendered: string }; excerpt: { raw?: string; rendered: string }; featured_media: number }>(
      `/wp/v2/posts/${id}?context=edit`,
      { method: 'GET' },
    );
    return { id: p.id, title: p.title.raw ?? p.title.rendered, excerpt: p.excerpt.raw ?? p.excerpt.rendered, featuredMedia: p.featured_media };
  }

  async upload(bytes: Uint8Array, filename: string, alt: string, title: string): Promise<{ id: number; url: string }> {
    const media = await this.call<{ id: number; source_url: string }>('/wp/v2/media', {
      method: 'POST',
      headers: { 'Content-Type': 'image/png', 'Content-Disposition': `attachment; filename="${filename}"` },
      body: bytes,
    });
    await this.call(`/wp/v2/media/${media.id}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ alt_text: alt, title, caption: '' }),
    });
    return { id: media.id, url: media.source_url };
  }

  async setFeatured(postId: number, mediaId: number): Promise<void> {
    await this.call(`/wp/v2/posts/${postId}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ featured_media: mediaId }),
    });
  }
}

export type FeaturedImageOutcome = { status: 'skipped'; reason: string } | { status: 'created'; mediaId: number; url: string };

/** Generate, upload and attach a featured image for one post. */
export async function addFeaturedImage(
  postId: number,
  deps: { wp: WordPressMedia; images: OpenAIImageGenerator; force?: boolean; reference?: { bytes: Uint8Array; filename: string; type: string }; scene?: string },
): Promise<FeaturedImageOutcome> {
  const post = await deps.wp.post(postId);
  if (post.featuredMedia > 0 && !deps.force) {
    return { status: 'skipped', reason: `post ${postId} already has featured image ${post.featuredMedia}` };
  }
  const article = { title: post.title, excerpt: post.excerpt, ...(deps.scene ? { scene: deps.scene } : {}) };
  const bytes = await deps.images.generate(buildImagePrompt(article), deps.reference);
  const slug = plain(post.title).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60) || `post-${postId}`;
  const media = await deps.wp.upload(bytes, `${slug}.png`, altText(article), plain(post.title));
  await deps.wp.setFeatured(postId, media.id);
  return { status: 'created', mediaId: media.id, url: media.url };
}
