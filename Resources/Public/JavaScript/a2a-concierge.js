/**
 * A2A concierge (frontend).
 *
 * The visitor-facing client of the site's own A2A agent. The widget sends
 * SendStreamingMessage (A2A 1.0, header A2A-Version: 1.0) to the public
 * JSON-RPC endpoint and reads the Server-Sent Events with fetch() and a
 * ReadableStream, since the call is a POST. Its message metadata names the
 * content element, the page and the URL ("agentNexus"), so the endpoint can
 * load this element's settings on the server and file the task under the page.
 *
 * A task that asks for input shows the question inline; the answer is a new
 * message carrying the task's id, which continues the same task. The
 * artifact streams into a card. The strip under the thread lists the 1.0
 * frames as they arrive.
 */

const ICONS = {
  pause: '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M5 3h2v10H5zM9 3h2v10H9z"/></svg>',
  alert: '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M8 2.5 14.5 13.5h-13zM8 6.5v3M8 11.6v.1"/></svg>',
  file: '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" d="M4 1.5h5.5L12.5 4.5v10h-8.5zM9.5 1.5v3h3"/></svg>',
};

function esc(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function rid() {
  return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : Math.random().toString(36).slice(2) + Date.now().toString(36);
}
function ready(fn) {
  if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn);
}
/** TASK_STATE_INPUT_REQUIRED -> "input-required" (the CSS modifier and the words shown). */
function slugOf(state) {
  return String(state || '').replace(/^TASK_STATE_/, '').toLowerCase().replace(/_/g, '-');
}
function textOf(parts) {
  return Array.isArray(parts) ? parts.map((p) => (typeof p?.text === 'string' ? p.text : '')).join('') : '';
}

