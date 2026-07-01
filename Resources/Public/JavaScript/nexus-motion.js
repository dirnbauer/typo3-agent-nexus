/**
 * Agent Nexus motion helpers.
 *
 * GSAP is vendored same-origin (CSP-safe, offline-safe) and lazy-loaded on
 * first use. Every helper is a no-op under prefers-reduced-motion, and the
 * CSS fallback animations in nexus-ui.css stay in place when GSAP never
 * arrives — callers flag `has-gsap` on the root to switch the two regimes.
 */

const GSAP_SRC = new URL('./Vendor/gsap.min.js', import.meta.url).href;

let gsapLoad = null;

export function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function loadGsap() {
  if (window.gsap) return Promise.resolve(window.gsap);
  if (gsapLoad) return gsapLoad;

  gsapLoad = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = GSAP_SRC;
    script.async = true;
    script.onload = () => window.gsap ? resolve(window.gsap) : reject(new Error('GSAP did not expose window.gsap'));
    script.onerror = () => reject(new Error('Could not load vendored GSAP'));
    document.head.appendChild(script);
  });

  return gsapLoad;
}

/**
 * Try to hand a scope over to GSAP: resolves with gsap and marks the root
 * element, or resolves with null (CSS fallback stays active).
 */
export async function withGsap(root) {
  if (prefersReducedMotion()) return null;
  try {
    const gsap = await loadGsap();
    if (root) root.classList.add('has-gsap');
    return gsap;
  } catch {
    return null;
  }
}

/** Staggered entrance for a list of elements (pairs with .anx-reveal). */
export function reveal(gsap, elements, opts = {}) {
  const targets = Array.from(elements || []);
  if (!gsap || targets.length === 0) return null;
  return gsap.fromTo(
    targets,
    { autoAlpha: 0, y: 10 },
    {
      autoAlpha: 1,
      y: 0,
      duration: opts.duration ?? 0.45,
      stagger: opts.stagger ?? 0.07,
      delay: opts.delay ?? 0,
      ease: opts.ease ?? 'power2.out',
      clearProps: 'transform',
      overwrite: 'auto',
    },
  );
}

/** Count a numeric element up from 0 to its data-anx-count value. */
export function countUp(gsap, el, opts = {}) {
  if (!gsap || !el) return null;
  const raw = el.dataset.anxCount ?? el.textContent ?? '0';
  const target = parseFloat(String(raw).replace(/[^\d.-]/g, ''));
  if (!isFinite(target)) return null;
  const decimals = opts.decimals ?? (String(raw).includes('.') ? String(raw).split('.')[1].replace(/\D/g, '').length : 0);
  const prefix = el.dataset.anxPrefix ?? '';
  const suffix = el.dataset.anxSuffix ?? '';
  const state = { value: 0 };
  return gsap.to(state, {
    value: target,
    duration: opts.duration ?? 0.9,
    delay: opts.delay ?? 0,
    ease: 'power1.out',
    onUpdate: () => {
      el.textContent = prefix + state.value.toFixed(decimals) + suffix;
    },
  });
}

/** Animate all [data-anx-count] descendants of a scope. */
export function countUpAll(gsap, scope, opts = {}) {
  if (!gsap || !scope) return;
  scope.querySelectorAll('[data-anx-count]').forEach((el, i) => {
    countUp(gsap, el, { ...opts, delay: (opts.delay ?? 0.15) + i * 0.08 });
  });
}

/** Kill a set of timelines/tweens defensively. */
export function killAll(...animations) {
  animations.forEach((animation) => {
    if (animation && typeof animation.kill === 'function') animation.kill();
  });
}
