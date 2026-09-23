/**
 * A2UI catalogue — draws the live example of every component with the same
 * renderer the playground and the widget use. A button in an example has no
 * agent to talk to, so its action message is shown instead of being sent.
 */
import labels from '~labels/agent_nexus.a2ui';
import { A2uiRenderer } from '@webconsulting/agent-nexus/a2ui-renderer.js';
import { BACKEND_CLASSES, rendererLabels } from '@webconsulting/agent-nexus/a2ui-backend.js';

const root = document.querySelector('[data-a2ui-catalog]');
if (root) {
  const status = root.querySelector('[data-a2ui-action-status]');
  root.querySelectorAll('[data-a2ui-example]').forEach((mount) => {
    let messages = [];
    try {
      messages = JSON.parse(mount.dataset.a2uiMessages || '[]');
    } catch (e) {
      return;
    }
    const json = mount.parentElement.querySelector('[data-a2ui-example-json]');
    if (json) json.textContent = messages.map((message) => JSON.stringify(message)).join('\n');
    const renderer = new A2uiRenderer(mount, {
      headingBase: 5,
      classes: BACKEND_CLASSES,
      labels: rendererLabels(),
      onAction: async (message) => {
        if (status) status.textContent = labels.get('js.catalog.action', [message.action.name]);
        if (json) json.textContent = messages.concat([message]).map((m) => JSON.stringify(m)).join('\n');
        return [];
      },
    });
    renderer.processAll(messages);
  });
}
