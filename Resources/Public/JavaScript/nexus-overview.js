/**
 * Agent Nexus overview interactions.
 *
 * Tabs, the decision helper and the glossary are plain JavaScript and work
 * with no GSAP at all (nexus-ui.css keeps a CSS reveal fallback). When the
 * vendored GSAP loads, it takes over: hero entrance, protocol-map edge
 * draw-in with travelling pulses, per-panel reveal/flow-beam timelines and
 * the Mermaid sequence cascade. Timelines are killed and rebuilt per active
 * panel so nothing leaks between tab switches.
 */

import { withGsap, reveal, countUpAll, killAll, ensureFinished } from '@webconsulting/agent-nexus/nexus-motion.js';

let panelIntro = null;
let panelLoop = null;
let mapIntro = null;
const mapPulses = [];

function ready(fn) {
  document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn);
}

/* ---- protocol map ------------------------------------------------------- */

function animateMap(gsap, root) {
  const map = root.querySelector('[data-anx-map]');
  if (!map) return;

  const edges = Array.from(map.querySelectorAll('[data-map-edge]'));
  const nodes = Array.from(map.querySelectorAll('[data-map-node]'));

  edges.forEach((edge) => {
    const len = edge.getTotalLength();
    edge.style.strokeDasharray = String(len);
    edge.style.strokeDashoffset = String(len);
  });

  mapIntro = gsap.timeline({ defaults: { ease: 'power2.out' } });
  mapIntro
    .fromTo(nodes, { autoAlpha: 0, scale: 0.92, transformOrigin: '50% 50%' }, { autoAlpha: 1, scale: 1, duration: 0.5, stagger: 0.07 }, 0)
    .to(edges, { strokeDashoffset: 0, duration: 0.9, stagger: 0.12 }, 0.25);
  ensureFinished(mapIntro);

  // A pulse dot travels each edge forever; getPointAtLength keeps us inside
  // GSAP core (no MotionPathPlugin needed).
  edges.forEach((edge, i) => {
    const pulse = map.querySelector(`[data-map-pulse="${edge.dataset.mapEdge}"]`);
    if (!pulse) return;
    const len = edge.getTotalLength();
    const state = { at: 0 };
    mapPulses.push(gsap.to(state, {
      at: 1,
      duration: 2.6,
      delay: 1 + i * 0.45,
      repeat: -1,
      repeatDelay: 1.6,
      ease: 'power1.inOut',
      onUpdate: () => {
        const p = edge.getPointAtLength(state.at * len);
        pulse.setAttribute('cx', String(p.x));
        pulse.setAttribute('cy', String(p.y));
        pulse.setAttribute('opacity', String(Math.sin(state.at * Math.PI)));
      },
    }));
  });
}

/* ---- per-panel animation -------------------------------------------------- */

function animatePanel(gsap, root) {
  const panel = root.querySelector('.anx-panel.is-active');
  if (!gsap || !panel) return;

  killAll(panelIntro, panelLoop);
  panelIntro = null;
  panelLoop = null;

  const reveals = panel.querySelectorAll('.anx-reveal');
  const flow = panel.querySelector('[data-anx-flow]');
  const nodes = flow ? flow.querySelectorAll('.anx-flow__node') : [];
  const beam = flow ? flow.querySelector('.anx-flow__beam') : null;
  const steps = flow ? flow.querySelectorAll('[data-anx-flow-step]') : [];
  const media = panel.querySelector('.anx-media img');
  const mmSteps = panel.querySelectorAll('[data-mm-step]');
  const mmActors = panel.querySelectorAll('[data-mm-actor]');

  panelIntro = gsap.timeline({ defaults: { duration: 0.55, ease: 'power3.out' } });
  panelIntro
    .fromTo(reveals, { y: 14, autoAlpha: 0 }, { y: 0, autoAlpha: 1, stagger: 0.06 }, 0)
    .fromTo(nodes, { scale: 0.92, autoAlpha: 0 }, { scale: 1, autoAlpha: 1, stagger: 0.08 }, 0.12)
    .fromTo(steps, { y: 10, autoAlpha: 0 }, { y: 0, autoAlpha: 1, stagger: 0.055 }, 0.2);

  if (media) {
    panelIntro.fromTo(media, { scale: 1.04, autoAlpha: 0.72 }, { scale: 1, autoAlpha: 1, duration: 0.7 }, 0.05);
  }
  if (mmActors.length) {
    panelIntro.fromTo(mmActors, { autoAlpha: 0, y: -6 }, { autoAlpha: 1, y: 0, duration: 0.4, stagger: 0.05 }, 0.15);
  }
  if (mmSteps.length) {
    // Message lines and their labels share indices; animate in document order.
    panelIntro.fromTo(mmSteps, { autoAlpha: 0 }, { autoAlpha: 1, duration: 0.32, stagger: 0.07, ease: 'power1.out' }, 0.3);
  }
  ensureFinished(panelIntro, 2000);

  if (!beam || !steps.length) return;

  panelLoop = gsap.timeline({ repeat: -1, repeatDelay: 0.25, defaults: { ease: 'power2.inOut' } });
  panelLoop
    .set(beam, { xPercent: -160, autoAlpha: 1 })
    .to(beam, { xPercent: 840, duration: 2.8, ease: 'none' }, 0);

  steps.forEach((step, index) => {
    panelLoop
      .to(step, { y: -3, scale: 1.04, borderColor: 'var(--accent)', duration: 0.18 }, index * 0.28)
      .to(step, { y: 0, scale: 1, duration: 0.32 }, index * 0.28 + 0.18);
  });
}

