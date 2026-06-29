/**
 * AP2 Trusted Surface (frontend).
 *
 * The visitor sets a spending cap and authorizes a purchase; the surface POSTs the
 * cart to the `ap2_authorize` eID, which mints a signed Intent Mandate and Cart
 * Mandate, walks the authorization chain and returns the verified result. The
 * surface renders the per-step checks, the verdict and (optionally) the issued
 * mandate tokens. Sandbox-signed; nothing is charged.
 */

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }

function initSurface(root) {
  const runUrl = root.dataset.runUrl;
  const page = root.dataset.page || '0';
  const showEvents = root.dataset.showEvents === '1';

  const capEl = root.querySelector('[data-ap2-cap]');
  const runBtn = root.querySelector('[data-ap2-run]');
  const resultEl = root.querySelector('[data-ap2-result]');
  const mandatesEl = root.querySelector('[data-ap2-mandates]');

  // The fixed demo cart (Agency bundle + onboarding = €448).
  const cart = {
    items: [{ name: 'Agency Bundle', price: 14900 }, { name: 'Onboarding Add-on', price: 29900 }],
    totalCents: 44800, currency: 'EUR', merchant: 'desiderio-store',
  };

  function renderMandate(label, jwt, claims) {
    const parts = jwt.split('.');
    return '<div class="ap2-ts__mandate"><div class="ap2-ts__mandate-h">' + esc(label) + ' <code>' + esc(claims.jti || '') + '</code></div>' +
      '<div class="ap2-ts__jwt"><b>' + esc(parts[0]) + '</b>.<i>' + esc(parts[1]) + '</i>.<s>' + esc(parts[2]) + '</s></div></div>';
  }

  async function authorize() {
    runBtn.disabled = true; runBtn.classList.add('is-busy');
    resultEl.innerHTML = '<div class="ap2-ts__working">Issuing mandates and verifying the chain…</div>';
    mandatesEl.hidden = true; mandatesEl.innerHTML = '';
    const capCents = Math.max(1, Math.round(parseFloat(capEl.value || '500') * 100));
    try {
      const res = await fetch(runUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cart, capCents, page: Number(page), url: location.href }) });
      const r = await res.json();
      if (r.error) throw new Error(r.error);

      const checks = (r.checks || []).map((c) =>
        '<div class="ap2-ts__check ' + (c.pass ? 'is-pass' : 'is-fail') + '"><span class="ap2-ts__check-icon">' + (c.pass ? '✓' : '✕') + '</span>' +
        '<span>' + esc(c.label) + '</span></div>').join('');
      resultEl.innerHTML =
        '<div class="ap2-ts__checks">' + checks + '</div>' +
        '<div class="ap2-ts__verdict ' + (r.authorized ? 'is-ok' : 'is-no') + '">' +
        (r.authorized ? '✓ Payment authorized' : '✕ Authorization refused') +
        '<small>' + (r.authorized ? 'The mandate chain verified. Simulated — nothing was charged.' : 'A check failed — no payment would be made.') + '</small></div>';

      if (showEvents && r.intentJwt) {
        mandatesEl.hidden = false;
        mandatesEl.innerHTML = '<div class="ap2-ts__mandates-h">Issued mandates (signed JWS)</div>' +
          renderMandate('Intent Mandate', r.intentJwt, r.intentClaims || {}) +
          renderMandate('Cart Mandate', r.cartJwt, r.cartClaims || {});
      }
    } catch (e) {
      resultEl.innerHTML = '<div class="ap2-ts__verdict is-no">⛔ ' + esc(e.message || 'Authorization failed') + '</div>';
    } finally {
      runBtn.disabled = false; runBtn.classList.remove('is-busy');
    }
  }

  runBtn.addEventListener('click', authorize);
}

ready(() => { document.querySelectorAll('[data-ap2-surface]').forEach(initSurface); });
export {};
