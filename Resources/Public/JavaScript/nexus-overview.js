/**
 * Agent Nexus hub.
 *
 * The hub is server-rendered and complete without JavaScript — every number,
 * link and health state is already in the HTML. This adds two things the server
 * cannot: the stat counters ticking up on arrival, and copy-to-clipboard on the
 * seed command, which is the one thing an operator will want to take with them.
 */

import { countUpAll } from '@webconsulting/agent-nexus/nexus-motion.js';

function ready(fn) {
  document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn);
}

/** Turn the command block into a button that copies itself. */
function initCopy(root) {
  const block = root.querySelector('.anx-hub__cmd');
  const code = block?.querySelector('code');
  if (!block || !code || !navigator.clipboard) return;

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'anx-hub__copy';
  button.textContent = 'Copy';
  button.setAttribute('aria-label', 'Copy the seed command to the clipboard');
  block.appendChild(button);

  let reset = 0;
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(code.textContent.trim());
      button.textContent = 'Copied';
    } catch {
      button.textContent = 'Press Ctrl+C';
    }
    clearTimeout(reset);
    reset = setTimeout(() => { button.textContent = 'Copy'; }, 2000);
  });
}

ready(() => {
  const root = document.querySelector('[data-anx-overview]');
  if (!root) return;

  countUpAll(root);
  initCopy(root);
});

export {};
