// Browsertests (WebKit, iPhone- en desktopbreedte) tegen `php -S` met de vaste feed uit test/fixtures.
// Draaien: werk-webkit npx playwright test -c test/playwright.config.mjs
import { defineConfig, devices } from '@playwright/test';
import { existsSync, mkdtempSync, copyFileSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join } from 'node:path';

const php = process.env.PHP || (existsSync(join(homedir(), '.cache/podsite-php/php')) ? join(homedir(), '.cache/podsite-php/php') : 'php');
const port = Number(process.env.E2E_PORT || 18080) + 20;
const feedPort = port + 1;
const feedDir = process.env.FEED_DIR || mkdtempSync(join(tmpdir(), 'podsite-e2e-feed-'));
const cacheDir = process.env.PODSITE_CACHE_DIR || mkdtempSync(join(tmpdir(), 'podsite-e2e-cache-'));
process.env.FEED_DIR = feedDir;
process.env.PODSITE_CACHE_DIR = cacheDir;
copyFileSync('test/fixtures/feed.xml', join(feedDir, 'feed.xml'));
copyFileSync('test/fixtures/youtube.xml', join(feedDir, 'youtube.xml'));
const env = {
  FEED_DIR: feedDir,
  PODSITE_CACHE_DIR: cacheDir,
  PODSITE_FEED_URL: `http://127.0.0.1:${feedPort}/feed.xml`,
  PODSITE_YOUTUBE_URL: `http://127.0.0.1:${feedPort}/youtube.xml`,
  PODSITE_THUMB_BASE: `http://127.0.0.1:${feedPort}/vi/`,
  PHP_CLI_SERVER_WORKERS: '3',
};

export default defineConfig({
  testDir: 'e2e',
  timeout: 30000,
  workers: 1,
  use: { baseURL: `http://127.0.0.1:${port}` },
  projects: [
    { name: 'iphone', use: { ...devices['iPhone 13'], browserName: 'webkit', viewport: { width: 390, height: 844 } } },
    { name: 'desktop', use: { browserName: 'webkit', viewport: { width: 1440, height: 900 } } },
  ],
  webServer: [
    { command: `${php} -S 127.0.0.1:${feedPort} -t test test/feed-server.php`, port: feedPort, cwd: '..', env, reuseExistingServer: false, stdout: 'ignore' },
    { command: `${php} -S 127.0.0.1:${port} -t public test/router.php`, port, cwd: '..', env, reuseExistingServer: false, stdout: 'ignore' },
  ],
});
