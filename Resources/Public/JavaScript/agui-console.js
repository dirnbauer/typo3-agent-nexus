/**
 * AG-UI run console (backend module "AG-UI > Run console").
 *
 * The browser is the AG-UI 1.0 client here: it sends a RunAgentInput, reads
 * the event stream and answers an interrupt with a resume on the next run of
 * the same thread. Editor tasks go to the module's backend route; site
 * assistant tasks go to the public endpoint, exactly as any other client
 * would call it.
 */
import Notification from '@typo3/backend/notification.js';
import labels from '~labels/agent_nexus.agui';
import { Conversation, applyPatch, newId, runAgent, runInput } from '@webconsulting/agent-nexus/agui-stream.js';

const FAMILY_BADGE = {
  RUN: 'primary',
  STEP: 'default',
  TEXT: 'info',
  TOOL: 'warning',
  REASONING: 'secondary',
  STATE: 'success',
  MESSAGES: 'success',
  ACTIVITY: 'success',
  SUBAGENT: 'notice',
  RAW: 'default',
  CUSTOM: 'default',
};

class RunConsole {
  constructor(root) {
    this.root = root;
    this.runUrl = root.dataset.runUrl || (window.TYPO3?.settings?.ajaxUrls?.agentnexus_agui_run ?? '');
    this.endpoint = root.dataset.endpoint || '';
    this.select = root.querySelector('[data-agui-preset]');
    this.message = root.querySelector('[data-agui-message]');
    this.target = root.querySelector('[data-agui-target]');
    this.startButton = root.querySelector('[data-agui-start]');
    this.status = root.querySelector('[data-agui-status]');
    this.count = root.querySelector('[data-agui-count]');
    this.events = root.querySelector('[data-agui-events]');
    this.eventsEmpty = root.querySelector('[data-agui-events-empty]');
    this.provenance = root.querySelector('[data-agui-provenance]');
    this.reasoning = root.querySelector('[data-agui-reasoning]');
    this.reasoningText = root.querySelector('[data-agui-reasoning-text]');
    this.answer = root.querySelector('[data-agui-answer]');
    this.result = root.querySelector('[data-agui-result]');
    this.interrupt = root.querySelector('[data-agui-interrupt]');
    this.interruptMessage = root.querySelector('[data-agui-interrupt-message]');
    this.interruptFacts = root.querySelector('[data-agui-interrupt-facts]');
    this.interruptForm = root.querySelector('[data-agui-interrupt-form]');
    this.interruptFields = root.querySelector('[data-agui-interrupt-fields]');
    this.state = root.querySelector('[data-agui-state]');
    this.deltas = root.querySelector('[data-agui-deltas]');
    this.deltasEmpty = root.querySelector('[data-agui-deltas-empty]');
    this.activityCard = root.querySelector('[data-agui-activity-card]');
    this.activity = root.querySelector('[data-agui-activity]');

    this.busy = false;
    this.thread = null;

    this.select.addEventListener('change', () => this.presetChanged());
    root.querySelector('[data-agui-form]').addEventListener('submit', (event) => {
      event.preventDefault();
      this.start();
    });
    root.querySelector('[data-agui-clear]').addEventListener('click', () => this.clear());
    this.interruptForm.addEventListener('submit', (event) => {
      event.preventDefault();
      this.answerInterrupt(true);
    });
    root.querySelector('[data-agui-reject]').addEventListener('click', () => this.answerInterrupt(false));

    this.presetChanged();
    this.clear();
  }

  option() {
    return this.select.options[this.select.selectedIndex];
  }

  presetChanged() {
    const option = this.option();
    this.message.value = option.dataset.message || '';
    this.target.textContent = labels.get(option.dataset.audience === 'site' ? 'console.target.site' : 'console.target.editor');
  }

