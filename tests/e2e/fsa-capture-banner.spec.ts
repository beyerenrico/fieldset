import { expectAccessible } from './support/axe';
import { test, expect } from './support/test';

// The capture-mode banner (BUILD_PROMPT_V2 R24, plan R59, ADR 0080) in a real browser, with axe (WCAG 2.2 AA:
// contrast, landmarks, names). It needs an fsa instance in capture mode, so the J4 install journey runs it
// (tests/Install/Journeys/J04CaptureModeTest.php) with E2E_BASE_URL pointed at the instance and
// FIELDSET_CAPTURE_SESSIONS holding a session cookie per member; against the dev stack it has nothing to check and skips.

interface CaptureSession {
  readonly name: string;
  readonly path: string;
  readonly variant: 'full' | 'narrow';
  readonly label: string;
  readonly cookie: { readonly name: string; readonly value: string };
}

const sessions: readonly CaptureSession[] = JSON.parse(process.env.FIELDSET_CAPTURE_SESSIONS ?? '[]');

test.describe('capture banner on an fsa instance', () => {
  test.skip(sessions.length === 0, 'runs from the J4 install journey against an fsa instance in capture mode');

  for (const session of sessions) {
    test(`${session.name} sees the ${session.variant} banner, axe-clean`, async ({ page, context, baseURL }) => {
      await context.addCookies([{ name: session.cookie.name, value: session.cookie.value, url: baseURL ?? '' }]);

      await page.goto(session.path);

      const banner = page.getByRole('region', { name: session.label });
      await expect(banner).toBeVisible();
      await expect(banner).toHaveAttribute('data-capture-banner', session.variant);
      await expect(banner).toBeInViewport();
      await expectAccessible(page, `capture banner (${session.name})`, { include: ['[data-capture-banner]'] });
    });
  }
});
