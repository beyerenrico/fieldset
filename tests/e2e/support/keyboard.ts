import { expect, type Locator, type Page } from '@playwright/test';

// Keyboard walkthroughs (M8-T04, WCAG 2.1.1, 2.1.2, 2.4.3, 2.4.7): Tab through a screen, check that every
// focused element shows a focus indicator, that targets come in reading order and that focus can leave.

export interface Focus {
  readonly tag: string;
  readonly name: string;
  /** False when the element left the document's focus (on the body or outside the page). */
  readonly inPage: boolean;
  readonly indicator: boolean;
  /** The element's outline and box-shadow, for the failure message. */
  readonly style?: string;
}

/** The focused element, its accessible-ish name and whether it (or a wrapper) draws a focus indicator. */
export function currentFocus(page: Page): Promise<Focus> {
  return page.evaluate(() => {
    let active: Element | null = document.activeElement;
    // Into same-origin frames and open shadow roots: the innermost focused element.
    while (active instanceof HTMLElement && active.shadowRoot?.activeElement) {
      active = active.shadowRoot.activeElement;
    }
    if (active === null || active === document.body || active === document.documentElement) {
      return { tag: 'BODY', name: '', inPage: false, indicator: false };
    }
    const element = active as HTMLElement;
    const labelled = element.getAttribute('aria-labelledby');
    const labelledText = labelled
      ? labelled
          .split(/\s+/)
          .map((id) => document.getElementById(id)?.textContent ?? '')
          .join(' ')
      : '';
    const fieldLabels = 'labels' in element ? (element as HTMLInputElement).labels : null;
    const name = (
      element.getAttribute('aria-label') ??
      (labelledText || fieldLabels?.[0]?.textContent || element.getAttribute('title') || element.textContent || '')
    )
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 80);
    // The element or a wrapper up to three levels (Filament rings inputs on their wrapper, :focus-within).
    // An outline, or a ring: a box-shadow without offset and blur but with a spread (Tailwind's ring-*);
    // ordinary drop shadows (offset or blur) are always there and do not count.
    const draws = (node: Element): boolean => {
      const style = getComputedStyle(node);
      const outline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) >= 1;
      return outline || /(^|\s|\))0px 0px 0px [1-9]/.test(style.boxShadow);
    };
    let indicator = false;
    let node: Element | null = element;
    for (let level = 0; node !== null && level < 4 && !indicator; level++, node = node.parentElement) {
      indicator = draws(node);
    }
    const own = getComputedStyle(element);
    const style = `outline: ${own.outlineStyle} ${own.outlineWidth}; box-shadow: ${own.boxShadow}`;
    return { tag: element.tagName, name, inPage: true, indicator, style };
  });
}

/**
 * Presses Tab (or Shift+Tab) until the target has focus: a name pattern or an element. Every element on
 * the way must show a focus indicator, and focus must not leave the page first: a target that comes
 * before the start in tab order would only be reached after wrapping through the browser, which fails.
 */
export async function tabTo(
  page: Page,
  target: RegExp | Locator,
  options: { back?: boolean; max?: number } = {},
): Promise<Focus[]> {
  const path: Focus[] = [];
  const reached = async (focus: Focus): Promise<boolean> =>
    target instanceof RegExp ? target.test(focus.name) : target.evaluate((el) => el === document.activeElement);
  for (let step = 0; step < (options.max ?? 120); step++) {
    await page.keyboard.press(options.back === true ? 'Shift+Tab' : 'Tab');
    const focus = await currentFocus(page);
    if (!focus.inPage && path.length > 0) {
      throw new Error(`focus left the page before reaching ${String(target)}; path: ${names(path)}`);
    }
    if (!focus.inPage) continue; // the first press from the body
    path.push(focus);
    if (!focus.indicator && focus.tag === 'IFRAME') {
      // The builder marks a focused preview iframe one task after its window blurs (preview.ts).
      await page.waitForTimeout(100);
      path[path.length - 1] = await currentFocus(page);
    }
    const settled = path[path.length - 1] ?? focus;
    expect(settled.indicator, `focus indicator on <${settled.tag}> "${settled.name}" (${settled.style})`).toBe(true);
    if (await reached(settled)) return path;
  }
  throw new Error(`${String(target)} not reached by keyboard; path: ${names(path)}`);
}

/** No keyboard trap (2.1.2): Tab from the current element reaches the end of the page within `max` presses. */
export async function expectNoTrap(page: Page, max = 120): Promise<void> {
  const seen: Focus[] = [];
  for (let step = 0; step < max; step++) {
    await page.keyboard.press('Tab');
    const focus = await currentFocus(page);
    if (!focus.inPage) return;
    seen.push(focus);
  }
  throw new Error(`focus did not leave the page after ${max} Tab presses: ${names(seen.slice(-10))}`);
}

function names(path: readonly Focus[]): string {
  return path.map((focus) => `<${focus.tag}> ${focus.name}`).join(' → ');
}
