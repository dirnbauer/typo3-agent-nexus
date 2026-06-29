/**
 * A2A Console (backend).
 *
 * Plays a *client agent*: it fetches the site's Agent Card, then delegates a task
 * by POSTing an A2A `message/stream` JSON-RPC request to the backend route and
 * consuming the streamed Task lifecycle (task → status-update → artifact-update).
 * The cooperative `input-required` state renders an inline question the operator
 * answers, which resumes the same task. SSE is read over fetch() (the call is a
 * POST, so EventSource — GET only — cannot be used).
 */

const KIND_CLASS = { task: 'task', 'status-update': 'status', 'artifact-update': 'artifact', message: 'message' };
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function rid() { return Math.random().toString(36).slice(2, 9); }
function ready(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }

ready(() => {
  const root = document.querySelector('[data-a2a-console]');
  if (!root) return;

  const timelineEl = root.querySelector('[data-a2a-timeline]');
  const countEl = root.querySelector('[data-a2a-eventcount]');
  const stateEl = root.querySelector('[data-a2a-state]');
  const msgEl = root.querySelector('[data-a2a-msg]');
  const inputEl = root.querySelector('[data-a2a-input]');
  const artifactEl = root.querySelector('[data-a2a-artifact]');
  const cardEl = root.querySelector('[data-a2a-card]');
  const sendBtn = root.querySelector('[data-a2a-send]');

  let skill = 'summarize_page';
  root.querySelectorAll('[data-a2a-skill]').forEach((b) => {
    b.addEventListener('click', () => {
      root.querySelectorAll('[data-a2a-skill]').forEach((x) => x.classList.remove('active'));
      b.classList.add('active');
      skill = b.dataset.a2aSkill;
    });
  });

  let count = 0;
  let task = { id: '', contextId: '' };
  let artifactOpen = false;
  let artifactBody = null;

  // ---- Agent Card discovery ----
  (async () => {
    try {
      const res = await fetch(root.dataset.cardUrl, { headers: { Accept: 'application/json' } });
      const card = await res.json();
      cardEl.innerHTML =
        '<div class="a2a-card__head"><span class="a2a-card__name">' + esc(card.name) + '</span>' +
        '<span class="a2a-card__ver">v' + esc(card.version) + ' · ' + esc(card.protocolVersion) + '</span>' +
        (card.capabilities && card.capabilities.streaming ? '<span class="a2a-card__cap">streaming</span>' : '') + '</div>' +
        '<div class="a2a-card__desc">' + esc(card.description) + '</div>' +
        '<div class="a2a-card__skills">' + (card.skills || []).map((s) =>
          '<span class="a2a-card__skill">' + esc(s.name) + '</span>').join('') + '</div>';
    } catch (e) {
      cardEl.innerHTML = '<span class="a2a-msg__err">Could not load the Agent Card.</span>';
    }
  })();

  function reset() {
    count = 0; countEl.textContent = '0 frames';
    timelineEl.innerHTML = '';
    msgEl.innerHTML = '';
    inputEl.innerHTML = ''; inputEl.classList.add('d-none');
    artifactEl.innerHTML = ''; artifactEl.classList.add('d-none');
    artifactOpen = false; artifactBody = null;
    setState('idle');
  }

  function setState(s) { stateEl.textContent = s; stateEl.className = 'a2a-state a2a-state--' + s; }

  function addFrame(result) {
    count++; countEl.textContent = count + (count === 1 ? ' frame' : ' frames');
    const kind = result.kind || 'message';
    const row = document.createElement('div');
    row.className = 'a2a-evt a2a-kind--' + (KIND_CLASS[kind] || 'message');
    let label = kind;
    if (kind === 'status-update') label = 'status: ' + (result.status && result.status.state);
    row.innerHTML = '<span class="a2a-evt__kind">' + esc(label) + '</span>';
    const payload = JSON.stringify(result);
    const p = document.createElement('span'); p.className = 'a2a-evt__payload';
    p.textContent = payload.length > 110 ? payload.slice(0, 110) + '…' : payload;
    row.appendChild(p);
    timelineEl.appendChild(row);
    timelineEl.scrollTop = timelineEl.scrollHeight;
  }

  function addMessage(text, accent) {
    const line = document.createElement('div'); line.className = 'a2a-msg__line';
    line.innerHTML = '<span class="a2a-msg__dot" style="--c:' + (accent || '#d97706') + '"></span><span>' + esc(text) + '</span>';
    if (msgEl.querySelector('.text-body-secondary')) msgEl.innerHTML = '';
    msgEl.appendChild(line);
  }

  function renderInputRequired(question) {
    setState('input-required');
    inputEl.classList.remove('d-none');
    inputEl.innerHTML =
      '<span class="a2a-input__badge">⏸ input-required</span>' +
      '<div class="a2a-input__q">' + esc(question) + '</div>' +
      '<div class="a2a-input__row"><input type="text" class="a2a-input__field" data-a2a-answer placeholder="Type your answer…" />' +
      '<button type="button" class="btn btn-primary btn-sm" data-a2a-answer-send>Send</button></div>';
    const field = inputEl.querySelector('[data-a2a-answer]');
    const go = () => { const v = field.value.trim(); inputEl.classList.add('d-none'); delegate(true, v); };
    inputEl.querySelector('[data-a2a-answer-send]').addEventListener('click', go);
    field.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    field.focus();
  }

  function openArtifact(name) {
    artifactEl.classList.remove('d-none');
    artifactEl.innerHTML = '<div class="a2a-artifact__head">📄 Artifact <span class="a2a-artifact__name">' + esc(name) + '</span></div><div class="a2a-artifact__body" data-a2a-artbody></div>';
    artifactBody = artifactEl.querySelector('[data-a2a-artbody]');
    artifactOpen = true;
  }

  function handle(result) {
    addFrame(result);
    switch (result.kind) {
      case 'task':
        task = { id: result.id, contextId: result.contextId };
        setState(result.status && result.status.state || 'submitted');
        break;
      case 'status-update': {
        const st = result.status && result.status.state;
        if (st) setState(st);
        const m = result.status && result.status.message;
        if (m && m.parts) { const txt = m.parts.map((p) => p.text || '').join(''); if (txt) addMessage(txt, st === 'completed' ? '#16a34a' : (st === 'input-required' ? '#7c3aed' : '#d97706')); }
        if (st === 'input-required') {
          const q = (m && m.parts && m.parts.map((p) => p.text || '').join('')) || 'More detail needed.';
          renderInputRequired(q);
        }
        break;
      }
      case 'artifact-update':
        if (!result.append && result.artifact) openArtifact(result.artifact.name || 'artifact');
        if (result.artifact && result.artifact.parts && artifactBody) {
          result.artifact.parts.forEach((p) => { if (p.kind === 'text') artifactBody.textContent += p.text; });
          artifactBody.scrollTop = artifactBody.scrollHeight;
        }
        break;
      default: break;
    }
  }

  async function delegate(resume, answer) {
    const url = window.TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls ? TYPO3.settings.ajaxUrls.a2a_send : null;
    if (!url) { addMessage('AJAX route unavailable', '#dc2626'); return; }
    if (!resume) { reset(); task = { id: '', contextId: '' }; }
    sendBtn.disabled = true;

    const message = {
      role: 'user',
      parts: [{ kind: 'text', text: resume ? answer : ('Run skill: ' + skill) }],
      messageId: 'm-' + rid(),
      metadata: { skill, resume: !!resume, input: answer || '' },
    };
    if (resume && task.id) { message.taskId = task.id; message.contextId = task.contextId; }

    const body = { jsonrpc: '2.0', id: 1, method: 'message/stream', params: { message } };
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
          if (!line) continue;
          const j = line.slice(5).trim(); if (!j) continue;
          let env; try { env = JSON.parse(j); } catch { continue; }
          if (env.result) handle(env.result);
          else if (env.error) addMessage('Error: ' + env.error.message, '#dc2626');
        }
      }
    } catch (e) {
      addMessage('Stream failed: ' + e.message, '#dc2626');
    } finally {
      sendBtn.disabled = false;
    }
  }

  sendBtn.addEventListener('click', () => delegate(false, ''));
});
