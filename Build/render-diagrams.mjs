/**
 * Render Build/Diagrams/*.mmd to standalone, theme-aware SVG assets.
 *
 * Dev-only (`npm run diagrams`); the generated SVGs are committed under
 * Resources/Public/Diagrams, so neither editors nor CI ever need node or
 * Chromium — and the frontend can reference them as plain images.
 *
 * Because an <img>-loaded SVG is its own document, page-level custom properties
 * do not reach it. Each file therefore carries its own palette: mermaid renders
 * with a placeholder hex palette, those hexes are swapped for var(--anx-*), and
 * a small stylesheet defining those variables (light plus a prefers-color-scheme
 * dark block, and the protocol's accent) is injected into the SVG itself.
 *
 * Mermaid sizes a sequence diagram from measured text, so the geometry depends
 * on the fonts and Chromium build of whoever renders it: the same sources give
 * a different viewBox on macOS than on a Linux runner. Re-rendering in CI and
 * diffing the result therefore cannot work. Instead this writes Build/diagrams.lock.json,
 * recording the hash of every source, of this renderer and of
 * each generated file; `npm run diagrams:check` (Build/check-diagrams.mjs)
 * verifies those hashes without node modules or a browser. Keep the transforms
 * deterministic anyway, so re-rendering on one machine stays a no-op.
 */

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SRC = join(ROOT, 'Build/Diagrams');
const OUT = join(ROOT, 'Resources/Public/Diagrams');

const LABELS = {
  a2ui: 'A2UI v0.9.1 sequence: visitor intent, generated surface, action and confirmation',
  agui: 'AG-UI 1.0 sequence: streamed run, interrupt and resumed approval',
  a2a: 'A2A 1.0 sequence: Agent Card discovery, streamed task and artifact delivery',
  ucp: 'UCP sequence: profile discovery, checkout session and approved order',
  ap2: 'AP2 v0.2.0 sequence: checkout and payment mandates, verified before the order',
};

/** Protocol accents — must stay in step with --anx-accent-* in nexus-tokens.css. */
const ACCENTS = {
  a2ui: { light: '#7c3aed', dark: '#a78bfa' },
  agui: { light: '#2563eb', dark: '#60a5fa' },
  a2a: { light: '#059669', dark: '#34d399' },
  ucp: { light: '#d97706', dark: '#fbbf24' },
  ap2: { light: '#e11d48', dark: '#fb7185' },
};

// Placeholder palette rendered by mermaid, swapped for tokens afterwards.
const THEME = {
  theme: 'base',
  themeVariables: {
    fontFamily: 'inherit',
    fontSize: '13px',
    actorBkg: '#101010',
    actorBorder: '#202020',
    actorTextColor: '#303030',
    actorLineColor: '#404040',
    signalColor: '#505050',
    signalTextColor: '#606060',
    noteBkgColor: '#707070',
    noteBorderColor: '#808080',
    noteTextColor: '#909090',
    sequenceNumberColor: '#a0a0a0',
    labelBoxBkgColor: '#101010',
    labelBoxBorderColor: '#202020',
    labelTextColor: '#303030',
    loopTextColor: '#606060',
  },
  sequence: {
    mirrorActors: false,
    useMaxWidth: false,
    actorMargin: 34,
    messageMargin: 30,
    boxMargin: 8,
    noteMargin: 8,
    bottomMarginAdj: 2,
  },
};

