/**
 * AG-UI live assistant (frontend widget).
 *
 * An AG-UI 1.0 client on the page. It sends the visitor's question as a
 * RunAgentInput to the public AG-UI endpoint — with its content element, page
 * and URL in forwardedProps.agentNexus — and renders the event stream as it
 * arrives: the agent's reasoning, the streamed answer, the plan comparison
 * (an activity), and finally the approval the run stops for. The approval is
 * an interrupt: the visitor's answer goes back as `resume` on the next run of
 * the same thread, and only an approval sends anything.
 */
import { Conversation, applyPatch, newId, runAgent, runInput } from './agui-stream.js';

const PROVENANCE = 'at.webconsulting.agentnexus.provenance';
const PLAN_COMPARISON = 'at.webconsulting.agentnexus.plan-comparison';
const UNAVAILABLE = 'The assistant is unavailable right now. Please try again.';

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
}

function label(name) {
  return name.charAt(0).toUpperCase() + name.slice(1).replace(/([A-Z])/g, ' $1').toLowerCase();
}

function money(amount, currency) {
  if (!Number(amount)) {
    return 'Free';
  }
  try {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency: currency || 'EUR', maximumFractionDigits: 0 }).format(amount);
  } catch (e) {
    return String(amount);
  }
}

function initAssistant(root) {
  const endpoint = root.dataset.endpoint;
  const preset = root.dataset.scenario || 'plan';
  const context = {
    ce: Number(root.dataset.ce || '0'),
    page: Number(root.dataset.page || '0'),
    url: location.href,
    preset,
  };
  const success = root.dataset.success || 'Thank you. We have received your request.';
  const showEvents = root.dataset.showEvents === '1';

  const form = root.querySelector('[data-agui-form]');
  const intentEl = root.querySelector('[data-agui-intent]');
  const sendBtn = root.querySelector('[data-agui-send]');
  const threadEl = root.querySelector('[data-agui-thread]');
  const presetsWrap = root.querySelector('[data-agui-presets-wrap]');
  const presetsEl = root.querySelector('[data-agui-presets]');
  const eventsEl = root.querySelector('[data-agui-events]');
  const eventsListEl = root.querySelector('[data-agui-events-list]');
  const eventCountEl = root.querySelector('[data-agui-eventcount]');

  const threadId = newId('thread');
  const conversation = new Conversation();
  let state = {};
  let pending = null;
  let busy = false;
  let eventCount = 0;

  (root.dataset.presets || '').split('\n').map((text) => text.trim()).filter(Boolean).forEach((text) => {
    const chip = element('button', 'agui-asst__preset', text);
    chip.type = 'button';
    chip.addEventListener('click', () => ask(text));
    presetsEl.appendChild(chip);
  });
  if (!presetsEl.children.length) {
    presetsWrap.hidden = true;
  }
  if (showEvents) {
    eventsEl.hidden = false;
  }

  function scrollDown() {
    threadEl.scrollTop = threadEl.scrollHeight;
  }

  function bubble(role) {
    const node = element('div', 'agui-asst__msg agui-asst__msg--' + role);
    threadEl.appendChild(node);
    scrollDown();
    return node;
  }

  function logEvent(event) {
    eventCount++;
    if (eventCountEl) {
      eventCountEl.textContent = String(eventCount);
    }
    if (showEvents) {
      eventsListEl.appendChild(element('span', 'agui-asst__evt', event.type));
      eventsListEl.scrollLeft = eventsListEl.scrollWidth;
    }
  }

  function setBusy(on) {
    busy = on;
    sendBtn.disabled = on;
    intentEl.disabled = on;
    sendBtn.classList.toggle('is-busy', on);
    presetsEl.querySelectorAll('button').forEach((chip) => { chip.disabled = on; });
  }

  // ---- generative UI: the plan comparison activity ----------------------
  function renderPlans(host, content) {
    const plans = Array.isArray(content.plans) ? content.plans : [];
    const list = element('ul', 'agui-asst__plans');
    list.setAttribute('aria-label', 'Plan comparison');
    plans.forEach((plan) => {
      const recommended = plan.name === content.recommended;
      const item = element('li', 'agui-asst__plan' + (recommended ? ' is-rec' : ''));
      if (recommended) {
        item.appendChild(element('span', 'agui-asst__plan-tag', 'Recommended'));
      }
      item.appendChild(element('span', 'agui-asst__plan-name', String(plan.name || '')));
      const price = element('span', 'agui-asst__plan-price', money(plan.price, content.currency));
      if (Number(plan.price)) {
        price.appendChild(element('small', '', '/mo'));
      }
      item.appendChild(price);
      item.appendChild(element('span', 'agui-asst__plan-seats', String(plan.seats || '') + ' seats'));
      list.appendChild(item);
    });
    host.replaceChildren(list);
    scrollDown();
  }

  // ---- the approval the run stopped for ---------------------------------
  function renderApproval(turn, interrupt) {
    const host = element('div', 'agui-asst__ui-block');
    turn.ui.appendChild(host);
    const card = element('div', 'agui-asst__confirm');
    const headingId = newId('agui-approval');
    card.setAttribute('role', 'group');
    card.setAttribute('aria-labelledby', headingId);

    const head = element('div', 'agui-asst__confirm-head');
    const badge = element('span', 'agui-asst__confirm-badge', 'Needs your approval');
    badge.id = headingId;
    head.append(badge, element('span', 'agui-asst__confirm-name', 'Confirm and send'));
    card.appendChild(head);
    if (interrupt.message) {
      card.appendChild(element('p', 'agui-asst__confirm-message', interrupt.message));
    }

    const args = conversation.toolArguments(interrupt.toolCallId);
    const summary = element('dl', 'agui-asst__confirm-summary');
    Object.entries(args).forEach(([key, value]) => {
      if (key === 'currency' || value === null || typeof value === 'object') {
        return;
      }
      const row = element('div', 'agui-asst__confirm-row');
      row.append(element('dt', '', label(key)), element('dd', '', key === 'price' ? money(value, args.currency) : String(value)));
      summary.appendChild(row);
    });
    if (summary.children.length) {
      card.appendChild(summary);
    }

    const confirmForm = element('form', 'agui-asst__confirm-form');
    const schema = interrupt.responseSchema || {};
    const required = new Set([...(schema.required || []), ...((schema.then && schema.then.required) || [])]);
    Object.entries(schema.properties || {}).forEach(([name, property]) => {
      if (name === 'approved' || name === 'editedArgs') {
        return;
      }
      const field = element('label', 'agui-asst__field');
      const input = element('input');
      input.name = name;
      input.type = property.format === 'email' ? 'email' : 'text';
      input.required = required.has(name);
      input.autocomplete = name === 'email' ? 'email' : (name === 'name' ? 'name' : 'off');
      if (property.maxLength) {
        input.maxLength = property.maxLength;
      }
      if (name === 'email') {
        input.placeholder = 'you@company.com';
      }
      field.append(element('span', '', property.title || label(name)), input);
      confirmForm.appendChild(field);
    });
    const error = element('p', 'agui-asst__err');
    error.setAttribute('role', 'alert');
    error.hidden = true;
    const actions = element('div', 'agui-asst__confirm-actions');
    const approve = element('button', 'agui-asst__approve', 'Confirm and send');
    approve.type = 'submit';
    const reject = element('button', 'agui-asst__reject', 'Not now');
    reject.type = 'button';
    actions.append(approve, reject);
    confirmForm.append(error, actions);
    card.appendChild(confirmForm);
    host.appendChild(card);
    scrollDown();

    pending = { interrupt, card, error };
    confirmForm.addEventListener('submit', (event) => {
      event.preventDefault();
      if (busy || !confirmForm.reportValidity()) {
        return;
      }
      const payload = { approved: true };
      confirmForm.querySelectorAll('input').forEach((input) => {
        if (input.value.trim() !== '') {
          payload[input.name] = input.value.trim();
        }
      });
      answer({ interruptId: interrupt.id, status: 'resolved', payload });
    });
    reject.addEventListener('click', () => {
      if (!busy) {
        answer({ interruptId: interrupt.id, status: 'cancelled' });
      }
    });
    const first = confirmForm.querySelector('input');
    if (first) {
      first.focus();
    }
  }

  function answer(entry) {
    if (!pending) {
      return;
    }
    pending.card.classList.add('is-done');
    pending.error.hidden = true;
    return run([entry]);
  }

  // ---- one run -----------------------------------------------------------
  async function run(resume) {
    setBusy(true);
    const node = bubble('agent');
    node.setAttribute('aria-busy', 'true');
    const turn = {
      provenance: element('div', 'agui-asst__provenance'),
      think: element('div', 'agui-asst__think'),
      text: element('div', 'agui-asst__text'),
      ui: element('div', 'agui-asst__ui'),
      activities: new Map(),
    };
    turn.provenance.hidden = true;
    turn.think.hidden = true;
    node.append(turn.provenance, turn.think, turn.text, turn.ui);

    const input = runInput({ threadId, messages: conversation.messages(), state, forwardedProps: { agentNexus: context }, resume });
    let ended = false;
    try {
      await runAgent(endpoint, input, (event) => {
        logEvent(event);
        conversation.apply(event);
        if (event.type === 'RUN_FINISHED' || event.type === 'RUN_ERROR') {
          ended = true;
        }
        handle(event, turn, Boolean(resume));
      });
      if (!ended) {
        turn.text.appendChild(element('span', 'agui-asst__err', UNAVAILABLE));
      }
    } catch (error) {
      turn.text.replaceChildren(element('span', 'agui-asst__err', UNAVAILABLE));
      if (pending && resume) {
        pending.card.classList.remove('is-done');
      }
    } finally {
      node.removeAttribute('aria-busy');
      setBusy(false);
      scrollDown();
    }
  }

  function handle(event, turn, resuming) {
    switch (event.type) {
      case 'CUSTOM':
        if (event.name === PROVENANCE && event.value) {
          turn.provenance.hidden = false;
          turn.provenance.className = 'agui-asst__provenance' + (event.value.mode === 'llm' ? ' is-live' : '');
          turn.provenance.textContent = event.value.label || (event.value.mode === 'llm' ? 'Live model' : 'Scripted demo');
          turn.provenance.title = event.value.reason || '';
        }
        break;
      case 'REASONING_START':
        turn.think.hidden = false;
        turn.think.replaceChildren(element('span', 'agui-asst__think-dot'), element('span', 'agui-asst__think-text'));
        turn.think.firstChild.setAttribute('aria-hidden', 'true');
        break;
      case 'REASONING_MESSAGE_CONTENT': {
        const text = turn.think.querySelector('.agui-asst__think-text');
        if (text) {
          text.textContent += event.delta;
        }
        break;
      }
      case 'REASONING_END':
        turn.think.classList.add('is-done');
        break;
      case 'TEXT_MESSAGE_CONTENT':
        turn.text.textContent += event.delta;
        scrollDown();
        break;
      case 'STATE_SNAPSHOT':
        state = event.snapshot;
        break;
      case 'STATE_DELTA':
        state = applyPatch(state, event.delta).value;
        break;
      case 'ACTIVITY_SNAPSHOT':
        if (event.activityType === PLAN_COMPARISON) {
          let activity = turn.activities.get(event.messageId);
          if (!activity) {
            activity = { host: element('div', 'agui-asst__ui-block'), content: event.content };
            turn.ui.appendChild(activity.host);
            turn.activities.set(event.messageId, activity);
          } else if (event.replace !== false) {
            activity.content = event.content;
          }
          renderPlans(activity.host, activity.content);
        }
        break;
      case 'ACTIVITY_DELTA': {
        const activity = turn.activities.get(event.messageId);
        if (activity) {
          activity.content = applyPatch(activity.content, event.patch).value;
          renderPlans(activity.host, activity.content);
        }
        break;
      }
      case 'RUN_FINISHED':
        finished(event, turn, resuming);
        break;
      case 'RUN_ERROR':
        if (resuming && pending && event.code === 'invalid_answer') {
          // The interrupt is still open: the visitor can correct the answer.
          pending.card.classList.remove('is-done');
          pending.error.textContent = event.message;
          pending.error.hidden = false;
          turn.text.textContent = event.message;
        } else {
          turn.text.replaceChildren(element('span', 'agui-asst__err', event.message || 'Something went wrong.'));
        }
        break;
      default:
        break;
    }
  }

  function finished(event, turn, resuming) {
    const outcome = event.outcome || { type: 'success' };
    if (outcome.type === 'interrupt' && Array.isArray(outcome.interrupts) && outcome.interrupts.length) {
      renderApproval(turn, outcome.interrupts[0]);
      return;
    }
    if (resuming) {
      pending = null;
    }
    const result = event.result && typeof event.result === 'object' ? event.result : null;
    if (result && result.status === 'sent') {
      const ok = element('div', 'agui-asst__success');
      const check = element('span', 'agui-asst__success-check');
      check.setAttribute('aria-hidden', 'true');
      check.innerHTML = '<svg viewBox="0 0 16 16" width="12" height="12"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3.5 8.5l3 3 6-7"/></svg>';
      ok.append(check, document.createTextNode(success));
      turn.ui.appendChild(ok);
    } else if (result && result.status === 'declined') {
      turn.ui.appendChild(element('div', 'agui-asst__note', 'Nothing was sent.'));
    }
    scrollDown();
  }

  async function ask(text) {
    const question = (text || intentEl.value || '').trim();
    if (busy || question === '') {
      return;
    }
    intentEl.value = '';
    if (pending) {
      // A new question sets the open approval aside: cancel it first, as
      // AG-UI asks — a thread cannot move on past an unanswered interrupt.
      await answer({ interruptId: pending.interrupt.id, status: 'cancelled' });
    }
    conversation.addUser(question);
    bubble('user').textContent = question;
    await run(null);
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    ask();
  });
}

function ready(fn) {
  if (document.readyState !== 'loading') {
    fn();
  } else {
    document.addEventListener('DOMContentLoaded', fn);
  }
}

ready(() => {
  document.querySelectorAll('[data-agui-assistant]').forEach(initAssistant);
});
