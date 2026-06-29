/**
 * A2A Concierge (frontend).
 *
 * The visitor-facing twin of the backend A2A Console. The visitor picks a skill
 * (or types a request) and the widget POSTs an A2A `message/stream` JSON-RPC body
 * to the `a2a_concierge` eID, consuming the streamed Task lifecycle over SSE
 * (fetch + ReadableStream, since the call is a POST). The cooperative
 * `input-required` state renders an inline question; answering resumes the same
 * task. A finished task streams its artifact into a styled card.
 */

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function rid() { return Math.random().toString(36).slice(2, 9); }
function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }

function initConcierge(root) {
  const runUrl = root.dataset.runUrl;
  const page = root.dataset.page || '0';
  const showEvents = root.dataset.showEvents === '1';

  const form = root.querySelector('[data-a2a-form]');
  const intentEl = root.querySelector('[data-a2a-intent]');
  const sendBtn = root.querySelector('[data-a2a-send]');
  const threadEl = root.querySelector('[data-a2a-thread]');
  const eventsEl = root.querySelector('[data-a2a-events]');
  const eventsListEl = root.querySelector('[data-a2a-events-list]');
  const eventCountEl = root.querySelector('[data-a2a-eventcount]');

  let skill = 'summarize_page';
  root.querySelectorAll('[data-skill]').forEach((b) => {
    b.addEventListener('click', () => {
      root.querySelectorAll('[data-skill]').forEach((x) => x.classList.remove('is-active'));
      b.classList.add('is-active');
      skill = b.dataset.skill;
    });
  });
  if (showEvents) eventsEl.hidden = false;

  let busy = false;
  let eventCount = 0;
  let task = { id: '', contextId: '' };

  function bubble(role) {
    const b = document.createElement('div');
    b.className = 'a2a-cc__msg a2a-cc__msg--' + role;
    threadEl.appendChild(b); threadEl.scrollTop = threadEl.scrollHeight;
    return b;
  }
  function scrollDown() { threadEl.scrollTop = threadEl.scrollHeight; }

  function logFrame(result) {
    eventCount++; if (eventCountEl) eventCountEl.textContent = eventCount;
    if (!showEvents) return;
    const row = document.createElement('span'); row.className = 'a2a-cc__evt';
    row.textContent = result.kind === 'status-update' ? ('status:' + (result.status && result.status.state)) : result.kind;
    eventsListEl.appendChild(row); eventsListEl.scrollLeft = eventsListEl.scrollWidth;
  }

  async function delegate(resume, answer) {
    if (busy) return;
    busy = true; setBusy(true);

    const turn = bubble('agent');
    const status = document.createElement('div'); status.className = 'a2a-cc__status';
    const ui = document.createElement('div'); ui.className = 'a2a-cc__ui';
    turn.append(status, ui);
    let artifactBody = null;

    const message = {
      role: 'user',
      parts: [{ kind: 'text', text: resume ? answer : (intentLast || ('Run skill: ' + skill)) }],
      messageId: 'm-' + rid(),
      metadata: { skill, resume: !!resume, input: answer || '', page: Number(page), url: location.href },
    };
    if (resume && task.id) { message.taskId = task.id; message.contextId = task.contextId; }
    const body = { jsonrpc: '2.0', id: 1, method: 'message/stream', params: { message } };

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
          if (!line) continue;
          const j = line.slice(5).trim(); if (!j) continue;
          let env; try { env = JSON.parse(j); } catch { continue; }
          const r = env.result; if (!r) { if (env.error) status.innerHTML = '<span class="a2a-cc__err">⛔ ' + esc(env.error.message) + '</span>'; continue; }
          logFrame(r);
          if (r.kind === 'task') { task = { id: r.id, contextId: r.contextId }; setStatus(status, 'submitted', 'Task accepted'); }
          else if (r.kind === 'status-update') {
            const st = r.status && r.status.state;
            const m = r.status && r.status.message;
            const txt = (m && m.parts) ? m.parts.map((p) => p.text || '').join('') : '';
            if (st === 'input-required') { renderInput(ui, txt); }
            else if (txt) setStatus(status, st, txt);
            else if (st) setStatus(status, st, null);
          } else if (r.kind === 'artifact-update') {
            if (!r.append && r.artifact) { artifactBody = openArtifact(ui, r.artifact.name || 'artifact'); }
            if (r.artifact && r.artifact.parts && artifactBody) { r.artifact.parts.forEach((p) => { if (p.kind === 'text') artifactBody.textContent += p.text; }); scrollDown(); }
          }
        }
      }
    } catch (e) {
      status.innerHTML = '<span class="a2a-cc__err">⛔ The agent is unavailable right now. Please try again.</span>';
    } finally {
      busy = false; setBusy(false);
    }
  }

  function setStatus(host, state, text) {
    host.className = 'a2a-cc__status a2a-cc__status--' + (state || 'working');
    host.innerHTML = '<span class="a2a-cc__state">' + esc(state || '') + '</span>' + (text ? '<span class="a2a-cc__statustxt">' + esc(text) + '</span>' : '');
  }

  function renderInput(host, question) {
    const block = document.createElement('div'); block.className = 'a2a-cc__confirm';
    block.innerHTML =
      '<div class="a2a-cc__confirm-badge">⏸ One more detail</div>' +
      '<div class="a2a-cc__confirm-q">' + esc(question) + '</div>' +
      '<div class="a2a-cc__confirm-row"><input type="text" class="a2a-cc__confirm-field" placeholder="Type your answer…" />' +
      '<button type="button" class="a2a-cc__approve">Send</button></div>';
    host.appendChild(block); scrollDown();
    const field = block.querySelector('.a2a-cc__confirm-field');
    const go = () => { const v = field.value.trim(); block.classList.add('is-done'); delegate(true, v); };
    block.querySelector('.a2a-cc__approve').addEventListener('click', go);
    field.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    field.focus();
  }

  function openArtifact(host, name) {
    const block = document.createElement('div'); block.className = 'a2a-cc__artifact';
    block.innerHTML = '<div class="a2a-cc__artifact-head">📄 <span class="a2a-cc__artifact-name">' + esc(name) + '</span></div><div class="a2a-cc__artifact-body"></div>';
    host.appendChild(block); scrollDown();
    return block.querySelector('.a2a-cc__artifact-body');
  }

  function setBusy(on) { sendBtn.disabled = on; intentEl.disabled = on; sendBtn.classList.toggle('is-busy', on); }

  let intentLast = '';
  function start() {
    if (busy) return;
    intentLast = (intentEl.value || '').trim();
    const label = intentLast || ({ summarize_page: 'Summarise a page', draft_outreach: 'Draft an email', plan_onboarding: 'Plan onboarding' }[skill] || 'Run a task');
    const u = bubble('user'); u.textContent = label;
    intentEl.value = '';
    task = { id: '', contextId: '' };
    delegate(false, '');
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); start(); });
}

ready(() => { document.querySelectorAll('[data-a2a-concierge]').forEach(initConcierge); });
export {};
