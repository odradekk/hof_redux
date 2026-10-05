import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { chromium } from 'playwright';
import { buildReview } from './visual-review.mjs';

const require = createRequire(import.meta.url);
const base = new URL(process.env.HOF_BASE_URL ?? 'http://127.0.0.1:8080');
assert.ok(['localhost', '127.0.0.1'].includes(base.hostname) && ['http:', 'https:'].includes(base.protocol), 'Browser acceptance requires a loopback disposable test deployment');
assert.ok(!base.username && !base.password && base.pathname === '/' && !base.search && !base.hash, 'Use an origin without credentials, path, or query');
const output = process.env.HOF_SCREENSHOT_DIR ?? 'screenshots';
const fixture = JSON.parse(await readFile(process.env.HOF_FIXTURE_FILE ?? 'fixtures.json', 'utf8'));
assert.equal(fixture.schema, 1, 'Run the guarded seed script before browser acceptance');
assert.equal(fixture.characterIds.length, 5);
const axeSource = await readFile(require.resolve('axe-core/axe.min.js'), 'utf8');
const login = 'hof_browser_qa';
const password = 'Synthetic-browser-only-2026!';
const widths = [1280, 768, 390];
const failures = [];
const checks = [];
const captures = [];
await mkdir(`${output}/actual`, { recursive: true });
await mkdir(`${output}/axe`, { recursive: true });
const browser = await chromium.launch();

