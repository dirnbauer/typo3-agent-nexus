/**
 * AP2 mandate studio (backend).
 *
 * Three tools on one screen:
 *  - sign any of the four AP2 v0.2 mandate types (AJAX route agentnexus_ap2_mint),
 *  - verify pasted mandates, chains, checkouts and receipts (agentnexus_ap2_verify),
 *  - run the whole autonomous flow through the public endpoint, exactly as the
 *    Trusted Surface content element does (POST /api/agent-nexus/ap2/authorize).
 *
 * Everything is built with DOM methods and textContent: tokens and claims are
 * shown, never interpreted as markup. Nothing here is ever charged.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import labels from '~labels/agent_nexus.ap2';

/** A label, with arguments; the fallback when the key is missing. */
function t(key, args, fallback) {
  try {
    return labels.get(key, args);
  } catch {
    return fallback ?? key;
  }
}

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
    if (child === null || child === undefined || child === false) {
      continue;
    }
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

function euroCents(input) {
  const value = String(input || '').trim().replace(',', '.');
  if (!/^\d{1,7}(\.\d{1,2})?$/.test(value)) {
    return null;
  }
  const cents = Math.round(parseFloat(value) * 100);
  return cents > 0 ? cents : null;
}

function code(value) {
  const text = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
  return el('pre', { class: 'anx-code', tabindex: '0' }, el('code', { text }));
}

function statusBadge(pass) {
  return el(
    'span',
    { class: 'badge ' + (pass ? 'badge-success' : 'badge-danger') },
    el('span', { 'aria-hidden': 'true', text: pass ? '✓ ' : '✕ ' }),
    pass ? t('verify.pass', undefined, 'Passed') : t('verify.fail', undefined, 'Failed'),
  );
}

function checksTable(checks, caption) {
  return el('div', { class: 'table-fit' }, el(
    'table',
    { class: 'table table-striped table-hover anx-ap2__checks' },
    el('caption', { class: 'visually-hidden', text: caption }),
    el('thead', {}, el('tr', {},
      el('th', { scope: 'col', text: t('verify.check', undefined, 'Check') }),
      el('th', { scope: 'col', text: t('verify.result', undefined, 'Result') }),
      el('th', { scope: 'col', text: t('verify.detail', undefined, 'Detail') }),
    )),
    el('tbody', {}, (checks || []).map((check) => el('tr', {},
      el('th', { scope: 'row', text: t('check.' + check.id, undefined, check.label) }),
      el('td', {}, statusBadge(check.pass)),
      el('td', { class: 'anx-ap2__detail', text: check.detail || '' }),
    ))),
  ));
}

function verdictCallout(result) {
  const valid = result.valid === true;
  return el(
    'div',
    { class: 'callout ' + (valid ? 'callout-success' : 'callout-danger') },
    el('div', { class: 'callout-body' },
      el('p', { class: 'anx-ap2__verdict' },
        el('span', { 'aria-hidden': 'true', text: valid ? '✓ ' : '✕ ' }),
        valid ? t('verify.valid', undefined, 'Valid') : t('verify.invalid', [result.error || ''], 'Refused: ' + (result.error || '')),
      ),
      valid || !result.errorDescription ? null : el('p', { text: result.errorDescription }),
    ),
  );
}

function disclosuresTable(disclosures) {
  if (!disclosures || disclosures.length === 0) {
    return el('p', { class: 'text-variant', text: t('output.noDisclosures', undefined, 'This token has no disclosures.') });
  }
  return el('div', { class: 'table-fit' }, el(
    'table',
    { class: 'table table-striped anx-ap2__disclosures' },
    el('caption', { class: 'visually-hidden', text: t('output.disclosures', undefined, 'Disclosures') }),
    el('thead', {}, el('tr', {},
      el('th', { scope: 'col', text: t('output.claim', undefined, 'Claim') }),
      el('th', { scope: 'col', text: t('output.value', undefined, 'Value') }),
      el('th', { scope: 'col', text: t('output.digest', undefined, 'Digest') }),
    )),
    el('tbody', {}, disclosures.map((disclosure) => el('tr', {},
      el('th', { scope: 'row' }, disclosure.name ? el('code', { text: disclosure.name }) : t('output.element', undefined, '(array element)')),
      el('td', {}, code(disclosure.value)),
      el('td', {}, el('code', { class: 'anx-ap2__digest', text: disclosure.digest })),
    ))),
  ));
}

