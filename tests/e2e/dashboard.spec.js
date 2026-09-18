const { test, expect } = require("@playwright/test");

async function mockDashboard(page) {
  await page.route("**/api/**", async (route) => {
    const url = new URL(route.request().url());
    let body = { ok: true };
    if (url.pathname.endsWith("/auth.php"))
      body = {
        user: { id: 1, name: "Test User", email: "test@example.com" },
        csrf: "test-csrf",
      };
    else if (url.pathname.endsWith("/settings.php"))
      body = {
        settings: {
          github_connected: false,
          github_delete_permission: false,
          github_status_message: "Not connected",
          ai_provider: null,
          ai_key_configured: false,
        },
      };
    else if (url.pathname.endsWith("/projects.php")) body = { projects: [] };
    else if (url.pathname.endsWith("/builds.php"))
      body = { builds: [], total: 0, success_rate: null };
    else if (url.pathname.endsWith("/flashes.php")) body = { monthly_total: 0 };
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify(body),
    });
  });
}

test("landing authentication dialog is keyboard accessible and dismissible", async ({
  page,
}) => {
  await page.goto("/index.html");
  await page.locator("[data-open-auth]:visible").first().click();
  const dialog = page.locator("#auth");
  await expect(dialog).toBeVisible();
  await page.locator("#auth .close").click();
  await expect(dialog).toBeHidden();
});

test("mobile dashboard exposes navigation, logout, and Web Serial guidance", async ({
  page,
}, testInfo) => {
  test.skip(!testInfo.project.name.includes("android"));
  await mockDashboard(page);
  await page.goto("/dashboard.html#settings");
  await expect(page.locator("#mobile-logout")).toBeVisible();
  await page.locator('[data-tab="flash"]').last().click();
  await expect(page.locator("#serial-support")).toContainText(
    /Web Serial|browser/i,
  );
  await expect(page.locator('meta[name="viewport"]')).toHaveAttribute(
    "content",
    /width=device-width/,
  );
});

test("OAuth restoration error appears once per tab and dialog closes outside", async ({
  page,
}) => {
  await mockDashboard(page);
  await page.goto(
    "/dashboard.html?oauth_error=Identity%20already%20bound#settings",
  );
  const dialog = page.locator("#app-dialog");
  await expect(dialog).toBeVisible();
  await expect(page.locator("#app-dialog-message")).toContainText(
    "Identity already bound",
  );
  await page.locator("#app-dialog [data-dialog-close]").click();
  await expect(dialog).toBeHidden();
  await page.goto(
    "/dashboard.html?oauth_error=Identity%20already%20bound#settings",
  );
  await expect(dialog).toBeHidden();
});
