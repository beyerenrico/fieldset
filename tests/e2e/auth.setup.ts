import { test as setup } from '@playwright/test';
import { adminLogin, adminState, expectTenantDashboard } from './support/auth';

for (const user of ['owner', 'designer'] as const) {
  setup(`sign in ${user}`, async ({ page }) => {
    await adminLogin(page, `${user}@demo.test`);
    await expectTenantDashboard(page);
    await page.context().storageState({ path: adminState(user) });
  });
}
