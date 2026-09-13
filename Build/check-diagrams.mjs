/**
 * Verify that the committed diagram SVGs belong to the committed sources.
 *
 * Re-rendering in CI and diffing cannot work: mermaid sizes a sequence diagram
 * from measured text, so fonts and the Chromium build decide the geometry and
 * the same sources produce a different viewBox on a Linux runner than on the
 * author's machine. What actually needs guarding is that nobody edits a .mmd,
 * or this renderer, without re-running `npm run diagrams` — and that nobody
 * hand-edits a generated SVG. Both are hashes, so this needs no node modules
 * and no browser.
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
  problems.push('Build/render-diagrams.mjs changed since the SVGs were rendered.');
}

const keys = readdirSync(SRC)
  .filter((file) => file.endsWith('.mmd'))
  .map((file) => basename(file, '.mmd'))
  .sort();

for (const key of keys) {
  const entry = recorded[key];
  if (entry === undefined) {
    problems.push(`Build/Diagrams/${key}.mmd has never been rendered.`);
    continue;
  }
  if (!existsSync(join(OUT, `${key}.svg`))) {
    problems.push(`Resources/Public/Diagrams/${key}.svg is missing.`);
    continue;
  }
  if (entry.source !== sha(join(SRC, `${key}.mmd`))) {
    problems.push(`Build/Diagrams/${key}.mmd changed since ${key}.svg was rendered.`);
  }
  if (entry.svg !== sha(join(OUT, `${key}.svg`))) {
    problems.push(`Resources/Public/Diagrams/${key}.svg was edited by hand.`);
  }
}

for (const key of Object.keys(recorded).sort()) {
  if (!keys.includes(key)) {
    problems.push(`diagrams.lock.json still lists "${key}", which has no source any more.`);
  }
}

if (problems.length > 0) {
  for (const problem of problems) console.error(`::error::${problem}`);
  console.error('Run "npm run diagrams" and commit Resources/Public/Diagrams.');
  process.exit(1);
}

console.log(`${keys.length} diagrams match their sources.`);
