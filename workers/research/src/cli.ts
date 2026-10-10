#!/usr/bin/env node
/**
 * lexranked-research [--once | --loop]
 *
 *   --once  claim and run at most one job, then exit (cron / CI friendly)
 *   --loop  keep polling for jobs until SIGINT/SIGTERM (default)
 *
 * Exit codes: 0 ok · 1 configuration error · 2 last job failed (--once)
 */

import { ResearchApi } from './api.js';
import { ConfigError, loadConfig } from './config.js';
import { SafeFetcher } from './fetcher.js';
import { JsonLogger } from './logger.js';
import { OpenAIClient } from './ai/openai.js';
import { resolveModel } from './ai/models.js';
import { runOnce, type Outcome } from './runner.js';

async function main(argv: string[]): Promise<number> {
  const logger = new JsonLogger();
  let config;
  try {
    config = loadConfig();
  } catch (err) {
    if (err instanceof ConfigError) {
      logger.log('error', err.message);
      return 1;
    }
    throw err;
  }

  const api = new ResearchApi({ baseUrl: config.apiUrl, user: config.user, appPassword: config.appPassword, userAgent: config.userAgent });
  const fetcher = new SafeFetcher({
    userAgent: config.userAgent,
    timeoutMs: config.fetchTimeoutMs,
    maxBytes: config.fetchMaxBytes,
    perHostIntervalMs: config.perHostIntervalMs,
    allowPrivateNetwork: config.allowPrivateNetwork,
  });
  let ai: OpenAIClient | null = null;
  if (config.ai) {
    let model: string;
    try {
      model = await resolveModel('text', { apiKey: config.ai.apiKey, baseUrl: config.ai.baseUrl, override: config.ai.model });
    } catch (err) {
      logger.log('error', (err as Error).message);
      return 1;
    }
    config.ai = { ...config.ai, model };
    ai = new OpenAIClient({ apiKey: config.ai.apiKey, model, baseUrl: config.ai.baseUrl, maxCalls: config.ai.maxCallsPerJob, timeoutMs: config.ai.timeoutMs });
  }
  const shutdown = new AbortController();
  for (const sig of ['SIGINT', 'SIGTERM'] as const) {
    process.once(sig, () => {
      logger.log('info', `Received ${sig}; finishing the current batch`);
      shutdown.abort();
    });
  }

  const once = argv.includes('--once');
  logger.log('info', 'Research worker started', { worker: config.workerId, types: config.jobTypes, mode: once ? 'once' : 'loop', ai: config.ai ? config.ai.model : 'off' });

  let last: Outcome = 'idle';
  while (!shutdown.signal.aborted) {
    try {
      last = await runOnce({ api, config, fetcher, logger, ai, shutdown: shutdown.signal });
    } catch (err) {
      // Claim itself failed (API unreachable after retries): wait and try again.
      logger.log('error', 'Could not claim a job', { error: (err as Error).message });
      last = 'failed';
    }
    if (once) break;
    if (last === 'idle' || last === 'failed') {
      await new Promise<void>((resolve) => {
        const t = setTimeout(resolve, config.pollSeconds * 1000);
        shutdown.signal.addEventListener('abort', () => {
          clearTimeout(t);
          resolve();
        }, { once: true });
      });
    }
  }
  logger.log('info', 'Research worker stopped', { last });
  return once && last === 'failed' ? 2 : 0;
}

main(process.argv.slice(2)).then(
  (code) => process.exit(code),
  (err: unknown) => {
    process.stderr.write(`Fatal: ${(err as Error).message}\n`);
    process.exit(1);
  },
);
