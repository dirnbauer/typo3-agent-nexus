/**
 * A2UI Smart Inquiry — frontend widget.
 *
 * The visitor describes what they need; the widget asks the agent for a
 * surface over the public A2UI binding (POST …/a2ui/surfaces) exactly as any
 * other client would, and draws it with the same renderer the backend uses.
 * When the visitor sends the form, the renderer's action message — with the
 * form's data model in the metadata — goes to POST …/a2ui/actions, and the
 * agent's answer (a confirmation, or deleteSurface for "Start over") is drawn
 * in its place.
 *
 * The request names this content element and page (`agentNexus`), so the
 * server loads the element's own settings — business context, confirmation
 * text — and stores the surface on the right page. Nothing that shapes the
 * prompt comes from the browser.
 */
import { A2uiRenderer, capabilities } from './a2ui-renderer.js';

const TEXT = {
  building: 'Building your form…',
  sending: 'Sending…',
  ready: 'Your form is ready.',
  again: 'Describe what you need to start again.',
  tooMany: 'Too many requests. Please wait a moment and try again.',
  failed: 'Something went wrong. Please try again.',
  notSent: 'Could not send your request. Please try again.',
  gone: 'This form has expired. Please build a new one.',
};

function ready(fn) {
  if (document.readyState !== 'loading') fn();
  else document.addEventListener('DOMContentLoaded', fn);
}

ready(() => {
  document.querySelectorAll('[data-a2ui-inquiry]').forEach(initWidget);
});

function initWidget(root) {
  const surfacesUrl = root.dataset.surfacesUrl;
  const actionsUrl = root.dataset.actionsUrl;
  const version = root.dataset.version || 'v0.9.1';
  const form = root.querySelector('[data-a2ui-inquiry-form]');
  const input = root.querySelector('[data-a2ui-intent]');
  const presetsEl = root.querySelector('[data-a2ui-presets]');
  const presetsWrap = root.querySelector('[data-a2ui-presets-wrap]');
  const feedback = root.querySelector('[data-a2ui-feedback]');
  const liveEl = root.querySelector('[data-a2ui-live]');
  const provenance = root.querySelector('[data-a2ui-provenance]');
  let surfaceId = null;

  const context = () => ({
    ce: Number(root.dataset.ce || 0),
    page: Number(root.dataset.page || 0),
    url: window.location.href,
  });

  const renderer = new A2uiRenderer(liveEl, {
    headingBase: 3,
    onAction: send,
    onLifecycle: (type) => {
      if (type === 'deleted') {
        surfaceId = null;
        provenance.hidden = true;
        say(TEXT.again);
        input.focus();
      }
    },
  });

  // Preset chips from the newline-separated list.
  (root.dataset.presets || '')
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean)
    .forEach((preset) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'a2ui-inquiry__preset';
      chip.textContent = preset;
      chip.addEventListener('click', () => {
        input.value = preset;
        generate(preset);
      });
      presetsEl.appendChild(chip);
    });
  if (presetsWrap && presetsEl.children.length === 0) {
    presetsWrap.hidden = true;
  }

  function busy(on, message) {
    root.setAttribute('aria-busy', on ? 'true' : 'false');
    form.querySelectorAll('button, input').forEach((el) => { el.disabled = on; });
    presetsEl.querySelectorAll('button').forEach((el) => { el.disabled = on; });
    if (on) {
      feedback.replaceChildren();
      const status = document.createElement('div');
      status.className = 'a2ui-inquiry__status';
      status.textContent = message;
      feedback.appendChild(status);
    }
  }

  function say(message, level) {
    feedback.replaceChildren();
    if (!message) return;
    const el = document.createElement('div');
    el.className = level ? `a2ui-inquiry__notice a2ui-inquiry__notice--${level}` : 'a2ui-visually-hidden';
    if (level === 'error') el.setAttribute('role', 'alert');
    el.textContent = message;
    feedback.appendChild(el);
  }

  async function post(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...body, agentNexus: context() }),
    });
    let data = null;
    try {
      data = await response.json();
    } catch (e) {
      data = null;
    }
    return { status: response.status, ok: response.ok, data };
  }

  async function generate(intent) {
    intent = (intent || '').trim();
    if (!intent) {
      input.focus();
      return;
    }
    busy(true, TEXT.building);
    renderer.clear();
    provenance.hidden = true;
    try {
      const { status, ok, data } = await post(surfacesUrl, {
        intent,
        version,
        locale: document.documentElement.lang || 'en',
        ...capabilities(version),
      });
      if (status === 429) throw new Error(TEXT.tooMany);
      if (!ok || !data || !Array.isArray(data.messages)) throw new Error(TEXT.failed);
      busy(false);
      surfaceId = data.surfaceId;
      renderer.processAll(data.messages);
      if (data.provenance && data.provenance.label) {
        provenance.textContent = data.provenance.label;
        provenance.hidden = false;
      }
      say(TEXT.ready);
      renderer.focus(surfaceId);
    } catch (error) {
      busy(false);
      say(error.message || TEXT.failed, 'error');
    }
  }

  /** The renderer's action goes to the agent; its answer goes back into the renderer. */
  async function send(message, metadata) {
    say(TEXT.sending);
    try {
      const { status, ok, data } = await post(actionsUrl, Object.keys(metadata).length > 0 ? { ...message, metadata } : message);
      if (status === 429) throw new Error(TEXT.tooMany);
      if (status === 404 || status === 409 || status === 410) throw new Error(TEXT.gone);
      if (!ok || !data || !Array.isArray(data.messages)) throw new Error(TEXT.notSent);
      say('');
      // The renderer applies the answer right after this resolves; move focus
      // to what it drew (the confirmation) once it is there.
      window.setTimeout(() => { if (surfaceId) renderer.focus(surfaceId); }, 0);
      return data.messages;
    } catch (error) {
      say(error.message || TEXT.notSent, 'error');
      return [];
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    generate(input.value);
  });
}
