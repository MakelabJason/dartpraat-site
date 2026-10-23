import { test, expect } from '@playwright/test';

test('pagina: hero, raster, geen horizontale scroll, geen consolefouten', async ({ page }) => {
  const errors = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto('/');
  await expect(page.locator('h1')).toContainText('Gian van Veen');
  await expect(page.locator('.ep')).toHaveCount(6);
  await expect(page.locator('.wall a').first()).toBeVisible();
  for (const w of [390, 768, 1024, 1440]) {
    await page.setViewportSize({ width: w, height: 900 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `horizontale scroll op ${w}px`).toBeLessThanOrEqual(0);
  }
  expect(errors).toEqual([]);
});

test('YouTube pas na een klik, via youtube-nocookie', async ({ page }) => {
  const yt = [];
  page.on('request', (r) => /youtube/.test(r.url()) && yt.push(r.url()));
  await page.route(/youtube-nocookie\.com/, (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<p>embed</p>' }));
  await page.goto('/');
  await page.waitForLoadState('networkidle');
  expect(yt).toEqual([]);
  await page.locator('.feature[data-yt]').click();
  const frame = page.locator('.feature iframe');
  await expect(frame).toHaveAttribute('src', /^https:\/\/www\.youtube-nocookie\.com\/embed\/okvES8MG7QE/);
});

test('Luister nu opent de speler met de mp3 uit de feed', async ({ page }) => {
  await page.route(/\.mp3/, (r) => r.fulfill({ status: 200, contentType: 'audio/mpeg', body: '' }));
  await page.goto('/');
  await page.locator('.play').click();
  await expect(page.locator('#player')).toBeVisible();
  await expect(page.locator('#audio')).toHaveAttribute('src', /megaphone\.fm\/.+\.mp3/);
  await expect(page.locator('#player-title')).toContainText('S04E11');
});