/** A signed artefact taken apart: token, header, claims, disclosures, the chain's tokens. */
function artefactView(artefact, headingLevel, onVerify) {
  const title = t('type.' + keyToType(artefact.kind), undefined, artefact.title);
  const copy = el('button', { type: 'button', class: 'btn btn-default btn-sm' }, t('output.copy', undefined, 'Copy the token'));
  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(artefact.token);
      Notification.success(t('output.copied', undefined, 'The token is on the clipboard.'));
    } catch {
      Notification.warning(t('output.copyFailed', undefined, 'The browser did not allow copying.'));
    }
  });
  const actions = el('p', { class: 'anx-ap2__actions' }, copy);
  if (onVerify) {
    const verify = el('button', { type: 'button', class: 'btn btn-default btn-sm' }, t('output.verify', undefined, 'Verify this token'));
    verify.addEventListener('click', () => onVerify(artefact.token));
    actions.append(verify);
  }
  if (artefact.inspectorUri) {
    actions.append(el('a', { class: 'btn btn-link btn-sm', href: artefact.inspectorUri }, t('output.inspect', undefined, 'Open in the inspector')));
  }

  const hops = (artefact.hops || []).map((hop) => el('details', { class: 'anx-ap2__hop' },
    el('summary', { text: t('output.hop', [String(hop.position), hop.typ || '', hop.kid || '–'], 'Token ' + hop.position) }),
    el('p', { class: 'anx-ap2__label', text: t('output.header', undefined, 'Header') }), code(hop.header),
    el('p', { class: 'anx-ap2__label', text: t('output.signedPayload', undefined, 'Signed payload, with digests') }), code(hop.payload),
    disclosuresTable(hop.disclosures),
  ));

  return el('article', { class: 'anx-ap2__artefact' },
    el('h' + headingLevel, { class: 'anx-ap2__artefact-title' },
      title, ' ',
      el('span', { class: 'badge badge-default', text: t('role.' + artefact.role, undefined, artefact.roleLabel) }),
    ),
    el('p', { class: 'text-variant' }, el('code', { text: artefact.kind }), ' · ', artefact.label),
    el('p', { class: 'anx-ap2__label', text: t('output.token', undefined, 'Compact token') }),
    el('pre', { class: 'anx-code anx-ap2__token', tabindex: '0' }, el('code', { text: artefact.token })),
    actions,
    el('div', { class: 'anx-ap2__decoded' },
      el('div', {}, el('p', { class: 'anx-ap2__label', text: t('output.header', undefined, 'Header') }), code(artefact.header)),
      el('div', {}, el('p', { class: 'anx-ap2__label', text: t('output.claims', undefined, 'Claims, disclosures applied') }), code(artefact.claims)),
    ),
    el('p', { class: 'anx-ap2__label', text: t('output.disclosures', undefined, 'Disclosures') }),
    disclosuresTable(artefact.disclosures),
    hops.length ? el('p', { class: 'anx-ap2__label', text: t('output.hops', undefined, 'Tokens in the chain') }) : null,
    hops,
  );
}

async function errorMessage(error) {
  const response = error && error.response;
  if (response && typeof response.json === 'function') {
    try {
      const data = await response.json();
      if (data && data.error) {
        return t('error.' + data.error.key, undefined, data.error.message || String(data.error.code || ''));
      }
    } catch {
      // Not JSON: fall through.
    }
  }
  return t('error.network', undefined, 'The request failed. Try again.');
}

