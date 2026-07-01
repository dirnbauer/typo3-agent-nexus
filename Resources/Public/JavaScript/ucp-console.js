/**
 * UCP Console (backend).
 *
 * Plays a *shopping agent*: it fetches the merchant manifest, then runs an
 * agent-driven checkout by POSTing a shopping intent to the backend route and
 * consuming the streamed commerce state machine (checkout.started → cart.updated
 * → authorization.required → order.confirmed). The authorization step is a
 * human-in-the-loop gate — nothing is "purchased" until the operator approves,
 * and every order is SIMULATED. SSE is read over fetch() (POST, so no EventSource).
 */

import { withGsap, reveal, countUpAll } from '@webconsulting/agent-nexus/nexus-motion.js';

// Entrance stagger + stat count-up (no-op under reduced motion / missing GSAP).
const anxRoot = document.querySelector('.anx');
if (anxRoot) {
  withGsap(anxRoot).then((gsap) => {
    if (!gsap) return;
    reveal(gsap, anxRoot.querySelectorAll('.anx-reveal'));
    countUpAll(gsap, anxRoot);
  });
}

/** Commerce event type → .anx-console__event modifier. */
const TYPE_CLASS = {
  'checkout.started': 'accent', 'checkout.step': 'accent', 'checkout.error': 'danger',
  'cart.updated': 'accent', 'agent.reasoning': 'muted',
  'authorization.required': 'warn', 'authorization.approved': 'ok',
  'order.confirmed': 'ok', 'order.declined': 'danger',
};

/** Checkout state → .anx-badge modifier for the state pill. */
const STATE_BADGE = {
  idle: '', discovering: 'anx-badge--run', building_cart: 'anx-badge--run',
  review: 'anx-badge--accent', authorization_required: 'anx-badge--warn',
  confirmed: 'anx-badge--ok', declined: 'anx-badge--danger',
};

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function money(cents, cur) { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur || 'EUR' }).format((cents || 0) / 100); }
function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }

