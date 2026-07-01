/**
 * AG-UI Run Console (backend).
 *
 * Sends a RunAgentInput to the backend SSE route and consumes the agent's typed
 * AG-UI event stream, rendering each event into a live timeline and driving the
 * draft pane, the shared-state inspector and the human-in-the-loop Approve/Reject
 * gate. SSE is read over `fetch()` (the run is a POST, so EventSource — GET only —
 * cannot be used).
 */

import { withGsap, reveal, countUpAll } from '@webconsulting/agent-nexus/nexus-motion.js';

// Entrance stagger for the anx shell (runs on Console and Catalog views alike).
const anxRoot = document.querySelector('.anx');
withGsap(anxRoot).then((g) => {
  if (!g || !anxRoot) return;
  reveal(g, anxRoot.querySelectorAll('.anx-reveal'));
  countUpAll(g, anxRoot);
});

const CATEGORY = {
  RUN_STARTED: 'lifecycle', RUN_FINISHED: 'lifecycle', RUN_ERROR: 'lifecycle', STEP_STARTED: 'lifecycle', STEP_FINISHED: 'lifecycle',
  TEXT_MESSAGE_START: 'text', TEXT_MESSAGE_CONTENT: 'text', TEXT_MESSAGE_END: 'text', TEXT_MESSAGE_CHUNK: 'text',
  TOOL_CALL_START: 'tool', TOOL_CALL_ARGS: 'tool', TOOL_CALL_END: 'tool', TOOL_CALL_RESULT: 'tool',
  STATE_SNAPSHOT: 'state', STATE_DELTA: 'state', MESSAGES_SNAPSHOT: 'state', ACTIVITY_SNAPSHOT: 'state', ACTIVITY_DELTA: 'state',
  REASONING_START: 'reason', REASONING_MESSAGE_CONTENT: 'reason', REASONING_END: 'reason',
  RAW: 'special', CUSTOM: 'special',
};
const HITL_TOOLS = ['confirm_apply', 'confirm_booking'];

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

function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }

