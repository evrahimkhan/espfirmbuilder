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
  await page.locator('[data-tab="flash"]:visible').click();
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

test("AI analysis terminal can be minimized and reopened while work continues", async ({
  page,
}) => {
  await mockDashboard(page);
  let polls = 0;
  await page.route("**/api/targets.php**", async (route) => {
    polls++;
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify(
        polls < 2
          ? {
              status: "analyzing",
              retry_after: 1,
              commit_sha: "a".repeat(40),
              progress: [
                {
                  time: 1,
                  stage: "ai_request",
                  message:
                    "Calling the configured AI model to identify hardware targets.",
                  details: { provider: "google", model: "gemini-test" },
                },
                {
                  time: 2,
                  stage: "materialization",
                  message:
                    "Validated targets are being converted into immutable build workflows.",
                  details: { targets: 65 },
                },
              ],
            }
          : {
              status: "ready",
              targets: [
                {
                  id: "esp32",
                  name: "ESP32",
                  type: "arduino",
                  fqbn: "esp32:esp32:esp32",
                },
              ],
            },
      ),
    });
  });
  await page.goto("/dashboard.html#repos");
  await page.evaluate(() => {
    void window.build(7);
  });
  const terminal = page.locator("#analysis-dialog");
  await expect(terminal).toBeVisible();
  await expect(page.locator("#analysis-terminal")).toContainText(
    "Revision aaaaaaaaaaaa locked",
  );
  await expect(page.locator("#analysis-terminal")).toContainText(
    "AI API: google / gemini-test",
  );
  await page.locator("#analysis-dialog [data-analysis-close]").last().click();
  await expect(terminal).toBeHidden();
  const launcher = page.locator("#analysis-terminal-launcher");
  await expect(launcher).toBeVisible();
  await launcher.click();
  await expect(terminal).toBeVisible();
  await expect(page.locator("#analysis-dialog-status")).toContainText(
    /running|complete/i,
  );
});

test("target picker marks successful builds and excludes them from build all", async ({
  page,
}) => {
  await mockDashboard(page);
  await page.route("**/api/targets.php**", async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        status: "ready",
        targets: [
          {
            id: "verified",
            name: "Verified board",
            type: "arduino",
            fqbn: "esp32:esp32:esp32",
            build_verification: "verified",
          },
          {
            id: "retry",
            name: "Retry board",
            type: "arduino",
            fqbn: "esp32:esp32:esp32s3",
            build_verification: "unverified",
          },
        ],
      }),
    });
  });
  await page.goto("/dashboard.html#repos");
  await page.evaluate(() => {
    void window.build(3);
  });
  await expect(page.locator("#target-dialog")).toBeVisible();
  await expect(page.locator(".target-build-state.verified")).toContainText(
    "Build verified",
  );
  await expect(page.locator(".target-build-state.unverified")).toContainText(
    "Build unverified",
  );
  await expect(
    page.locator('#target-list button[data-target="verified"]'),
  ).toBeDisabled();
  await expect(page.locator("#build-all-targets")).toContainText("(1)");
});
