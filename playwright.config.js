const { defineConfig, devices } = require("@playwright/test");

module.exports = defineConfig({
  testDir: "./tests/e2e",
  timeout: 20_000,
  fullyParallel: true,
  retries: 1,
  use: { baseURL: "http://127.0.0.1:8080", trace: "retain-on-failure" },
  webServer: {
    command: "php -S 127.0.0.1:8080 -t public",
    url: "http://127.0.0.1:8080/index.html",
    reuseExistingServer: false,
  },
  projects: [
    { name: "desktop-chromium", use: { ...devices["Desktop Chrome"] } },
    { name: "android-chromium", use: { ...devices["Pixel 7"] } },
  ],
});