ready(() => {
  const root = document.querySelector('[data-agui-console]');
  if (!root) return;

  const timelineEl = root.querySelector('[data-agui-timeline]');
  const draftEl = root.querySelector('[data-agui-draft]');
  const reasoningEl = root.querySelector('[data-agui-reasoning]');
  const stateEl = root.querySelector('[data-agui-state]');
  const hitlEl = root.querySelector('[data-agui-hitl]');
  const uiSlotEl = root.querySelector('[data-agui-uislot]');
  const countEl = root.querySelector('[data-agui-eventcount]');
  const runBtn = root.querySelector('[data-agui-run]');

  let preset = 'seo';
  root.querySelectorAll('[data-agui-preset]').forEach((b) => {
    b.addEventListener('click', () => {
      root.querySelectorAll('[data-agui-preset]').forEach((x) => x.classList.remove('active'));
      b.classList.add('active');
      preset = b.dataset.aguiPreset;
    });
  });

  const threadId = 't-' + Math.random().toString(36).slice(2, 8);
  let count = 0;
  let state = {};
  const toolArgs = {};
  const toolNames = {};

  function reset() {
    count = 0; state = {}; draftEl.innerHTML = ''; reasoningEl.innerHTML = ''; reasoningEl.classList.add('d-none');
    timelineEl.innerHTML = ''; hitlEl.innerHTML = ''; hitlEl.classList.add('d-none'); uiSlotEl.innerHTML = '';
    stateEl.textContent = '{}'; countEl.textContent = '0 events';
  }

  // Event kind → .anx-console__event modifier: accent for tool calls, ok for a
  // finished run, warn while a HITL tool awaits approval, danger for errors,
  // muted for the high-volume text/reasoning deltas.
  function eventModifier(ev) {
    const type = String(ev.type || '');
    if (type === 'RUN_ERROR') return 'danger';
    if (type === 'RUN_FINISHED') return 'ok';
    if (type.indexOf('TOOL_CALL') === 0) {
      if (type === 'TOOL_CALL_END' && HITL_TOOLS.includes(toolNames[ev.toolCallId] || '')) return 'warn';
      return 'accent';
    }
    const cat = CATEGORY[type] || 'special';
    return (cat === 'text' || cat === 'reason') ? 'muted' : '';
  }

  function addRow(ev) {
    count++; countEl.textContent = count + ' events';
    const mod = eventModifier(ev);
    const row = document.createElement('div');
    row.className = 'anx-console__event' + (mod ? ' anx-console__event--' + mod : '');
    const { type, ...rest } = ev;
    const kind = document.createElement('span');
    kind.className = 'anx-console__kind';
    kind.textContent = type;
    row.appendChild(kind);
    const payload = Object.keys(rest).length ? JSON.stringify(rest) : '';
    if (payload) { const p = document.createElement('span'); p.className = 'agui-evt__payload'; p.textContent = payload.length > 90 ? payload.slice(0, 90) + '…' : payload; row.appendChild(p); }
    timelineEl.appendChild(row);
    timelineEl.scrollTop = timelineEl.scrollHeight;
  }

  function renderState() { stateEl.textContent = JSON.stringify(state, null, 2); }

  function handle(ev) {
    addRow(ev);
    switch (ev.type) {
      case 'REASONING_START': reasoningEl.classList.remove('d-none'); reasoningEl.textContent = ''; break;
      case 'REASONING_MESSAGE_CONTENT': reasoningEl.textContent += ev.delta; break;
      case 'TEXT_MESSAGE_START': draftEl.textContent = ''; break;
      case 'TEXT_MESSAGE_CONTENT': draftEl.textContent += ev.delta; break;
      case 'STATE_SNAPSHOT': state = ev.snapshot || {}; renderState(); break;
      case 'STATE_DELTA': applyPatch(state, ev.delta); renderState(); break;
      case 'TOOL_CALL_START': toolNames[ev.toolCallId] = ev.toolCallName; toolArgs[ev.toolCallId] = ''; break;
      case 'TOOL_CALL_ARGS': toolArgs[ev.toolCallId] = (toolArgs[ev.toolCallId] || '') + ev.delta; break;
      case 'TOOL_CALL_END': onToolEnd(ev.toolCallId); break;
      case 'RUN_ERROR': addRunError(ev); break;
      default: break;
    }
  }

  function onToolEnd(id) {
    let args = {}; try { args = JSON.parse(toolArgs[id] || '{}'); } catch (e) { /* ignore */ }
    const name = toolNames[id] || '';
    if (HITL_TOOLS.includes(name)) {
      renderHitl(id, name, args);
    } else if (name === 'render_plan_card') {
      renderPlanCard(args);
    }
  }

  function renderPlanCard(args) {
    const plans = args.plans || [];
    uiSlotEl.innerHTML = '<div class="agui-plancards">' + plans.map((p) =>
      '<div class="agui-plancard' + (p.name === args.recommended ? ' is-rec' : '') + '">' +
      (p.name === args.recommended ? '<span class="agui-plancard__tag">Recommended</span>' : '') +
      '<div class="agui-plancard__name">' + p.name + '</div><div class="agui-plancard__price">€' + p.price + '<small>/mo</small></div>' +
      '<div class="agui-plancard__seats">' + p.seats + ' seats</div></div>').join('') + '</div>';
  }

  function renderHitl(toolCallId, name, args) {
    hitlEl.classList.remove('d-none');
    const summary = Object.entries(args).map(([k, v]) =>
      '<div class="agui-hitl__row"><span>' + k + '</span><b>' + (typeof v === 'object' ? JSON.stringify(v) : v) + '</b></div>').join('');
    hitlEl.innerHTML =
      '<div class="agui-hitl__head"><span class="anx-badge anx-badge--warn">Awaiting your approval</span>' +
      '<span class="anx-code">' + name + '</span></div>' +
      '<div class="agui-hitl__body">' + summary + '</div>' +
      '<div class="agui-hitl__actions"><button type="button" class="anx-btn anx-btn--primary anx-btn--sm" data-decision="approved">Approve &amp; apply</button>' +
      '<button type="button" class="anx-btn anx-btn--ghost anx-btn--sm" data-decision="rejected">Reject</button></div>';
    hitlEl.querySelectorAll('[data-decision]').forEach((b) => {
      b.addEventListener('click', () => {
        hitlEl.classList.add('d-none');
        run({ approval: { toolCallId, decision: b.dataset.decision } });
      });
    });
  }

  function addRunError(ev) { draftEl.innerHTML = '<div class="agui-error">' + (ev.message || 'Run failed') + ' <small>(' + (ev.code || '') + ')</small></div>'; }

  async function run(extra) {
    const url = window.TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls.agui_run : null;
    if (!url) { addRunError({ message: 'AJAX route unavailable', code: 'NO_ROUTE' }); return; }
    if (!extra) reset();
    runBtn.disabled = true;
    const input = Object.assign({ threadId, runId: 'r-' + Math.random().toString(36).slice(2, 8), preset, messages: [], tools: [], state }, extra || {});
    try {
      const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input) });
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
          if (line) { const j = line.slice(5).trim(); if (j) { try { handle(JSON.parse(j)); } catch (e) { /* ignore */ } } }
        }
      }
    } catch (e) {
      addRunError({ message: e.message, code: 'STREAM' });
    } finally {
      runBtn.disabled = false;
    }
  }

  runBtn.addEventListener('click', () => run());
});