  clear() {
    this.events.replaceChildren();
    this.eventsEmpty.hidden = false;
    this.count.textContent = '0';
    this.provenance.hidden = true;
    this.reasoning.hidden = true;
    this.reasoningText.textContent = '';
    this.answer.textContent = '';
    this.result.hidden = true;
    this.result.textContent = '';
    this.interrupt.hidden = true;
    this.state.textContent = '{}';
    this.deltas.replaceChildren();
    this.deltasEmpty.hidden = false;
    this.activityCard.hidden = true;
    this.activity.textContent = '';
    this.status.textContent = '';
    this.thread = null;
  }

  /** A new conversation: a fresh thread for the selected task. */
  start() {
    if (this.busy) {
      return;
    }
    const option = this.option();
    const text = this.message.value.trim();
    if (text === '') {
      this.message.focus();
      return;
    }
    this.clear();
    const site = option.dataset.audience === 'site';
    this.thread = {
      id: newId('thread'),
      url: site ? this.endpoint : this.runUrl,
      preset: option.value,
      conversation: new Conversation(),
      state: {},
      pending: null,
    };
    this.thread.conversation.addUser(text);
    this.run(null);
  }

  async run(resume) {
    const thread = this.thread;
    if (!thread || !thread.url) {
      this.setStatus(labels.get('js.status.noRoute'));
      return;
    }
    const input = runInput({
      threadId: thread.id,
      messages: thread.conversation.messages(),
      state: thread.state,
      forwardedProps: { agentNexus: { preset: thread.preset } },
      resume,
    });
    this.busy = true;
    this.startButton.disabled = true;
    this.interrupt.hidden = true;
    this.answer.textContent = '';
    this.result.hidden = true;
    this.startedAt = performance.now();
    this.setStatus(labels.get('js.status.running', [input.runId]));
    let finished = false;
    try {
      await runAgent(thread.url, input, (event) => {
        if (event.type === 'RUN_FINISHED' || event.type === 'RUN_ERROR') {
          finished = true;
        }
        this.handle(event, input.runId);
      });
      if (!finished) {
        this.setStatus(labels.get('js.status.truncated'));
      }
    } catch (error) {
      const text = error.status
        ? labels.get('js.status.refused', [String(error.status), error.message])
        : labels.get('js.status.network', [error.message]);
      this.setStatus(text);
      Notification.error(labels.get('console.heading'), text);
    } finally {
      this.busy = false;
      this.startButton.disabled = false;
    }
  }

  handle(event, runId) {
    this.addEvent(event);
    this.thread.conversation.apply(event);
    switch (event.type) {
      case 'CUSTOM':
        if (event.name === 'at.webconsulting.agentnexus.provenance' && event.value) {
          this.provenance.hidden = false;
          this.provenance.textContent = labels.get('js.provenance', [String(event.value.label || event.value.mode)]);
          this.provenance.title = event.value.reason || '';
        }
        break;
      case 'REASONING_MESSAGE_CONTENT':
        this.reasoning.hidden = false;
        this.reasoningText.textContent += event.delta;
        break;
      case 'TEXT_MESSAGE_CONTENT':
        this.answer.textContent += event.delta;
        break;
      case 'STATE_SNAPSHOT':
        this.thread.state = event.snapshot;
        this.showState();
        break;
      case 'STATE_DELTA': {
        const { value, error } = applyPatch(this.thread.state, event.delta);
        this.thread.state = value;
        this.showState();
        this.addDelta(event.delta, error);
        break;
      }
      case 'ACTIVITY_SNAPSHOT':
        this.activityCard.hidden = false;
        this.activity.textContent = JSON.stringify({ activityType: event.activityType, content: event.content }, null, 2);
        break;
      case 'RUN_FINISHED':
        this.finished(event, runId);
        break;
      case 'RUN_ERROR':
        this.setStatus(labels.get('js.status.error', [runId, event.message]));
        break;
      default:
        break;
    }
  }

