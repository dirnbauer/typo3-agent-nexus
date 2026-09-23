/**
 * A2A task console (backend).
 *
 * The console is a client agent like any other: it reads the Agent Card, takes
 * the JSON-RPC interface the card lists for the chosen protocol version, and
 * calls it from the browser — SendStreamingMessage read with fetch() and a
 * ReadableStream (EventSource can only GET), SendMessage, GetTask and
 * CancelTask as plain JSON. Every request and every frame is listed as it
 * crosses the wire. A task that asks for input is answered with a message
 * that names its taskId.
 *
 * Both dialects are understood: A2A 1.0 (header A2A-Version: 1.0, frames
 * wrapped as {task} / {statusUpdate} / {artifactUpdate}) and A2A 0.3 (no
 * header, frames with a "kind").
 */
import Notification from '@typo3/backend/notification.js';
import labels from '~labels/agent_nexus.a2a';

const LEGACY_METHODS = {
  SendMessage: 'message/send',
  SendStreamingMessage: 'message/stream',
  GetTask: 'tasks/get',
  CancelTask: 'tasks/cancel',
};

const BADGES = {
  TASK_STATE_SUBMITTED: 'info',
  TASK_STATE_WORKING: 'info',
  TASK_STATE_INPUT_REQUIRED: 'warning',
  TASK_STATE_AUTH_REQUIRED: 'warning',
  TASK_STATE_COMPLETED: 'success',
  TASK_STATE_FAILED: 'danger',
  TASK_STATE_REJECTED: 'danger',
  TASK_STATE_CANCELED: 'default',
};

const TERMINAL = ['TASK_STATE_COMPLETED', 'TASK_STATE_FAILED', 'TASK_STATE_CANCELED', 'TASK_STATE_REJECTED'];

/** A 0.3 state ("input-required") or a 1.0 one, as the 1.0 enum value. */
function stateOf(value) {
  if (typeof value !== 'string' || value === '') {
    return '';
  }
  return value.startsWith('TASK_STATE_') ? value : 'TASK_STATE_' + value.toUpperCase().replace(/-/g, '_');
}

/** The text parts of a message or artifact, joined. */
function textOf(parts) {
  return Array.isArray(parts) ? parts.map((part) => (typeof part?.text === 'string' ? part.text : '')).join('') : '';
}

/**
 * One result — a 1.0 StreamResponse or SendMessageResponse, a 0.3 event, a
 * bare Task — as {type, …}.
 */
function normalise(result) {
  if (!result || typeof result !== 'object') {
    return { type: 'unknown', name: '?' };
  }
  if (result.task) return { type: 'task', name: 'task', task: result.task };
  if (result.statusUpdate) return { type: 'status', name: 'statusUpdate', event: result.statusUpdate };
  if (result.artifactUpdate) return { type: 'artifact', name: 'artifactUpdate', event: result.artifactUpdate };
  if (result.message) return { type: 'message', name: 'message', message: result.message };
  switch (result.kind) {
    case 'task': return { type: 'task', name: 'task', task: result };
    case 'status-update': return { type: 'status', name: 'status-update', event: result };
    case 'artifact-update': return { type: 'artifact', name: 'artifact-update', event: result };
    case 'message': return { type: 'message', name: 'message', message: result };
    default: break;
  }
  if (result.id && result.status) {
    return { type: 'task', name: 'Task', task: result };
  }
  return { type: 'unknown', name: '?' };
}

