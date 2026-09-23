/**
 * UCP checkout console (backend).
 *
 * The backend plays the visitor: it runs the shopping agent through its public
 * AG-UI endpoint, exactly as the checkout widget does, and shows the stream —
 * the agent's narration, every UCP request it sends with the response it gets,
 * the checkout the business returns and the approval the agent stops for.
 * A second form calls GET and cancel on the REST binding directly, with the
 * headers every platform sends.
 */
import labels from '~labels/agent_nexus.ucp';
import Notification from '@typo3/backend/notification.js';
import { money, parseJson, runInput, streamRun, ToolCalls, uuid } from '@webconsulting/agent-nexus/ucp-agent-stream.js';

const BADGES = {
  incomplete: 'warning',
  requires_escalation: 'warning',
  ready_for_complete: 'info',
  complete_in_progress: 'info',
  completed: 'success',
  canceled: 'default',
};

class UcpConsole {
  constructor(root) {
    this.root = root;
    this.agentUrl = root.dataset.agentUrl;
    this.restEndpoint = root.dataset.restEndpoint;
    this.platformProfile = root.dataset.platformProfile;
    this.inspectorUrl = root.dataset.inspectorUrl;

    this.form = root.querySelector('[data-ucp-form]');
    this.startButton = root.querySelector('[data-ucp-start]');
    this.status = root.querySelector('[data-ucp-status]');
    this.events = root.querySelector('[data-ucp-events]');
    this.eventsEmpty = root.querySelector('[data-ucp-events-empty]');
    this.eventCount = root.querySelector('[data-ucp-eventcount]');
    this.state = root.querySelector('[data-ucp-state]');

    this.approval = root.querySelector('[data-ucp-approval]');
    this.approvalHeading = root.querySelector('[data-ucp-approval-heading]');
    this.approvalMessage = root.querySelector('[data-ucp-approval-message]');
    this.approvalForm = root.querySelector('[data-ucp-approval-form]');
    this.approvalEmail = root.querySelector('[data-ucp-approval-email]');
    this.approvalEmailInput = root.querySelector('[data-ucp-approval-email-input]');
    this.approvalEmailError = root.querySelector('[data-ucp-approval-email-error]');

    this.threadId = '';
    this.interrupt = null;
    this.props = {};
    this.busy = false;
    this.count = 0;
    this.rows = new Map();
    this.rawItem = null;
    this.text = null;
    this.tools = new ToolCalls((call) => this.renderCall(call));

    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.start();
    });
    this.approvalForm.addEventListener('submit', (event) => {
      event.preventDefault();
      this.answer(true);
    });
    root.querySelector('[data-ucp-reject]').addEventListener('click', () => this.answer(false));
    root.querySelector('[data-ucp-clear]').addEventListener('click', () => this.clearEvents());

    const manual = root.querySelector('[data-ucp-manual]');
    manual.addEventListener('submit', (event) => {
      event.preventDefault();
      this.manual('get');
    });
    root.querySelector('[data-ucp-manual-cancel]').addEventListener('click', () => this.manual('cancel'));
  }

  start() {
    const intent = this.form.querySelector('input[name="anx-ucp-intent"]:checked');
    const email = this.form.querySelector('[data-ucp-email]').value.trim();
    this.props = { intent: intent ? intent.value : 'pro' };
    if (email !== '') {
      this.props.email = email;
    }
    if (this.form.querySelector('[data-ucp-decline]').checked) {
      this.props.payment = 'decline';
    }
    this.threadId = uuid();
    this.clearEvents();
    this.renderCheckout(null);
    this.hideApproval();
    this.run(runInput({ threadId: this.threadId, runId: uuid(), text: intent ? intent.dataset.label : '', props: this.props }));
  }

  answer(approved) {
    if (!this.interrupt) {
      return;
    }
    const payload = { approved };
    if (approved && !this.approvalEmail.hidden) {
      const email = this.approvalEmailInput.value.trim();
      if (!this.approvalEmailInput.checkValidity() || email === '') {
        this.approvalEmailInput.setAttribute('aria-invalid', 'true');
        this.approvalEmailError.hidden = false;
        this.approvalEmailInput.focus();
        return;
      }
      payload.email = email;
    }
    this.addRow('you', approved ? labels.get('js.approval.approved') : labels.get('js.approval.rejected'));
    const resume = [{ interruptId: this.interrupt.id, status: 'resolved', payload }];
    this.hideApproval();
    this.run(runInput({ threadId: this.threadId, runId: uuid(), text: approved ? 'Approve' : 'Not now', props: this.props, resume }));
  }

  async run(input) {
    if (this.busy) {
      return;
    }
    this.busy = true;
    this.startButton.disabled = true;
    this.say(labels.get('js.status.running'));
    try {
      await streamRun(this.agentUrl, input, (event) => this.handle(event));
    } catch (error) {
      this.say(labels.get('js.status.unreachable'));
      Notification.error(labels.get('js.notify.error.title'), String(error && error.message ? error.message : error));
    } finally {
      this.busy = false;
      this.startButton.disabled = false;
    }
  }

  handle(event) {
    this.count++;
    this.eventCount.textContent = String(this.count);
    this.eventsEmpty.hidden = true;
    this.rawEvent(event);
    if (this.tools.handle(event)) {
      return;
    }
    switch (event.type) {
      case 'RUN_STARTED':
        this.addRow('run', labels.get('js.row.runStarted', [event.protocolVersion || '']));
        break;
      case 'STEP_STARTED':
        this.addRow('step', labels.get('js.row.step', [event.stepName]));
        break;
      case 'TEXT_MESSAGE_START':
        this.text = this.addRow('agent', '');
        break;
      case 'TEXT_MESSAGE_CONTENT':
      case 'REASONING_MESSAGE_CONTENT':
        if (this.text) {
          this.text.querySelector('[data-text]').textContent += event.delta;
        }
        break;
      case 'TEXT_MESSAGE_END':
      case 'REASONING_MESSAGE_END':
        this.text = null;
        break;
      case 'REASONING_MESSAGE_START':
        this.text = this.addRow('reasoning', '');
        break;
      case 'CUSTOM':
        if (event.name === 'agentnexus.provenance' && event.value && event.value.label) {
          this.addRow('provenance', event.value.label);
        }
        break;
      case 'STATE_SNAPSHOT':
        this.renderCheckout(event.snapshot && event.snapshot.checkout ? event.snapshot.checkout : null);
        break;
      case 'RUN_FINISHED':
        this.finished(event);
        break;
      case 'RUN_ERROR':
        this.addRow('error', labels.get('js.row.error', [event.message]));
        this.say(labels.get('js.status.failed', [event.message]));
        Notification.error(labels.get('js.notify.error.title'), event.message);
        break;
      default:
        break;
    }
  }

  finished(event) {
    const outcome = event.outcome || { type: 'success' };
    if (outcome.type === 'interrupt' && Array.isArray(outcome.interrupts) && outcome.interrupts.length) {
      this.addRow('run', labels.get('js.row.finished.interrupt'));
      this.say(labels.get('js.status.interrupted'));
      this.showApproval(outcome.interrupts[0]);
      return;
    }
    this.addRow('run', labels.get('js.row.finished.success'));
    this.say(labels.get('js.status.finished'));
    const result = event.result || {};
    if (result.status === 'completed') {
      Notification.success(labels.get('js.notify.completed.title'), labels.get('js.notify.completed', [result.orderId || '']));
    } else if (result.status === 'canceled') {
      Notification.info(labels.get('js.notify.canceled.title'), labels.get('js.notify.canceled'));
    }
  }

  showApproval(interrupt) {
    this.interrupt = interrupt;
    const needsEmail = Boolean(interrupt.metadata && interrupt.metadata.needsEmail);
    this.approvalMessage.textContent = interrupt.message || '';
    this.approvalEmail.hidden = !needsEmail;
    this.approvalEmailInput.required = needsEmail;
    this.approvalEmailInput.removeAttribute('aria-invalid');
    this.approvalEmailError.hidden = true;
    this.approval.hidden = false;
    this.approvalHeading.focus();
  }

  hideApproval() {
    this.interrupt = null;
    this.approval.hidden = true;
  }

  /** One narrated line of the timeline. */
  addRow(kind, text) {
    const item = document.createElement('li');
    item.className = 'anx-events__item anx-ucp__row anx-ucp__row--' + kind;
    const label = document.createElement('span');
    label.className = 'anx-ucp__row-kind';
    label.textContent = labels.get('js.kind.' + kind);
    const body = document.createElement('span');
    body.className = 'anx-ucp__row-text';
    body.dataset.text = '';
    body.textContent = text;
    item.append(label, body);
    this.events.append(item);
    return item;
  }

  /** A UCP call: an expandable row with the request and the response. */
  renderCall(call) {
    let item = this.rows.get(call.id);
    if (!item) {
      item = document.createElement('li');
      item.className = 'anx-events__item anx-ucp__call';
      item.innerHTML = '<details><summary><span class="anx-ucp__row-kind"></span> <span class="anx-events__name"></span> <code class="anx-ucp__call-path"></code> <span class="badge"></span></summary>'
        + '<p class="text-variant anx-ucp__call-label" data-request-label></p><pre class="anx-code" tabindex="0"><code data-request></code></pre>'
        + '<p class="text-variant anx-ucp__call-label" data-response-label></p><pre class="anx-code" tabindex="0"><code data-response></code></pre></details>';
      item.querySelector('.anx-ucp__row-kind').textContent = labels.get('js.kind.call');
      item.querySelector('[data-request-label]').textContent = labels.get('js.row.request');
      item.querySelector('[data-response-label]').textContent = labels.get('js.row.response');
      this.rows.set(call.id, item);
      this.events.append(item);
    }
    const request = parseJson(call.args);
    if (call.name) {
      item.querySelector('.anx-events__name').textContent = call.name;
    }
    if (request) {
      item.querySelector('.anx-ucp__call-path').textContent = (request.method || '') + ' ' + (request.path || '');
      item.querySelector('[data-request]').textContent = JSON.stringify(request, null, 2);
    }
    const badge = item.querySelector('.badge');
    const result = call.result === null ? null : parseJson(call.result);
    if (result === null) {
      badge.className = 'badge badge-warning';
      badge.textContent = call.ended ? labels.get('js.row.proposed') : '…';
      item.querySelector('[data-response]').textContent = '';
      return;
    }
    item.querySelector('[data-response]').textContent = JSON.stringify(result, null, 2);
    if (typeof result.status === 'number') {
      badge.className = 'badge badge-' + (result.status >= 400 ? 'danger' : 'success');
      badge.textContent = String(result.status);
    } else {
      badge.className = 'badge badge-default';
      badge.textContent = labels.get('js.row.notSent');
    }
  }

  /** Every AG-UI event as it arrived, for the raw view below the timeline. */
  rawEvent(event) {
    if (!this.rawItem) {
      this.rawItem = document.createElement('details');
      this.rawItem.className = 'anx-ucp__raw';
      this.rawItem.innerHTML = '<summary data-ucp-raw-summary></summary><pre class="anx-code" tabindex="0"><code data-ucp-raw></code></pre>';
      this.events.after(this.rawItem);
    }
    this.rawItem.querySelector('[data-ucp-raw-summary]').textContent = labels.get('js.raw.heading', [String(this.count)]);
    this.rawItem.querySelector('[data-ucp-raw]').textContent += JSON.stringify(event) + '\n';
  }

  clearEvents() {
    this.events.replaceChildren();
    if (this.rawItem) {
      this.rawItem.remove();
      this.rawItem = null;
    }
    this.rows.clear();
    this.tools = new ToolCalls((call) => this.renderCall(call));
    this.count = 0;
    this.eventCount.textContent = '0';
    this.eventsEmpty.hidden = false;
    this.text = null;
  }

  renderCheckout(checkout) {
    const empty = this.root.querySelector('[data-ucp-checkout-empty]');
    const body = this.root.querySelector('[data-ucp-checkout-body]');
    if (!checkout) {
      empty.hidden = false;
      body.hidden = true;
      this.state.className = 'badge badge-default';
      this.state.textContent = labels.get('js.state.none');
      return;
    }
    empty.hidden = true;
    body.hidden = false;
    const currency = checkout.currency || 'EUR';
    this.state.className = 'badge badge-' + (BADGES[checkout.status] || 'default');
    this.state.textContent = this.stateLabel(checkout.status);
    this.root.querySelector('[data-ucp-checkout-id]').textContent = checkout.id || '';
    this.root.querySelector('[data-ucp-expires]').textContent = checkout.expires_at ? new Date(checkout.expires_at).toLocaleString() : '';
    const orderRow = this.root.querySelector('[data-ucp-order-row]');
    orderRow.hidden = !checkout.order;
    if (checkout.order) {
      this.root.querySelector('[data-ucp-order]').textContent = (checkout.order.label || '') + ' (' + checkout.order.id + ')';
    }

    const lines = this.root.querySelector('[data-ucp-lines]');
    lines.replaceChildren(...(checkout.line_items || []).map((line) => {
      const row = document.createElement('tr');
      const lineTotal = (line.totals || []).find((t) => t.type === 'total');
      [line.item ? line.item.title : '', String(line.quantity), money(line.item ? line.item.price : 0, currency), money(lineTotal ? lineTotal.amount : 0, currency)].forEach((value, index) => {
        const cell = document.createElement('td');
        if (index > 0) {
          cell.className = 'text-end';
        }
        cell.textContent = value;
        row.append(cell);
      });
      return row;
    }));
    const totals = this.root.querySelector('[data-ucp-totals]');
    totals.replaceChildren(...(checkout.totals || []).map((total) => {
      const row = document.createElement('tr');
      const label = document.createElement('th');
      label.scope = 'row';
      label.colSpan = 3;
      label.textContent = total.display_text || total.type;
      const amount = document.createElement('td');
      amount.className = 'text-end';
      amount.textContent = money(total.amount, currency);
      row.append(label, amount);
      return row;
    }));
    const messages = this.root.querySelector('[data-ucp-messages]');
    messages.replaceChildren(...(checkout.messages || []).map((message) => {
      const item = document.createElement('li');
      const badge = document.createElement('span');
      badge.className = 'badge badge-' + (message.type === 'error' ? 'danger' : message.type === 'warning' ? 'warning' : 'info');
      badge.textContent = message.code || message.type;
      item.append(badge, document.createTextNode(' ' + (message.content || '')));
      return item;
    }));
    const inspect = this.root.querySelector('[data-ucp-inspect]');
    if (checkout.id && this.inspectorUrl) {
      inspect.href = this.inspectorUrl + (this.inspectorUrl.includes('?') ? '&' : '?') + 'search=' + encodeURIComponent(checkout.id);
      inspect.hidden = false;
    }
  }

  stateLabel(status) {
    try {
      return labels.get('state.' + status);
    } catch {
      return status;
    }
  }

  async manual(operation) {
    const input = this.root.querySelector('[data-ucp-manual-id]');
    const id = input.value.trim();
    const result = this.root.querySelector('[data-ucp-manual-result]');
    const status = this.root.querySelector('[data-ucp-manual-status]');
    const request = this.root.querySelector('[data-ucp-manual-request]');
    const body = this.root.querySelector('[data-ucp-manual-body]');
    if (!/^chk_[0-9a-f]{24}$/.test(id)) {
      input.setAttribute('aria-invalid', 'true');
      Notification.warning(labels.get('js.manual.invalid.title'), labels.get('js.manual.invalid'));
      input.focus();
      return;
    }
    input.removeAttribute('aria-invalid');
    const url = this.restEndpoint + '/checkout-sessions/' + encodeURIComponent(id) + (operation === 'cancel' ? '/cancel' : '');
    const headers = { 'UCP-Agent': 'profile="' + this.platformProfile + '"', 'Request-Id': uuid() };
    if (operation === 'cancel') {
      headers['Idempotency-Key'] = uuid();
    }
    try {
      const response = await fetch(url, { method: operation === 'cancel' ? 'POST' : 'GET', headers });
      const json = parseJson(await response.text());
      result.hidden = false;
      status.className = 'badge badge-' + (response.ok ? 'success' : 'danger');
      status.textContent = String(response.status);
      request.textContent = (operation === 'cancel' ? 'POST ' : 'GET ') + new URL(url).pathname;
      body.textContent = json === null ? '' : JSON.stringify(json, null, 2);
      if (json && json.id && json.status) {
        this.renderCheckout(json);
      }
    } catch (error) {
      Notification.error(labels.get('js.manual.failed'), String(error && error.message ? error.message : error));
    }
  }

  say(text) {
    this.status.textContent = text;
  }
}

document.querySelectorAll('[data-ucp-console]').forEach((root) => new UcpConsole(root));
