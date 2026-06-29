/**
 * AP2 Mandate Studio (backend).
 *
 * Drives the three-step authorization flow against the backend mint/verify
 * routes: mint an Intent Mandate (spending limits), mint a Cart Mandate for a
 * specific cart, then verify the chain. A tamper box lets the operator edit a
 * signed token and watch verification fail — the whole point of a signed mandate.
 * Everything is sandbox-signed; no real payment is initiated.
 */

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function ajax(name) { return window.TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls[name] : null; }
async function post(name, body) {
  const url = ajax(name);
  if (!url) throw new Error('route ' + name + ' unavailable');
  const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  return res.json();
}
function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }

function renderToken(host, jwt, claims) {
  const parts = jwt.split('.');
  host.classList.remove('d-none');
  host.innerHTML =
    '<div class="ap2-token__jwt"><b>' + esc(parts[0]) + '</b>.<i>' + esc(parts[1]) + '</i>.<s>' + esc(parts[2]) + '</s></div>' +
    '<pre class="ap2-token__claims">' + esc(JSON.stringify(claims, null, 2)) + '</pre>' +
    '<div class="ap2-token__jti">jti <b>' + esc(claims.jti || '') + '</b></div>';
}

ready(() => {
  const root = document.querySelector('[data-ap2-studio]');
  if (!root) return;

  const capEl = root.querySelector('[data-ap2-cap]');
  const merchantEl = root.querySelector('[data-ap2-merchant]');
  const humanEl = root.querySelector('[data-ap2-human]');
  const intentOut = root.querySelector('[data-ap2-intent-out]');
  const cartOut = root.querySelector('[data-ap2-cart-out]');
  const checksEl = root.querySelector('[data-ap2-checks]');
  const verdictEl = root.querySelector('[data-ap2-verdict]');
  const tamperEl = root.querySelector('[data-ap2-tamper]');

  const stepCart = root.querySelector('[data-ap2-step="cart"]');
  const stepVerify = root.querySelector('[data-ap2-step="verify"]');
  const mintIntentBtn = root.querySelector('[data-ap2-mint-intent]');
  const mintCartBtn = root.querySelector('[data-ap2-mint-cart]');
  const verifyBtn = root.querySelector('[data-ap2-verify]');
  const retamperBtn = root.querySelector('[data-ap2-retamper]');

  let intentJwt = '';
  let intentJti = '';
  let cartJwt = '';

  // The fixed demo cart (Agency bundle + onboarding).
  function cart() {
    return {
      items: [
        { name: 'Agency Bundle', price: 14900 },
        { name: 'Onboarding Add-on', price: 29900 },
      ],
      totalCents: 44800,
      currency: 'EUR',
      merchant: (merchantEl.value || 'desiderio-store').trim(),
    };
  }

  function renderChecks(result) {
    const checks = result.checks || [];
    checksEl.innerHTML = checks.map((c) =>
      '<div class="ap2-check ' + (c.pass ? 'is-pass' : 'is-fail') + '"><span class="ap2-check__icon">' + (c.pass ? '✓' : '✕') + '</span>' +
      '<span>' + esc(c.label) + ' <span class="ap2-check__detail">' + esc(c.detail) + '</span></span></div>').join('');
    verdictEl.classList.remove('d-none', 'is-ok', 'is-no');
    if (result.authorized) {
      verdictEl.classList.add('is-ok');
      verdictEl.innerHTML = '✓ Payment authorized<small>The chain is valid — a real deployment would now charge via the payment network.</small>';
    } else {
      verdictEl.classList.add('is-no');
      verdictEl.innerHTML = '✕ Authorization refused<small>At least one check failed — no payment would be made.</small>';
    }
  }

  mintIntentBtn.addEventListener('click', async () => {
    mintIntentBtn.disabled = true;
    try {
      const cap = Math.max(1, Math.round(parseFloat(capEl.value || '500') * 100));
      const r = await post('ap2_mint', { step: 'intent', constraints: { maxAmountCents: cap, currency: 'EUR', merchants: [(merchantEl.value || 'desiderio-store').trim()], humanPresent: humanEl.checked } });
      intentJwt = r.jwt; intentJti = r.claims.jti;
      renderToken(intentOut, intentJwt, r.claims);
      stepCart.classList.remove('is-locked');
      mintCartBtn.disabled = false;
    } catch (e) { intentOut.classList.remove('d-none'); intentOut.innerHTML = '<span style="color:#dc2626">' + esc(e.message) + '</span>'; }
    mintIntentBtn.disabled = false;
  });

  mintCartBtn.addEventListener('click', async () => {
    mintCartBtn.disabled = true;
    try {
      const r = await post('ap2_mint', { step: 'cart', cart: cart(), intentRef: intentJti });
      cartJwt = r.jwt;
      renderToken(cartOut, cartJwt, r.claims);
      stepVerify.classList.remove('is-locked');
      verifyBtn.disabled = false;
      tamperEl.value = cartJwt;
      retamperBtn.disabled = false;
    } catch (e) { cartOut.classList.remove('d-none'); cartOut.innerHTML = '<span style="color:#dc2626">' + esc(e.message) + '</span>'; }
    mintCartBtn.disabled = false;
  });

  verifyBtn.addEventListener('click', async () => {
    verifyBtn.disabled = true;
    try { renderChecks(await post('ap2_verify', { intentJwt, cartJwt })); }
    catch (e) { checksEl.innerHTML = '<span style="color:#dc2626">' + esc(e.message) + '</span>'; }
    verifyBtn.disabled = false;
  });

  retamperBtn.addEventListener('click', async () => {
    retamperBtn.disabled = true;
    try { renderChecks(await post('ap2_verify', { intentJwt, cartJwt: (tamperEl.value || '').trim() })); }
    catch (e) { checksEl.innerHTML = '<span style="color:#dc2626">' + esc(e.message) + '</span>'; }
    retamperBtn.disabled = false;
  });
});