async function makeContext(javaScriptEnabled = true) {
  const context = await browser.newContext({ javaScriptEnabled, viewport: { width: 1280, height: 900 }, locale: 'zh-CN', timezoneId: 'Asia/Shanghai', reducedMotion: 'reduce' });
  await context.addInitScript(() => {
    window.hofCspErrors = [];
    document.addEventListener('securitypolicyviolation', event => window.hofCspErrors.push(`${event.violatedDirective}: ${event.blockedURI}`));
  });
  return context;
}
function observe(page) {
  page.setDefaultTimeout(15000);
  page.on('pageerror', error => failures.push(`Browser exception ${page.url()}: ${error}`));
  page.on('console', message => {
    if (message.type() === 'error') failures.push(`Console ${page.url()}: ${message.text()}`);
  });
  page.on('requestfailed', request => failures.push(`Request ${request.method()} ${request.url()}: ${request.failure()?.errorText}`));
  page.on('response', response => {
    if (response.status() >= 400) failures.push(`HTTP ${response.status()}: ${response.url()}`);
  });
}
async function open(page, path) {
  const expected = new URL(path, base);
  const response = await page.goto(expected.href, { waitUntil: 'networkidle' });
  assert.equal(response.status(), 200, path);
  assert.equal(page.url(), expected.href, `Unexpected redirect for ${path}`);
  await page.locator('#contents').waitFor({ state: 'visible' });
  assert.ok((await page.locator('#contents').innerText()).trim(), `Empty page: ${path}`);
  return response;
}
async function capture(page, path, name, width, { audit = true } = {}) {
  await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
  const response = await open(page, path);
  // Full-page captures include offscreen catalog cards. Trigger lazy image loads
  // in this test document before checking assets or recording screenshot pixels.
  await page.evaluate(async () => {
    for (const image of document.images) image.loading = 'eager';
    await Promise.all([...document.images].map(image => image.decode().catch(() => {})));
    await document.fonts.ready;
  });
  const id = `${name}-${width}`;
  const screenshot = `actual/${id}.png`;
  await page.screenshot({ path: `${output}/${screenshot}`, fullPage: true, animations: 'disabled' });
  const frameScreenshot = `actual/${id}-frame.png`;
  await page.locator('.frame').screenshot({ path: `${output}/${frameScreenshot}`, animations: 'disabled' });
  captures.push({ name, width, path, screenshot, frameScreenshot });
  const problems = [];
  const policy = response.headers()['content-security-policy'] ?? '';
  if (!policy.includes("script-src 'self'") || !policy.includes("style-src 'self'") || /unsafe-inline|unsafe-eval/.test(policy)) problems.push('Missing or weakened CSP');
  const health = await page.evaluate(() => {
    const overflow = [...document.querySelectorAll('body *')].filter(element => {
      const rect = element.getBoundingClientRect();
      return rect.width > 0 && (rect.right > innerWidth + 1 || rect.left < -1) && getComputedStyle(element).position !== 'fixed';
    }).slice(0, 15).map(element => `${element.tagName.toLowerCase()}.${element.className}`);
    return {
      viewport: innerWidth,
      scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
      overflow,
      csp: window.hofCspErrors ?? [],
      images: [...document.images].filter(image => !image.complete || image.naturalWidth === 0).map(image => image.src),
      imageAttributes: [...document.images].filter(image => !image.hasAttribute('alt') || !image.hasAttribute('width') || !image.hasAttribute('height')).map(image => image.src),
      styles: [...document.querySelectorAll('link[rel="stylesheet"]')].filter(link => !link.sheet).map(link => link.href),
      headings: document.querySelectorAll('h1').length,
      rawTranslations: document.body.innerText.match(/hof\.(?:item_fields|slots)\.[\w.]+/g) ?? [],
      unlabeledCells: [...document.querySelectorAll('.tbl-stack td:not(.primary):not([colspan])')].filter(cell => !cell.hasAttribute('data-label')).map(cell => cell.textContent.trim()),
      inlineStyles: document.querySelectorAll('[style], style').length,
      inlineScripts: document.querySelectorAll('script:not([src])').length,
      inlineHandlers: [...document.querySelectorAll('*')].flatMap(element => [...element.attributes].filter(attribute => /^on/i.test(attribute.name)).map(attribute => attribute.name)),
      paginationSvg: document.querySelectorAll('.pager svg, .pagination svg, nav[aria-label="分页"] svg').length,
    };
  });
  health.svgImages = await page.evaluate(async () => {
    const sources = [...new Set([...document.querySelectorAll('svg image')].map(image => image.getAttribute('href') ?? image.getAttribute('xlink:href')))];
    const failures = await Promise.all(sources.map(async source => {
      if (!source || new URL(source, location.href).origin !== location.origin) return source ?? '(missing SVG image href)';
      const image = new Image();
      image.src = source;
      try { await image.decode(); return image.naturalWidth ? null : source; }
      catch { return source; }
    }));
    return failures.filter(Boolean);
  });
  if (health.scrollWidth > health.viewport) problems.push(`Horizontal overflow ${health.scrollWidth} > ${health.viewport}: ${health.overflow.join(', ')}`);
  for (const field of ['csp', 'images', 'svgImages', 'imageAttributes', 'styles', 'inlineHandlers', 'rawTranslations', 'unlabeledCells']) if (health[field].length) problems.push(`${field}: ${JSON.stringify(health[field])}`);
  if (health.headings !== 1) problems.push(`Expected one page h1; found ${health.headings}`);
  for (const field of ['inlineStyles', 'inlineScripts', 'paginationSvg']) if (health[field]) problems.push(`${field}: ${health[field]}`);
  let violations = [];
  if (audit) {
    // Test-only DevTools evaluation loads axe without weakening the application's CSP.
    await page.evaluate(axeSource);
    const result = await page.evaluate(async () => window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }));
    violations = result.violations.filter(violation => ['serious', 'critical'].includes(violation.impact));
    await writeFile(`${output}/axe/${id}.json`, JSON.stringify({ url: result.url, testEngine: result.testEngine, violations: result.violations, incomplete: result.incomplete }, null, 2));
    if (violations.length) problems.push(`axe serious/critical: ${violations.map(violation => `${violation.id} (${violation.nodes.map(node => node.target.join(' ')).join(', ')})`).join('; ')}`);
  }
  checks.push({ name, path, width, health, seriousOrCritical: violations.length, problems });
  for (const problem of problems) failures.push(`${id}: ${problem}`);
  console.log(`${problems.length ? 'FAIL' : 'PASS'} ${path} at ${width}: assets, CSP, overflow${audit ? ', axe' : ''}`);
}
async function matrix(page, path, name) {
  for (const width of widths) {
    try { await capture(page, path, name, width); }
    catch (error) { failures.push(`${name}-${width}: ${error.stack ?? error}`); }
  }
}
async function clickSubmit(page, form, button = form.locator('button[type="submit"]').first()) {
  const action = new URL(await form.getAttribute('action'), page.url()).href;
  const [request] = await Promise.all([
    page.waitForRequest(request => request.method() === 'POST' && request.url() === action),
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    button.click(),
  ]);
  return request;
}
async function signIn(page, username = login) {
  await open(page, '/login');
  await page.locator('[name="login"]').fill(username);
  await page.locator('[name="password"]').fill(password);
  await clickSubmit(page, page.locator('form[action$="/login"]'));
  assert.equal(new URL(page.url()).pathname, '/');
}
async function validation(page, form, name) {
  // Bypass only browser constraint validation, so invalid input reaches server validation.
  // Application JavaScript remains disabled throughout this context.
  const button = form.locator('button[type="submit"]').first();
  await button.evaluate(element => element.setAttribute('formnovalidate', ''));
  await clickSubmit(page, form, button);
  assert.ok(await page.locator('[role="alert"]').count(), `${name}: server validation must be visible`);
  assert.equal(await page.locator('[role="status"]').count(), 0, `${name}: invalid action must not report success`);
  await page.screenshot({ path: `${output}/actual/no-js-${name}-validation.png`, fullPage: true });
}
async function retryNativeSubmission(page, form, name) {
  const request = await clickSubmit(page, form);
  assert.equal(await page.locator('[role="alert"]').count(), 0, `${name}: no validation failure`);
  assert.ok(await page.locator('[role="status"]').count(), `${name}: successful native submission`);
  const resultUrl = page.url();
  const replay = await page.context().request.post(request.url(), {
    data: request.postData(),
    headers: { 'content-type': request.headers()['content-type'], referer: request.headers().referer ?? base.href },
    maxRedirects: 0,
  });
  assert.equal(replay.status(), 302, `${name}: repeated POST redirects normally`);
  assert.equal(new URL(replay.headers().location, base).href, resultUrl, `${name}: repeat returns the same destination/report`);
  await page.goto(resultUrl, { waitUntil: 'networkidle' });
  assert.equal(await page.locator('[role="alert"]').count(), 0, `${name}: replay must not fall back to an error`);
  await page.screenshot({ path: `${output}/actual/no-js-${name}-repeated.png`, fullPage: true });
  console.log(`PASS JavaScript-disabled ${name}: validation, native submit, identical POST replay`);
}

