import { expectAccessible } from './support/axe';
import { createBlankCanvas, palette, selected, selectedId } from './support/builder-v2';
import { expectNoTrap, tabTo } from './support/keyboard';
import { adminState } from './support/auth';
import { fieldAppLogin, signOnCanvas } from './support/field-app';
import { waitForOfflineReady } from './support/offline';
import { continueAfterSetUp, createOperator, newOperator, platformSignIn, setUpAuthenticator } from './support/platform';
import { test, expect, devices, type BrowserContext, type Page } from './support/test';

// M8-T04 accessibility audit (BUILD_PROMPT §11: WCAG 2.2 AA for the Field App): axe-core (wcag2a, wcag2aa,
// wcag21a, wcag21aa, wcag22aa) on every Field App screen, the key admin pages, Builder v2 and /platform.
// Accepted exceptions are narrow `exclude` selectors, each justified in evidence/M8/a11y.md. Keyboard
// walkthroughs (Tab order, visible focus, no trap) cover the Field App fill flow and Builder v2.
// Without defaultBrowserType, which test.use() may not set inside a describe.
const { defaultBrowserType: _browser, ...PIXEL } = devices['Pixel 7'];
const PHONE = { ...PIXEL, storageState: { cookies: [], origins: [] } };
// Its own login: the Fortify limit is 5 per minute per email and IP; worker@ is used by five journeys.
const WORKER = 'tablet@demo.test';

test.describe('Field App sign-in', () => {
  test.use(PHONE);

  test('login: email step', async ({ page }) => {
    await page.goto('/app/login');
    await expect(page.getByLabel(/^(email|e-mail)$/i)).toBeVisible();
    await expectAccessible(page, 'login (email)');
  });

  test('login: password step', async ({ page }) => {
    await page.goto('/app/login');
    await page.getByLabel(/^(email|e-mail)$/i).fill(WORKER);
    await page.getByRole('button', { name: /^(continue|weiter)$/i }).click();
    await expect(page.getByLabel(/^(password|passwort)$/i)).toBeVisible();
    await expectAccessible(page, 'login (password)');
  });

  test('login: SSO choice (organization without enforcement)', async ({ page }) => {
    await page.goto('/app/login');
    await page.getByLabel(/^(email|e-mail)$/i).fill('jemand@saml-demo.test');
    await page.getByRole('button', { name: /^(continue|weiter)$/i }).click();
    await expect(page.getByRole('button', { name: /^(sign in with a password|mit passwort anmelden)$/i })).toBeVisible();
    await expectAccessible(page, 'login (SSO choice)');
  });
});

