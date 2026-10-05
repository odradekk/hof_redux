import { copyFile, mkdir, readdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const escape = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const referenceNames = { login: 'landing', character: 'character', home: 'home', 'shop-buy': 'shop', battle: 'battle', auction: 'auction', 'hunt-party': 'hunt', town: 'town', components: 'components' };
export async function buildReview(output, captures) {
  const source = fileURLToPath(new URL('../../docs/rewrite/ui-baseline/screens/', import.meta.url));
  const references = (await readdir(source)).filter(name => /^proposed-.*\.(?:jpg|png)$/.test(name));
  await mkdir(`${output}/proposed`, { recursive: true });
  for (const name of references) await copyFile(`${source}/${name}`, `${output}/proposed/${name}`);
  const rows = captures.map(capture => {
    const reference = capture.width === 1280 ? `proposed-desktop-${referenceNames[capture.name]}.jpg` : capture.width === 390 ? `proposed-mobile-${referenceNames[capture.name]}.jpg` : '';
    const proposed = references.includes(reference) ? `<a href="proposed/${escape(reference)}"><img src="proposed/${escape(reference)}" alt="Proposed ${escape(capture.name)}"></a>` : '<p>No matching proposed reference at this width. Review the actual layout manually.</p>';
    return `<section><h2>${escape(capture.name)} · ${capture.width}px · ${escape(capture.path)}</h2><div class="pair"><figure><figcaption>Proposed reference (not an approved baseline)</figcaption>${proposed}</figure><figure><figcaption>Actual frame crop · <a href="${escape(capture.screenshot)}">full viewport</a></figcaption><a href="${escape(capture.frameScreenshot)}"><img src="${escape(capture.frameScreenshot)}" alt="Actual ${escape(capture.name)} frame at ${capture.width}px viewport"></a></figure></div></section>`;
  }).join('\n');
  await writeFile(`${output}/visual-review.html`, `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>HOF visual review · approval pending</title><style>body{font:16px system-ui;margin:24px;color:#222;background:#eee}h1{font-size:24px}.pair{display:grid;grid-template-columns:1fr 1fr;gap:16px}figure{margin:0;background:white;padding:12px;min-width:0}img{width:100%;height:auto}figcaption{font-weight:bold;margin-bottom:12px}section{margin-block:32px}a{color:#174a81}@media(max-width:700px){.pair{grid-template-columns:1fr}}</style><h1>Manual visual approval: PENDING</h1><p>These are proposed references beside actual screenshots, not an automatically accepted baseline. Open the images at original size to compare typography, spacing, sprite placement, wrapping, and responsive behavior. Different synthetic content is expected; layout regressions still need review. No pixel-diff threshold or snapshot update grants approval.</p><p>See acceptance.json and axe/*.json for automated results. Browser/device and keyboard/zoom checks remain a separate manual checklist.</p>${rows}</html>`);
}