  finished(event, runId) {
    const outcome = event.outcome || { type: 'success' };
    if (outcome.type === 'interrupt' && Array.isArray(outcome.interrupts) && outcome.interrupts.length > 0) {
      this.thread.pending = { interrupt: outcome.interrupts[0], url: this.thread.url };
      this.showInterrupt(outcome.interrupts[0]);
      this.setStatus(labels.get('js.status.interrupted', [runId]));
      return;
    }
    if (outcome.type === 'cancelled') {
      this.setStatus(labels.get('js.status.cancelled', [runId]));
      return;
    }
    this.setStatus(labels.get('js.status.finished', [runId]));
    if (event.result && typeof event.result === 'object') {
      this.result.hidden = false;
      this.result.textContent = labels.get('js.result', [String(event.result.status || '')])
        + (event.result.simulated ? ' ' + labels.get('js.result.simulated') : '');
    }
  }

  showInterrupt(interrupt) {
    this.interrupt.hidden = false;
    this.interruptMessage.textContent = interrupt.message || '';
    const proposal = this.thread.conversation.toolArguments(interrupt.toolCallId);
    const call = this.thread.conversation.toolOwners.get(interrupt.toolCallId)?.toolCalls.find((item) => item.id === interrupt.toolCallId);
    this.interruptFacts.replaceChildren(
      fact(labels.get('js.interrupt.reason'), interrupt.reason),
      fact(labels.get('js.interrupt.tool'), call ? call.function.name : interrupt.toolCallId || ''),
    );

    const schema = interrupt.responseSchema || {};
    const properties = schema.properties || {};
    const required = new Set([...(schema.required || []), ...((schema.then && schema.then.required) || [])]);
    this.interruptFields.replaceChildren();
    Object.entries(properties).forEach(([name, property]) => {
      if (name === 'approved') {
        return;
      }
      const id = 'agui-answer-' + name;
      const group = document.createElement('div');
      group.className = 'form-group';
      const label = document.createElement('label');
      label.className = 'form-label';
      label.htmlFor = id;
      let control;
      if (name === 'editedArgs') {
        label.textContent = labels.get('js.interrupt.edit');
        control = document.createElement('textarea');
        control.className = 'form-control agui-console__json';
        control.rows = 8;
        control.spellcheck = false;
        control.value = JSON.stringify(proposal, null, 2);
        control.dataset.original = control.value;
        const help = document.createElement('p');
        help.className = 'form-text';
        help.id = id + '-help';
        help.textContent = labels.get('js.interrupt.edit.help');
        control.setAttribute('aria-describedby', help.id);
        group.append(label, control, help);
      } else {
        label.textContent = property.title || name;
        control = document.createElement('input');
        control.className = 'form-control';
        control.type = property.format === 'email' ? 'email' : 'text';
        if (property.maxLength) {
          control.maxLength = property.maxLength;
        }
        control.autocomplete = name === 'email' ? 'email' : (name === 'name' ? 'name' : 'off');
        control.required = required.has(name);
        group.append(label, control);
      }
      control.id = id;
      control.name = name;
      this.interruptFields.append(group);
    });
    if (!Object.prototype.hasOwnProperty.call(properties, 'editedArgs')) {
      const pre = document.createElement('pre');
      pre.className = 'anx-code';
      pre.tabIndex = 0;
      pre.setAttribute('aria-label', labels.get('js.interrupt.arguments'));
      pre.textContent = JSON.stringify(proposal, null, 2);
      this.interruptFields.prepend(pre);
    }
    const first = this.interruptFields.querySelector('input, textarea');
    (first || this.interrupt.querySelector('[data-agui-approve]')).focus();
  }

