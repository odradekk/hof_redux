import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Only disposable test deployments: this flow creates an account and first character.
const base = process.env.HOF_BASE_URL ?? 'http://127.0.0.1:8080';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Smoke tests require a loopback test deployment');
const output = process.env.HOF_SCREENSHOT_DIR ?? 'screenshots';
await mkdir(output, { recursive: true });
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', error => errors.push(String(error)));
page.on('console', message => {
  if (message.type() === 'error') errors.push(message.text());
});
page.on('requestfailed', request => errors.push(`${request.method()} ${request.url()}: ${request.failure()?.errorText}`));
page.on('response', response => {
  if (response.status() >= 400) errors.push(`HTTP ${response.status()} ${response.url()}`);
});
await page.addInitScript(() => {
  window.hofCspErrors = [];
  document.addEventListener('securitypolicyviolation', event => {
    window.hofCspErrors.push(`${event.violatedDirective}: ${event.blockedURI}`);
  });
});
async function checkPage(path, screenshot) {
  const response = await page.goto(new URL(path, base).href, { waitUntil: 'networkidle' });
  assert.equal(response.status(), 200, path);
  assert.equal(new URL(page.url()).pathname, path, `Unexpected redirect: ${path}`);
  await page.locator('#contents').waitFor({ state: 'visible' });
  assert.ok(await page.locator('#contents').innerText(), `Empty content: ${path}`);
  assert.ok(response.headers()['content-security-policy'], `Missing CSP: ${path}`);
  assert.deepEqual(await page.evaluate(() => window.hofCspErrors), [], `CSP violation: ${path}`);
  const brokenAssets = await page.evaluate(() => ({
    images: [...document.images].filter(image => !image.complete || image.naturalWidth === 0).map(image => image.src),
    styles: [...document.querySelectorAll('link[rel="stylesheet"]')].filter(link => !link.sheet).map(link => link.href),
  }));
  assert.deepEqual(brokenAssets, { images: [], styles: [] }, `Broken assets: ${path}`);
  await page.screenshot({ path: `${output}/${screenshot}.png`, fullPage: true });
  console.log(`PASS ${path}`);
}
async function submit(action, target) {
  await Promise.all([
    page.waitForURL(new URL(target, base).href),
    page.locator(`form[action$="${action}"] button[type="submit"]`).click(),
  ]);
}
try {
  await checkPage('/register', 'register');
  const login = `smoke${Date.now().toString().slice(-10)}`;
  const password = 'Synthetic-smoke-only-2026';
  await page.locator('[name="login"]').fill(login);
  await page.locator('[name="password"]').fill(password);
  await page.locator('[name="password_confirmation"]').fill(password);
  await submit('/register', '/setup');
  // A reload before setup must preserve the pending account and form.
  await checkPage('/setup', 'setup');
  await page.reload();
  await page.locator('[name="name"]').fill(login);
  await page.locator('[name="character_name"]').fill('Smoke Hero');
  await page.locator('[name="base_type"]').selectOption('1');
  await page.locator('[name="gender"]').selectOption('0');
  await submit('/setup', '/');
  await checkPage('/', 'home');
  await Promise.all([
    page.waitForURL(new URL('/login', base).href),
    page.locator('form[action$="/logout"] button').click(),
  ]);
  await checkPage('/login', 'login');
  await page.locator('[name="login"]').fill(login);
  await page.locator('[name="password"]').fill(password);
  await submit('/login', '/');
  for (const path of ['/characters', '/inventory', '/shop', '/crafting', '/preferences', '/hunt', '/simulation', '/auction', '/ranking', '/bosses', '/town', '/account', '/manual', '/updates', '/catalog', '/reports']) {
    await checkPage(path, path.slice(1));
  }
  await checkPage('/characters', 'characters');
  const character = await page.locator('a[href*="/characters/"]').first().getAttribute('href');
  assert.ok(character, 'First character detail link');
  await checkPage(new URL(character, base).pathname, 'character-detail');
  await Promise.all([
    page.waitForURL(url => /^\/reports\/\d+$/.test(url.pathname)),
    page.locator('form[action$="/simulation"] button').click(),
  ]);
  await checkPage(new URL(page.url()).pathname, 'battle-report');
  await page.setViewportSize({ width: 390, height: 844 });
  await checkPage('/', 'home-mobile');
  assert.deepEqual(errors, [], 'Browser, HTTP, or asset errors');
  console.log('PASS registration, interrupted setup, login, major pages, assets, CSP, desktop/mobile screenshots');
} catch (error) {
  await page.screenshot({ path: `${output}/failure.png`, fullPage: true }).catch(() => {});
  console.error('Browser errors:', errors);
  throw error;
} finally {
  await browser.close();
}
