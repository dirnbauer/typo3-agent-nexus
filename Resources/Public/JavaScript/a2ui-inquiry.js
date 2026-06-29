/**
 * A2UI Smart Inquiry — frontend widget.
 *
 * The visitor describes what they need; we ask the agent (server-side) for a
 * tailored A2UI v1.0 surface and render it with the very same trusted client the
 * backend playground uses. When they submit, the resolved data model is sent
 * back and stored as an inquiry. Same renderer, same protocol — now in front of
 * real website visitors.
 */
import { A2UIClient } from './a2ui-renderer.js';

function ready(fn) {
  if (document.readyState !== 'loading') fn();
  else document.addEventListener('DOMContentLoaded', fn);
}

ready(() => {
  document.querySelectorAll('[data-a2ui-inquiry]').forEach(initWidget);
});

function initWidget(root) {
  const generateUrl = root.dataset.generateUrl;
  const submitUrl = root.dataset.submitUrl;
  const businessContext = root.dataset.businessContext || '';
  const page = root.dataset.page || '0';
  const successMessage = root.dataset.success || 'Thank you — we have received your request.';

  const form = root.querySelector('[data-a2ui-inquiry-form]');
  const input = root.querySelector('[data-a2ui-intent]');
  const presetsEl = root.querySelector('[data-a2ui-presets]');
  const liveEl = root.querySelector('[data-a2ui-live]');

  let lastPayload = null;
  let lastIntent = '';

  const client = new A2UIClient({
    onAction: (userAction) => submitInquiry(userAction),
  });

  // Build preset chips from the newline-separated list.
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

  // Hide the "Or start with" row when no presets are configured.
  const presetsWrap = root.querySelector('[data-a2ui-presets-wrap]');
  if (presetsWrap && presetsEl.children.length === 0) {
    presetsWrap.style.display = 'none';
  }

  function setBusy(busy, message) {
    if (busy) {
      liveEl.innerHTML = '<div class="a2ui-inquiry__status">' + (message || 'Building your form…') + '</div>';
    }
    root.querySelectorAll('button, input').forEach((el) => { el.disabled = busy; });
  }

  async function generate(intent) {
    intent = (intent || '').trim();
    if (!intent) return;
    lastIntent = intent;
    setBusy(true, 'Building your form…');
    try {
      const body = new URLSearchParams();
      body.set('intent', intent);
      body.set('businessContext', businessContext);
      body.set('page', page);
      const res = await fetch(generateUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      });
      if (res.status === 429) throw new Error('Too many requests — please wait a moment and try again.');
      if (!res.ok) throw new Error('Sorry, something went wrong. Please try again.');
      const data = await res.json();
      lastPayload = data.payload;
      setBusy(false);
      client.render(data.payload, liveEl);
    } catch (err) {
      setBusy(false);
      notice(err.message, 'error');
    }
  }

  async function submitInquiry(userAction) {
    setBusy(true, 'Sending…');
    try {
      const body = new URLSearchParams();
      body.set('intent', lastIntent);
      body.set('page', page);
      body.set('action', userAction ? userAction.name : '');
      body.set('surfaceId', (lastPayload && lastPayload.createSurface && lastPayload.createSurface.surfaceId) || '');
      body.set('payload', JSON.stringify(lastPayload || {}));
      body.set('data', JSON.stringify((userAction && userAction.context) || client.dataModel || {}));
      body.set('url', location.href);
      const res = await fetch(submitUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      });
      if (!res.ok) throw new Error('Could not send your request. Please try again.');
      notice(successMessage, 'success');
      if (form) form.reset();
    } catch (err) {
      setBusy(false);
      notice(err.message, 'error');
    } finally {
      root.querySelectorAll('button, input').forEach((el) => { el.disabled = false; });
    }
  }

  function notice(message, level) {
    liveEl.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'a2ui-inquiry__notice a2ui-inquiry__notice--' + (level || 'info');
    el.textContent = message;
    liveEl.appendChild(el);
  }

  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      generate(input.value);
    });
  }
}
