/**
 * AP2 Trusted Surface (frontend).
 *
 * The visitor approves a shopping list and a spending cap. The surface posts
 * to POST /api/agent-nexus/ap2/authorize, where every AP2 role runs with its
 * sandbox key: the Trusted Surface signs the open mandates, the merchant signs
 * the checkout, the agent closes both mandates with its own key, the credential
 * provider, the merchant and the payment processor check them and sign
 * receipts. The surface shows that chain step by step. Nothing is charged.
 *
 * Built with DOM methods and textContent only; tokens are shown, never parsed
 * as markup.
 */

function el(tag, attributes = {}, ...children) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    if (value === null || value === undefined || value === false) {
      continue;
    }
    if (name === 'class') {
      node.className = value;
    } else if (name === 'text') {
      node.textContent = value;
    } else {
      node.setAttribute(name, value === true ? '' : String(value));
    }
  }
  for (const child of children.flat()) {
    if (child !== null && child !== undefined && child !== false) {
      node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
  }
  return node;
}

const STATUS = {
  done: { icon: '✓', text: 'Done', tone: 'pass' },
  passed: { icon: '✓', text: 'Passed', tone: 'pass' },
  failed: { icon: '✕', text: 'Failed', tone: 'fail' },
  skipped: { icon: '–', text: 'Skipped', tone: 'skip' },
};

const ROLES = {
  'trusted-surface': 'Trusted Surface',
  'shopping-agent': 'Shopping agent',
  merchant: 'Merchant',
  'credential-provider': 'Credential provider',
  'payment-processor': 'Payment processor',
};

/** An icon for the eye and a word for everyone: status never by colour alone. */
function marker(pass, text) {
  return el('span', { class: 'ap2-ts__marker ' + (pass ? 'is-pass' : 'is-fail') },
    el('span', { class: 'ap2-ts__marker-icon', 'aria-hidden': 'true', text: pass ? '✓' : '✕' }),
    el('span', { class: 'ap2-ts__visually-hidden', text: text }),
  );
}

function roleOf(artefact) {
  return artefact.roleLabel || ROLES[artefact.role] || artefact.role;
}

function tokenDetails(artefact) {
  return el('details', { class: 'ap2-ts__token' },
    el('summary', {}, artefact.title, ' ', el('span', { class: 'ap2-ts__muted', text: '(' + roleOf(artefact) + ')' })),
    el('p', { class: 'ap2-ts__label', text: 'Compact token' }),
    el('pre', { tabindex: '0' }, el('code', { text: artefact.token })),
    el('p', { class: 'ap2-ts__label', text: 'What it says' }),
    el('pre', { tabindex: '0' }, el('code', { text: JSON.stringify(artefact.claims, null, 2) })),
    artefact.disclosures && artefact.disclosures.length
      ? [
        el('p', { class: 'ap2-ts__label', text: 'Disclosures the holder revealed' }),
        el('ul', { class: 'ap2-ts__disclosures' }, artefact.disclosures.map((disclosure) => el('li', {},
          el('code', { text: disclosure.name || 'array element' }), ': ',
          el('span', { text: typeof disclosure.value === 'string' ? disclosure.value.slice(0, 80) + (disclosure.value.length > 80 ? '…' : '') : JSON.stringify(disclosure.value) }),
        ))),
      ]
      : null,
  );
}

function checksList(verification) {
  return el('ul', { class: 'ap2-ts__checks' }, (verification.checks || []).map((check) => el('li', { class: 'ap2-ts__check ' + (check.pass ? 'is-pass' : 'is-fail') },
    marker(check.pass, check.pass ? 'Passed:' : 'Failed:'),
    el('span', { class: 'ap2-ts__check-label', text: check.label }),
    check.detail ? el('span', { class: 'ap2-ts__check-detail', text: check.detail }) : null,
  )));
}

