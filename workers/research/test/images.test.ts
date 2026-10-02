import { describe, expect, it } from 'vitest';
import { addFeaturedImage, altText, buildImagePrompt, ImageError, OpenAIImageGenerator, WordPressMedia } from '../src/images/featuredImage.js';
import { parseArgs, wpJsonBase } from '../src/images-cli.js';

type Call = { url: string; init: RequestInit };

function fakeFetch(handler: (url: string, init: RequestInit) => { status?: number; json?: unknown }) {
  const calls: Call[] = [];
  const impl = (async (url: string | URL | Request, init: RequestInit = {}) => {
    calls.push({ url: String(url), init });
    const r = handler(String(url), init);
    return new Response(JSON.stringify(r.json ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } });
  }) as typeof fetch;
  return { impl, calls };
}

const PNG = Buffer.from('fake-png').toString('base64');

describe('featured images', () => {
  it('builds prompts from the article with fixed no-people, no-text rules', () => {
    const prompt = buildImagePrompt({ title: 'Car Accidents in <b>Miami</b>', excerpt: 'PIP &amp; the 14-day rule.' });
    expect(prompt).toContain('"Car Accidents in Miami"');
    expect(prompt).toContain('no people');
    expect(prompt).toContain('no text');
    expect(prompt).not.toContain('<b>');
    expect(altText({ title: 'Car Accidents in Miami', excerpt: '' })).toBe('Illustration for the article “Car Accidents in Miami”');
  });

  it('decodes the generated image and retries rate limits', async () => {
    let n = 0;
    const { impl, calls } = fakeFetch(() => (++n === 1 ? { status: 429 } : { json: { data: [{ b64_json: PNG }] } }));
    const gen = new OpenAIImageGenerator({ apiKey: 'sk-test', model: 'img-model', fetchImpl: impl, sleep: async () => {} });
    const bytes = await gen.generate('p');
    expect(Buffer.from(bytes).toString()).toBe('fake-png');
    expect(calls).toHaveLength(2);
    expect(JSON.parse(String(calls[1]!.init.body))).toMatchObject({ model: 'img-model', prompt: 'p', n: 1 });
    expect((calls[1]!.init.headers as Record<string, string>).Authorization).toBe('Bearer sk-test');
  });

  it('fails clearly without image data', async () => {
    const { impl } = fakeFetch(() => ({ json: { data: [] } }));
    await expect(new OpenAIImageGenerator({ apiKey: 'k', model: 'm', fetchImpl: impl }).generate('p')).rejects.toBeInstanceOf(ImageError);
  });

  it('uploads, sets alt text and attaches the image; skips posts that have one', async () => {
    const { impl: wpFetch, calls } = fakeFetch((url, init) => {
      if (url.endsWith('/wp/v2/posts/7?context=edit')) return { json: { id: 7, title: { raw: 'PIP in Florida' }, excerpt: { raw: 'How PIP works.' }, featured_media: 0 } };
      if (url.endsWith('/wp/v2/posts/8?context=edit')) return { json: { id: 8, title: { raw: 'X' }, excerpt: { raw: '' }, featured_media: 99 } };
      if (url.endsWith('/wp/v2/media') && init.method === 'POST') return { json: { id: 55, source_url: 'https://cms.test/pip.png' } };
      return { json: {} };
    });
    const { impl: aiFetch } = fakeFetch(() => ({ json: { data: [{ b64_json: PNG }] } }));
    const wp = new WordPressMedia({ wpJsonUrl: 'https://cms.test/wp-json/', user: 'u', appPassword: 'p', fetchImpl: wpFetch });
    const images = new OpenAIImageGenerator({ apiKey: 'k', model: 'm', fetchImpl: aiFetch });

    expect(await addFeaturedImage(7, { wp, images })).toEqual({ status: 'created', mediaId: 55, url: 'https://cms.test/pip.png' });
    const upload = calls.find((c) => c.url === 'https://cms.test/wp-json/wp/v2/media')!;
    expect((upload.init.headers as Record<string, string>)['Content-Disposition']).toBe('attachment; filename="pip-in-florida.png"');
    const meta = calls.find((c) => c.url.endsWith('/wp/v2/media/55'))!;
    expect(JSON.parse(String(meta.init.body))).toMatchObject({ alt_text: 'Illustration for the article “PIP in Florida”' });
    const attach = calls.find((c) => c.url.endsWith('/wp/v2/posts/7') && c.init.method === 'POST')!;
    expect(JSON.parse(String(attach.init.body))).toEqual({ featured_media: 55 });

    expect(await addFeaturedImage(8, { wp, images })).toMatchObject({ status: 'skipped' });
  });

  it('parses CLI arguments and the WordPress base URL', () => {
    expect(parseArgs(['--post', '10', '--post', '12', '--force'])).toEqual({ posts: [10, 12], force: true });
    expect(parseArgs(['--post', 'x'])).toEqual({ posts: [], force: false });
    expect(wpJsonBase('https://wp.lexranked.com/wp-json/lexranked/v1/')).toBe('https://wp.lexranked.com/wp-json');
  });
});

describe('model choice', () => {
  const list = (ids: string[]) => (async () => new Response(JSON.stringify({ data: ids.map((id) => ({ id })) }), { status: 200 })) as unknown as typeof fetch;

  it('picks the first preferred model the key can use', async () => {
    const { resolveModel, TEXT_MODEL_PREFERENCE } = await import('../src/ai/models.js');
    const newestCheap = TEXT_MODEL_PREFERENCE[0];
    expect(await resolveModel('text', { apiKey: 'k', fetchImpl: list(['gpt-4o-mini', newestCheap, 'some-flagship']) })).toBe(newestCheap);
    expect(await resolveModel('text', { apiKey: 'k', fetchImpl: list(['gpt-4o-mini', 'gpt-4.1-mini']) })).toBe('gpt-4.1-mini');
    expect(await resolveModel('image', { apiKey: 'k', fetchImpl: list(['gpt-image-1', 'gpt-image-1-mini']) })).toBe('gpt-image-1-mini');
  });

  it('honours an explicit override and fails clearly when nothing fits', async () => {
    const { resolveModel, ModelError } = await import('../src/ai/models.js');
    expect(await resolveModel('text', { apiKey: 'k', override: 'my-model', fetchImpl: list([]) })).toBe('my-model');
    await expect(resolveModel('image', { apiKey: 'k', fetchImpl: list(['text-only']) })).rejects.toBeInstanceOf(ModelError);
  });
});
