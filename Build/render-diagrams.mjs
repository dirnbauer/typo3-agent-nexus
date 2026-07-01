/**
 * Render Build/Diagrams/*.mmd to theme-aware inline-SVG Fluid partials.
 *
 * Dev-only (`npm run diagrams`); the generated partials are committed, so
 * neither editors nor CI ever need node/Chromium. Mermaid renders with
 * placeholder hex colors that are swapped for --anx-* custom properties,
 * which cascade into the inlined SVG at runtime (dark mode included).
 * Message lines/texts get data-mm-step indices so GSAP can cascade them.
 */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SRC = join(ROOT, 'Build/Diagrams');
const OUT = join(ROOT, 'Resources/Private/Partials/Overview/Diagram');

const LABELS = {
  a2ui: 'A2UI sequence: visitor intent to generated form to stored inquiry',
  agui: 'AG-UI sequence: streamed answer with a human approval gate',
  a2a: 'A2A sequence: agent discovery, task delegation and artifact delivery',
  ucp: 'UCP sequence: manifest discovery, cart proposal and authorized order',
  ap2: 'AP2 sequence: chained signed mandates and verification',
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
  [/#505050/gi, 'var(--accent)'],
  [/#606060/gi, 'var(--anx-fg)'],
  [/#707070/gi, 'color-mix(in srgb, var(--accent) 10%, var(--anx-card))'],
  [/#808080/gi, 'color-mix(in srgb, var(--accent) 45%, var(--anx-border))'],
  [/#909090/gi, 'var(--anx-fg)'],
  [/#a0a0a0/gi, 'var(--anx-card)'],
  // mermaid defaults that ignore themeVariables
  [/#eaeaea/gi, 'var(--anx-surface-1)'],
  [/#EDF2AE/gi, 'color-mix(in srgb, var(--accent) 10%, var(--anx-card))'],
  [/stroke="#666"/gi, 'stroke="var(--anx-border)"'],
  [/stroke="#999"/gi, 'stroke="var(--anx-border)"'],
  [/fill:#333/gi, 'fill:var(--anx-fg)'],
  [/#0b0b0b/gi, 'var(--accent)'],
  [/stroke="#000000"/gi, 'stroke="var(--accent)"'],
  [/font-family:\s*"?trebuchet ms"?[^;"']*/gi, 'font-family:inherit'],
  [/font-family:\s*inherit,\s*sans-serif/gi, 'font-family:inherit'],
];

function postProcess(svg, key) {
  let out = svg;
  for (const [pattern, replacement] of SWAPS) out = out.replace(pattern, replacement);

  // Responsive, labelled root; touch ONLY the opening <svg> tag. Sizing is
  // handled by the .anx-mm CSS class against the preserved viewBox.
  out = out.replace(/<svg[^>]*>/, (tag) => tag
    .replace(/\s(width|height)="[^"]*"/g, '')
    .replace(/\sstyle="[^"]*"/, '')
    .replace(/<svg /, `<svg class="anx-mm" data-mm="${key}" aria-label="${LABELS[key] ?? key}" `));

  // Sequence-step tags for the GSAP cascade: message lines and their labels
  // get a shared, document-ordered index. Original classes stay untouched so
  // the embedded stylesheet keeps matching.
  let line = 0;
  out = out.replace(/class="(messageLine\d)"/g, (m, cls) => `class="${cls}" data-mm-step="${line++}"`);
  let text = 0;
  out = out.replace(/class="messageText"/g, () => `class="messageText" data-mm-step="${text++}"`);
  out = out.replace(/<g class="actor /g, '<g data-mm-actor class="actor ');

  return out;
}

mkdirSync(OUT, { recursive: true });
const work = mkdtempSync(join(tmpdir(), 'anx-mmd-'));
writeFileSync(join(work, 'config.json'), JSON.stringify(THEME));

const sources = readdirSync(SRC).filter((file) => file.endsWith('.mmd')).sort();
for (const file of sources) {
  const key = basename(file, '.mmd');
  const svgPath = join(work, `${key}.svg`);
  execFileSync('npx', [
    '-y', '@mermaid-js/mermaid-cli',
    '-i', join(SRC, file),
    '-o', svgPath,
    '-c', join(work, 'config.json'),
    '-b', 'transparent',
    // unique id per diagram: five of these are inlined on the same page and
    // the embedded stylesheet scopes all rules to this id
    '--svgId', `anx-mm-${key}`,
    '--quiet',
  ], { stdio: 'inherit' });

  const svg = readFileSync(svgPath, 'utf8');
  const partial = key.charAt(0).toUpperCase() + key.slice(1);
  writeFileSync(
    join(OUT, `${partial}.html`),
    `<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers" data-namespace-typo3-fluid="true">\n${postProcess(svg, key)}\n</html>\n`,
  );
  console.log(`rendered ${file} -> Partials/Overview/Diagram/${partial}.html`);
}

rmSync(work, { recursive: true, force: true });
