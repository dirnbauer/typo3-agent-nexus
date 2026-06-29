/**
 * Agent Nexus overview interactions.
 *
 * The tab switcher is plain JavaScript. GSAP is lazy-loaded for the protocol
 * motion diagrams; if the CDN is unavailable, the CSS fallback animation stays
 * in place. The timelines are killed and rebuilt per active panel.
 */

const GSAP_SRC = 'https://cdn.jsdelivr.net/npm/gsap@3.15.0/dist/gsap.min.js';

let gsapLoad = null;
let activeIntroTimeline = null;
let activeLoopTimeline = null;

function ready(fn) {
  document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn);
}

function wantsReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function loadGsap() {
  if (window.gsap) return Promise.resolve(window.gsap);
  if (gsapLoad) return gsapLoad;

  gsapLoad = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = GSAP_SRC;
    script.async = true;
    script.crossOrigin = 'anonymous';
    script.onload = () => window.gsap ? resolve(window.gsap) : reject(new Error('GSAP did not expose window.gsap'));
    script.onerror = () => reject(new Error('Could not load GSAP'));
    document.head.appendChild(script);
  });

  return gsapLoad;
}

function killTimelines() {
  if (activeIntroTimeline) activeIntroTimeline.kill();
  if (activeLoopTimeline) activeLoopTimeline.kill();
  activeIntroTimeline = null;
  activeLoopTimeline = null;
}

function animatePanel(root) {
  if (wantsReducedMotion()) return;

  loadGsap().then((gsap) => {
    const panel = root.querySelector('.agst-panel.is-active');
    if (!panel) return;

    killTimelines();
    root.classList.add('has-gsap');

    const reveals = panel.querySelectorAll('.agst-reveal');
    const flow = panel.querySelector('[data-agst-motion]');
    const nodes = flow ? flow.querySelectorAll('.agst-flow__node') : [];
    const beam = flow ? flow.querySelector('.agst-flow__beam') : null;
    const steps = flow ? flow.querySelectorAll('[data-agst-step]') : [];
    const media = panel.querySelector('.agst-media img');

    activeIntroTimeline = gsap.timeline({ defaults: { duration: 0.55, ease: 'power3.out' } });
    activeIntroTimeline
      .fromTo(reveals, { y: 14, autoAlpha: 0 }, { y: 0, autoAlpha: 1, stagger: 0.06 }, 0)
      .fromTo(nodes, { scale: 0.92, autoAlpha: 0 }, { scale: 1, autoAlpha: 1, stagger: 0.08 }, 0.12)
      .fromTo(steps, { y: 10, autoAlpha: 0 }, { y: 0, autoAlpha: 1, stagger: 0.055 }, 0.2);

    if (media) {
      activeIntroTimeline.fromTo(media, { scale: 1.04, autoAlpha: 0.72 }, { scale: 1, autoAlpha: 1, duration: 0.7 }, 0.05);
    }

    if (!beam || !steps.length) return;

    activeLoopTimeline = gsap.timeline({
      repeat: -1,
      repeatDelay: 0.25,
      defaults: { ease: 'power2.inOut' },
    });
    activeLoopTimeline
      .set(beam, { xPercent: -160, autoAlpha: 1 })
      .to(beam, { xPercent: 840, duration: 2.8, ease: 'none' }, 0);

    steps.forEach((step, index) => {
      activeLoopTimeline
        .to(step, { y: -3, scale: 1.04, borderColor: 'var(--accent)', duration: 0.18 }, index * 0.28)
        .to(step, { y: 0, scale: 1, duration: 0.32 }, index * 0.28 + 0.18);
    });
  }).catch(() => {
    root.classList.remove('has-gsap');
  });
}

ready(() => {
  const root = document.querySelector('[data-agentstack-overview]');
  if (!root) return;

  const tabs = Array.from(root.querySelectorAll('[data-agst-tab]'));
  const panels = Array.from(root.querySelectorAll('[data-agst-panel]'));
  if (!tabs.length) return;

  function activate(key, focus) {
    tabs.forEach((tab) => {
      const on = tab.getAttribute('data-agst-tab') === key;
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) tab.focus();
    });
    panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-agst-panel') === key));
    animatePanel(root);
  }

  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => activate(tab.getAttribute('data-agst-tab')));
    tab.addEventListener('keydown', (e) => {
      let next = null;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = tabs[(i + 1) % tabs.length];
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = tabs[(i - 1 + tabs.length) % tabs.length];
      else if (e.key === 'Home') next = tabs[0];
      else if (e.key === 'End') next = tabs[tabs.length - 1];
      if (next) {
        e.preventDefault();
        activate(next.getAttribute('data-agst-tab'), true);
      }
    });
  });

  const active = tabs.find((t) => t.classList.contains('is-active')) || tabs[0];
  tabs.forEach((t) => { t.tabIndex = t === active ? 0 : -1; });
  animatePanel(root);
});

export {};
