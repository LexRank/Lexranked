/**
 * Worker configuration from environment variables. Secrets are read here and
 * nowhere else; they are never logged (see logger.ts redaction).
 */

export interface WorkerConfig {
  apiUrl: string;
  user: string;
  appPassword: string;
  workerId: string;
  jobTypes: string[];
  dataDir: string;
  userAgent: string;
  pollSeconds: number;
  heartbeatSeconds: number;
  fetchTimeoutMs: number;
  fetchMaxBytes: number;
  perHostIntervalMs: number;
  allowPrivateNetwork: boolean;
  batchSize: number;
  /** Test hook: exit abruptly after this many processed rows (simulates a crash). */
  crashAfterRows: number | null;
}

export const WORKER_JOB_TYPES = ['candidate_discovery', 'source_refresh'] as const;

export class ConfigError extends Error {}

type Env = Record<string, string | undefined>;

function int(env: Env, key: string, fallback: number, min: number, max: number): number {
  const raw = env[key];
  if (raw === undefined || raw === '') return fallback;
  const value = Number(raw);
  if (!Number.isInteger(value) || value < min || value > max) {
    throw new ConfigError(`${key} must be an integer between ${min} and ${max}`);
  }
  return value;
}

export function loadConfig(env: Env = process.env): WorkerConfig {
  const apiUrl = (env.LEXRANKED_API_URL ?? '').replace(/\/+$/, '');
  if (!/^https?:\/\/[^/]+/i.test(apiUrl)) {
    throw new ConfigError('LEXRANKED_API_URL must be the WordPress REST base, e.g. https://cms.lexranked.com/wp-json/lexranked/v1');
  }
  const user = env.LEXRANKED_WORKER_USER ?? '';
  const appPassword = env.LEXRANKED_WORKER_APP_PASSWORD ?? '';
  if (user === '' || appPassword === '') {
    throw new ConfigError('LEXRANKED_WORKER_USER and LEXRANKED_WORKER_APP_PASSWORD are required');
  }
  if (apiUrl.startsWith('http://') && !/^http:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?\//i.test(apiUrl + '/') && env.LEXRANKED_ALLOW_INSECURE_API !== '1') {
    throw new ConfigError('LEXRANKED_API_URL must use https (credentials are sent with every request)');
  }

  const types = (env.LEXRANKED_WORKER_JOB_TYPES ?? WORKER_JOB_TYPES.join(','))
    .split(',')
    .map((t) => t.trim())
    .filter(Boolean);
  for (const t of types) {
    if (!(WORKER_JOB_TYPES as readonly string[]).includes(t)) {
      throw new ConfigError(`Unsupported job type "${t}"`);
    }
  }

  const workerId = env.LEXRANKED_WORKER_ID ?? `research-${process.pid}`;
  if (!/^[A-Za-z0-9_.:@-]{1,100}$/.test(workerId)) {
    throw new ConfigError('LEXRANKED_WORKER_ID may only contain letters, digits and _ . : @ -');
  }

  const crash = env.LEXRANKED_WORKER_CRASH_AFTER_ROWS;
  return {
    apiUrl,
    user,
    appPassword,
    workerId,
    jobTypes: types,
    dataDir: env.LEXRANKED_DATA_DIR ?? 'data',
    userAgent: env.LEXRANKED_USER_AGENT ?? 'LexRankedBot/0.5 (+https://lexranked.com/about/methodology)',
    pollSeconds: int(env, 'LEXRANKED_POLL_SECONDS', 30, 1, 3600),
    heartbeatSeconds: int(env, 'LEXRANKED_HEARTBEAT_SECONDS', 60, 1, 3600),
    fetchTimeoutMs: int(env, 'LEXRANKED_FETCH_TIMEOUT_MS', 10_000, 100, 120_000),
    fetchMaxBytes: int(env, 'LEXRANKED_FETCH_MAX_BYTES', 2_000_000, 1_000, 20_000_000),
    perHostIntervalMs: int(env, 'LEXRANKED_PER_HOST_INTERVAL_MS', 2_000, 0, 600_000),
    allowPrivateNetwork: env.LEXRANKED_ALLOW_PRIVATE_NETWORK === '1',
    batchSize: int(env, 'LEXRANKED_BATCH_SIZE', 10, 1, 50),
    crashAfterRows: crash === undefined || crash === '' ? null : int(env, 'LEXRANKED_WORKER_CRASH_AFTER_ROWS', 0, 1, 1_000_000),
  };
}