function element(tag, className = '', text = '') {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

class A2aConsole {
  constructor(root) {
    this.root = root;
    this.fallbackEndpoint = root.dataset.jsonrpcUrl;
    this.cardUrl = root.dataset.cardUrl;
    this.inspectorUrl = root.dataset.inspectorUrl;
    this.interfaces = [];
    this.requestId = 0;
    this.frames = 0;
    this.task = null;
    this.artifactText = '';

    const q = (selector) => root.querySelector(selector);
    this.form = q('[data-a2a-form]');
    this.text = q('[data-a2a-text]');
    this.method = q('[data-a2a-method]');
    this.version = q('[data-a2a-version]');
    this.sendButton = q('[data-a2a-send]');
    this.cardBody = q('[data-a2a-card]');
    this.stateBadge = q('[data-a2a-state]');
    this.taskEmpty = q('[data-a2a-task-empty]');
    this.facts = q('[data-a2a-facts]');
    this.taskIdField = q('[data-a2a-task-id]');
    this.contextIdField = q('[data-a2a-context-id]');
    this.skillField = q('[data-a2a-skill]');
    this.status = q('[data-a2a-status]');
    this.answerForm = q('[data-a2a-answer]');
    this.answerInput = this.answerForm.querySelector('input');
    this.question = q('[data-a2a-question]');
    this.artifact = q('[data-a2a-artifact]');
    this.artifactName = q('[data-a2a-artifact-name]');
    this.artifactBody = q('[data-a2a-artifact-body]');
    this.artifactSource = q('[data-a2a-artifact-source]');
    this.getButton = q('[data-a2a-get]');
    this.cancelButton = q('[data-a2a-cancel]');
    this.inspectLink = q('[data-a2a-inspect]');
    this.frameList = q('[data-a2a-frames]');
    this.framesEmpty = q('[data-a2a-frames-empty]');
    this.frameCount = q('[data-a2a-framecount]');
    this.announcer = q('[data-a2a-announce]');
    this.lastExample = this.text.value;

    this.form.addEventListener('submit', (event) => this.submit(event));
    this.answerForm.addEventListener('submit', (event) => this.answer(event));
    this.getButton.addEventListener('click', () => this.task && this.call('GetTask', { id: this.task.id }));
    this.cancelButton.addEventListener('click', () => this.task && this.call('CancelTask', { id: this.task.id }));
    q('[data-a2a-clear]').addEventListener('click', () => this.clearFrames());
    this.form.querySelectorAll('input[name="anx-a2a-skill"]').forEach((radio) => {
      radio.addEventListener('change', () => this.useExample(radio.dataset.example || ''));
    });

    this.loadCard();
  }

  /* ---- discovery ------------------------------------------------------------- */

  async loadCard() {
    try {
      const response = await fetch(this.cardUrl, { headers: { Accept: 'application/json' }, credentials: 'omit' });
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      const card = await response.json();
      this.interfaces = Array.isArray(card.supportedInterfaces) ? card.supportedInterfaces : [];
      this.renderCard(card);
    } catch (error) {
      this.cardBody.replaceChildren(element('p', 'text-danger', labels.get('js.card.error', { error: String(error.message || error) })));
    } finally {
      this.cardBody.setAttribute('aria-busy', 'false');
    }
  }

  renderCard(card) {
    const title = element('h3', 'h5 anx-a2a__card-name', card.name || '');
    title.append(' ', element('span', 'badge badge-default', labels.get('js.card.version', { version: String(card.version || '') })));

    const interfaces = element('ul', 'anx-a2a__interfaces');
    this.interfaces.forEach((item) => {
      const entry = element('li');
      entry.append(element('code', '', item.protocolBinding || ''), ' ', element('code', '', item.protocolVersion || ''), ' ', element('code', 'anx-a2a__url', item.url || ''));
      interfaces.append(entry);
    });

    const capabilities = element('ul', 'anx-a2a__flags');
    const flags = card.capabilities || {};
    [['streaming', 'js.card.streaming'], ['pushNotifications', 'js.card.push'], ['extendedAgentCard', 'js.card.extended']].forEach(([key, labelKey]) => {
      const entry = element('li');
      entry.append(
        element('span', flags[key] ? 'badge badge-success' : 'badge badge-default', labels.get(flags[key] ? 'js.card.yes' : 'js.card.no')),
        ' ',
        labels.get(labelKey),
      );
      capabilities.append(entry);
    });

    const skills = element('p', '', (card.skills || []).map((skill) => skill.name).join(' · '));

    this.cardBody.replaceChildren(
      title,
      element('p', 'text-variant', card.description || ''),
      element('h4', 'h6', labels.get('js.card.interfaces')),
      interfaces,
      element('h4', 'h6', labels.get('js.card.capabilities')),
      capabilities,
      element('h4', 'h6', labels.get('js.card.skills')),
      skills,
    );
  }

  /** The JSON-RPC interface the card lists for a version; the server-side URL until the card is read. */
  endpoint(version) {
    const match = this.interfaces.find((item) => item.protocolBinding === 'JSONRPC' && item.protocolVersion === version);
    return match?.url || this.fallbackEndpoint;
  }

  /* ---- sending ---------------------------------------------------------------- */

  useExample(example) {
    const current = this.text.value.trim();
    if (example && (current === '' || current === this.lastExample.trim())) {
      this.text.value = example;
    }
    this.lastExample = example || this.lastExample;
  }

  submit(event) {
    event.preventDefault();
    const text = this.text.value.trim();
    if (text === '') {
      this.text.focus();
      Notification.warning(labels.get('js.message.required'));
      return;
    }
    const skill = this.form.querySelector('input[name="anx-a2a-skill"]:checked')?.value || '';
    this.resetTask();
    this.call(this.method.value, { message: this.message(text, skill, null) });
  }

  answer(event) {
    event.preventDefault();
    const text = this.answerInput.value.trim();
    if (text === '' || !this.task) {
      this.answerInput.focus();
      Notification.warning(labels.get('js.answer.required'));
      return;
    }
    this.answerForm.hidden = true;
    this.answerInput.value = '';
    this.call(this.method.value, { message: this.message(text, '', this.task) });
  }

  /** A user message in the dialect of the chosen version. */
  message(text, skill, task) {
    const legacy = this.version.value === '0.3';
    const message = legacy
      ? { kind: 'message', messageId: 'console-' + crypto.randomUUID(), role: 'user', parts: [{ kind: 'text', text }] }
      : { messageId: 'console-' + crypto.randomUUID(), role: 'ROLE_USER', parts: [{ text }] };
    if (task) {
      message.taskId = task.id;
      message.contextId = task.contextId;
    }
    if (skill) {
      message.metadata = { skill };
    }
    return message;
  }

  async call(operation, params) {
    const version = this.version.value;
    const method = version === '0.3' ? (LEGACY_METHODS[operation] || operation) : operation;
    const request = { jsonrpc: '2.0', id: ++this.requestId, method, params };
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' };
    if (version !== '0.3') {
      headers['A2A-Version'] = version;
    }
    const url = this.endpoint(version);
    const started = performance.now();
    this.addFrame(labels.get('js.frame.request'), method + (headers['A2A-Version'] ? ' · A2A-Version: ' + headers['A2A-Version'] : ''), { url, headers, body: request }, 0, 'request');
    this.setBusy(true);

    try {
      const response = await fetch(url, { method: 'POST', headers, body: JSON.stringify(request), credentials: 'omit' });
      const type = response.headers.get('Content-Type') || '';
      if (type.includes('text/event-stream') && response.body) {
        await this.readStream(response.body, started);
        this.announce(labels.get('js.stream.closed', { state: this.stateLabel(this.task?.state) }));
      } else {
        this.handleEnvelope(await response.json(), started);
      }
    } catch (error) {
      Notification.error(labels.get('js.request.failed'), String(error.message || error));
    } finally {
      this.setBusy(false);
    }
  }

  async readStream(body, started) {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      let boundary = buffer.indexOf('\n\n');
      while (boundary >= 0) {
        const record = buffer.slice(0, boundary);
        buffer = buffer.slice(boundary + 2);
        const data = record.split('\n').filter((line) => line.startsWith('data:')).map((line) => line.slice(5).trimStart()).join('\n');
        if (data !== '') {
          try {
            this.handleEnvelope(JSON.parse(data), started);
          } catch {
            // Not JSON: nothing a client can act on.
          }
        }
        boundary = buffer.indexOf('\n\n');
      }
    }
  }

  handleEnvelope(envelope, started) {
    const offset = Math.round(performance.now() - started);
    if (envelope && envelope.error) {
      const error = envelope.error;
      this.addFrame(labels.get('js.frame.error'), String(error.code ?? ''), envelope, offset, 'danger');
      const text = labels.get('js.rpc.error', { code: String(error.code ?? ''), message: String(error.message ?? '') });
      this.status.textContent = text;
      Notification.error(labels.get('js.request.failed'), text);
      return;
    }
    const frame = normalise(envelope ? envelope.result : null);
    const state = stateOf(frame.task?.status?.state || frame.event?.status?.state || '');
    this.addFrame(frame.name, state, envelope, offset, this.tone(frame, state));
    this.apply(frame);
  }

  /* ---- the task panel ------------------------------------------------------------- */

  apply(frame) {
    switch (frame.type) {
      case 'task':
        this.showTask(frame.task);
        break;
      case 'status':
        this.showStatus(frame.event);
        break;
      case 'artifact':
        this.showArtifactChunk(frame.event);
        break;
      case 'message':
        this.status.textContent = textOf(frame.message.parts);
        break;
      default:
        break;
    }
  }

  showTask(task) {
    this.task = { id: task.id, contextId: task.contextId, state: stateOf(task.status?.state) };
    this.taskEmpty.hidden = true;
    this.facts.hidden = false;
    this.taskIdField.textContent = task.id || '';
    this.contextIdField.textContent = task.contextId || '';
    const skill = task.metadata?.skillId;
    this.skillField.textContent = skill || labels.get('js.skill.auto');
    this.setState(this.task.state);
    this.status.textContent = textOf(task.status?.message?.parts);
    const artifacts = Array.isArray(task.artifacts) ? task.artifacts : [];
    if (artifacts.length > 0) {
      const last = artifacts[artifacts.length - 1];
      this.openArtifact(last);
      this.artifactText = textOf(last.parts);
      this.artifactBody.textContent = this.artifactText;
    }
    this.toggleQuestion(this.task.state, this.status.textContent);
    this.getButton.disabled = false;
    this.cancelButton.disabled = TERMINAL.includes(this.task.state);
    const inspect = new URL(this.inspectorUrl, window.location.origin);
    inspect.searchParams.set('search', task.id);
    this.inspectLink.href = inspect.toString();
    this.inspectLink.hidden = false;
  }

  showStatus(event) {
    if (!this.task) {
      this.task = { id: event.taskId, contextId: event.contextId, state: '' };
    }
    const state = stateOf(event.status?.state);
    this.task.state = state;
    this.setState(state);
    const text = textOf(event.status?.message?.parts);
    if (text) {
      this.status.textContent = text;
    }
    if (event.metadata?.skillId) {
      this.skillField.textContent = event.metadata.skillId;
    }
    this.toggleQuestion(state, text);
    this.cancelButton.disabled = TERMINAL.includes(state);
  }

  showArtifactChunk(event) {
    const artifact = event.artifact || {};
    if (!event.append) {
      this.openArtifact(artifact);
      this.artifactText = '';
    }
    this.artifactText += textOf(artifact.parts);
    this.artifactBody.textContent = this.artifactText;
  }

  openArtifact(artifact) {
    this.artifact.hidden = false;
    this.artifactName.textContent = artifact.name || artifact.artifactId || '';
    const metadata = artifact.metadata || {};
    this.artifactSource.textContent = metadata.writtenBy === 'model'
      ? labels.get('js.artifact.model', { model: String(metadata.model || '') })
      : (metadata.writtenBy === 'script' ? labels.get('js.artifact.script') : '');
  }

  toggleQuestion(state, question) {
    const asks = state === 'TASK_STATE_INPUT_REQUIRED';
    this.answerForm.hidden = !asks;
    if (asks) {
      this.question.textContent = question || labels.get('task.answer');
      this.answerInput.focus();
    }
  }

  setState(state) {
    this.stateBadge.className = 'badge badge-' + (BADGES[state] || 'default');
    this.stateBadge.textContent = this.stateLabel(state);
    this.stateBadge.title = state;
    this.announce(labels.get('js.task.announce', { state: this.stateLabel(state) }));
  }

  stateLabel(state) {
    return state ? labels.get('state.' + state) : labels.get('task.none');
  }

  resetTask() {
    this.task = null;
    this.artifactText = '';
    this.taskEmpty.hidden = false;
    this.facts.hidden = true;
    this.status.textContent = '';
    this.answerForm.hidden = true;
    this.artifact.hidden = true;
    this.artifactBody.textContent = '';
    this.artifactSource.textContent = '';
    this.getButton.disabled = true;
    this.cancelButton.disabled = true;
    this.inspectLink.hidden = true;
    this.stateBadge.className = 'badge badge-default';
    this.stateBadge.textContent = labels.get('task.none');
  }

  setBusy(busy) {
    this.sendButton.disabled = busy;
    this.root.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  announce(text) {
    if (this.announcer) {
      this.announcer.textContent = text;
    }
  }

  /* ---- the frame list ----------------------------------------------------------- */

  tone(frame, state) {
    if (frame.type === 'task') return 'accent';
    if (frame.type === 'artifact') return 'muted';
    if (state === 'TASK_STATE_COMPLETED') return 'ok';
    if (state === 'TASK_STATE_FAILED' || state === 'TASK_STATE_REJECTED') return 'danger';
    if (state === 'TASK_STATE_INPUT_REQUIRED' || state === 'TASK_STATE_AUTH_REQUIRED') return 'warn';
    return 'accent';
  }

  addFrame(name, detail, payload, offset, tone) {
    this.frames += 1;
    this.framesEmpty.hidden = true;
    this.frameCount.textContent = String(this.frames);
    this.frameCount.title = labels.get('js.frames.count', { count: String(this.frames) });

    const item = element('li', 'anx-events__item anx-a2a__event anx-a2a__event--' + tone);
    const details = element('details');
    const summary = element('summary');
    summary.append(
      element('span', 'anx-events__index', String(this.frames)),
      element('code', 'anx-events__name', name),
      element('span', 'anx-a2a__event-detail', detail),
      element('span', 'anx-events__offset text-variant', labels.get('js.frame.offset', { ms: String(offset) })),
    );
    const pre = element('pre', 'anx-code');
    pre.tabIndex = 0;
    pre.append(element('code', '', JSON.stringify(payload, null, 2)));
    details.append(summary, pre);
    item.append(details);
    this.frameList.append(item);
    this.frameList.scrollTop = this.frameList.scrollHeight;
  }

  clearFrames() {
    this.frames = 0;
    this.frameList.replaceChildren();
    this.framesEmpty.hidden = false;
    this.frameCount.textContent = '0';
  }
}

document.querySelectorAll('[data-a2a-console]').forEach((root) => new A2aConsole(root));
