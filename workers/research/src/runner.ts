/**
 * Claims one job, runs its pipeline with a heartbeat keeping the lease
 * alive, and reports the outcome. Failure handling:
 *
 * - LeaseLostError (cancelled / taken over): stop, write nothing more;
 * - ProviderError (bad params, missing dataset): fail, not retryable;
 * - anything else (network, API outage): fail, retryable — the server
 *   schedules the retry with exponential backoff and the next attempt
 *   resumes from the last checkpoint;
 * - process crash: the lease expires and the next claim resumes.
 */

import { LeaseLostError, type ClaimedJob, type LogEntry, type ResearchApi } from './api.js';
import type { WorkerConfig } from './config.js';
import type { SafeFetcher } from './fetcher.js';
import type { Logger } from './logger.js';
import { AbortedError, type JobContext, type PipelineResult } from './pipeline/context.js';
import { runDiscovery } from './pipeline/discovery.js';
import { runRefresh } from './pipeline/refresh.js';
import { ProviderError } from './providers/csvSeed.js';

/** Thrown by a test crash hook: escapes the runner like a killed process would. */
export class SimulatedCrash extends Error {}

export type Outcome = 'idle' | 'completed' | 'failed' | 'lease_lost' | 'stopped';

export interface RunnerDeps {
  api: ResearchApi;
  config: WorkerConfig;
  fetcher: SafeFetcher;
  logger: Logger;
  /** Abort signal for graceful shutdown. */
  shutdown: AbortSignal;
  /** Invoked when config.crashAfterRows is reached (default: hard exit). */
  crash?: () => never;
}

const PIPELINES: Record<string, (ctx: JobContext) => Promise<PipelineResult>> = {
  candidate_discovery: runDiscovery,
  source_refresh: runRefresh,
};

export async function runOnce(deps: RunnerDeps): Promise<Outcome> {
  const { api, config, logger } = deps;
  const claimed = await api.claim(config.workerId, config.jobTypes);
  const job = claimed.job;
  if (!job) return 'idle';

  logger.log('info', 'Claimed research job', { job_id: job.id, job_type: job.jobType, cursor: job.cursor, retry: job.retryCount });
  const pipeline = PIPELINES[job.jobType];
  const controller = new AbortController();
  const onShutdown = (): void => controller.abort();
  deps.shutdown.addEventListener('abort', onShutdown, { once: true });

  let buffer: LogEntry[] = [];
  const takeLogs = (): LogEntry[] => {
    const logs = buffer.slice(0, 100);
    buffer = buffer.slice(100);
    return logs;
  };
  let lost = false;
  const markLost = (err: unknown): void => {
    if (err instanceof LeaseLostError) {
      lost = true;
      controller.abort();
    }
  };

  const ctx: JobContext = {
    api,
    job,
    config,
    fetcher: deps.fetcher,
    signal: controller.signal,
    log(level, stage, message, context) {
      logger.log(level, message, { job_id: job.id, stage, ...context });
      if (level !== 'debug') buffer.push({ level, stage, message, ...(context ? { context } : {}) });
    },
    async checkpoint(cursor, processed, stats) {
      try {
        await api.heartbeat(job, { cursor, processed_count: processed, stats, logs: takeLogs() });
      } catch (err) {
        markLost(err);
        throw err;
      }
    },
    afterCheckpoint(rowsThisRun) {
      if (config.crashAfterRows !== null && rowsThisRun >= config.crashAfterRows) {
        logger.log('warning', 'Simulated crash (LEXRANKED_WORKER_CRASH_AFTER_ROWS)', { job_id: job.id, rows: rowsThisRun });
        (deps.crash ?? (() => process.exit(137)))();
      }
    },
  };

  const timer = setInterval(() => {
    api.heartbeat(job, { logs: takeLogs() }).catch((err: unknown) => {
      markLost(err);
      logger.log('warning', 'Heartbeat failed', { job_id: job.id, error: (err as Error).message });
    });
  }, config.heartbeatSeconds * 1000);
  timer.unref();

  try {
    if (!pipeline) {
      throw new ProviderError(`This worker cannot run "${job.jobType}" jobs`);
    }
    const result = await pipeline(ctx);
    await api.complete(job, { cursor: result.cursor, processed_count: result.processed, stats: result.stats, logs: takeLogs() });
    logger.log('info', 'Research job completed', { job_id: job.id, stats: result.stats });
    return 'completed';
  } catch (err) {
    if (err instanceof SimulatedCrash) throw err;
    if (lost || err instanceof LeaseLostError) {
      logger.log('warning', 'Lease lost; stopping without further writes', { job_id: job.id });
      return 'lease_lost';
    }
    const stopped = err instanceof AbortedError;
    const retryable = !(err instanceof ProviderError);
    const message = stopped ? 'Worker stopped before finishing; will resume from the last checkpoint.' : (err as Error).message;
    logger.log(stopped ? 'warning' : 'error', 'Research job attempt failed', { job_id: job.id, error: message, retryable });
    await reportFailure(api, job, message, retryable, takeLogs(), logger);
    return stopped ? 'stopped' : 'failed';
  } finally {
    clearInterval(timer);
    deps.shutdown.removeEventListener('abort', onShutdown);
  }
}

async function reportFailure(api: ResearchApi, job: ClaimedJob, message: string, retryable: boolean, logs: LogEntry[], logger: Logger): Promise<void> {
  try {
    await api.fail(job, message, retryable, { logs });
  } catch (err) {
    // If even this fails, the lease expires and the job resumes later.
    logger.log('error', 'Could not report failure', { job_id: job.id, error: (err as Error).message });
  }
}