test.describe.serial('Field App screens', () => {
  let context: BrowserContext;
  let page: Page;
  let number = '';

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext(PHONE);
    page = await context.newPage();
    await fieldAppLogin(page, WORKER);
  });

  test.afterAll(async () => {
    await context.close();
  });

  test.beforeEach(async ({ browserProblems }) => {
    await browserProblems.watch(context); // the CSP guard for the shared phone context
  });

  test('home', async () => {
    await page.goto('/app/');
    await expect(page.getByText(/^(hello|hallo), /i)).toBeVisible();
    await expectAccessible(page, 'home');
  });

  test('catalog', async () => {
    await page.goto('/app/forms');
    await expect(page.getByText('Servicebericht', { exact: true }).first()).toBeVisible();
    await expectAccessible(page, 'catalog');
  });

  test('fill: page 1, then its error summary', async () => {
    await page.getByText('Servicebericht', { exact: true }).first().click();
    await expect(page).toHaveURL(/\/app\/fill\//);
    await expect(page.getByRole('textbox', { name: /^Kunde/ })).toBeVisible();
    await expectAccessible(page, 'fill page 1');

    await page.getByRole('button', { name: /^(next|weiter)$/i }).click(); // required fields are empty
    await expect(page.getByRole('alert').first()).toBeVisible();
    await expectAccessible(page, 'fill page 1 with errors');
  });

  test('fill: page 2 (material and labour)', async () => {
    await page.getByRole('textbox', { name: /^Kunde/ }).fill('Barrierefrei GmbH');
    await page.getByRole('combobox', { name: /Art des Einsatzes/ }).selectOption('maintenance');
    await page.getByRole('textbox', { name: /Durchgeführte Arbeiten/ }).fill('Wartung, Filter getauscht');
    await page.getByRole('button', { name: /^(next|weiter)$/i }).click();
    const description = page.getByRole('textbox', { name: /^Beschreibung/ }).first();
    if (!(await description.isVisible())) {
      await page.getByRole('button', { name: /zeile hinzufügen|add line|add entry|eintrag hinzufügen/i }).first().click();
    }
    await description.fill('Arbeitszeit');
    await page.getByRole('textbox', { name: /^Menge/ }).first().fill('1.5');
    await page.getByRole('textbox', { name: /^Einzelpreis/ }).first().fill('65.00');
    await expect(page.getByText(/97,50/).first()).toBeVisible();
    await expectAccessible(page, 'fill page 2');
  });

  test('fill: page 3 (acceptance and signature)', async () => {
    await page.getByRole('button', { name: /^(next|weiter)$/i }).click();
    await expect(page.getByRole('checkbox', { name: /ordnungsgemäß ausgeführt/ })).toBeVisible();
    await expectAccessible(page, 'fill page 3');
    await page.getByRole('checkbox', { name: /ordnungsgemäß ausgeführt/ }).check();
    await signOnCanvas(page);
    await expectAccessible(page, 'fill page 3 signed');
  });

  test('review', async () => {
    await page.getByRole('button', { name: /^(review|prüfen|überprüfen)$/i }).click();
    await expect(page).toHaveURL(/\/review$/);
    await expectAccessible(page, 'review');
  });

  test('submitted detail', async () => {
    await page.getByRole('button', { name: /^(submit|einreichen|absenden)$/i }).click();
    const shown = page.getByText(/SR-\d{4}-\d{5}/).first();
    await expect(shown).toBeVisible({ timeout: 20_000 });
    number = /SR-\d{4}-\d{5}/.exec((await shown.textContent()) ?? '')?.[0] ?? '';
    await expectAccessible(page, 'detail after submit');
  });

  test('submissions', async () => {
    await page.goto('/app/submissions');
    await expect(page.getByText(number).first()).toBeVisible();
    await expectAccessible(page, 'submissions');
  });

  test('submission detail', async () => {
    await page.getByText(number).first().click();
    await expect(page).toHaveURL(/\/app\/submissions\/[0-9a-f-]{36}/);
    await expect(page.getByText('Barrierefrei GmbH').first()).toBeVisible();
    await expectAccessible(page, 'submission detail');
  });

  test('notifications', async () => {
    await page.goto('/app/notifications');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectAccessible(page, 'notifications');
  });

  test('settings', async () => {
    await page.goto('/app/settings');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectAccessible(page, 'settings');
  });

  test('the fill flow works with the keyboard: order, visible focus, no trap', async () => {
    test.setTimeout(120_000);
    await page.goto('/app/forms');
    await expect(page.getByText('Servicebericht', { exact: true }).first()).toBeVisible();
    await tabTo(page, /^Servicebericht/);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/app\/fill\//);
    // After a navigation focus moves to the new page's heading (RootLayout), so Tab continues from there.
    await expect(page.locator('main h1')).toBeFocused();

    // Page 1, in reading order: customer, type of job, work done, then "Next".
    await tabTo(page, /^Kunde/);
    await page.keyboard.type('Tastatur KG');
    await tabTo(page, /Art des Einsatzes/);
    await page.keyboard.type('Wartung'); // native select: type-ahead picks the option
    await expect(page.getByRole('combobox', { name: /Art des Einsatzes/ })).toHaveValue('maintenance');
    await tabTo(page, /Durchgeführte Arbeiten/);
    await page.keyboard.type('Mit der Tastatur erfasst');
    await tabTo(page, /^(next|weiter)$/i);
    await page.keyboard.press('Enter');

    // Page 2: the wizard moves focus to the page heading; Tab continues into its one material line.
    await expect(page.getByRole('heading', { level: 2, name: /Material/ })).toBeFocused();
    const addLine = /^(zeile hinzufügen|add line|add entry|eintrag hinzufügen)/i;
    const description = page.getByRole('textbox', { name: /^Beschreibung/ }).first();
    await expect(description.or(page.getByRole('button', { name: addLine }).first())).toBeVisible();
    if (!(await description.isVisible())) {
      await tabTo(page, addLine);
      await page.keyboard.press('Enter');
      await expect(description).toBeVisible();
      await tabTo(page, /^Beschreibung/, { back: true }); // the new row sits above the button
    } else {
      await tabTo(page, /^Beschreibung/);
    }
    await page.keyboard.type('Arbeitszeit');
    await tabTo(page, /^Menge/);
    await page.keyboard.type('1.5');
    await tabTo(page, /^Einzelpreis/);
    await page.keyboard.type('65.00');
    await expect(page.getByText(/97,50/).first()).toBeVisible();
    await tabTo(page, /^(next|weiter)$/i);
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { level: 2 })).toBeFocused();
    await expect(page.getByRole('checkbox', { name: /ordnungsgemäß ausgeführt/ })).toBeVisible();

    // Page 3: the acceptance check box, then the signature. Drawing it needs a pointer: a handwritten
    // signature depends on the path of the movement (WCAG 2.1.1 exception, a11y.md); its buttons do not.
    await tabTo(page, /ordnungsgemäß ausgeführt/);
    await page.keyboard.press('Space');
    await expect(page.getByRole('checkbox', { name: /ordnungsgemäß ausgeführt/ })).toBeChecked();
    await drawSignature(page);
    await tabTo(page, /^(confirm signature|unterschrift bestätigen)$/i);
    await page.keyboard.press('Enter');
    await expect(page.getByText(/signed by|unterschrieben von/i).first()).toBeVisible();
    await tabTo(page, /^(review|prüfen|überprüfen)$/i);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/review$/);
    await expect(page.locator('main h1')).toBeFocused();

    await tabTo(page, /^(submit|einreichen|absenden)$/i);
    await page.keyboard.press('Enter');
    await expect(page.getByText(/SR-\d{4}-\d{5}/).first()).toBeVisible({ timeout: 20_000 });
    await expectNoTrap(page);
  });

  test('offline state', async () => {
    await page.goto('/app/');
    await waitForOfflineReady(page);
    await context.setOffline(true);
    try {
      await page.reload(); // the app shell from the service worker
      await expect(page.getByText(/^(hello|hallo), /i)).toBeVisible();
      await expect(page.getByText(/offline/i).first()).toBeVisible();
      await expectAccessible(page, 'home offline');
    } finally {
      await context.setOffline(false);
    }
  });
});

