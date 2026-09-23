/**
 * A2UI playground — the backend screen that plays both ends of A2UI.
 *
 * It asks the agent for a surface (the backend route agentnexus_a2ui_generate,
 * which runs the same binding as POST /a2ui/surfaces), lists every message
 * numbered in the order it travelled, draws the surface with the renderer and
 * sends the renderer's actions back (agentnexus_a2ui_action), showing the
 * action, its metadata and the agent's answer in the same stream.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import labels from '~labels/agent_nexus.a2ui';
import { A2uiRenderer, capabilities } from '@webconsulting/agent-nexus/a2ui-renderer.js';
import { BACKEND_CLASSES, rendererLabels } from '@webconsulting/agent-nexus/a2ui-backend.js';

const JSON_HEADERS = { headers: { 'Content-Type': 'application/json; charset=utf-8' } };

class Playground {
  constructor(root) {
    this.root = root;
    this.form = root.querySelector('[data-a2ui-form]');
    this.intent = root.querySelector('[data-a2ui-intent]');
    this.example = root.querySelector('[data-a2ui-example]');
    this.generateButton = root.querySelector('[data-a2ui-generate]');
    this.resetButton = root.querySelector('[data-a2ui-reset]');
    this.status = root.querySelector('[data-a2ui-status]');
    this.notes = root.querySelector('[data-a2ui-notes]');
    this.notesList = root.querySelector('[data-a2ui-notes-list]');
    this.stream = root.querySelector('[data-a2ui-stream]');
    this.streamEmpty = root.querySelector('[data-a2ui-stream-empty]');
    this.provenance = root.querySelector('[data-a2ui-provenance]');
    this.data = root.querySelector('[data-a2ui-data]');
    this.count = 0;
    this.surfaceId = null;

    this.renderer = new A2uiRenderer(root.querySelector('[data-a2ui-mount]'), {
      headingBase: 3,
      classes: BACKEND_CLASSES,
      labels: rendererLabels(),
      onAction: (message, metadata) => this.send(message, metadata),
      onError: (message) => this.log('renderer', message),
      onChange: (surfaceId, dataModel) => { this.data.textContent = JSON.stringify(dataModel, null, 2); },
      onLifecycle: (type) => {
        if (type === 'deleted') {
          this.say(labels.get('js.status.deleted'));
          this.data.textContent = '{}';
        }
      },
    });

    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.generate();
    });
    this.example.addEventListener('change', () => {
      if (this.example.value !== '') this.intent.value = this.example.value;
    });
    this.resetButton.addEventListener('click', () => this.reset());
  }

  version() {
    const checked = this.form.querySelector('input[name="version"]:checked');
    return checked ? checked.value : 'v0.9.1';
  }

  async generate() {
    const intent = this.intent.value.trim();
    if (intent === '') {
      this.say(labels.get('js.status.intent'));
      this.intent.focus();
      return;
    }
    const version = this.version();
    this.reset(false);
    this.busy(true);
    this.say(labels.get('js.status.generating'));
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.agentnexus_a2ui_generate)
        .post({ intent, version, ...capabilities(version) }, JSON_HEADERS);
      const result = await response.resolve();
      result.messages.forEach((message) => this.log('agent', message));
      this.renderer.processAll(result.messages);
      this.surfaceId = result.surfaceId;
      this.data.textContent = JSON.stringify(this.renderer.dataModel(result.surfaceId) || {}, null, 2);
      this.provenance.textContent = result.provenance.label;
      this.provenance.parentElement.hidden = false;
      this.showNotes(result.notes || []);
      this.say(labels.get('js.status.generated', [result.surfaceId, String(result.messages.length), result.provenance.label]));
      this.resetButton.hidden = false;
    } catch (error) {
      await this.fail(error);
    } finally {
      this.busy(false);
    }
  }

  /** The renderer's action goes to the agent; the answer comes back into the renderer. */
  async send(message, metadata) {
    const body = Object.keys(metadata).length > 0 ? { ...message, metadata } : message;
    this.log('renderer', body);
    this.say(labels.get('js.status.sending'));
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.agentnexus_a2ui_action).post(body, JSON_HEADERS);
      const result = await response.resolve();
      result.messages.forEach((reply) => this.log('agent', reply));
      this.say(labels.get('js.status.answered', [String(result.messages.length)]));
      return result.messages;
    } catch (error) {
      await this.fail(error);
      return [];
    }
  }

  log(direction, message) {
    this.count += 1;
    const type = Object.keys(message).find((key) => key !== 'version' && key !== 'metadata') || 'message';
    const item = document.createElement('li');
    item.className = `anx-events__item anx-a2ui__message anx-a2ui__message--${direction}`;
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    const index = document.createElement('span');
    index.className = 'anx-events__index';
    index.textContent = String(this.count);
    const badge = document.createElement('span');
    badge.className = `badge ${direction === 'agent' ? 'badge-info' : 'badge-default'}`;
    badge.textContent = labels.get(direction === 'agent' ? 'js.stream.agent' : 'js.stream.renderer');
    const name = document.createElement('code');
    name.className = 'anx-events__name';
    name.textContent = type;
    // Spaces between the parts keep the summary readable as one sentence.
    summary.append(index, ' ', badge, ' ', name);
    if (message.metadata) {
      const extra = document.createElement('span');
      extra.className = 'text-variant';
      extra.textContent = labels.get('js.stream.metadata');
      summary.append(' ', extra);
    }
    const pre = document.createElement('pre');
    pre.className = 'anx-code';
    pre.tabIndex = 0;
    const code = document.createElement('code');
    code.textContent = JSON.stringify(message, null, 2);
    pre.appendChild(code);
    details.append(summary, pre);
    item.appendChild(details);
    this.stream.appendChild(item);
    this.stream.hidden = false;
    this.streamEmpty.hidden = true;
  }

  showNotes(notes) {
    this.notesList.replaceChildren(...notes.map((note) => {
      const li = document.createElement('li');
      li.textContent = note;
      return li;
    }));
    this.notes.hidden = notes.length === 0;
  }

  reset(clearIntent = true) {
    this.renderer.clear();
    this.stream.replaceChildren();
    this.stream.hidden = true;
    this.streamEmpty.hidden = false;
    this.count = 0;
    this.surfaceId = null;
    this.data.textContent = '{}';
    this.provenance.parentElement.hidden = true;
    this.showNotes([]);
    this.resetButton.hidden = true;
    if (clearIntent) {
      this.status.textContent = '';
      this.intent.focus();
    }
  }

  busy(on) {
    this.generateButton.disabled = on;
    this.root.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  say(text) {
    this.status.textContent = text;
  }

  async fail(error) {
    let message = error instanceof Error ? error.message : '';
    if (error && typeof error.resolve === 'function') {
      try {
        const body = await error.resolve('json');
        message = body && body.error ? `${body.error.code}: ${body.error.message}` : message;
      } catch (e) {
        /* not JSON */
      }
    }
    this.say(labels.get('js.status.failed', [message]));
    Notification.error(labels.get('js.notification.failed'), message);
  }
}

document.querySelectorAll('[data-a2ui-playground]').forEach((root) => new Playground(root));
