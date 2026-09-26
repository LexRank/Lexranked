/**
 * Structured JSON-lines logger. Keys that look like secrets are redacted
 * recursively, so credentials can never reach stdout or the job log.
 */

export type Level = 'debug' | 'info' | 'warning' | 'error';

const SECRET_KEY = /pass|secret|token|authorization|api[-_]?key|cookie/i;

export function redact(value: unknown, depth = 0): unknown {
  if (depth > 6) return '[truncated]';
  if (Array.isArray(value)) return value.map((v) => redact(v, depth + 1));
  if (value !== null && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out[k] = SECRET_KEY.test(k) ? '[redacted]' : redact(v, depth + 1);
    }
    return out;
  }
  return value;
}

export interface Logger {
  log(level: Level, message: string, context?: Record<string, unknown>): void;
}

export class JsonLogger implements Logger {
  constructor(
    private readonly write: (line: string) => void = (line) => process.stdout.write(line + '\n'),
    private readonly minLevel: Level = 'info',
  ) {}

  log(level: Level, message: string, context: Record<string, unknown> = {}): void {
    const order: Level[] = ['debug', 'info', 'warning', 'error'];
    if (order.indexOf(level) < order.indexOf(this.minLevel)) return;
    this.write(JSON.stringify({ ts: new Date().toISOString(), level, msg: message, ...(redact(context) as object) }));
  }
}
