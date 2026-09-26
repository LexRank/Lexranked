/**
 * What a pipeline gets to work with. Pipelines are resumable: they start
 * from job.cursor, checkpoint after every batch, and every write they make is
 * idempotent on the server (dedupe keys / claim hashes), so re-processing
 * the batch that was in flight when a worker died creates no duplicates.
 */

import type { AiClient } from '../ai/openai.js';
import type { ClaimedJob, LogEntry, ResearchApi } from '../api.js';
import type { WorkerConfig } from '../config.js';
import type { SafeFetcher } from '../fetcher.js';

export type Stats = Record<string, number>;

export interface JobContext {
  api: ResearchApi;
  job: ClaimedJob;
  config: WorkerConfig;
  fetcher: SafeFetcher;
  /** Null when AI is not configured for this worker. */
  ai: AiClient | null;
  signal: AbortSignal;
  log(level: LogEntry['level'], stage: string, message: string, context?: Record<string, unknown>): void;
  /** Persist progress (cursor + counters) and flush buffered job logs. */
  checkpoint(cursor: string, processed: number, stats: Stats): Promise<void>;
  /** Called after each checkpoint with the rows processed in this run (crash-test hook). */
  afterCheckpoint(rowsThisRun: number): void;
}

export interface PipelineResult {
  cursor: string | null;
  processed: number;
  stats: Stats;
}

export class AbortedError extends Error {
  constructor() {
    super('Worker stopped');
  }
}

export function bump(stats: Stats, key: string, by = 1): void {
  stats[key] = (stats[key] ?? 0) + by;
}

export function assertNotAborted(signal: AbortSignal): void {
  if (signal.aborted) throw new AbortedError();
}
