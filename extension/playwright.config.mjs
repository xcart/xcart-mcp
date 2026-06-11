import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 120_000,
  retries: 1,
  reporter: 'list',
  use: { ignoreHTTPSErrors: true },
});