async function post(route, body) {
  const url = window.TYPO3?.settings?.ajaxUrls?.[route];
  if (!url) {
    throw new Error('The backend route ' + route + ' is not available.');
  }
  const response = await new AjaxRequest(url).post(body, { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
  return response.resolve();
}

function initStudio(root) {
  const mintForm = root.querySelector('[data-ap2-mint]');
  const typeField = root.querySelector('[data-ap2-type]');
  const output = root.querySelector('[data-ap2-output]');
  const verifyForm = root.querySelector('[data-ap2-verify]');
  const verifyInput = verifyForm.querySelector('textarea');
  const verifyResults = root.querySelector('[data-ap2-verify-results]');
  const flowForm = root.querySelector('[data-ap2-flow]');
  const flowResults = root.querySelector('[data-ap2-flow-results]');
  const linked = mintForm.querySelector('[name="linked"]');
  const transaction = mintForm.querySelector('[name="transaction"]');
  const last = { open_checkout: '', open_payment: '', checkoutHash: '' };

  const field = (name) => mintForm.querySelector('[data-ap2-field="' + name + '"]');
  const signer = () => mintForm.querySelector('[name="signer"]:checked')?.value || 'trusted-surface';

  function updateFields() {
    const type = typeField.value;
    const byAgent = signer() === 'shopping-agent';
    const visible = {
      open_checkout: ['items', 'merchant'],
      open_payment: ['cap', 'merchant', 'linked'],
      checkout: ['signer', 'items', ...(byAgent ? ['linked'] : [])],
      payment: ['signer', 'items', 'merchant', 'transaction', ...(byAgent ? ['linked'] : [])],
    }[type] || [];
    for (const name of ['signer', 'items', 'cap', 'merchant', 'linked', 'transaction']) {
      const wrapper = field(name);
      if (wrapper) {
        wrapper.hidden = !visible.includes(name);
      }
    }
    // Offer the last matching open mandate, unless the field holds something typed by hand.
    const wanted = type === 'open_payment' || type === 'checkout' ? last.open_checkout : type === 'payment' ? last.open_payment : '';
    if (linked.dataset.auto === '1' || linked.value.trim() === '') {
      linked.value = visible.includes('linked') ? wanted : '';
      linked.dataset.auto = '1';
    }
    if (type === 'payment' && transaction.value.trim() === '' && last.checkoutHash) {
      transaction.value = last.checkoutHash;
    }
  }
  linked.addEventListener('input', () => { linked.dataset.auto = '0'; });
  typeField.addEventListener('change', updateFields);
  mintForm.querySelectorAll('[name="signer"]').forEach((radio) => radio.addEventListener('change', updateFields));
  updateFields();

  function showForVerification(token) {
    verifyInput.value = token;
    verifyInput.focus();
    verifyInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  mintForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = mintForm.querySelector('button[type="submit"]');
    button.disabled = true;
    const body = {
      type: typeField.value,
      signer: signer(),
      items: [...mintForm.querySelectorAll('[name="items"]:checked')].map((box) => box.value),
      cap: mintForm.querySelector('[name="cap"]').value,
      merchant: mintForm.querySelector('[name="merchant"]').value,
      expires: mintForm.querySelector('[name="expires"]').value,
      linked: field('linked').hidden ? '' : linked.value,
      transaction: field('transaction').hidden ? '' : transaction.value,
    };
    try {
      const result = await post('agentnexus_ap2_mint', body);
      const artefact = { ...result.artefact, inspectorUri: result.inspectorUri };
      if (body.type === 'open_checkout' || body.type === 'open_payment') {
        last[body.type] = artefact.token;
      }
      if (artefact.claims && artefact.claims.checkout_hash) {
        last.checkoutHash = artefact.claims.checkout_hash;
      }
      (result.related || []).forEach((related) => {
        if (related.kind === 'checkout_jwt') {
          last.checkoutHash = related.reference;
        }
      });
      output.replaceChildren(
        artefactView(artefact, 3, showForVerification),
        ...(result.related || []).map((related, index) => el('details', { class: 'anx-ap2__related' },
          el('summary', { text: t('output.related', undefined, 'Also signed') + ': ' + related.label }),
          artefactView({ ...related, inspectorUri: (result.relatedInspectorUris || [])[index] || '' }, 4, showForVerification),
        )),
      );
      Notification.success(t('mint.signed', [artefact.label], 'Signed: ' + artefact.label));
    } catch (error) {
      const message = await errorMessage(error);
      output.replaceChildren(el('div', { class: 'callout callout-danger' }, el('div', { class: 'callout-body' }, el('p', { text: message }))));
      Notification.error(t('mint.failed', undefined, 'The mandate was not signed'), message);
    } finally {
      button.disabled = false;
    }
  });

  verifyForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = verifyForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const response = await post('agentnexus_ap2_verify', { token: verifyInput.value });
      verifyResults.replaceChildren(...(response.results || []).map((result) => el('section', { class: 'anx-ap2__result' },
        el('h3', { text: result.artefact ? t('type.' + result.kind, undefined, result.title) : result.title }),
        verdictCallout(result),
        checksTable(result.checks, t('verify.caption', [result.title], 'Checks of ' + result.title)),
        result.artefact ? el('details', { class: 'anx-ap2__related' },
          el('summary', { text: t('verify.decoded', undefined, 'What the token says') }),
          artefactView({ ...result.artefact, inspectorUri: result.inspectorUri || '' }, 4, null),
        ) : null,
      )));
    } catch (error) {
      verifyResults.replaceChildren(el('div', { class: 'callout callout-danger' }, el('div', { class: 'callout-body' }, el('p', { text: await errorMessage(error) }))));
    } finally {
      button.disabled = false;
    }
  });

  flowForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const capCents = euroCents(flowForm.querySelector('[name="cap"]').value);
    if (capCents === null) {
      flowResults.replaceChildren(el('div', { class: 'callout callout-warning' }, el('div', { class: 'callout-body' }, el('p', { text: t('error.cap', undefined, 'Enter the spending cap in euros, for example 500 or 499.90.') }))));
      return;
    }
    const button = flowForm.querySelector('button[type="submit"]');
    button.disabled = true;
    flowResults.replaceChildren(el('p', { class: 'text-variant', text: t('flow.running', undefined, 'Signing and checking…') }));
    try {
      const response = await fetch(root.dataset.authorizeUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ capCents, intent: flowForm.querySelector('[name="intent"]:checked')?.value || 'within' }),
      });
      const result = await response.json();
      if (!response.ok) {
        throw new Error(result?.error?.message || String(response.status));
      }
      renderFlow(result);
    } catch (error) {
      flowResults.replaceChildren(el('div', { class: 'callout callout-danger' }, el('div', { class: 'callout-body' }, el('p', { text: t('flow.failed', [error.message], 'The flow could not run: ' + error.message) }))));
    } finally {
      button.disabled = false;
    }
  });

  function renderFlow(result) {
    const inspector = root.dataset.inspectorUrl
      ? root.dataset.inspectorUrl + (root.dataset.inspectorUrl.includes('?') ? '&' : '?') + 'context=' + encodeURIComponent(result.chainId)
      : '';
    const artefacts = result.artefacts || {};
    const verifications = result.verifications || {};
    const receipts = result.receipts || {};
    const money = (cents) => new Intl.NumberFormat(document.documentElement.lang || 'en', { style: 'currency', currency: result.cap?.currency || 'EUR' }).format(cents / 100);

    const steps = el('ol', { class: 'anx-ap2__steps' }, (result.steps || []).map((step) => {
      const pass = step.status === 'passed' || step.status === 'done';
      const children = [
        el('p', { class: 'anx-ap2__step-title' },
          el('span', { class: 'badge ' + (step.status === 'skipped' ? 'badge-default' : pass ? 'badge-success' : 'badge-danger') },
            el('span', { 'aria-hidden': 'true', text: step.status === 'skipped' ? '– ' : pass ? '✓ ' : '✕ ' }),
            t('status.' + step.status, undefined, step.status)),
          ' ',
          t('step.' + step.id, undefined, step.title),
          ' ',
          el('span', { class: 'text-variant', text: '(' + t('role.' + step.role, undefined, step.role) + ')' }),
        ),
      ];
      if (step.verification && verifications[step.verification]) {
        children.push(checksTable(verifications[step.verification].checks, t('step.' + step.id, undefined, step.title)));
      }
      (step.artefacts || []).forEach((key) => {
        if (artefacts[key]) {
          children.push(el('details', { class: 'anx-ap2__related' },
            el('summary', { text: t('type.' + keyToType(artefacts[key].kind), undefined, artefacts[key].title) + ' · ' + artefacts[key].label }),
            artefactView(artefacts[key], 4, showForVerification),
          ));
        }
      });
      return el('li', { class: 'anx-ap2__step' }, children);
    }));

    const receiptViews = ['checkout', 'payment'].filter((key) => receipts[key]).map((key) => el('details', { class: 'anx-ap2__related' },
      el('summary', { text: t('type.' + receipts[key].kind, undefined, receipts[key].title) + ' · ' + receipts[key].label }),
      artefactView(receipts[key], 4, showForVerification),
    ));

    flowResults.replaceChildren(
      el('div', { class: 'callout ' + (result.authorised ? 'callout-success' : 'callout-warning') },
        el('div', { class: 'callout-body' },
          el('p', { class: 'anx-ap2__verdict' },
            el('span', { 'aria-hidden': 'true', text: result.authorised ? '✓ ' : '✕ ' }),
            result.authorised
              ? t('flow.authorised', undefined, 'Payment authorised. Nothing was charged.')
              : t('flow.refused', [result.error || ''], 'Payment refused: ' + (result.error || '')),
          ),
          el('p', { text: t('flow.cart', [money(result.cart.total), money(result.cap.amount)], 'Cart ' + money(result.cart.total) + ', cap ' + money(result.cap.amount)) }),
          result.fallback ? el('p', { text: t('flow.fallback', undefined, result.fallback.message) }) : null,
          result.note ? el('p', { class: 'text-variant', text: result.note }) : null,
        ),
      ),
      steps,
      receiptViews.length ? el('h3', { text: t('receipts.heading', undefined, 'Receipts') }) : null,
      ...receiptViews,
      inspector ? el('p', {}, el('a', { class: 'btn btn-default btn-sm', href: inspector }, t('flow.chain', undefined, 'Show this chain in the inspector'))) : null,
    );
  }
}

function keyToType(kind) {
  return {
    'mandate.checkout.open.1': 'open_checkout',
    'mandate.checkout.1': 'checkout',
    'mandate.payment.open.1': 'open_payment',
    'mandate.payment.1': 'payment',
  }[kind] || kind;
}

document.querySelectorAll('[data-ap2-studio]').forEach(initStudio);
