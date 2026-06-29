/**
 * UCP Agent Checkout (frontend).
 *
 * The visitor-facing twin of the backend UCP Console. The visitor picks a
 * shopping intent and the widget POSTs it to the `ucp_checkout` eID, consuming
 * the streamed commerce state machine over SSE. The agent builds a cart and
 * pauses at `authorization.required`; the visitor authorizes (or declines) the
 * SIMULATED purchase, and the order confirms. fetch + ReadableStream (POST).
 */

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function money(cents, cur) { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur || 'EUR' }).format((cents || 0) / 100); }
function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }

function initCheckout(root) {
  const runUrl = root.dataset.runUrl;
  const page = root.dataset.page || '0';
  const showEvents = root.dataset.showEvents === '1';

  const intentsEl = root.querySelector('[data-ucp-intents]');
  const runBtn = root.querySelector('[data-ucp-run]');
  const stageEl = root.querySelector('[data-ucp-stage]');
  const eventsEl = root.querySelector('[data-ucp-events]');
  const eventsListEl = root.querySelector('[data-ucp-events-list]');
  const eventCountEl = root.querySelector('[data-ucp-eventcount]');

  let intent = 'pro';
  intentsEl.querySelectorAll('[data-intent]').forEach((b) => {
    b.addEventListener('click', () => {
      intentsEl.querySelectorAll('[data-intent]').forEach((x) => x.classList.remove('is-active'));
      b.classList.add('is-active');
      intent = b.dataset.intent;
    });
  });
  if (showEvents) eventsEl.hidden = false;

  let busy = false;
  let eventCount = 0;
  let orderId = '';
  let currency = 'EUR';
  let stage = null;

  function logEvent(ev) {
    eventCount++; if (eventCountEl) eventCountEl.textContent = eventCount;
    if (!showEvents) return;
    const row = document.createElement('span'); row.className = 'ucp-cc__evt'; row.textContent = ev.type;
    eventsListEl.appendChild(row); eventsListEl.scrollLeft = eventsListEl.scrollWidth;
  }

  function newStage() {
    stageEl.innerHTML =
      '<div class="ucp-cc__card">' +
      '<div class="ucp-cc__status" data-status><span class="ucp-cc__state">starting</span></div>' +
      '<div class="ucp-cc__reasoning" data-reasoning hidden></div>' +
      '<div class="ucp-cc__cart" data-cart></div>' +
      '<div class="ucp-cc__auth" data-auth hidden></div>' +
      '<div class="ucp-cc__receipt" data-receipt hidden></div>' +
      '</div>';
    stage = {
      status: stageEl.querySelector('[data-status]'),
      reasoning: stageEl.querySelector('[data-reasoning]'),
      cart: stageEl.querySelector('[data-cart]'),
      auth: stageEl.querySelector('[data-auth]'),
      receipt: stageEl.querySelector('[data-receipt]'),
    };
  }

  function setStatus(state) { stage.status.innerHTML = '<span class="ucp-cc__state ucp-cc__state--' + state + '">' + esc(state.replace(/_/g, ' ')) + '</span>'; }

  function renderCart(items, total) {
    stage.cart.innerHTML = '<div class="ucp-cc__cart-list">' + items.map((i) =>
      '<div class="ucp-cc__cart-row"><span>' + esc(i.name) + '</span><span class="ucp-cc__cart-price">' + money(i.price, currency) + ' <small>' + esc(i.unit || '') + '</small></span></div>').join('') +
      '<div class="ucp-cc__cart-total"><span>Total</span><span>' + money(total, currency) + '</span></div></div>';
  }

  function renderAuth(order) {
    setStatus('authorization_required');
    stage.auth.hidden = false;
    stage.auth.innerHTML =
      '<div class="ucp-cc__auth-badge">⏸ Authorize this order</div>' +
      '<div class="ucp-cc__auth-line">Total <b>' + money(order.totalCents, order.currency) + '</b> — simulated, no payment is taken.</div>' +
      '<div class="ucp-cc__auth-actions"><button type="button" class="ucp-cc__approve" data-decision="approved">Authorize</button>' +
      '<button type="button" class="ucp-cc__decline" data-decision="declined">Not now</button></div>';
    stage.auth.querySelectorAll('[data-decision]').forEach((b) => {
      b.addEventListener('click', () => { stage.auth.classList.add('is-done'); run({ orderId, authorization: { decision: b.dataset.decision } }); });
    });
  }

  function renderReceipt(order, simulated) {
    stage.auth.hidden = true;
    stage.receipt.hidden = false;
    stage.receipt.innerHTML =
      '<div class="ucp-cc__receipt-head">✓ Order confirmed</div>' +
      '<div class="ucp-cc__receipt-row"><span>Order</span><b>' + esc(order.orderId) + '</b></div>' +
      '<div class="ucp-cc__receipt-row"><span>Total</span><b>' + money(order.totalCents, order.currency) + '</b></div>' +
      (simulated ? '<div class="ucp-cc__receipt-sim">Simulated — no payment was taken and no order was placed with any real system.</div>' : '');
  }

  function handle(ev) {
    logEvent(ev);
    switch (ev.type) {
      case 'checkout.started': orderId = ev.orderId; setStatus('discovering'); break;
      case 'agent.reasoning': stage.reasoning.hidden = false; stage.reasoning.textContent = '🧠 ' + ev.text; break;
      case 'cart.updated': currency = ev.currency || currency; renderCart(ev.items || [], ev.totalCents || 0); break;
      case 'checkout.step': setStatus(ev.state); break;
      case 'authorization.required': renderAuth(ev.order || {}); break;
      case 'order.confirmed': setStatus('confirmed'); renderReceipt(ev.order || {}, !!ev.simulated); break;
      case 'order.declined': setStatus('declined'); stage.receipt.hidden = false; stage.receipt.innerHTML = '<div class="ucp-cc__note">No problem — nothing was ordered.</div>'; break;
      case 'checkout.error': stage.cart.innerHTML = '<span class="ucp-cc__err">⛔ ' + esc(ev.message) + '</span>'; break;
      default: break;
    }
  }

  async function run(extra) {
    if (busy) return;
    busy = true; runBtn.disabled = true; runBtn.classList.add('is-busy');
    if (!extra) newStage();
    const body = Object.assign({ intent, page: Number(page), url: location.href }, extra || {});
    try {
      const res = await fetch(runUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);
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
      if (stage) stage.cart.innerHTML = '<span class="ucp-cc__err">⛔ The agent is unavailable right now. Please try again.</span>';
    } finally {
      busy = false; runBtn.disabled = false; runBtn.classList.remove('is-busy');
    }
  }

  runBtn.addEventListener('click', () => run());
}

ready(() => { document.querySelectorAll('[data-ucp-checkout]').forEach(initCheckout); });
export {};