function initConcierge(root) {
  const endpoint = root.dataset.endpoint;
  const page = Number(root.dataset.page || '0');
  const ce = Number(root.dataset.ce || '0');
  const showEvents = root.dataset.showEvents === '1';

  const form = root.querySelector('[data-a2a-form]');
  const intentEl = root.querySelector('[data-a2a-intent]');
  const sendBtn = root.querySelector('[data-a2a-send]');
  const threadEl = root.querySelector('[data-a2a-thread]');
  const eventsEl = root.querySelector('[data-a2a-events]');
  const eventsListEl = root.querySelector('[data-a2a-events-list]');
  const eventCountEl = root.querySelector('[data-a2a-eventcount]');
  const chips = Array.from(root.querySelectorAll('[data-skill]'));

  // The first chip is shown active; a typed request is routed by the agent
  // until the visitor pins a skill by clicking a chip.
  let skill = chips[0] ? chips[0].dataset.skill : '';
  let skillPinned = false;
  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      chips.forEach((other) => {
        other.classList.toggle('is-active', other === chip);
        other.setAttribute('aria-pressed', other === chip ? 'true' : 'false');
      });
      skill = chip.dataset.skill;
      skillPinned = true;
    });
  });
  if (showEvents) eventsEl.hidden = false;

  let busy = false;
  let eventCount = 0;
  let requestId = 0;
  let task = null;
  let lastChip = null;

  function scrollDown() { threadEl.scrollTop = threadEl.scrollHeight; }
  function bubble(role) {
    const b = document.createElement('div');
    b.className = 'a2a-cc__msg a2a-cc__msg--' + role;
    threadEl.appendChild(b);
    scrollDown();
    return b;
  }

  /** The "under the hood" strip: one chip per frame, runs of artifact chunks folded into one. */
  function logFrame(result) {
    eventCount++;
    if (eventCountEl) eventCountEl.textContent = String(eventCount);
    if (!showEvents) return;
    let name = Object.keys(result)[0] || '?';
    const event = result[name] || {};
    const state = event.status ? event.status.state : '';
    if (name === 'artifactUpdate' && lastChip && lastChip.dataset.name === 'artifactUpdate') {
      lastChip.dataset.count = String(Number(lastChip.dataset.count) + 1);
      lastChip.textContent = 'artifactUpdate ×' + lastChip.dataset.count;
      return;
    }
    const chip = document.createElement('span');
    chip.className = 'a2a-cc__evt';
    chip.dataset.name = name;
    chip.dataset.count = '1';
    chip.textContent = state ? name + ' · ' + String(state).replace(/^TASK_STATE_/, '') : name;
    const json = JSON.stringify(result);
    chip.title = json.length > 400 ? json.slice(0, 400) + '…' : json;
    eventsListEl.appendChild(chip);
    eventsListEl.scrollLeft = eventsListEl.scrollWidth;
    lastChip = chip;
  }

  function setStatus(host, state, text) {
    const slug = slugOf(state);
    host.className = 'a2a-cc__status a2a-cc__status--' + (slug || 'working');
    host.innerHTML = '<span class="a2a-cc__state">' + esc(slug.replace(/-/g, ' ')) + '</span>'
      + (text ? '<span class="a2a-cc__statustxt">' + esc(text) + '</span>' : '');
  }

  function showError(host, text) {
    host.className = 'a2a-cc__status a2a-cc__status--failed';
    host.innerHTML = '<span class="a2a-cc__err">' + ICONS.alert + ' ' + esc(text) + '</span>';
  }

  function renderInput(host, question) {
    const block = document.createElement('div');
    block.className = 'a2a-cc__confirm';
    const fieldId = 'a2a-cc-answer-' + rid();
    block.innerHTML =
      '<div class="a2a-cc__confirm-badge">' + ICONS.pause + ' One more detail</div>' +
      '<label class="a2a-cc__confirm-q" for="' + fieldId + '">' + esc(question) + '</label>' +
      '<div class="a2a-cc__confirm-row"><input type="text" class="a2a-cc__confirm-field" id="' + fieldId + '" placeholder="Type your answer…" autocomplete="off" />' +
      '<button type="button" class="a2a-cc__approve">Send</button></div>';
    host.appendChild(block);
    scrollDown();
    const field = block.querySelector('.a2a-cc__confirm-field');
    const go = () => {
      const value = field.value.trim();
      if (value === '' || busy) { field.focus(); return; }
      block.classList.add('is-done');
      const user = bubble('user');
      user.textContent = value;
      delegate(value, true);
    };
    block.querySelector('.a2a-cc__approve').addEventListener('click', go);
    field.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); go(); } });
    field.focus();
  }

  function openArtifact(host, artifact) {
    const block = document.createElement('div');
    block.className = 'a2a-cc__artifact';
    const metadata = artifact.metadata || {};
    const source = metadata.writtenBy === 'model'
      ? 'Live model' + (metadata.model ? ' · ' + metadata.model : '')
      : (metadata.writtenBy === 'script' ? 'Scripted demo' : '');
    block.innerHTML = '<div class="a2a-cc__artifact-head">' + ICONS.file
      + ' <span class="a2a-cc__artifact-name">' + esc(artifact.name || 'artifact') + '</span>'
      + (source ? '<span class="a2a-cc__artifact-source">' + esc(source) + '</span>' : '')
      + '</div><div class="a2a-cc__artifact-body"></div>';
    host.appendChild(block);
    scrollDown();
    return block.querySelector('.a2a-cc__artifact-body');
  }

  function setBusy(on) {
    busy = on;
    sendBtn.disabled = on;
    intentEl.disabled = on;
    sendBtn.classList.toggle('is-busy', on);
  }

  function handle(result, status, ui, artifactRef) {
    if (result.task) {
      task = { id: result.task.id, contextId: result.task.contextId };
      const state = result.task.status ? result.task.status.state : 'TASK_STATE_SUBMITTED';
      setStatus(status, state, state === 'TASK_STATE_SUBMITTED' ? 'Task accepted' : null);
    } else if (result.statusUpdate) {
      const st = result.statusUpdate.status || {};
      const text = st.message ? textOf(st.message.parts) : '';
      if (st.state === 'TASK_STATE_INPUT_REQUIRED') {
        setStatus(status, st.state, null);
        renderInput(ui, text);
      } else {
        setStatus(status, st.state, text || null);
      }
    } else if (result.artifactUpdate) {
      const artifact = result.artifactUpdate.artifact || {};
      if (!result.artifactUpdate.append || !artifactRef.body) {
        artifactRef.body = openArtifact(ui, artifact);
      }
      artifactRef.body.textContent += textOf(artifact.parts);
      scrollDown();
    } else if (result.message) {
      setStatus(status, 'TASK_STATE_COMPLETED', textOf(result.message.parts));
    }
  }

  async function delegate(text, resume) {
    if (busy) return;
    setBusy(true);

    const turn = bubble('agent');
    const status = document.createElement('div');
    status.className = 'a2a-cc__status';
    const ui = document.createElement('div');
    ui.className = 'a2a-cc__ui';
    turn.append(status, ui);
    const artifactRef = { body: null };

    const metadata = { agentNexus: { ce, page, url: location.href } };
    if (!resume && (skillPinned || intentEl.dataset.fromChip === '1') && skill) {
      metadata.skill = skill;
    }
    const message = { messageId: 'widget-' + rid(), role: 'ROLE_USER', parts: [{ text }], metadata };
    if (resume && task) {
      message.taskId = task.id;
      message.contextId = task.contextId;
    }
    const body = { jsonrpc: '2.0', id: ++requestId, method: 'SendStreamingMessage', params: { message } };

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'A2A-Version': '1.0' },
        body: JSON.stringify(body),
      });
      const type = res.headers.get('Content-Type') || '';
      if (!type.includes('text/event-stream') || !res.body) {
        // Refused before the stream opened: a plain JSON-RPC error.
        const json = await res.json().catch(() => null);
        throw new Error(json && json.error ? json.error.message : 'HTTP ' + res.status);
      }
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        let boundary = buffer.indexOf('\n\n');
        while (boundary >= 0) {
          const record = buffer.slice(0, boundary);
          buffer = buffer.slice(boundary + 2);
          boundary = buffer.indexOf('\n\n');
          const data = record.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(5).trim()).join('');
          if (!data) continue;
          let envelope;
          try { envelope = JSON.parse(data); } catch { continue; }
          if (envelope.error) { showError(status, envelope.error.message || 'The agent stopped with an error.'); continue; }
          if (!envelope.result) continue;
          logFrame(envelope.result);
          handle(envelope.result, status, ui, artifactRef);
        }
      }
    } catch (error) {
      showError(status, error && error.message && !/^(HTTP|Failed to fetch|NetworkError)/.test(error.message)
        ? error.message
        : 'The agent is unavailable right now. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  function start() {
    if (busy) return;
    const typed = (intentEl.value || '').trim();
    const chip = chips.find((c) => c.dataset.skill === skill);
    // No text: run the active chip's skill with its first example.
    const text = typed || (chip ? (chip.dataset.example || chip.textContent.trim()) : '');
    if (!text) { intentEl.focus(); return; }
    intentEl.dataset.fromChip = typed ? '0' : '1';
    const user = bubble('user');
    user.textContent = typed || (chip ? chip.textContent.trim() : text);
    intentEl.value = '';
    task = null;
    lastChip = null;
    delegate(text, false);
  }

  form.addEventListener('submit', (event) => { event.preventDefault(); start(); });
}

ready(() => { document.querySelectorAll('[data-a2a-concierge]').forEach(initConcierge); });
export {};
