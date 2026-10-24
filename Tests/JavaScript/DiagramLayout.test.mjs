import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { ARCHIFY, layout, passesQuality } from '../../Build/render-diagrams.mjs';

const source = (name) => JSON.parse(readFileSync(new URL(`../../Build/Diagrams/${name}.json`, import.meta.url)));

test('the stable renderer receives its required portable output without changing authored protocol content', () => {
  const authored = source('ap2');
  const before = structuredClone(authored);
  const result = layout(authored, 'ap2.html');
  assert.equal(result.meta.output, 'ap2.html');
  assert.equal(result.meta.column_fit, 'spread');
  assert.deepEqual(authored, before);
  for (const original of authored.messages) {
    const { y, ...rendered } = result.messages.find((message) => message.id === original.id);
    assert.deepEqual(rendered, original);
    assert.equal(typeof y, 'number');
  }
  assert.equal(ARCHIFY.commit, '208acba8d412335185b2056fedf54b3405df43fb');
});

test('the wrapped AP2 mandate note clears the following error response label', () => {
  const result = layout(source('ap2'), 'ap2.html');
  const note = result.messages.find((message) => message.id === 'present-payment');
  const next = result.messages[result.messages.indexOf(note) + 1];
  // Archify 3.2 wraps this unchanged note to two lines; showcase requires 55px.
  assert.ok(next.y - note.y >= 55);
});

test('all five diagrams reserve the showcase legend below every timeline segment', () => {
  for (const name of ['a2a', 'a2ui', 'agui', 'ap2', 'ucp']) {
    const result = layout(source(name), `${name}.html`);
    const bottom = Math.max(...result.segments.map((segment) => segment.to));
    // The stable renderer needs a 12px gap plus its 86px legend block.
    assert.equal(result.meta.viewBox, undefined, name);
    const html = readFileSync(new URL(`../../Resources/Public/Diagrams/${name}.html`, import.meta.url), 'utf8');
    const svg = html.match(/<svg\b[^>]*viewBox="0 0 ([\d.]+) ([\d.]+)"[^>]*>/)[0];
    assert.match(svg, /data-reader-fit="width-first"/, name);
    const height = Number(svg.match(/viewBox="0 0 [\d.]+ ([\d.]+)"/)[1]);
    assert.ok(height >= bottom + 98, name);
    assert.ok(result.messages.every((message, i, messages) => i === 0 || message.y > messages[i - 1].y), name);
  }
});

test('invalid phase references still fail before rendering', () => {
  const unknown = source('a2a');
  unknown.phases[0].messages.push('missing');
  assert.throws(() => layout(unknown, 'a2a.html'), /unknown message/);
  const duplicate = source('a2a');
  duplicate.phases[1].messages.push(duplicate.phases[0].messages[0]);
  assert.throws(() => layout(duplicate, 'a2a.html'), /more than one phase/);
  const unassigned = source('a2a');
  unassigned.phases[0].messages.shift();
  assert.throws(() => layout(unassigned, 'a2a.html'), /in no phase/);
});

test('showcase success cannot hide delivery or composition warnings', () => {
  assert.equal(passesQuality({ ok: true, validation: { errors: 0, warnings: 0 } }), true);
  assert.equal(passesQuality({ ok: true, composition: { summary: { errors: 0, warnings: 0 } } }), true);
  assert.equal(passesQuality({ ok: true, validation: { errors: 0, warnings: 1 } }), false);
  assert.equal(passesQuality({ ok: true, composition: { summary: { errors: 1, warnings: 0 } } }), false);
  assert.equal(passesQuality({ ok: true }), false);
  assert.equal(passesQuality({ ok: false, validation: { errors: 0, warnings: 0 } }), false);
});
