/**
 * Agent Nexus motion helpers — Web Animations API, no dependencies.
 *
 * Agent Nexus used to vendor a full animation library to stagger a few cards
 * and count a few numbers up. The browser does both natively, so this is all
 * that is left. Every helper is a no-op under prefers-reduced-motion and leaves
 * the element in its final state, so nothing is ever stuck invisible.
 *
 * Background tabs suspend animation frames, which is exactly when an entrance
 * animation would freeze at opacity 0 — `fill: 'both'` plus the final inline
 * state below means the end state is applied even if the animation never runs.
 */

const REDUCED = '(prefers-reduced-motion: reduce)';
const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';

export function prefersReducedMotion() {
  return window.matchMedia?.(REDUCED).matches ?? false;
}

/** Fade + lift one element into place. Returns the Animation, or null. */
export function reveal(element, { duration = 420, delay = 0, distance = 10 } = {}) {
  if (!element || prefersReducedMotion() || typeof element.animate !== 'function') return null;
  return element.animate(
    [
      { opacity: 0, transform: `translateY(${distance}px)` },
      { opacity: 1, transform: 'translateY(0)' },
    ],
    { duration, delay, easing: EASE, fill: 'both' },
  );
}

/** Reveal a collection one after the other. */
export function stagger(elements, { step = 60, ...options } = {}) {
  const targets = Array.from(elements || []);
  return targets
    .map((element, index) => reveal(element, { ...options, delay: (options.delay ?? 0) + index * step }))
    .filter(Boolean);
}

/**
 * Count an element up to its data-anx-count value (falling back to its own
 * text), keeping data-anx-prefix / data-anx-suffix around the number.
 */
export function countUp(element, { duration = 900, delay = 0 } = {}) {
  if (!element) return null;
  const raw = String(element.dataset.anxCount ?? element.textContent ?? '0');
  const target = Number.parseFloat(raw.replace(/[^\d.-]/g, ''));
  if (!Number.isFinite(target)) return null;

  const decimals = raw.includes('.') ? (raw.split('.')[1].match(/\d/g) || []).length : 0;
  const prefix = element.dataset.anxPrefix ?? '';
  const suffix = element.dataset.anxSuffix ?? '';
  const write = (value) => { element.textContent = prefix + value.toFixed(decimals) + suffix; };

  if (prefersReducedMotion() || typeof requestAnimationFrame !== 'function') {
    write(target);
    return null;
  }

  const started = performance.now() + delay;
  const tick = (now) => {
    const progress = Math.min(1, Math.max(0, (now - started) / duration));
    write(target * (1 - (1 - progress) ** 3));
    if (progress < 1) requestAnimationFrame(tick);
  };
  write(0);
  requestAnimationFrame(tick);
  return null;
}

/** Count up every [data-anx-count] inside a scope. */
export function countUpAll(scope, options = {}) {
  scope?.querySelectorAll('[data-anx-count]').forEach((element, index) => {
    countUp(element, { ...options, delay: (options.delay ?? 120) + index * 70 });
  });
}