const SWAPS = [
  // placeholder palette from themeVariables
  [/#101010/gi, 'var(--anx-surface-1)'],
  [/#202020/gi, 'var(--anx-border)'],
  [/#303030/gi, 'var(--anx-fg)'],
  [/#404040/gi, 'var(--anx-border)'],
  [/#505050/gi, 'var(--anx-accent)'],
  [/#606060/gi, 'var(--anx-fg)'],
  [/#707070/gi, 'var(--anx-note-bg)'],
  [/#808080/gi, 'var(--anx-note-border)'],
  [/#909090/gi, 'var(--anx-fg)'],
  [/#a0a0a0/gi, 'var(--anx-card)'],
  // mermaid defaults that ignore themeVariables
  [/#eaeaea/gi, 'var(--anx-surface-1)'],
  [/#EDF2AE/gi, 'var(--anx-note-bg)'],
  [/stroke="#666"/gi, 'stroke="var(--anx-border)"'],
  [/stroke="#999"/gi, 'stroke="var(--anx-border)"'],
  [/fill:#333/gi, 'fill:var(--anx-fg)'],
  [/#0b0b0b/gi, 'var(--anx-accent)'],
  [/stroke="#000000"/gi, 'stroke="var(--anx-accent)"'],
  [/font-family:\s*"?trebuchet ms"?[^;"']*/gi, 'font-family:inherit'],
  [/font-family:\s*inherit,\s*sans-serif/gi, 'font-family:inherit'],
];

function palette(key) {
  const accent = ACCENTS[key] ?? ACCENTS.a2ui;
  return `<style>
svg{
  --anx-fg:#1b1f26;
  --anx-card:#ffffff;
  --anx-surface-1:#f4f6f9;
  --anx-border:#d5dae1;
  --anx-accent:${accent.light};
  --anx-note-bg:color-mix(in srgb, var(--anx-accent) 10%, var(--anx-card));
  --anx-note-border:color-mix(in srgb, var(--anx-accent) 45%, var(--anx-border));
  font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
}
@media (prefers-color-scheme: dark){
  svg{
    --anx-fg:#e7eaef;
    --anx-card:#171a1f;
    --anx-surface-1:#20242b;
    --anx-border:#343a44;
    --anx-accent:${accent.dark};
  }
}
</style>`;
}

function postProcess(svg, key) {
  let out = svg;
  for (const [pattern, replacement] of SWAPS) out = out.replace(pattern, replacement);

  // Responsive, labelled root; touch ONLY the opening <svg> tag. Consumers size
  // the image with CSS against the preserved viewBox.
  out = out.replace(/<svg[^>]*>/, (tag) => tag
    .replace(/\s(width|height)="[^"]*"/g, '')
    .replace(/\sstyle="[^"]*"/, '')
    .replace(/<svg /, '<svg class="anx-mm" data-mm="' + key + '" '));

  // Standalone SVGs are read by assistive tech as images, so the label has to
  // live inside the document rather than on a host element.
  out = out.replace(/(<svg[^>]*>)/, `$1<title>${LABELS[key] ?? key}</title>${palette(key)}`);

  return out;
}

mkdirSync(OUT, { recursive: true });
const work = mkdtempSync(join(tmpdir(), 'anx-mmd-'));
writeFileSync(join(work, 'config.json'), JSON.stringify(THEME));

// mermaid-cli renders through headless Chromium. Ubuntu 24.04 (and the GitHub
// runners built on it) restrict unprivileged user namespaces with AppArmor, so
// Chromium's own sandbox cannot start and the process aborts with "No usable
// sandbox". The inputs here are the .mmd files in this repository, so dropping
// the sandbox costs nothing: nothing untrusted is ever loaded into the browser.
writeFileSync(
  join(work, 'puppeteer.json'),
  JSON.stringify({ args: ['--no-sandbox', '--disable-dev-shm-usage'] }),
);

const sources = readdirSync(SRC).filter((file) => file.endsWith('.mmd')).sort();
for (const file of sources) {
  const key = basename(file, '.mmd');
  const svgPath = join(work, `${key}.svg`);
  execFileSync('npx', [
    '--no-install', 'mmdc',
    '-i', join(SRC, file),
    '-o', svgPath,
    '-c', join(work, 'config.json'),
    '-p', join(work, 'puppeteer.json'),
    '-b', 'transparent',
    // unique id per diagram: the embedded stylesheet scopes all rules to it
    '--svgId', `anx-mm-${key}`,
    '--quiet',
  ], { stdio: 'inherit' });

  writeFileSync(join(OUT, `${key}.svg`), `${postProcess(readFileSync(svgPath, 'utf8'), key)}\n`);
  console.log(`rendered ${file} -> Resources/Public/Diagrams/${key}.svg`);
}

rmSync(work, { recursive: true, force: true });

const sha = (path) => createHash('sha256').update(readFileSync(path)).digest('hex');
writeFileSync(
  join(ROOT, 'Build/diagrams.lock.json'),
  `${JSON.stringify(
    {
      note: 'Written by Build/render-diagrams.mjs. Verified by Build/check-diagrams.mjs.',
      generator: sha(join(ROOT, 'Build/render-diagrams.mjs')),
      diagrams: Object.fromEntries(
        sources.map((file) => {
          const key = basename(file, '.mmd');
          return [key, { source: sha(join(SRC, file)), svg: sha(join(OUT, `${key}.svg`)) }];
        }),
      ),
    },
    null,
    2,
  )}\n`,
);
console.log('wrote Build/diagrams.lock.json');
