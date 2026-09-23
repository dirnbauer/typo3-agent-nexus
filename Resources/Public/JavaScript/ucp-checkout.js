/**
 * UCP checkout widget (frontend).
 *
 * The visitor picks what the shopping agent should buy. The widget runs the
 * agent over AG-UI (`{api}/ucp/agent`); the agent reads the store's UCP
 * profile, opens a checkout session priced by the store, explains its choice
 * and stops. The visitor approves (adding an email address when the store
 * still needs one) or declines, and a second run completes or cancels the
 * checkout. Every UCP request the agent sends shows up in the "UCP calls"
 * strip. Every order is simulated; no payment is taken.
 */
import { money, parseJson, runInput, streamRun, ToolCalls, totalOf, uuid } from './ucp-agent-stream.js';

const STATE_LABELS = {
  starting: 'starting',
  working: 'working',
  awaiting: 'awaiting approval',
  approving: 'approving',
  confirmed: 'confirmed',
  declined: 'declined',
  failed: 'stopped',
};

function ready(fn) {
  if (document.readyState !== 'loading') {
    fn();
  } else {
    document.addEventListener('DOMContentLoaded', fn);
  }
}

function initCheckout(root) {
  const agentUrl = root.dataset.agentUrl;
  const showEvents = root.dataset.showEvents === '1';
  const widget = { ce: Number(root.dataset.ce || '0'), page: Number(root.dataset.page || '0'), url: location.href };

  const intentButtons = Array.from(root.querySelectorAll('[data-intent]'));
  const runButton = root.querySelector('[data-ucp-run]');
  const stage = root.querySelector('[data-ucp-stage]');
  const stateEl = root.querySelector('[data-ucp-state]');
  const provenance = root.querySelector('[data-ucp-provenance]');
  const thread = root.querySelector('[data-ucp-thread]');
  const cart = root.querySelector('[data-ucp-cart]');
  const auth = root.querySelector('[data-ucp-auth]');
  const authHeading = root.querySelector('[data-ucp-auth-heading]');
  const authLine = root.querySelector('[data-ucp-auth-line]');
  const authForm = root.querySelector('[data-ucp-auth-form]');
  const emailField = root.querySelector('[data-ucp-email-field]');
  const emailInput = root.querySelector('[data-ucp-email]');
  const emailError = root.querySelector('[data-ucp-email-error]');
  const receipt = root.querySelector('[data-ucp-receipt]');
  const errorEl = root.querySelector('[data-ucp-error]');
  const eventsEl = root.querySelector('[data-ucp-events]');
  const eventsList = root.querySelector('[data-ucp-events-list]');
  const eventCount = root.querySelector('[data-ucp-eventcount]');

  let intent = 'pro';
  let threadId = '';
  let interrupt = null;
  let checkout = null;
  let busy = false;
  let calls = 0;
  let text = null;
  const chips = new Map();
  let tools = new ToolCalls(renderCall);

  intentButtons.forEach((button) => {
    button.addEventListener('click', () => {
      intentButtons.forEach((other) => {
        other.classList.toggle('is-active', other === button);
        other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
      });
      intent = button.dataset.intent;
    });
  });
  if (showEvents) {
    eventsEl.hidden = false;
  }

  runButton.addEventListener('click', () => {
    const chosen = intentButtons.find((button) => button.dataset.intent === intent);
    threadId = uuid();
    reset();
    run(runInput({ threadId, runId: uuid(), text: chosen ? chosen.textContent.trim() : intent, props: { ...widget, intent } }), 'working');
  });

  authForm.addEventListener('submit', (event) => {
    event.preventDefault();
    answer(true);
  });
  root.querySelector('[data-ucp-decline]').addEventListener('click', () => answer(false));

  function reset() {
    stage.hidden = false;
    thread.replaceChildren();
    cart.hidden = true;
    cart.replaceChildren();
    auth.hidden = true;
    receipt.hidden = true;
    receipt.replaceChildren();
    errorEl.hidden = true;
    provenance.hidden = true;
    eventsList.replaceChildren();
    chips.clear();
    calls = 0;
    eventCount.textContent = '0';
    tools = new ToolCalls(renderCall);
    interrupt = null;
    checkout = null;
    setState('starting');
  }

  function setState(state) {
    stateEl.className = 'ucp-cc__state ucp-cc__state--' + state;
    stateEl.textContent = STATE_LABELS[state] || state;
  }

  function answer(approved) {
    if (!interrupt || busy) {
      return;
    }
    const payload = { approved };
    if (approved && !emailField.hidden) {
      const email = emailInput.value.trim();
      if (email === '' || !emailInput.checkValidity()) {
        emailInput.setAttribute('aria-invalid', 'true');
        emailError.hidden = false;
        emailInput.focus();
        return;
      }
      payload.email = email;
    }
    const resume = [{ interruptId: interrupt.id, status: 'resolved', payload }];
    auth.hidden = true;
    say(approved ? 'You approved the order.' : 'You did not approve the order.', 'you');
    run(runInput({ threadId, runId: uuid(), text: approved ? 'Approve' : 'Not now', props: { ...widget, intent }, resume }), approved ? 'approving' : 'working');
  }

  async function run(input, state) {
    if (busy) {
      return;
    }
    busy = true;
    runButton.disabled = true;
    runButton.classList.add('is-busy');
    setState(state);
    try {
      await streamRun(agentUrl, input, handle);
    } catch {
      fail('The agent is unavailable right now. Please try again.');
    } finally {
      busy = false;
      runButton.disabled = false;
      runButton.classList.remove('is-busy');
    }
  }

  function handle(event) {
    if (tools.handle(event)) {
      return;
    }
    switch (event.type) {
      case 'TEXT_MESSAGE_START':
        text = say('', 'agent');
        break;
      case 'REASONING_MESSAGE_START':
        text = say('', 'reasoning');
        break;
      case 'TEXT_MESSAGE_CONTENT':
      case 'REASONING_MESSAGE_CONTENT':
        if (text) {
          text.lastChild.textContent += event.delta;
        }
        break;
      case 'TEXT_MESSAGE_END':
      case 'REASONING_MESSAGE_END':
        text = null;
        break;
      case 'CUSTOM':
        if (event.name === 'agentnexus.provenance' && event.value && event.value.label) {
          provenance.textContent = event.value.label;
          provenance.hidden = false;
        }
        break;
      case 'STATE_SNAPSHOT':
        if (event.snapshot && event.snapshot.checkout) {
          checkout = event.snapshot.checkout;
          renderCart(checkout);
        }
        break;
      case 'RUN_FINISHED':
        finished(event);
        break;
      case 'RUN_ERROR':
        fail(event.message || 'The agent stopped.');
        break;
      default:
        break;
    }
  }

  function finished(event) {
    const outcome = event.outcome || { type: 'success' };
    if (outcome.type === 'interrupt' && Array.isArray(outcome.interrupts) && outcome.interrupts.length) {
      showApproval(outcome.interrupts[0]);
      return;
    }
    interrupt = null;
    if (checkout && checkout.status === 'completed') {
      setState('confirmed');
      showReceipt(checkout);
    } else if (checkout && checkout.status === 'canceled') {
      setState('declined');
      receipt.hidden = false;
      receipt.replaceChildren(note('Nothing was ordered.'));
    } else {
      setState('failed');
    }
  }

  function showApproval(next) {
    interrupt = next;
    setState('awaiting');
    const needsEmail = Boolean(next.metadata && next.metadata.needsEmail);
    const currency = checkout ? checkout.currency : 'EUR';
    authLine.replaceChildren(
      document.createTextNode('Total '),
      bold(money(checkout ? totalOf(checkout) : next.metadata && next.metadata.amount, currency)),
      document.createTextNode('. Simulated: no payment is taken.'),
    );
    emailField.hidden = !needsEmail;
    emailInput.required = needsEmail;
    emailInput.removeAttribute('aria-invalid');
    emailError.hidden = true;
    auth.hidden = false;
    authHeading.focus();
  }

  function showReceipt(done) {
    const order = done.order || {};
    receipt.hidden = false;
    receipt.replaceChildren(
      head('Order confirmed'),
      row('Order', order.label || order.id || ''),
      row('Total', money(totalOf(done), done.currency)),
      simulated('Simulated. No payment was taken and no real order was placed.'),
    );
    receipt.focus();
  }

  function renderCart(current) {
    const list = document.createElement('div');
    list.className = 'ucp-cc__cart-list';
    (current.line_items || []).forEach((line) => {
      const item = line.item || {};
      const lineTotal = (line.totals || []).find((total) => total.type === 'total');
      const monthly = (current.messages || []).some((message) => message.code === 'billing_period' && message.path === '$.line_items[' + (current.line_items || []).indexOf(line) + ']');
      const rowEl = document.createElement('div');
      rowEl.className = 'ucp-cc__cart-row';
      const name = document.createElement('span');
      name.textContent = item.title + (line.quantity > 1 ? ' × ' + line.quantity : '');
      const price = document.createElement('span');
      price.className = 'ucp-cc__cart-price';
      price.textContent = money(lineTotal ? lineTotal.amount : 0, current.currency) + ' ';
      const unit = document.createElement('small');
      unit.textContent = monthly ? 'per month' : '';
      price.append(unit);
      rowEl.append(name, price);
      list.append(rowEl);
    });
    const total = document.createElement('div');
    total.className = 'ucp-cc__cart-total';
    const label = document.createElement('span');
    label.textContent = 'Total';
    const amount = document.createElement('span');
    amount.textContent = money(totalOf(current), current.currency);
    total.append(label, amount);
    list.append(total);
    cart.replaceChildren(list);
    cart.hidden = false;
  }

  /** One line of the agent's narration, the visitor's answer or the reasoning. */
  function say(content, kind) {
    const item = document.createElement('li');
    item.className = 'ucp-cc__line ucp-cc__line--' + kind;
    if (kind === 'reasoning') {
      const prefix = document.createElement('span');
      prefix.className = 'ucp-cc__sr-only';
      prefix.textContent = 'Why the agent chose this: ';
      item.append(prefix);
    }
    const body = document.createElement('span');
    body.textContent = content;
    item.append(body);
    thread.append(item);
    return item;
  }

  /** A UCP call in the "UCP calls" strip: operation and HTTP status. */
  function renderCall(call) {
    let chip = chips.get(call.id);
    if (!chip) {
      chip = document.createElement('li');
      chip.className = 'ucp-cc__evt';
      chips.set(call.id, chip);
      eventsList.append(chip);
      calls++;
      eventCount.textContent = String(calls);
    }
    const request = parseJson(call.args);
    const result = call.result === null ? null : parseJson(call.result);
    const name = (call.name || chip.dataset.name || '').replace(/^ucp\./, '');
    chip.dataset.name = call.name || chip.dataset.name || '';
    let status = '';
    if (result && typeof result.status === 'number') {
      status = String(result.status);
    } else if (result) {
      status = 'not sent';
    } else if (call.ended) {
      status = 'waiting';
    }
    chip.textContent = name + (status ? ' ' + status : '');
    if (request && request.method) {
      chip.title = request.method + ' ' + request.path;
    }
  }

  function fail(message) {
    setState('failed');
    errorEl.textContent = message;
    errorEl.hidden = false;
  }

  function bold(value) {
    const element = document.createElement('b');
    element.textContent = value;
    return element;
  }

  function head(value) {
    const element = document.createElement('div');
    element.className = 'ucp-cc__receipt-head';
    element.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 8.5 6.5 12 13 4.5"/></svg>';
    element.append(document.createTextNode(value));
    return element;
  }

  function row(label, value) {
    const element = document.createElement('div');
    element.className = 'ucp-cc__receipt-row';
    const name = document.createElement('span');
    name.textContent = label;
    element.append(name, bold(value));
    return element;
  }

  function simulated(value) {
    const element = document.createElement('div');
    element.className = 'ucp-cc__receipt-sim';
    element.textContent = value;
    return element;
  }

  function note(value) {
    const element = document.createElement('div');
    element.className = 'ucp-cc__note';
    element.textContent = value;
    return element;
  }
}

ready(() => {
  document.querySelectorAll('[data-ucp-checkout]').forEach(initCheckout);
});