try {
  const guest = await makeContext();
  const guestPage = await guest.newPage();
  observe(guestPage);
  await matrix(guestPage, '/login', 'login');
  await matrix(guestPage, '/register', 'register');
  const newLogin = 'hofqasignup';
  await open(guestPage, '/register');
  await guestPage.locator('[name="login"]').fill(newLogin);
  await guestPage.locator('[name="password"]').fill(password);
  await guestPage.locator('[name="password_confirmation"]').fill(password);
  await clickSubmit(guestPage, guestPage.locator('form[action$="/register"]'));
  assert.equal(new URL(guestPage.url()).pathname, '/setup');
  await matrix(guestPage, '/setup', 'setup');
  await guestPage.reload({ waitUntil: 'networkidle' });
  await guestPage.locator('[name="name"]').fill('验收新队伍');
  await guestPage.locator('[name="character_name"]').fill('验收新人');
  await guestPage.locator('[name="base_type"][value="1"]').check();
  await guestPage.locator('[name="gender"][value="0"]').check();
  await clickSubmit(guestPage, guestPage.locator('form[action$="/setup"]'));
  assert.equal(new URL(guestPage.url()).pathname, '/');
  await clickSubmit(guestPage, guestPage.locator('form[action$="/logout"]'));
  await signIn(guestPage, newLogin);
  await guest.close();

  const authenticated = await makeContext();
  const page = await authenticated.newPage();
  observe(page);
  await signIn(page);
  const pages = [
    ['/', 'home'], ['/hunt', 'hunt'], ['/characters', 'characters'],
    [`/characters/${fixture.characterIds[0]}`, 'character'], ['/inventory', 'inventory'],
    ['/shop', 'shop-buy'], ['/shop/sell', 'shop-sell'], ['/shop/work', 'shop-work'],
    ['/smithy/refine', 'smithy-refine'], ['/smithy/create', 'smithy-create'],
    ['/bosses', 'boss'], ['/auction', 'auction'], ['/auction?sort=price', 'auction-price'],
    ['/ranking', 'ranking'], [`/reports/${fixture.battleReportId}`, 'battle'], ['/reports', 'reports'],
    ['/town', 'town'], ['/account', 'account'], ['/manual', 'manual'], ['/updates', 'updates'],
    ['/catalog', 'catalog'], ['/simulation', 'simulation'], ['/admin', 'admin'],
    ['/admin?page=2', 'admin-pagination'], [`/admin/users/${fixture.userId}`, 'admin-user'], ['/dev/ui', 'components'],
  ];
  await open(page, '/hunt');
  const areaHref = await page.locator('a[href*="/hunt/"]').first().getAttribute('href');
  assert.ok(areaHref, 'An available hunt area exists');
  const areaPath = new URL(areaHref, base).pathname;
  pages.splice(2, 0, [areaPath, 'hunt-party']);
  for (const [path, name] of pages) await matrix(page, path, name);
  for (const [from, to] of [['/crafting', '/smithy/refine'], ['/preferences', '/account']]) {
    const response = await authenticated.request.get(new URL(from, base).href, { maxRedirects: 0 });
    assert.equal(response.status(), 302, `${from} retains its canonical redirect`);
    assert.equal(new URL(response.headers().location, base).pathname, to);
  }
  // Keep tab/anchor/history navigation and simulation as real browser interactions.
  await open(page, `/characters/${fixture.characterIds[0]}`);
  await page.locator('a[href="#c-ai"]').click();
  assert.equal(new URL(page.url()).hash, '#c-ai');
  await page.goBack({ waitUntil: 'networkidle' });
  assert.equal(new URL(page.url()).hash, '');
  await page.goForward({ waitUntil: 'networkidle' });
  assert.equal(new URL(page.url()).hash, '#c-ai');
  await open(page, '/simulation');
  await page.locator('[name="party[]"]').first().check();
  await clickSubmit(page, page.locator('form[action$="/simulation"]'));
  assert.match(new URL(page.url()).pathname, /^\/reports\/\d+$/);
  await authenticated.close();

  const noJs = await makeContext(false);
  const native = await noJs.newPage();
  observe(native);
  await native.setViewportSize({ width: 390, height: 844 });
  await signIn(native);
  await open(native, '/shop');
  await validation(native, native.locator('form[action$="/player/buy"]'), 'buy');
  let form = native.locator('form[action$="/player/buy"]');
  const itemName = await form.locator('input[name$="[id]"][value="1002"]').getAttribute('name');
  assert.ok(itemName, 'The fixed-price heavy sword is available');
  await form.locator(`input[type="checkbox"][name="${itemName.replace('[id]', '[on]')}"]`).check();
  await form.locator(`input[name="${itemName.replace('[id]', '[quantity]')}"]`).fill('2');
  await retryNativeSubmission(native, form, 'buy');

  await open(native, '/auction');
  form = native.locator(`form[action$="/auction/${fixture.auctionIds[0]}/bid"]`);
  await form.locator('[name="price"]').fill('1');
  await validation(native, form, 'bid');
  form = native.locator(`form[action$="/auction/${fixture.auctionIds[0]}/bid"]`);
  await form.locator('[name="price"]').fill('1100');
  await retryNativeSubmission(native, form, 'bid');

  await open(native, areaPath);
  for (const input of await native.locator('[name="party[]"]').all()) await input.uncheck();
  await validation(native, native.locator('form').filter({ has: native.locator('[name="party[]"]') }), 'hunt');
  for (const input of await native.locator('[name="party[]"]').all()) await input.uncheck();
  await native.locator('[name="party[]"]').first().check();
  await retryNativeSubmission(native, native.locator('form').filter({ has: native.locator('[name="party[]"]') }), 'hunt');
  assert.match(new URL(native.url()).pathname, /^\/reports\/\d+$/);

  await open(native, `/characters/${fixture.characterIds[0]}`);
  form = native.locator('form[action$="/player/tactics"]');
  await form.locator('[name="tactics[0][quantity]"]').fill('-1');
  await validation(native, form, 'tactics');
  form = native.locator('form[action$="/player/tactics"]');
  assert.equal(await form.locator('[name="tactics[0][quantity]"]').inputValue(), '-1', 'Rejected tactics values remain visible');
  await form.locator('[name="tactics[0][quantity]"]').fill('25');
  await retryNativeSubmission(native, form, 'tactics');
  assert.equal(await native.locator('[name="tactics[0][quantity]"]').inputValue(), '25');
  await noJs.close();
} catch (error) {
  failures.push(error.stack ?? String(error));
  for (const context of browser.contexts()) for (const page of context.pages()) {
    await page.screenshot({ path: `${output}/failure-${browser.contexts().indexOf(context)}.png`, fullPage: true }).catch(() => {});
  }
} finally {
  await browser.close();
  await writeFile(`${output}/acceptance.json`, JSON.stringify({ checks, captures, failures, manualVisualApproval: 'PENDING', manualChecks: ['Firefox', 'Safari/iOS', 'Android Chrome', '200% zoom', 'keyboard-only hunt to battle', 'proposed-versus-actual visual approval'] }, null, 2));
  await buildReview(output, captures);
}
assert.deepEqual(failures, [], 'Browser acceptance failures; inspect acceptance.json, axe reports, and actual screenshots');
console.log(`PASS ${checks.length} page/viewport checks, registration/reload/login, canonical redirects, history, simulation, and four no-JS retry-safe workflows. Visual approval remains pending human review.`);
