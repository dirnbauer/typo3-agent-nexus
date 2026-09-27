/**
 * Render Build/Diagrams/*.json to interactive Archify sequence diagrams.
 *
 * Dev-only (`npm run diagrams`); the generated HTML files are committed under
 * Resources/Public/Diagrams, so neither editors nor CI ever need node, Archify
 * or a browser. The protocol info plugin shows each file in an iframe in
 * Archify's embed mode; see Build/Diagrams/README.md for the source format.
 *
 * Archify (https://github.com/tt-a1i/archify, MIT) is not an npm package. The
 * pinned commit below is cloned into Build/.archify on first use (gitignored);
 * set ARCHIFY_DIR to use an existing checkout of the same commit instead.
 *
 * Every document must pass Archify's `showcase` quality profile: 9 artifact
 * checks, no composition errors, no warnings. `deliver` then renders the
 * exact validated bytes. Build/diagrams.lock.json records the Archify commit
 * and the hashes of each source, of this script and of each generated file;
 * Build/check-diagrams.mjs verifies them without node modules or a browser.
 */

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SRC = join(ROOT, 'Build/Diagrams');
const OUT = join(ROOT, 'Resources/Public/Diagrams');
const MANIFEST = join(ROOT, 'Build/diagrams.lock.json');

export const ARCHIFY = {
  repository: 'https://github.com/tt-a1i/archify.git',
  commit: '9e35d2b0b39b155553ba9fcfe0b4f2a5198dd993',
};

// Layout rhythm. Archify needs at least 28 px between messages that share
// horizontal space; 32 px keeps the labels clear of each other.
const TOP = 165;
const STEP = 32;
const PHASE_GAP = 14;
const BOTTOM = 90;

const sha = (value) => createHash('sha256').update(value).digest('hex');

function archifyDir() {
  const dir = process.env.ARCHIFY_DIR ?? join(ROOT, 'Build/.archify');
  if (!existsSync(join(dir, '.git'))) {
    execFileSync('git', ['clone', '--quiet', ARCHIFY.repository, dir], { stdio: 'inherit' });
  }
  const head = execFileSync('git', ['-C', dir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  if (head !== ARCHIFY.commit) {
    execFileSync('git', ['-C', dir, 'fetch', '--quiet', 'origin', ARCHIFY.commit], { stdio: 'inherit' });
    execFileSync('git', ['-C', dir, 'checkout', '--quiet', ARCHIFY.commit], { stdio: 'inherit' });
  }
  return join(dir, 'archify');
}

/** Turn phases and activation spans into Archify coordinates. */
export function layout(source) {
  const { phases, activations, ...document } = structuredClone(source);
  const ids = new Set(document.messages.map((message) => message.id));
  const y = new Map();
  const segments = [];
  let cursor = TOP;
  for (const phase of phases) {
    const start = cursor;
    for (const id of phase.messages) {
      if (!ids.has(id)) throw new Error(`phase "${phase.label}" names unknown message "${id}"`);
      if (y.has(id)) throw new Error(`message "${id}" is in more than one phase`);
      y.set(id, cursor);
      cursor += STEP;
    }
    segments.push({ from: start - 15, to: cursor - STEP + 15, label: phase.label });
    cursor += PHASE_GAP;
  }
  for (const message of document.messages) {
    if (!y.has(message.id)) throw new Error(`message "${message.id}" is in no phase`);
    message.y = y.get(message.id);
  }
  // Archify draws messages in document order; keep that order equal to y.
  document.messages.sort((a, b) => a.y - b.y);
  document.segments = segments;
  document.activations = activations.map((span) => ({
    participant: span.participant,
    from: y.get(span.from) - 5,
    to: y.get(span.to) + 6,
    type: span.type,
  }));
  const needed = Math.ceil((Math.max(...y.values()) + BOTTOM) / 10) * 10;
  const [width, height] = document.meta.viewBox;
  document.meta.viewBox = [width, Math.max(height, needed)];
  return document;
}

function run(archify, args) {
  try {
    return JSON.parse(execFileSync('node', [join(archify, 'bin/archify.mjs'), ...args], { cwd: archify, encoding: 'utf8' }));
  } catch (error) {
    const output = String(error.stdout ?? '');
    try {
      return JSON.parse(output);
    } catch {
      throw new Error(`archify ${args[0]} failed:\n${output}${error.stderr ?? ''}`);
    }
  }
}

function diagnostics(receipt) {
  const found = [];
  const walk = (node) => {
    if (Array.isArray(node)) node.forEach(walk);
    else if (node && typeof node === 'object') {
      if (typeof node.code === 'string' && typeof node.severity === 'string') found.push(`${node.severity} ${node.code}: ${node.message ?? ''}`);
      else Object.values(node).forEach(walk);
    }
  };
  walk(receipt);
  return [...new Set(found)];
}

function main() {
  const archify = archifyDir();
  const work = mkdtempSync(join(tmpdir(), 'anx-diagrams-'));
  const lock = { note: 'Written by Build/render-diagrams.mjs. Verified by Build/check-diagrams.mjs.', archify: ARCHIFY, generator: sha(readFileSync(new URL(import.meta.url))), diagrams: {} };
  try {
    for (const file of readdirSync(SRC).filter((name) => name.endsWith('.json')).sort()) {
      const key = basename(file, '.json');
      const sourceBytes = readFileSync(join(SRC, file));
      const spec = join(work, `${key}.sequence.json`);
      writeFileSync(spec, `${JSON.stringify(layout(JSON.parse(sourceBytes)), null, 2)}\n`);

      const validation = run(archify, ['validate', 'sequence', spec, '--quality', 'showcase', '--json']);
      if (validation.ok !== true) {
        throw new Error(`${file} does not pass the showcase profile:\n  ${diagnostics(validation).join('\n  ')}`);
      }
      const output = join(OUT, `${key}.html`);
      const delivery = run(archify, ['deliver', 'sequence', spec, output, '--quality', 'showcase', '--json']);
      if (delivery.ok !== true) {
        throw new Error(`${file} was not delivered:\n  ${diagnostics(delivery).join('\n  ')}`);
      }
      lock.diagrams[key] = { source: sha(sourceBytes), html: sha(readFileSync(output)) };
      console.log(`${key}: ${output.slice(ROOT.length)}`);
    }
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
  writeFileSync(MANIFEST, `${JSON.stringify(lock, null, 2)}\n`);
}

if (import.meta.url === `file://${process.argv[1]}`) main();
