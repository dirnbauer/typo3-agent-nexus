/**
 * AG-UI over Server-Sent Events, for the UCP shopping agent.
 *
 * Shared by the checkout widget (frontend) and the checkout console (backend):
 * both post an AG-UI RunAgentInput to the agent endpoint and read the event
 * stream frame by frame with fetch() — a POST, so EventSource cannot be used.
 * No framework and no dependencies.
 */

/** A random UUID, also where crypto.randomUUID() is missing. */
export function uuid() {
  if (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function') {
    return globalThis.crypto.randomUUID();
  }
  const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * An AG-UI 1.0 RunAgentInput. `props` travels in forwardedProps.agentNexus,
 * `resume` answers the interrupts of the previous run on the thread.
 */
export function runInput({ threadId, runId, text, props, resume }) {
  const input = {
    threadId,
    runId,
    protocolVersion: '1.0',
    messages: [{ id: uuid(), role: 'user', content: text || 'Buy for me' }],
    forwardedProps: { agentNexus: props || {} },
  };
  if (resume && resume.length) {
    input.resume = resume;
  }
  return input;
}

/**
 * Post a RunAgentInput and hand every event of the stream to `onEvent`.
 * Resolves with the HTTP status once the stream has ended. Error statuses
 * carry a stream too (a single RUN_ERROR), so they are read the same way.
 */
export async function streamRun(url, input, onEvent, signal) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
    body: JSON.stringify(input),
    signal,
  });
  if (!response.body) {
    throw new Error('HTTP ' + response.status);
  }
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  for (;;) {
    const { value, done } = await reader.read();
    if (done) {
      break;
    }
    buffer += decoder.decode(value, { stream: true }).replace(/\r\n/g, '\n');
    let end;
    while ((end = buffer.indexOf('\n\n')) >= 0) {
      const block = buffer.slice(0, end);
      buffer = buffer.slice(end + 2);
      const data = block
        .split('\n')
        .filter((line) => line.startsWith('data:'))
        .map((line) => line.slice(5).trimStart())
        .join('\n');
      if (data) {
        const event = parseJson(data);
        if (event && typeof event.type === 'string') {
          onEvent(event);
        }
      }
    }
  }
  return response.status;
}

export function parseJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/** Minor units as money, e.g. 4900 EUR as "€49.00". */
export function money(amount, currency, locale) {
  const lang = locale || document.documentElement.lang || 'en-GB';
  try {
    return new Intl.NumberFormat(lang, { style: 'currency', currency: currency || 'EUR' }).format((Number(amount) || 0) / 100);
  } catch {
    return ((Number(amount) || 0) / 100).toFixed(2) + ' ' + (currency || 'EUR');
  }
}

/** The grand total of a UCP checkout, in minor units. */
export function totalOf(checkout) {
  const total = (checkout && Array.isArray(checkout.totals) ? checkout.totals : []).find((entry) => entry && entry.type === 'total');
  return total ? total.amount : 0;
}

/**
 * Folds TOOL_CALL_* events into calls: name, argument text and result text.
 * `onChange(call)` fires whenever a call gains something.
 */
export class ToolCalls {
  constructor(onChange) {
    this.calls = new Map();
    this.onChange = onChange;
  }

  handle(event) {
    const id = event.toolCallId;
    switch (event.type) {
      case 'TOOL_CALL_START':
        this.calls.set(id, { id, name: event.toolCallName, args: '', result: null, ended: false });
        break;
      case 'TOOL_CALL_ARGS':
        if (this.calls.has(id)) {
          this.calls.get(id).args += event.delta;
        }
        break;
      case 'TOOL_CALL_END':
        if (this.calls.has(id)) {
          this.calls.get(id).ended = true;
        }
        break;
      case 'TOOL_CALL_RESULT':
        if (!this.calls.has(id)) {
          // The answer to a call proposed in an earlier run.
          this.calls.set(id, { id, name: '', args: '', result: null, ended: true });
        }
        this.calls.get(id).result = event.content;
        break;
      default:
        return false;
    }
    this.onChange(this.calls.get(id));
    return true;
  }
}
