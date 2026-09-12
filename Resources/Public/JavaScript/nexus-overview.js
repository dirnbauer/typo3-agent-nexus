/**
 * Agent Nexus overview interactions.
 *
 * Tabs, the decision helper and the glossary are plain JavaScript. Motion is
 * limited to entrance staggers and stat count-ups through the shared Web
 * Animations helper; the protocol map's travelling pulses and the flow beam are
 * CSS animations in nexus-backend.css, which keeps them declarative and lets
 * prefers-reduced-motion switch them off without any JavaScript involved.
 */

import { stagger, countUpAll } from '@webconsulting/agent-nexus/nexus-motion.js';

function ready(fn) {
  document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn);
}

/* ---- decision helper -------------------------------------------------------- */

function initDecision(root) {
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

    stagger(result.querySelectorAll('.anx-decide__card'));
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

function initCompare(root) {
  const table = root.querySelector('[data-anx-compare] tbody');
  if (!table || !('IntersectionObserver' in window)) return;
  const rows = table.querySelectorAll('tr');
  const io = new IntersectionObserver((entries) => {
    if (!entries.some((e) => e.isIntersecting)) return;
    io.disconnect();
    stagger(rows, { step: 55, distance: 6 });
  }, { threshold: 0.25 });
  io.observe(table);
}

/* ---- boot ----------------------------------------------------------------------- */

ready(() => {
  const root = document.querySelector('[data-anx-overview]');
  if (!root) return;

  const tabs = Array.from(root.querySelectorAll('[data-anx-tab]'));
  const panels = Array.from(root.querySelectorAll('[data-anx-panel]'));

  function activate(key, focus) {
    tabs.forEach((tab) => {
      const on = tab.getAttribute('data-anx-tab') === key;
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) tab.focus();
    });
    panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-anx-panel') === key));
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

  initDecision(root);
  initCompare(root);

  countUpAll(root.querySelector('[data-anx-hero]') || root);
});

export {};
