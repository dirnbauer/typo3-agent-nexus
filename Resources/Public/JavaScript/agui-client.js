/**
 * AG-UI Live Assistant (frontend).
 *
 * The visitor-facing twin of the backend Run Console. It POSTs a RunAgentInput to
 * the `agui_assistant` eID and consumes the agent's typed AG-UI event stream over
 * SSE (read via fetch + ReadableStream, since the run is a POST). Events drive a
 * chat-style thread: reasoning appears as a subtle "thinking" line, text streams in
 * token by token, a TOOL_CALL renders a generative UI card, and a `confirm_booking`
 * tool pauses the run for the visitor's explicit approval — nothing is captured
 * until they fill in their details and click Confirm. This is the whole AG-UI value
 * proposition made tangible on a real page.
 */

const HITL_TOOLS = ['confirm_booking', 'confirm_apply'];

function pointer(path) { return path.replace(/^\//, '').split('/').map((p) => p.replace(/~1/g, '/').replace(/~0/g, '~')); }
function applyPatch(obj, ops) {
  (ops || []).forEach((op) => {
    const parts = pointer(op.path); const last = parts.pop();
    let cur = obj; for (const p of parts) { if (cur[p] == null || typeof cur[p] !== 'object') cur[p] = {}; cur = cur[p]; }
    if (op.op === 'remove') { if (Array.isArray(cur)) cur.splice(Number(last), 1); else delete cur[last]; }
    else { cur[last] = op.value; }
  });
  return obj;
}
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function rid() { return Math.random().toString(36).slice(2, 9); }

function initAssistant(root) {
  const runUrl = root.dataset.runUrl;
  const scenario = root.dataset.scenario || 'plan';
  const page = root.dataset.page || '0';
  const success = root.dataset.success || 'Thank you — we have received your request.';
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

  const threadId = 't-' + rid();
  let busy = false;
  let state = {};
  let lastIntent = '';
  let eventCount = 0;
  const toolArgs = {};
  const toolNames = {};

  // ---- quick-start presets ----
  (root.dataset.presets || '').split('\n').map((s) => s.trim()).filter(Boolean).forEach((label) => {
    const chip = document.createElement('button');
    chip.type = 'button'; chip.className = 'agui-asst__preset'; chip.textContent = label;
    chip.addEventListener('click', () => { intentEl.value = label; start(label); });
    presetsEl.appendChild(chip);
  });
  if (!presetsEl.children.length) presetsWrap.hidden = true;
  if (showEvents) eventsEl.hidden = false;

  // ---- thread helpers ----
  function bubble(role) {
    const b = document.createElement('div');
    b.className = 'agui-asst__msg agui-asst__msg--' + role;
    threadEl.appendChild(b);
    threadEl.scrollTop = threadEl.scrollHeight;
    return b;
  }
  function scrollDown() { threadEl.scrollTop = threadEl.scrollHeight; }

  function logEvent(ev) {
    eventCount++; if (eventCountEl) eventCountEl.textContent = eventCount;
    if (!showEvents) return;
    const row = document.createElement('span');
    row.className = 'agui-asst__evt';
    row.textContent = ev.type;
    eventsListEl.appendChild(row);
    eventsListEl.scrollLeft = eventsListEl.scrollWidth;
  }

  // ---- generative UI: plan comparison ----
  function renderPlanCard(host, args) {
    const plans = args.plans || [];
    host.innerHTML = '<div class="agui-asst__plans">' + plans.map((p) =>
      '<div class="agui-asst__plan' + (p.name === args.recommended ? ' is-rec' : '') + '">' +
      (p.name === args.recommended ? '<span class="agui-asst__plan-tag">Recommended</span>' : '') +
      '<div class="agui-asst__plan-name">' + esc(p.name) + '</div>' +
      '<div class="agui-asst__plan-price">' + (p.price ? '€' + esc(p.price) + '<small>/mo</small>' : 'Free') + '</div>' +
      '<div class="agui-asst__plan-seats">' + esc(p.seats) + ' seats</div></div>').join('') + '</div>';
    scrollDown();
  }

  // ---- human-in-the-loop: capture details + approve/reject ----
  function renderHitl(host, toolCallId, name, args) {
    const isBooking = name === 'confirm_booking';
    const summary = Object.entries(args)
      .filter(([k]) => !['needs', 'action'].includes(k))
      .map(([k, v]) => '<div class="agui-asst__confirm-row"><span>' + esc(k) + '</span><b>' + esc(typeof v === 'object' ? JSON.stringify(v) : v) + '</b></div>').join('');
    const needs = Array.isArray(args.needs) ? args.needs : ['name', 'email'];
    const fields = needs.map((nm) => {
      const type = nm === 'email' ? 'email' : (nm.toLowerCase().includes('time') ? 'text' : 'text');
      const label = nm.charAt(0).toUpperCase() + nm.slice(1).replace(/([A-Z])/g, ' $1');
      return '<label class="agui-asst__field"><span>' + esc(label) + '</span>' +
        '<input type="' + type + '" data-need="' + esc(nm) + '" required ' +
        (nm === 'email' ? 'placeholder="you@company.com"' : '') + ' /></label>';
    }).join('');

    host.innerHTML =
      '<div class="agui-asst__confirm">' +
      '<div class="agui-asst__confirm-head"><span class="agui-asst__confirm-badge">⏸ Your approval</span>' +
      '<span class="agui-asst__confirm-name">' + esc(isBooking ? 'Confirm & send' : name) + '</span></div>' +
      '<div class="agui-asst__confirm-summary">' + summary + '</div>' +
      '<form class="agui-asst__confirm-form" data-confirm-form>' + fields +
      '<div class="agui-asst__confirm-actions">' +
      '<button type="submit" class="agui-asst__approve">Confirm &amp; send</button>' +
      '<button type="button" class="agui-asst__reject" data-reject>Not now</button>' +
      '</div></form></div>';
    scrollDown();

    const cForm = host.querySelector('[data-confirm-form]');
    cForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const lead = { plan: args.plan || null, scenario };
      host.querySelectorAll('[data-need]').forEach((inp) => { lead[inp.dataset.need] = inp.value.trim(); });
      host.querySelector('.agui-asst__confirm').classList.add('is-done');
      run({ approval: { toolCallId, decision: 'approved' }, lead });
    });
    host.querySelector('[data-reject]').addEventListener('click', () => {
      host.querySelector('.agui-asst__confirm').classList.add('is-done');
      run({ approval: { toolCallId, decision: 'rejected' }, lead: {} });
    });
  }

  // ---- a single run (propose, or apply after approval) ----
  async function run(extra) {
    if (busy) return;
    busy = true; setBusy(true);

    const turn = bubble('agent');
    const think = document.createElement('div'); think.className = 'agui-asst__think'; think.hidden = true;
    const text = document.createElement('div'); text.className = 'agui-asst__text';
    const ui = document.createElement('div'); ui.className = 'agui-asst__ui';
    turn.append(think, text, ui);

    const input = Object.assign({
      threadId, runId: 'r-' + rid(), preset: scenario, intent: lastIntent,
      page: Number(page), url: location.href, messages: [], tools: [], state,
    }, extra || {});

    let curText = '';
    try {
      const res = await fetch(runUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input) });
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
          if (!line) continue;
          const j = line.slice(5).trim(); if (!j) continue;
          let ev; try { ev = JSON.parse(j); } catch { continue; }
          logEvent(ev);
          switch (ev.type) {
            case 'REASONING_START': think.hidden = false; think.innerHTML = '<span class="agui-asst__think-dot"></span>'; break;
            case 'REASONING_MESSAGE_CONTENT': think.innerHTML = '<span class="agui-asst__think-dot"></span>' + esc((think.dataset.t = (think.dataset.t || '') + ev.delta)); break;
            case 'REASONING_END': break;
            case 'TEXT_MESSAGE_START': curText = ''; text.textContent = ''; break;
            case 'TEXT_MESSAGE_CONTENT': curText += ev.delta; text.textContent = curText; scrollDown(); break;
            case 'STATE_SNAPSHOT': state = ev.snapshot || {}; break;
            case 'STATE_DELTA': applyPatch(state, ev.delta); break;
            case 'TOOL_CALL_START': toolNames[ev.toolCallId] = ev.toolCallName; toolArgs[ev.toolCallId] = ''; break;
            case 'TOOL_CALL_ARGS': toolArgs[ev.toolCallId] = (toolArgs[ev.toolCallId] || '') + ev.delta; break;
            case 'TOOL_CALL_END': onToolEnd(ev.toolCallId, ui); break;
            case 'RUN_FINISHED': onFinished(ev, ui); break;
            case 'RUN_ERROR': text.innerHTML = '<span class="agui-asst__err">⛔ ' + esc(ev.message || 'Something went wrong.') + '</span>'; break;
            default: break;
          }
        }
      }
    } catch (e) {
      text.innerHTML = '<span class="agui-asst__err">⛔ The assistant is unavailable right now. Please try again.</span>';
    } finally {
      think.hidden = think.dataset.t ? false : true; // keep reasoning if it streamed, else hide
      busy = false; setBusy(false);
    }
  }

  function onToolEnd(id, ui) {
    let args = {}; try { args = JSON.parse(toolArgs[id] || '{}'); } catch { /* ignore */ }
    const name = toolNames[id] || '';
    // Each tool renders into its OWN block so a later tool (e.g. the HITL form)
    // never overwrites an earlier generative-UI card.
    const block = document.createElement('div'); block.className = 'agui-asst__ui-block'; ui.appendChild(block);
    if (name === 'render_plan_card') { renderPlanCard(block, args); }
    else if (HITL_TOOLS.includes(name)) { renderHitl(block, id, name, args); }
    else { block.remove(); }
  }

  function onFinished(ev, ui) {
    // Apply phase finished after approval → show the success confirmation.
    if (ev.result && (ev.result.updated || ev.result.simulated || ev.result.preset)) {
      const ok = document.createElement('div'); ok.className = 'agui-asst__success';
      ok.innerHTML = '<span class="agui-asst__success-check">✓</span> ' + esc(success);
      ui.appendChild(ok); scrollDown();
    } else if (ev.result && ev.result.decision === 'rejected') {
      const no = document.createElement('div'); no.className = 'agui-asst__note';
      no.textContent = 'No problem — nothing was sent.';
      ui.appendChild(no); scrollDown();
    }
  }

  function setBusy(on) {
    sendBtn.disabled = on; intentEl.disabled = on;
    sendBtn.classList.toggle('is-busy', on);
  }

  function start(intent) {
    if (busy) return;
    lastIntent = (intent || intentEl.value || '').trim();
    if (lastIntent) { const u = bubble('user'); u.textContent = lastIntent; }
    intentEl.value = '';
    run();
  }

  form.addEventListener('submit', (e) => { e.preventDefault(); start(); });
}

function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }
ready(() => { document.querySelectorAll('[data-agui-assistant]').forEach(initAssistant); });

export {};
