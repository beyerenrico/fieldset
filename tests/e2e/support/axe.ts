import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

// axe-core checks for M8-T04 (BUILD_PROMPT §11: WCAG 2.2 AA for the Field App, keyboard-operable builder).
export const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] as const;

export interface AxeScope {
  /** Regions to scan instead of the whole page. */
  readonly include?: readonly string[];
  /**
   * Narrow exclusions, each one an accepted exception recorded with its reason in
   * docs/agent/milestones/evidence/M8/a11y.md. Never a whole page or a rule.
   */
  readonly exclude?: readonly string[];
}

export interface AxeFinding {
  readonly rule: string;
  readonly impact: string;
  readonly help: string;
  readonly targets: readonly string[];
}

export async function axeFindings(page: Page, scope: AxeScope = {}): Promise<AxeFinding[]> {
  let builder = new AxeBuilder({ page }).withTags([...WCAG_TAGS]);
  for (const selector of scope.include ?? []) builder = builder.include(selector);
  for (const selector of scope.exclude ?? []) builder = builder.exclude(selector);
  const results = await builder.analyze();
  return results.violations.map((violation) => ({
    rule: violation.id,
    impact: violation.impact ?? 'unknown',
    help: violation.help,
    targets: violation.nodes.slice(0, 8).map((node) => node.target.join(' ')),
  }));
}

/** Fails with a readable list (rule, impact, first targets) when axe finds WCAG A/AA violations. */
export async function expectAccessible(page: Page, screen: string, scope: AxeScope = {}): Promise<void> {
  await page.waitForLoadState('networkidle');
  expect(await axeFindings(page, scope), `axe violations on ${screen}`).toEqual([]);
}