/* ---- decision helper -------------------------------------------------------- */

function initDecision(root, getGsap) {
  const wrap = root.querySelector('[data-anx-decide]');
  if (!wrap) return;
  const dataEl = wrap.querySelector('[data-anx-decide-data]');
  const result = wrap.querySelector('[data-anx-decide-result]');
  let rules = [];
  try { rules = (JSON.parse(dataEl?.textContent || '{}').rules) || []; } catch { /* helper stays inert */ }
  if (!rules.length || !result) return;

  const answers = {};
  const buttons = Array.from(wrap.querySelectorAll('[data-anx-q]'));
  const questionIds = [...new Set(buttons.map((b) => b.dataset.anxQ))];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  function resolve() {
    if (questionIds.some((id) => !answers[id])) return;
    const hit = rules.find((rule) => Object.entries(rule.if || {}).every(([k, v]) => answers[k] === v)) || rules[rules.length - 1];
    result.hidden = false;
    result.innerHTML =
      '<div class="anx-card anx-card--accent anx-decide__card anx-accent--' + esc(hit.accent) + '">' +
      '<span class="anx-section-label">Recommendation</span>' +
      '<h3 class="anx-decide__name"><i class="anx-dot"></i>' + esc(hit.name) + '</h3>' +
      '<p class="anx-decide__why">' + esc(hit.why) + '</p>' +
      '<button type="button" class="anx-btn anx-btn--outline anx-btn--sm" data-anx-goto="' + esc(hit.key) + '">Read the ' + esc(hit.name) + ' section</button>' +
      '</div>';

    result.querySelector('[data-anx-goto]')?.addEventListener('click', (e) => {
      const key = e.currentTarget.getAttribute('data-anx-goto');
      const tab = root.querySelector(`[data-anx-tab="${key}"]`);
      if (tab) {
        tab.click();
        tab.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });

    getGsap().then((gsap) => {
      if (!gsap) return;
      const card = result.querySelector('.anx-decide__card');
      gsap.fromTo(card, { autoAlpha: 0, y: 10 }, { autoAlpha: 1, y: 0, duration: 0.4, ease: 'power2.out' });
      gsap.fromTo(card, { boxShadow: '0 0 0 0px var(--accent-soft)' }, { boxShadow: '0 0 0 8px transparent', duration: 0.9, ease: 'power2.out', delay: 0.15 });
    });
  }

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      answers[button.dataset.anxQ] = button.dataset.anxV;
      buttons
        .filter((b) => b.dataset.anxQ === button.dataset.anxQ)
        .forEach((b) => {
          const on = b === button;
          b.classList.toggle('is-selected', on);
          b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      resolve();
    });
  });
}

/* ---- comparison rows on scroll ------------------------------------------------ */

function initCompare(root, getGsap) {
  const table = root.querySelector('[data-anx-compare] tbody');
  if (!table || !('IntersectionObserver' in window)) return;
  const rows = table.querySelectorAll('tr');
  const io = new IntersectionObserver((entries) => {
    if (!entries.some((e) => e.isIntersecting)) return;
    io.disconnect();
    getGsap().then((gsap) => {
      if (!gsap) return;
      gsap.fromTo(rows, { autoAlpha: 0.25, x: -8 }, { autoAlpha: 1, x: 0, duration: 0.35, stagger: 0.06, ease: 'power1.out' });
    });
  }, { threshold: 0.25 });
  io.observe(table);
}

/* ---- boot ----------------------------------------------------------------------- */

ready(() => {
  const root = document.querySelector('[data-anx-overview]');
  if (!root) return;

  const tabs = Array.from(root.querySelectorAll('[data-anx-tab]'));
  const panels = Array.from(root.querySelectorAll('[data-anx-panel]'));

  let gsapInstance = null;
  const gsapReady = withGsap(root).then((gsap) => { gsapInstance = gsap; return gsap; });
  const getGsap = () => gsapReady;

  function activate(key, focus) {
    tabs.forEach((tab) => {
      const on = tab.getAttribute('data-anx-tab') === key;
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) tab.focus();
    });
    panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-anx-panel') === key));
    if (gsapInstance) animatePanel(gsapInstance, root);
  }

  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => activate(tab.getAttribute('data-anx-tab')));
    tab.addEventListener('keydown', (e) => {
      let next = null;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = tabs[(i + 1) % tabs.length];
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = tabs[(i - 1 + tabs.length) % tabs.length];
      else if (e.key === 'Home') next = tabs[0];
      else if (e.key === 'End') next = tabs[tabs.length - 1];
      if (next) {
        e.preventDefault();
        activate(next.getAttribute('data-anx-tab'), true);
      }
    });
  });

  const active = tabs.find((t) => t.classList.contains('is-active')) || tabs[0];
  tabs.forEach((t) => { t.tabIndex = t === active ? 0 : -1; });

  initDecision(root, getGsap);
  initCompare(root, getGsap);

  gsapReady.then((gsap) => {
    if (!gsap) return;
    // Everything outside the tab panels reveals once on load: the hero and
    // the top-level section cards (map, theory, comparison, glossary).
    const topReveals = Array.from(root.querySelectorAll('.anx-reveal'))
      .filter((el) => !el.closest('.anx-panel'));
    ensureFinished(reveal(gsap, topReveals));
    countUpAll(gsap, root.querySelector('[data-anx-hero]') || root);
    animateMap(gsap, root);
    animatePanel(gsap, root);
  });
});

export {};
