// FAKE OpenAI Responses API for end-to-end tests. It returns canned,
// schema-valid structured output built only from the request; no model runs
// and nothing leaves the machine. Usage: node server.mjs <port>
import { createServer } from 'node:http';

const port = Number(process.argv[2] ?? 8097);

function answer(name, user) {
  switch (name) {
    case 'ranking_content': {
      const facts = user.facts;
      const ids = facts.map((f) => f.id);
      const first = facts.find((f) => f.label === 'Position 1');
      return {
        summary: `${first ? first.value.split(';')[0] : 'This ranking'} leads this ranking, based on the LexRank methodology.`,
        summaryFactRefs: [first ? first.id : ids[0]],
        sections: [
          {
            heading: 'How positions are decided',
            paragraphs: [{ text: 'Positions follow the LexRank score, a deterministic score from verified data, sources, experience and reviews; payment never affects positions.', factRefs: [facts.find((f) => f.label === 'How positions are decided')?.id ?? ids[0]] }],
          },
        ],
        faq: [{ question: 'Can a lawyer pay to rank higher?', answer: 'No. Payment never affects positions.', factRefs: [facts.find((f) => f.label === 'How positions are decided')?.id ?? ids[0]] }],
      };
    }
    case 'hub_content': {
      const facts = user.facts;
      const place = facts[0];
      const how = facts.find((f) => f.label === 'How profiles are ordered') ?? facts[0];
      return {
        summary: `This page covers lawyers in ${place.value}.`,
        summaryFactRefs: [place.id],
        sections: [{ heading: 'How profiles are ordered', paragraphs: [{ text: 'Profiles are ordered by the LexRank score; payment never affects positions.', factRefs: [how.id] }] }],
        faq: [],
      };
    }
    case 'profile_summary': {
      const name = user.facts[0];
      return { summary: `${name.value} has a LexRanked profile.`, summaryFactRefs: [name.id] };
    }
    case 'article_draft': {
      const how = user.facts.find((f) => f.label === 'How positions are decided') ?? user.facts[0];
      return {
        title: 'How LexRanked positions work',
        summary: 'A short guide to how positions are decided.',
        summaryFactRefs: [how.id],
        sections: [
          { heading: 'How positions are decided', paragraphs: [{ text: 'Positions follow the LexRank score, and payment never affects positions.', factRefs: [how.id] }] },
          { heading: 'What to check yourself', paragraphs: [{ text: 'Ask who will handle your case and how you will be kept informed.', factRefs: [] }] },
        ],
        faq: [],
      };
    }
    case 'content_qa':
      return { issues: [] };
    case 'match_review':
      return { verdict: 'unsure', confidence: 0.5, reason: 'Fake reviewer: records compared by name and city only.' };
    case 'profile_extraction':
      return { entityMentioned: false, facts: [], practiceAreas: [] };
    default:
      return null;
  }
}

createServer((req, res) => {
  let raw = '';
  req.on('data', (c) => (raw += c));
  req.on('end', () => {
    if (req.method !== 'POST' || req.url !== '/v1/responses' || !String(req.headers.authorization ?? '').startsWith('Bearer ')) {
      res.writeHead(404, { 'content-type': 'application/json' }).end(JSON.stringify({ error: { message: 'not found' } }));
      return;
    }
    const body = JSON.parse(raw);
    const data = answer(body.text?.format?.name, JSON.parse(body.input?.[1]?.content ?? '{}'));
    const payload = {
      id: 'resp_fake',
      object: 'response',
      status: 'completed',
      model: `fake-${body.model}`,
      output: [{ type: 'message', role: 'assistant', content: data === null ? [{ type: 'refusal', refusal: 'unknown schema' }] : [{ type: 'output_text', text: JSON.stringify(data) }] }],
      usage: { input_tokens: 1, output_tokens: 1 },
    };
    res.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify(payload));
  });
}).listen(port, '127.0.0.1');
