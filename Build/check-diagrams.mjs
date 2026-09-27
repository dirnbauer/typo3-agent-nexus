/**
 * Verify that the committed diagram HTML files belong to the committed sources.
 *
 * Rendering needs Archify and a pinned commit of it (Build/render-diagrams.mjs),
 * which CI does not have. What actually needs guarding is that nobody edits a
 * source or the renderer without re-running `npm run diagrams`, and that nobody
 * hand-edits a generated file. Both are hashes, so this needs no node modules,
 * no Archify and no browser.
 */

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { basename, join } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SRC = join(ROOT, 'Build/Diagrams');
const OUT = join(ROOT, 'Resources/Public/Diagrams');
const MANIFEST = join(ROOT, 'Build/diagrams.lock.json');

const problems = [];
const sha = (path) => createHash('sha256').update(readFileSync(path)).digest('hex');

if (!existsSync(MANIFEST)) {
  console.error('Build/diagrams.lock.json is missing. Run "npm run diagrams".');
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(MANIFEST, 'utf8'));
const recorded = manifest.diagrams ?? {};

if (manifest.generator !== sha(join(ROOT, 'Build/render-diagrams.mjs'))) {
  problems.push('Build/render-diagrams.mjs changed since the diagrams were rendered.');
}

const keys = readdirSync(SRC)
  .filter((file) => file.endsWith('.json'))
  .map((file) => basename(file, '.json'))
  .sort();

for (const key of keys) {
  const entry = recorded[key];
  if (entry === undefined) {
    problems.push(`Build/Diagrams/${key}.json has never been rendered.`);
    continue;
  }
  if (entry.source !== sha(join(SRC, `${key}.json`))) {
    problems.push(`Build/Diagrams/${key}.json changed since it was rendered.`);
  }
  const html = join(OUT, `${key}.html`);
  if (!existsSync(html)) {
    problems.push(`Resources/Public/Diagrams/${key}.html is missing.`);
  } else if (entry.html !== sha(html)) {
    problems.push(`Resources/Public/Diagrams/${key}.html was changed by hand.`);
  }
}

for (const key of Object.keys(recorded)) {
  if (!keys.includes(key)) problems.push(`Build/diagrams.lock.json lists "${key}", which has no source.`);
}

for (const file of readdirSync(OUT)) {
  if (!keys.includes(basename(file, '.html')) || !file.endsWith('.html')) {
    problems.push(`Resources/Public/Diagrams/${file} is not produced by any source.`);
  }
}

if (problems.length > 0) {
  console.error(`Diagrams are out of date:\n  ${problems.join('\n  ')}\nRun "npm run diagrams".`);
  process.exit(1);
}
console.log(`${keys.length} diagrams match their sources.`);