  answerInterrupt(approved) {
    const pending = this.thread && this.thread.pending;
    if (!pending || this.busy) {
      return;
    }
    const payload = { approved };
    if (approved) {
      for (const control of this.interruptFields.querySelectorAll('input, textarea')) {
        control.setCustomValidity('');
        if (control.name === 'editedArgs') {
          if (control.value === control.dataset.original) {
            continue;
          }
          try {
            const edited = JSON.parse(control.value);
            if (!edited || typeof edited !== 'object' || Array.isArray(edited)) {
              throw new Error('not an object');
            }
            payload.editedArgs = edited;
          } catch (e) {
            control.setCustomValidity(labels.get('js.interrupt.edit.invalid'));
          }
        } else if (control.value.trim() !== '') {
          payload[control.name] = control.value.trim();
        } else if (control.required) {
          control.setCustomValidity(labels.get('js.interrupt.required'));
        }
      }
      if (!this.interruptForm.reportValidity()) {
        return;
      }
    }
    this.thread.pending = null;
    this.interrupt.hidden = true;
    this.run([{ interruptId: pending.interrupt.id, status: 'resolved', payload }]);
  }

  addEvent(event) {
    const index = this.events.children.length + 1;
    this.eventsEmpty.hidden = true;
    this.count.textContent = String(index);
    const item = document.createElement('li');
    item.className = 'anx-events__item';
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    const number = span('anx-events__index', String(index));
    const badge = span('badge badge-' + (FAMILY_BADGE[event.type.split('_')[0]] || 'default'), event.type);
    const brief = span('anx-events__name', describe(event));
    const offset = span('anx-events__offset text-variant', labels.get('js.event.offset', [String(Math.round(performance.now() - this.startedAt))]));
    summary.append(number, badge, brief, offset);
    const pre = document.createElement('pre');
    pre.className = 'anx-code';
    const code = document.createElement('code');
    code.textContent = JSON.stringify(event, null, 2);
    pre.append(code);
    details.append(summary, pre);
    item.append(details);
    this.events.append(item);
  }

  addDelta(delta, error) {
    this.deltasEmpty.hidden = true;
    const item = document.createElement('li');
    const code = document.createElement('code');
    code.textContent = JSON.stringify(delta);
    item.append(code);
    if (error) {
      item.append(document.createTextNode(' — ' + error.message));
    }
    this.deltas.append(item);
  }

  showState() {
    this.state.textContent = JSON.stringify(this.thread.state, null, 2);
  }

  setStatus(text) {
    this.status.textContent = text;
  }
}

function span(className, text) {
  const element = document.createElement('span');
  element.className = className;
  element.textContent = text;
  return element;
}

function fact(term, value) {
  const group = document.createElement('div');
  const dt = document.createElement('dt');
  dt.textContent = term;
  const dd = document.createElement('dd');
  const code = document.createElement('code');
  code.textContent = value || '';
  dd.append(code);
  group.append(dt, dd);
  return group;
}

/** One line per event for the list; the full event is in the details. */
function describe(event) {
  switch (event.type) {
    case 'RUN_STARTED':
      return event.runId + ' · ' + (event.protocolVersion || '');
    case 'RUN_FINISHED':
      return (event.outcome && event.outcome.type) || 'success';
    case 'RUN_ERROR':
      return event.message;
    case 'STEP_STARTED':
    case 'STEP_FINISHED':
      return event.stepName;
    case 'TEXT_MESSAGE_CONTENT':
    case 'REASONING_MESSAGE_CONTENT':
    case 'TOOL_CALL_ARGS':
      return JSON.stringify(event.delta);
    case 'TOOL_CALL_START':
      return event.toolCallName;
    case 'TOOL_CALL_RESULT':
      return typeof event.content === 'string' ? event.content : '[…]';
    case 'STATE_DELTA':
      return (event.delta || []).map((op) => op.op + ' ' + op.path).join(', ');
    case 'ACTIVITY_SNAPSHOT':
    case 'ACTIVITY_DELTA':
      return event.activityType;
    case 'CUSTOM':
      return event.name;
    default:
      return event.messageId || event.toolCallId || '';
  }
}

const root = document.querySelector('[data-agui-console]');
if (root) {
  new RunConsole(root);
}