test.describe('admin pages (owner)', () => {
  test.use({ storageState: adminState('owner') });

  const pages: readonly (readonly [string, string])[] = [
    ['dashboard', '/admin/demo'],
    ['forms list', '/admin/demo/forms'],
    ['submissions inbox', '/admin/demo/submissions'],
    ['reports explorer', '/admin/demo/explorer?form=1'],
    ['saved reports', '/admin/demo/saved-reports'],
    ['workflows', '/admin/demo/workflows'],
    ['members', '/admin/demo/members'],
    ['SSO', '/admin/demo/sso'],
    ['audit log', '/admin/demo/audit-log'],
    ['privacy: retention', '/admin/demo/privacy/retention'],
    ['privacy: legal holds', '/admin/demo/privacy/legal-holds'],
    ['privacy: personal data', '/admin/demo/privacy/personal-data'],
    ['privacy: my data', '/admin/demo/privacy/my-data'],
    ['API tokens', '/admin/demo/api-tokens'],
  ];

  for (const [name, url] of pages) {
    test(name, async ({ page }) => {
      await page.goto(url);
      await expect(page.locator('main, .fi-main').first()).toBeVisible();
      await scrollWidgets(page);
      await expectAccessible(page, name);
    });
  }

  test('submission detail', async ({ page }) => {
    await page.goto('/admin/demo/submissions');
    await page.locator('.fi-ta-row').first().click();
    await expect(page).toHaveURL(/\/submissions\/[0-9a-f-]{36}/);
    await expectAccessible(page, 'submission detail');
  });

  test('workflow editor', async ({ page }) => {
    await page.goto('/admin/demo/workflows');
    await page.locator('.fi-ta-row').first().click();
    await expect(page).toHaveURL(/\/workflows\/\d+\/edit/);
    // Accepted (a11y.md A1): Filament's badge "remove" button in multi-selects is 20×20 px (target-size).
    await expectAccessible(page, 'workflow editor', { exclude: ['.fi-badge-delete-btn'] });
  });
});