function renderResult(result, showTokens) {
  const artefacts = result.artefacts || {};
  const verifications = result.verifications || {};
  const receipts = result.receipts || {};

  const verdict = el('div', { class: 'ap2-ts__verdict ' + (result.authorised ? 'is-ok' : 'is-no'), role: 'status' },
    el('span', { 'aria-hidden': 'true', text: result.authorised ? '✓ ' : '✕ ' }),
    result.authorised ? 'Payment authorised' : 'Payment refused',
    el('small', { text: result.authorised
      ? 'The mandate chain is valid. Simulated: nothing was charged.'
      : 'A check failed, so no payment would be made.' }),
  );

  const summary = el('p', { class: 'ap2-ts__summary', text: result.summary });
  const fallback = result.fallback
    ? el('p', { class: 'ap2-ts__fallback' }, el('strong', { text: 'Your approval is needed. ' }), result.fallback.message)
    : null;
  const note = result.note ? el('p', { class: 'ap2-ts__muted', text: result.note }) : null;

  const steps = el('ol', { class: 'ap2-ts__steps' }, (result.steps || []).map((step) => {
    const status = STATUS[step.status] || STATUS.skipped;
    return el('li', { class: 'ap2-ts__step is-' + status.tone },
      el('p', { class: 'ap2-ts__step-head' },
        el('span', { class: 'ap2-ts__step-status' },
          el('span', { 'aria-hidden': 'true', text: status.icon + ' ' }),
          status.text,
        ),
        el('span', { class: 'ap2-ts__step-title', text: step.title }),
      ),
      step.verification && verifications[step.verification] ? checksList(verifications[step.verification]) : null,
      showTokens ? (step.artefacts || []).filter((key) => artefacts[key]).map((key) => tokenDetails(artefacts[key])) : null,
    );
  }));

  const receiptList = ['checkout', 'payment'].filter((key) => receipts[key]);
  return [
    verdict,
    summary,
    fallback,
    note,
    el('h3', { class: 'ap2-ts__subhead', text: 'How the chain was signed and checked' }),
    steps,
    receiptList.length
      ? [
        el('h3', { class: 'ap2-ts__subhead', text: 'Receipts' }),
        el('ul', { class: 'ap2-ts__receipts' }, receiptList.map((key) => el('li', {},
          marker(receipts[key].claims.status === 'Success', receipts[key].claims.status === 'Success' ? 'Success:' : 'Error:'),
          el('span', { text: receipts[key].title + ' from the ' + roleOf(receipts[key]).toLowerCase() + ': ' + receipts[key].claims.status + (receipts[key].claims.error ? ' (' + receipts[key].claims.error + ')' : '') }),
          showTokens ? tokenDetails(receipts[key]) : null,
        ))),
      ]
      : null,
    result.explanation ? el('p', { class: 'ap2-ts__explain', text: result.explanation }) : null,
  ];
}

function initSurface(root) {
  const endpoint = root.dataset.endpoint;
  const showTokens = root.dataset.showEvents === '1';
  const capField = root.querySelector('[data-ap2-cap]');
  const buttons = [...root.querySelectorAll('[data-ap2-run]')];
  const result = root.querySelector('[data-ap2-result]');

  async function run(intent, button) {
    const euros = parseFloat(String(capField.value || '').replace(',', '.'));
    if (!Number.isFinite(euros) || euros <= 0) {
      result.replaceChildren(el('p', { class: 'ap2-ts__error', role: 'alert', text: 'Enter a spending cap above €0.' }));
      capField.focus();
      return;
    }
    buttons.forEach((candidate) => { candidate.disabled = true; });
    button.classList.add('is-busy');
    result.replaceChildren(el('p', { class: 'ap2-ts__working', text: 'Signing the mandates and checking the chain…' }));
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          agentNexus: { ce: Number(root.dataset.ce || 0), page: Number(root.dataset.page || 0), url: location.href },
          capCents: Math.round(euros * 100),
          intent,
        }),
      });
      const data = await response.json();
      if (!response.ok) {
        throw new Error(data && data.error ? data.error.message : 'The request failed.');
      }
      result.replaceChildren(...renderResult(data, showTokens).flat().filter(Boolean));
    } catch (error) {
      result.replaceChildren(el('p', { class: 'ap2-ts__error', role: 'alert', text: error.message || 'Authorisation failed.' }));
    } finally {
      buttons.forEach((candidate) => { candidate.disabled = false; });
      button.classList.remove('is-busy');
    }
  }

  buttons.forEach((button) => button.addEventListener('click', () => run(button.dataset.ap2Run || 'within', button)));
}

function ready(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn);
  } else {
    fn();
  }
}

ready(() => document.querySelectorAll('[data-ap2-surface]').forEach(initSurface));
export {};
