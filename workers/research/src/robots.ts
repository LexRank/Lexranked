/**
 * robots.txt evaluation (RFC 9309 subset): groups by user-agent, Allow /
 * Disallow with longest-match precedence, `*` and `$` wildcards.
 */

interface Rule {
  allow: boolean;
  pattern: string;
}

export class Robots {
  private constructor(private readonly rules: Rule[] | 'allow-all' | 'disallow-all') {}

  static allowAll(): Robots {
    return new Robots('allow-all');
  }

  static disallowAll(): Robots {
    return new Robots('disallow-all');
  }

  static parse(text: string, userAgentToken: string): Robots {
    const token = userAgentToken.toLowerCase();
    const groups: { agents: string[]; rules: Rule[] }[] = [];
    let current: { agents: string[]; rules: Rule[] } | null = null;
    let lastWasAgent = false;

    for (const rawLine of text.split(/\r?\n/)) {
      const line = rawLine.replace(/#.*$/, '').trim();
      const m = /^([A-Za-z-]+)\s*:\s*(.*)$/.exec(line);
      if (!m) continue;
      const key = (m[1] as string).toLowerCase();
      const value = (m[2] as string).trim();
      if (key === 'user-agent') {
        if (!current || !lastWasAgent) {
          current = { agents: [], rules: [] };
          groups.push(current);
        }
        current.agents.push(value.toLowerCase());
        lastWasAgent = true;
        continue;
      }
      lastWasAgent = false;
      if (!current) continue;
      if (key === 'allow' || key === 'disallow') {
        if (value === '' && key === 'disallow') continue; // "Disallow:" = allow everything.
        current.rules.push({ allow: key === 'allow', pattern: value });
      }
    }

    const specific = groups.filter((g) => g.agents.some((a) => a !== '*' && a === token));
    const chosen = specific.length > 0 ? specific : groups.filter((g) => g.agents.includes('*'));
    return new Robots(chosen.flatMap((g) => g.rules));
  }

  isAllowed(pathAndQuery: string): boolean {
    if (this.rules === 'allow-all') return true;
    if (this.rules === 'disallow-all') return false;
    let best: Rule | null = null;
    for (const rule of this.rules) {
      if (!matches(rule.pattern, pathAndQuery)) continue;
      if (!best || rule.pattern.length > best.pattern.length || (rule.pattern.length === best.pattern.length && rule.allow)) {
        best = rule;
      }
    }
    return best ? best.allow : true;
  }
}

function matches(pattern: string, path: string): boolean {
  const anchored = pattern.endsWith('$');
  const body = anchored ? pattern.slice(0, -1) : pattern;
  const regex = new RegExp('^' + body.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + (anchored ? '$' : ''));
  return regex.test(path);
}
