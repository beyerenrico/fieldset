import { test, expect, devices } from './support/test';
import { fieldAppLogin, fillAndSubmitServiceReport } from './support/field-app';

// M2 demo (journey 3 online part): a field worker on a phone fills the demo service report, signs it
// and submits; the server assigns the number. Requires the demo seed (published service report).
test.use({ ...devices['Pixel 7'] });

const evidence = (name: string) => `docs/agent/milestones/evidence/M2/${name}.png`;

test('field worker fills, signs and submits the service report', async ({ page }) => {
  test.setTimeout(120_000);
  await fieldAppLogin(page, 'worker@demo.test');
  await expect(page.getByText(/^(hello|hallo), willi/i)).toBeVisible();
  await page.screenshot({ path: evidence('01-home'), fullPage: true });

  const number = await fillAndSubmitServiceReport(page, 'Beispiel GmbH', (name, options) =>
    page.screenshot({ path: evidence(name), ...options }),
  );
  expect(number).toMatch(/^SR-\d{4}-\d{5}$/);
});
