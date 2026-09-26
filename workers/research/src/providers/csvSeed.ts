/**
 * Human-curated seed datasets (CSV). Each row names a lawyer or firm and the
 * public source a person read it from (e.g. a state bar directory profile),
 * with the date it was read. The worker never scrapes directories to find
 * candidates: discovery starts from rows a person vetted.
 *
 * Required columns: entity_type, name, source_url, source_type, retrieved_at
 * Optional columns: city, state, practice_area, website, phone,
 *                   bar_state, bar_number, bar_status, confidence
 */

import { readFile } from 'node:fs/promises';
import { join, resolve, sep } from 'node:path';
import { csvRecords } from '../csv.js';

export class ProviderError extends Error {}

export interface SeedRow {
  /** 1-based data row number in the file (for logs). */
  line: number;
  entity_type: 'lawyer' | 'law_firm';
  name: string;
  source_url: string;
  source_type: string;
  retrieved_at: string;
  city?: string;
  state?: string;
  practice_area?: string;
  website?: string;
  phone?: string;
  bar_state?: string;
  bar_number?: string;
  bar_status?: string;
  confidence: number;
}

export type ParsedRow = { ok: true; row: SeedRow } | { ok: false; line: number; error: string };

const REQUIRED = ['entity_type', 'name', 'source_url', 'source_type', 'retrieved_at'] as const;
const OPTIONAL = ['city', 'state', 'practice_area', 'website', 'phone', 'bar_state', 'bar_number', 'bar_status'] as const;

export function parseSeedCsv(text: string): ParsedRow[] {
  const records = csvRecords(text);
  const header = Object.keys(records[0] ?? {});
  const missing = REQUIRED.filter((c) => !header.includes(c));
  if (records.length > 0 && missing.length > 0) {
    throw new ProviderError(`Seed file is missing required column(s): ${missing.join(', ')}`);
  }
  return records.map((rec, idx): ParsedRow => {
    const line = idx + 1;
    for (const col of REQUIRED) {
      if (!rec[col]) return { ok: false, line, error: `${col} is empty` };
    }
    if (rec.entity_type !== 'lawyer' && rec.entity_type !== 'law_firm') {
      return { ok: false, line, error: 'entity_type must be lawyer or law_firm' };
    }
    if (Number.isNaN(Date.parse(rec.retrieved_at as string))) {
      return { ok: false, line, error: 'retrieved_at is not a date' };
    }
    const confidence = rec.confidence ? Number(rec.confidence) : 0.9;
    if (!(confidence >= 0 && confidence <= 1)) {
      return { ok: false, line, error: 'confidence must be between 0 and 1' };
    }
    const row: SeedRow = {
      line,
      entity_type: rec.entity_type,
      name: rec.name as string,
      source_url: rec.source_url as string,
      source_type: rec.source_type as string,
      retrieved_at: new Date(rec.retrieved_at as string).toISOString(),
      confidence,
    };
    for (const col of OPTIONAL) {
      const value = rec[col];
      if (value) row[col] = value;
    }
    return { ok: true, row };
  });
}

export async function loadDataset(dataDir: string, dataset: unknown): Promise<ParsedRow[]> {
  if (typeof dataset !== 'string' || !/^[a-z0-9][a-z0-9-]{0,63}$/.test(dataset)) {
    throw new ProviderError('params.dataset must be a dataset name like "florida-personal-injury"');
  }
  const root = resolve(dataDir);
  const file = resolve(join(root, `${dataset}.csv`));
  if (!file.startsWith(root + sep)) {
    throw new ProviderError('Dataset path escapes the data directory');
  }
  let text: string;
  try {
    text = await readFile(file, 'utf8');
  } catch {
    throw new ProviderError(`Dataset "${dataset}" not found in the data directory`);
  }
  return parseSeedCsv(text);
}
