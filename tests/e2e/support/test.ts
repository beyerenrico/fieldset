import { test as base, expect, type Browser, type BrowserContext, type TestInfo } from '@playwright/test';

// The `test` every journey imports (M8-T03 verification, ADR 0040): it fails a test on any Content
// Security Policy violation and on any uncaught page error in a page of the app, in the default context
// and in every context the test opens with `browser.newContext()`. Pages of other origins (Keycloak) are
// not ours and are ignored. A test that provokes an error on purpose says so with `browserProblems.allow`.
export { devices, expect, request } from '@playwright/test';
export type { APIRequestContext, Browser, BrowserContext, Locator, Page } from '@playwright/test';

/** One CSP violation, CSP console error or uncaught error seen in a page of the app. */
export interface BrowserProblem {
  readonly kind: 'csp-violation' | 'csp-console' | 'page-error';
  readonly url: string;
  readonly message: string;
}

interface ViolationReport {
  readonly directive: string;
  readonly blocked: string;
  readonly documentUrl: string;
  readonly source: string;
  readonly line: number;
  readonly sample: string;
  readonly disposition: string;
}

const BINDING = '__e2eCspViolation';

// Runs in every frame before any page script, sandboxed preview frames included. The event bubbles from
// the element (or the document) to the window.
function listenForViolations(binding: string): void {
  window.addEventListener(
    'securitypolicyviolation',
    (event) => {
      const report = (window as unknown as Record<string, unknown>)[binding];
      if (typeof report !== 'function') return;
      void (report as (payload: unknown) => Promise<void>)({
        directive: event.effectiveDirective,
        blocked: event.blockedURI,
        documentUrl: event.documentURI,
        source: event.sourceFile,
        line: event.lineNumber,
        sample: event.sample,
        disposition: event.disposition,
      });
    },
    true,
  );
}

/** Each context reports to the BrowserProblems of the test that watched it last (shared contexts). */
const routes = new WeakMap<BrowserContext, { target: BrowserProblems }>();

export class BrowserProblems {
  private readonly found: BrowserProblem[] = [];
  private readonly allowed: RegExp[] = [];

  constructor(private readonly origin: string) {}

  /** Accepts problems whose message matches, for a test that provokes them on purpose. */
  allow(pattern: RegExp): void {
    this.allowed.push(pattern);
  }

  /** Problems seen so far, without the allowed ones. */
  list(): readonly BrowserProblem[] {
    return this.found.filter((problem) => !this.allowed.some((pattern) => pattern.test(problem.message)));
  }

  /**
   * Reports the context's problems to this test from now on. A context created outside the test (in a
   * beforeAll, shared by a serial group) is watched by calling this at the start of each test.
   */
  async watch(context: BrowserContext): Promise<void> {
    const route = routes.get(context);
    if (route !== undefined) {
      route.target = this;
      return;
    }
    const current = { target: this as BrowserProblems };
    routes.set(context, current);
    await context.exposeBinding(BINDING, (_source, report: ViolationReport) => {
      const where = report.source === '' ? '' : ` at ${report.source}:${report.line}`;
      const sample = report.sample === '' ? '' : ` sample="${report.sample}"`;
      const blocked = report.blocked === '' ? 'inline' : report.blocked;
      current.target.record('csp-violation', report.documentUrl, `${report.disposition} ${report.directive} blocked ${blocked}${where}${sample}`);
    });
    await context.addInitScript(listenForViolations, BINDING);
    context.on('console', (message) => {
      if (message.type() === 'error' && /content security policy/i.test(message.text())) {
        current.target.record('csp-console', message.page()?.url() ?? message.location().url, message.text());
      }
    });
    context.on('weberror', (webError) => {
      const error = webError.error();
      current.target.record('page-error', webError.page()?.url() ?? '', `${error.name}: ${error.message}`);
    });
  }

  private record(kind: BrowserProblem['kind'], url: string, message: string): void {
    if (!this.isOurs(url)) return;
    this.found.push({ kind, url, message });
  }

  /** The app's origin; sandboxed preview documents keep their URL (only their origin is opaque). */
  private isOurs(url: string): boolean {
    if (url === '' || url === 'about:blank') return true;
    try {
      return new URL(url).origin === this.origin;
    } catch {
      return true;
    }
  }
}

/** Watches every context the test opens itself; returns the function that undoes the patch. */
function watchNewContexts(browser: Browser, problems: BrowserProblems): () => void {
  const original = browser.newContext.bind(browser);
  const patched: Browser['newContext'] = async (options) => {
    const context = await original(options);
    await problems.watch(context);
    return context;
  };
  browser.newContext = patched;
  return () => {
    browser.newContext = original;
  };
}

function originOf(testInfo: TestInfo): string {
  const baseURL = testInfo.project.use.baseURL ?? process.env.E2E_BASE_URL ?? 'http://localhost:8080';
  return new URL(baseURL).origin;
}

export const test = base.extend<{ browserProblems: BrowserProblems }>({
  browserProblems: [
    async ({ browser, context }, use, testInfo) => {
      const problems = new BrowserProblems(originOf(testInfo));
      await problems.watch(context);
      const restore = watchNewContexts(browser, problems);
      try {
        await use(problems);
      } finally {
        restore();
      }
      const found = problems.list();
      if (found.length > 0) {
        await testInfo.attach('browser-problems.json', {
          body: JSON.stringify(found, null, 2),
          contentType: 'application/json',
        });
      }
      expect(found, 'CSP violations and uncaught page errors').toEqual([]);
    },
    { auto: true },
  ],
});