ready(() => {
  const root = document.querySelector('[data-ucp-console]');
  if (!root) return;

  const timelineEl = root.querySelector('[data-ucp-timeline]');
  const countEl = root.querySelector('[data-ucp-eventcount]');
  const stateEl = root.querySelector('[data-ucp-state]');
  const reasoningEl = root.querySelector('[data-ucp-reasoning]');
  const cartEl = root.querySelector('[data-ucp-cart]');
  const authEl = root.querySelector('[data-ucp-auth]');
  const receiptEl = root.querySelector('[data-ucp-receipt]');
  const merchantEl = root.querySelector('[data-ucp-merchant]');
  const runBtn = root.querySelector('[data-ucp-run]');

  let intent = 'pro';
  root.querySelectorAll('[data-ucp-intent]').forEach((b) => {
    b.addEventListener('click', () => {
      root.querySelectorAll('[data-ucp-intent]').forEach((x) => x.classList.remove('active'));
      b.classList.add('active');
      intent = b.dataset.ucpIntent;
    });
  });

  let count = 0;
  let orderId = '';
  let currency = 'EUR';

  // ---- manifest discovery ----
  (async () => {
    try {
      const m = await (await fetch(root.dataset.manifestUrl, { headers: { Accept: 'application/json' } })).json();
      currency = (m.merchant && m.merchant.currency) || 'EUR';
      const caps = Object.entries(m.capabilities || {}).filter(([, v]) => v === true).map(([k]) => k);
      merchantEl.innerHTML =
        '<div class="ucp-merchant__head"><span class="ucp-merchant__name">' + esc(m.merchant && m.merchant.name) + '</span>' +
        '<span class="ucp-merchant__cur">' + esc(currency) + ' · UCP ' + esc(m.ucpVersion) + '</span>' +
        caps.map((c) => '<span class="ucp-merchant__cap">' + esc(c) + '</span>').join('') + '</div>' +
        '<div class="ucp-merchant__desc">' + esc(m.merchant && m.merchant.description) + '</div>' +
        '<div class="ucp-merchant__cat">' + (m.catalog || []).map((p) =>
          '<span class="ucp-merchant__item">' + esc(p.name) + ' · ' + money(p.price, currency) + '</span>').join('') + '</div>';
    } catch (e) {
      merchantEl.innerHTML = '<span class="ucp-error">Could not load the manifest.</span>';
    }
  })();

  function reset() {
    count = 0; countEl.textContent = '0 events';
    timelineEl.innerHTML = '';
    reasoningEl.innerHTML = ''; reasoningEl.classList.add('d-none');
    cartEl.innerHTML = '';
    authEl.innerHTML = ''; authEl.classList.add('d-none');
    receiptEl.innerHTML = ''; receiptEl.classList.add('d-none');
    setState('idle');
  }
  function setState(s) {
    stateEl.textContent = s;
    stateEl.className = 'anx-badge' + (STATE_BADGE[s] ? ' ' + STATE_BADGE[s] : '');
  }

  function addEvent(ev) {
    count++; countEl.textContent = count + (count === 1 ? ' event' : ' events');
    const cls = TYPE_CLASS[ev.type] || 'accent';
    const row = document.createElement('div');
    row.className = 'anx-console__event anx-console__event--' + cls;
    row.innerHTML = '<span class="anx-console__kind">' + esc(ev.type) + '</span>';
    const { type, ...rest } = ev;
    const payload = Object.keys(rest).length ? JSON.stringify(rest) : '';
    if (payload) { const p = document.createElement('span'); p.className = 'ucp-evt__payload'; p.textContent = payload.length > 100 ? payload.slice(0, 100) + '…' : payload; row.appendChild(p); }
    timelineEl.appendChild(row); timelineEl.scrollTop = timelineEl.scrollHeight;
  }

  function renderCart(items, totalCents) {
    cartEl.innerHTML = items.map((i) =>
      '<div class="ucp-cart__row"><span class="ucp-cart__name">' + esc(i.name) + ' <small>×' + (i.qty || 1) + '</small></span>' +
      '<span class="ucp-cart__price">' + money(i.price, currency) + ' <small>' + esc(i.unit || '') + '</small></span></div>').join('') +
      '<div class="ucp-cart__total"><span>Total</span><span class="ucp-cart__price">' + money(totalCents, currency) + '</span></div>';
  }

  function renderAuth(order) {
    setState('authorization_required');
    authEl.classList.remove('d-none');
    authEl.innerHTML =
      '<span class="anx-badge anx-badge--warn">Authorization required</span>' +
      '<div class="ucp-auth__line">The agent assembled this order. Authorize the (simulated) purchase, or decline.</div>' +
      '<div class="ucp-auth__total">' + money(order.totalCents, order.currency) + '</div>' +
      '<div class="anx-actions"><button type="button" class="anx-btn anx-btn--primary anx-btn--sm" data-decision="approved">Authorize purchase</button>' +
      '<button type="button" class="anx-btn anx-btn--ghost anx-btn--sm" data-decision="declined">Decline</button></div>';
    authEl.querySelectorAll('[data-decision]').forEach((b) => {
      b.addEventListener('click', () => { authEl.classList.add('d-none'); run({ orderId, authorization: { decision: b.dataset.decision } }); });
    });
  }

  function renderReceipt(order, simulated) {
    receiptEl.classList.remove('d-none');
    receiptEl.innerHTML =
      '<span class="anx-badge anx-badge--ok">Order confirmed</span>' +
      '<div class="ucp-receipt__row"><span>Order</span><b>' + esc(order.orderId) + '</b></div>' +
      '<div class="ucp-receipt__row"><span>Total</span><b>' + money(order.totalCents, order.currency) + '</b></div>' +
      (order.mandate ? '<div class="ucp-receipt__row"><span>Authorization</span><b>' + esc(order.mandate) + '</b></div>' : '') +
      (simulated ? '<div class="ucp-receipt__sim">Simulated — no payment was taken and no order was placed with any real system.</div>' : '');
  }

  function handle(ev) {
    addEvent(ev);
    switch (ev.type) {
      case 'checkout.started': orderId = ev.orderId; setState('discovering'); break;
      case 'agent.reasoning': reasoningEl.classList.remove('d-none'); reasoningEl.textContent = ev.text; break;
      case 'cart.updated': currency = ev.currency || currency; renderCart(ev.items || [], ev.totalCents || 0); break;
      case 'checkout.step': setState(ev.state); break;
      case 'authorization.required': renderAuth(ev.order || {}); break;
      case 'order.confirmed': setState('confirmed'); renderReceipt(ev.order || {}, !!ev.simulated); break;
      case 'order.declined': setState('declined'); break;
      case 'checkout.error': cartEl.innerHTML = '<span class="ucp-error">' + esc(ev.message) + '</span>'; break;
      default: break;
    }
  }

  async function run(extra) {
    const url = window.TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls.ucp_checkout : null;
    if (!url) { cartEl.innerHTML = '<span class="ucp-error">AJAX route unavailable</span>'; return; }
    if (!extra) reset();
    runBtn.disabled = true;
    const body = Object.assign({ intent }, extra || {});
    try {
      const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buf = '';
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });
        let i;
        while ((i = buf.indexOf('\n\n')) >= 0) {
          const frame = buf.slice(0, i); buf = buf.slice(i + 2);
          const line = frame.split('\n').find((l) => l.startsWith('data:'));
          if (line) { const j = line.slice(5).trim(); if (j) { try { handle(JSON.parse(j)); } catch { /* ignore */ } } }
        }
      }
    } catch (e) {
      cartEl.innerHTML = '<span class="ucp-error">Stream failed: ' + esc(e.message) + '</span>';
    } finally {
      runBtn.disabled = false;
    }
  }

  runBtn.addEventListener('click', () => run());
});
