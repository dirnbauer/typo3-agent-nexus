import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';

const moduleSource = readFileSync(new URL('../../Resources/Public/JavaScript/protocol-diagram.js', import.meta.url), 'utf8');

function harness(theme) {
  const base = 'https://nexus.test/assets/Diagrams/a2a.html?1792817537';
  const navigations = [];
  const listeners = {};
  const observers = [];
  const frame = {
    dataset: { anxDiagramSrc: base },
    style: {},
    contentDocument: { URL: base, body: { getBoundingClientRect: () => ({ height: 420 }) } },
    addEventListener: (event, listener) => { listeners[event] = listener; },
    set src(value) { navigations.push(value); },
  };
  const link = {};
  const figure = { querySelector: (selector) => selector === '[data-anx-diagram-frame]' ? frame : link };
  runInNewContext(moduleSource, {
    URL,
    window: { location: { href: 'https://nexus.test/protocols/' } },
    document: {
      documentElement: { classList: { contains: () => theme === 'dark' } },
      querySelectorAll: () => [figure],
    },
    MutationObserver: class { observe() {} },
    ResizeObserver: class { observe() { observers.push(true); } disconnect() {} },
  });
  return { base, frame, link, navigations, listeners, observers };
}

for (const theme of ['light', 'dark']) {
  test(`a cold lazy iframe reconciles the actual ${theme} embed navigation`, () => {
    const fixture = harness(theme);
    const desired = `${fixture.base}=&embed=1&theme=${theme}`;
    assert.deepEqual(fixture.navigations, [desired]);
    // Chromium can commit the parser's original lazy src after JS assigned src.
    // In a dark parent the original embed could also have lost its theme query.
    fixture.frame.contentDocument.URL = theme === 'dark' ? `${fixture.base}&embed=1` : fixture.base;
    fixture.listeners.load();
    assert.deepEqual(fixture.navigations, [desired, desired]);
    assert.equal(fixture.observers.length, 0);
    fixture.frame.contentDocument.URL = desired;
    fixture.listeners.load();
    assert.equal(fixture.frame.style.height, '420px');
    assert.equal(fixture.observers.length, 1);
    assert.equal(fixture.link.href, `${fixture.base}=&theme=${theme}`);
  });
}

test('an already correct embed is measured without another navigation', () => {
  const fixture = harness('light');
  fixture.frame.contentDocument.URL = fixture.navigations[0];
  fixture.listeners.load();
  assert.equal(fixture.navigations.length, 1);
  assert.equal(fixture.frame.style.height, '420px');
});
