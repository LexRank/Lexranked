/**
 * AI Interpretation Layer (Etap J).
 *
 * LexRanked's data flows one way: data → evidence → ranking → page → AI text.
 * The model sits at the very end and only INTERPRETS what the backend has
 * already established: it may summarize, explain, compare, classify and
 * write - from the numbered facts it is given. It may not invent facts,
 * research from memory, compute new numbers or decide, change or judge a
 * position. Everything it writes is a draft that deterministic QA checks and
 * an editor approves.
 */

export type InterpretationTask = 'summarize' | 'explain' | 'compare' | 'classify' | 'write';

export const INTERPRETATION_VERSION = 'interp/2';

/** What each task may do, stated to the model and in docs/ai-interpretation.md. */
export const TASK_DESCRIPTIONS: Record<InterpretationTask, string> = {
  summarize: 'Summarize the facts in plain language, answer first.',
  explain: 'Explain what the facts show (for example why an entry has its position), using only the explanations and components supplied.',
  compare: 'State the differences the facts show, side by side; never say who is better.',
  classify: 'Assign one of the supplied labels, quoting the evidence; never create a label.',
  write: 'Write editorial text for a page, built on the facts.',
};

/** Rules shared by every interpretation prompt. */
export const INTERPRETATION_RULES: readonly string[] = [
  'You interpret data LexRanked has already verified and computed. You do not research, recall from memory, estimate or guess.',
  'Use ONLY the numbered facts provided and cite the IDs you rely on in factRefs.',
  'Never compute new numbers: no averages, totals, differences, percentages or rankings of your own. Every number you write must appear in a cited fact.',
  'Never decide, change, predict or judge a position or score; positions come only from the LexRank engine. Do not say who is better or who to hire.',
  'Facts marked "verified" were verified by LexRanked; facts marked "sourced" come from a cited source but are not yet verified; say "verified" only for verified facts. Facts marked "computed" were calculated by LexRanked from its records.',
  'If the facts do not support a statement, leave it out.',
  'Never add names, dates, awards, reviews, fees, case outcomes or credentials that are not in the facts. Do not give legal advice.',
  'Do not call anyone "the best", do not promise results, do not include links, phone numbers or calls to action.',
  'Add something a generic page on the same topic would not have: at least one specific, useful point taken from these facts (for example a verified credential, a check date, or a market figure with its sample size). It must be true and cited; if the facts offer nothing distinctive, add nothing.',
  'US English, neutral and factual, written for someone choosing a lawyer.',
];

/** System prompt: the task, the page-specific instructions, then the shared rules. */
export function interpretationSystem(task: InterpretationTask, instructions: string[]): string {
  return [`Task (${task}): ${TASK_DESCRIPTIONS[task]}`, ...instructions, ...INTERPRETATION_RULES].join('\n');
}

/** Prompt version recorded on each draft: the interpretation contract plus the page prompt. */
export function promptVersion(pagePrompt: string): string {
  return `${INTERPRETATION_VERSION}+${pagePrompt}`;
}