test.describe('builders (designer)', () => {
  test.use({ storageState: adminState('designer') });

  test('canvas builder (v2) with the preview', async ({ page }) => {
    await newServiceReport(page, 'A11y Canvas');
    await page.locator('[data-builder-node]').first().click(); // fill the inspector
    await expect(page.locator('[data-builder-inspector]')).toHaveAttribute('data-builder-inspected', /.+/);
    await expect(page.frameLocator('iframe[data-builder-preview]').getByRole('textbox').first()).toBeVisible();
    await expectAccessible(page, 'canvas builder');
  });

  test('classic builder (v1)', async ({ page }) => {
    test.setTimeout(120_000); // the v1 page is heavy (ADR 0044)
    await newServiceReport(page, 'A11y Klassisch');
    await page.locator('[data-builder-toolbar="classic"]').click();
    await expect(page).toHaveURL(/\/forms\/\d+\/edit/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectAccessible(page, 'classic builder');
  });
});

test.describe('Builder v2 keyboard walkthrough', () => {
  test.use({ storageState: adminState('designer') });

  test('palette → canvas → inspector by Tab, visible focus, Move menu and pick-up cancel with Esc, no trap', async ({
    page,
  }) => {
    test.setTimeout(120_000);
    await createBlankCanvas(page, 'Canvas Tastaturpfad');
    // From the builder's toolbar on (the panel's own navigation comes first in tab order).
    await page.locator('[data-builder-toolbar]').first().focus();
    await tabTo(page, palette(page, 'text'));
    await page.keyboard.press('Enter'); // inserts after the selection and selects the new card
    await expect(selected(page)).toHaveAttribute('data-builder-type', 'text');

    const inspector = page.locator('[data-builder-inspector]');
    await expect(inspector).toHaveAttribute('data-builder-inspected', await selectedId(page));
    await tabTo(page, inspector.getByRole('textbox', { name: /^(bezeichnung|label)/i }).first());
    await page.keyboard.type('Tastaturpfad');

    // Back into the tree: the selected card is its single tab stop (roving focus), then its own buttons.
    await tabTo(page, selected(page), { back: true });
    await page.keyboard.press('Shift+F10'); // the Move menu, the single-pointer alternative to dragging
    const menu = page.locator('.bv2-menu[role="menu"]');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem').first()).toBeFocused();
    await page.keyboard.press('Escape'); // closes it and returns focus to the card's menu button
    await expect(menu).toHaveCount(0);
    await expect(selected(page).locator(':scope > .bv2-card__head > [data-builder-menu]')).toBeFocused();

    const handle = selected(page).locator(':scope > .bv2-card__head > [data-builder-handle]');
    await tabTo(page, handle, { back: true }); // the handle comes before the menu button
    await page.keyboard.press('Space'); // picked up
    await page.keyboard.press('ArrowUp');
    await page.keyboard.press('Escape'); // cancelled: back at its place
    await expect(page.locator('[data-builder-live]')).toContainText(/abgebrochen|cancel/i);
    await expect(selected(page)).toHaveAttribute('data-builder-type', 'text');

    await tabTo(page, inspector.getByRole('textbox', { name: /^(bezeichnung|label)/i }).first());
    await expectNoTrap(page);
  });
});

test.describe('platform panel', () => {
  test.use({ storageState: { cookies: [], origins: [] } });
  const operator = newOperator('a11y');

  test('login, MFA set-up, organizations, members and health', async ({ page }) => {
    test.setTimeout(120_000);
    createOperator(operator);
    await page.goto('/platform/login');
    await expectAccessible(page, 'platform login');
    await platformSignIn(page, operator);
    await expectAccessible(page, 'platform MFA set-up');
    await setUpAuthenticator(page, operator, () => expectAccessible(page, 'platform MFA set-up dialog'));
    await continueAfterSetUp(page);
    await expectAccessible(page, 'platform organizations');
    await page.getByRole('row').filter({ has: page.getByRole('cell', { name: 'demo', exact: true }) })
      .getByRole('link', { name: /^\s*(members|mitglieder)\s*$/i }).click();
    await expect(page).toHaveURL(/\/members/);
    await expectAccessible(page, 'platform members');
    await page.goto('/platform/health');
    await expectAccessible(page, 'platform health');
  });
});

/** Draws on the signature pad with the mouse (the one pointer-only step, see the walkthrough). */
async function drawSignature(page: Page): Promise<void> {
  const canvas = page.locator('canvas').first();
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (box === null) throw new Error('signature canvas not visible');
  await page.mouse.move(box.x + 20, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + 15, { steps: 8 });
  await page.mouse.move(box.x + box.width - 20, box.y + box.height - 15, { steps: 8 });
  await page.mouse.up();
}

/** A form from the service report template; new forms open in the canvas builder (ADR 0044). */
async function newServiceReport(page: Page, name: string): Promise<void> {
  await page.goto('/admin/demo/forms');
  await page.getByRole('button', { name: /from template|aus vorlage|vorlage/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('select').first().selectOption('service_report');
  await dialog.locator('input[type="text"]').last().fill(name);
  await dialog.getByRole('button', { name: /submit|absenden|erstellen|create/i }).first().click();
  await expect(page).toHaveURL(/\/forms\/\d+\/canvas/, { timeout: 30_000 });
  await expect(page.locator('[data-builder-root]')).toHaveAttribute('data-builder-ready', '', { timeout: 15_000 });
}

/** Filament widgets load lazily once they scroll into view (LEARNINGS #43). */
async function scrollWidgets(page: Page): Promise<void> {
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => window.scrollTo(0, 0));
}
