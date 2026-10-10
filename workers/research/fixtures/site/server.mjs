// FICTIONAL fixture website for end-to-end tests of the research worker.
// Usage: node server.mjs <port>
import { createServer } from 'node:http';

const port = Number(process.argv[2] ?? 8099);
const host = `127.0.0.1:${port}`;

const pages = {
  '/robots.txt': ['text/plain', 'User-agent: *\nDisallow: /private\n'],
  '/sample-fixture': [
    'text/html; charset=utf-8',
    `<!doctype html><html><head><title>Sample &amp; Fixture (fictional)</title>
<script type="application/ld+json">${JSON.stringify({
      '@context': 'https://schema.org',
      '@type': 'LegalService',
      name: 'Sample & Fixture, P.A.',
      telephone: '+1 305 555 0142',
      url: `http://${host}/sample-fixture`,
      address: { '@type': 'PostalAddress', streetAddress: '100 Fixture Way', addressLocality: 'Miami', addressRegion: 'FL', postalCode: '33101' },
    })}</script></head><body><h1>Sample &amp; Fixture - fictional test firm</h1></body></html>`,
  ],
};

createServer((req, res) => {
  const page = pages[new URL(req.url ?? '/', `http://${host}`).pathname];
  if (!page) {
    res.writeHead(404).end('not found');
    return;
  }
  res.writeHead(200, { 'content-type': page[0] }).end(page[1]);
}).listen(port, '127.0.0.1');
