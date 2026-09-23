/**
 * AG-UI 1.0 client helpers shared by the assistant widget and the backend
 * run console: build a RunAgentInput, POST it, read the Server-Sent Events
 * stream event by event, apply JSON Patch, and keep the conversation the next
 * run sends back as `messages`.
 *
 * No dependencies and no bundler: a plain ES module the widget imports by
 * relative URL and the backend imports through the import map.
 */

export const PROTOCOL_VERSION = '1.0';

/** An opaque, unique id. */
export function newId(prefix) {
  const random = globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function'
    ? globalThis.crypto.randomUUID()
    : Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
  return prefix + '-' + random;
}

/**
 * A RunAgentInput as a 1.0 client sends it: protocol version, the whole
 * conversation, the state the run starts from, tools and context (none),
 * forwardedProps, and resume answers when the run continues an interrupt.
 */
export function runInput({ threadId, runId, messages = [], state = {}, forwardedProps = {}, resume = null }) {
  const input = {
    threadId,
    runId: runId || newId('run'),
    protocolVersion: PROTOCOL_VERSION,
    messages: messages.filter((message) => message.role !== 'activity'),
    tools: [],
    context: [],
    state,
    forwardedProps,
  };
  if (Array.isArray(resume) && resume.length > 0) {
    input.resume = resume;
  }
  return input;
}

/**
 * POST a run and call `onEvent` for every event of the answer. A refusal
 * before the stream opens (4xx with a JSON body) rejects with an Error that
 * carries `status` and the server's `reason`.
 */
export async function runAgent(url, input, onEvent, { signal, headers = {} } = {}) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', ...headers },
    body: JSON.stringify(input),
    credentials: 'same-origin',
    signal,
  });
  const type = response.headers.get('Content-Type') || '';
  if (!response.ok || !type.includes('text/event-stream') || !response.body) {
    let reason = '';
    let message = response.statusText || 'The request failed.';
    try {
      const body = await response.json();
      if (body && body.error) {
        reason = String(body.error.reason || '');
        message = String(body.error.message || message);
      }
    } catch (e) {
      // Not JSON: keep the status text.
    }
    const error = new Error(message);
    error.status = response.status;
    error.reason = reason;
    throw error;
  }
  await readEventStream(response.body, onEvent);
}

/**
 * Read an SSE body: frames end with a blank line, `data:` lines of one frame
 * join with a newline, `:` comment lines (keep-alives) are ignored.
 */
export async function readEventStream(body, onEvent) {
  const reader = body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  const flush = (frame) => {
    const data = frame
      .split('\n')
      .filter((line) => line.startsWith('data:'))
      .map((line) => line.slice(line.startsWith('data: ') ? 6 : 5))
      .join('\n');
    if (data.trim() === '') {
      return;
    }
    let event;
    try {
      event = JSON.parse(data);
    } catch (e) {
      return;
    }
    if (event && typeof event.type === 'string') {
      onEvent(event);
    }
  };
  for (;;) {
    const { value, done } = await reader.read();
    if (done) {
      break;
    }
    buffer += decoder.decode(value, { stream: true }).replace(/\r\n?/g, '\n');
    let end;
    while ((end = buffer.indexOf('\n\n')) >= 0) {
      flush(buffer.slice(0, end));
      buffer = buffer.slice(end + 2);
    }
  }
  flush(buffer);
}

/**
 * RFC 6902 JSON Patch, applied atomically: on any failure the original is
 * returned unchanged together with the error.
 */
export function applyPatch(document, operations) {
  let working = document === undefined ? null : JSON.parse(JSON.stringify(document));
  try {
    for (const operation of operations || []) {
      working = applyOperation(working, operation);
    }
    return { value: working, error: null };
  } catch (error) {
    return { value: document, error };
  }
}

function segments(pointer) {
  if (typeof pointer !== 'string' || (pointer !== '' && pointer[0] !== '/')) {
    throw new Error('Not a JSON Pointer: ' + pointer);
  }
  return pointer === '' ? [] : pointer.slice(1).split('/').map((part) => part.replace(/~1/g, '/').replace(/~0/g, '~'));
}

function parentOf(document, path) {
  let node = document;
  for (const part of path.slice(0, -1)) {
    if (node === null || typeof node !== 'object' || !(part in node)) {
      throw new Error('Path does not exist: /' + path.join('/'));
    }
    node = node[part];
  }
  if (node === null || typeof node !== 'object') {
    throw new Error('Not a container: /' + path.join('/'));
  }
  return node;
}

function getAt(document, path) {
  let node = document;
  for (const part of path) {
    if (node === null || typeof node !== 'object' || !(part in node)) {
      throw new Error('Path does not exist: /' + path.join('/'));
    }
    node = node[part];
  }
  return node;
}

function addAt(document, path, value) {
  if (path.length === 0) {
    return value;
  }
  const parent = parentOf(document, path);
  const key = path[path.length - 1];
  if (Array.isArray(parent)) {
    const index = key === '-' ? parent.length : Number(key);
    if (!Number.isInteger(index) || index < 0 || index > parent.length) {
      throw new Error('Index out of range: ' + key);
    }
    parent.splice(index, 0, value);
  } else {
    parent[key] = value;
  }
  return document;
}

function removeAt(document, path) {
  const parent = parentOf(document, path);
  const key = path[path.length - 1];
  if (!(key in parent)) {
    throw new Error('Path does not exist: /' + path.join('/'));
  }
  if (Array.isArray(parent)) {
    parent.splice(Number(key), 1);
  } else {
    delete parent[key];
  }
  return document;
}

function applyOperation(document, operation) {
  const path = segments(operation.path);
  const clone = (value) => (value === undefined ? null : JSON.parse(JSON.stringify(value)));
  switch (operation.op) {
    case 'add':
      return addAt(document, path, clone(operation.value));
    case 'remove':
      return removeAt(document, path);
    case 'replace':
      return path.length === 0 ? clone(operation.value) : addAt(removeAt(document, path), path, clone(operation.value));
    case 'move': {
      const from = segments(operation.from);
      const value = clone(getAt(document, from));
      return addAt(removeAt(document, from), path, value);
    }
    case 'copy':
      return addAt(document, path, clone(getAt(document, segments(operation.from))));
    case 'test':
      if (JSON.stringify(canonical(getAt(document, path))) !== JSON.stringify(canonical(operation.value))) {
        throw new Error('Test failed at ' + operation.path);
      }
      return document;
    default:
      throw new Error('Unknown operation: ' + operation.op);
  }
}

function canonical(value) {
  if (Array.isArray(value)) {
    return value.map(canonical);
  }
  if (value && typeof value === 'object') {
    return Object.keys(value).sort().reduce((out, key) => {
      out[key] = canonical(value[key]);
      return out;
    }, {});
  }
  return value;
}

/**
 * The conversation as the next run sends it back: user messages the page
 * adds, and what the agent streamed — assistant text with its tool calls,
 * tool results. Reasoning and activity stay on the page.
 */
export class Conversation {
  constructor() {
    this.list = [];
    this.byId = new Map();
    this.toolOwners = new Map();
  }

  addUser(content) {
    const message = { id: newId('msg'), role: 'user', content };
    this.list.push(message);
    return message;
  }

  apply(event) {
    switch (event.type) {
      case 'TEXT_MESSAGE_START':
        this.ensure(event.messageId, event.role || 'assistant');
        break;
      case 'TEXT_MESSAGE_CONTENT': {
        const message = this.byId.get(event.messageId);
        if (message) {
          message.content = (message.content || '') + event.delta;
        }
        break;
      }
      case 'TOOL_CALL_START': {
        const owner = this.ensure(event.parentMessageId || event.toolCallId, 'assistant');
        owner.toolCalls = owner.toolCalls || [];
        owner.toolCalls.push({ id: event.toolCallId, type: 'function', function: { name: event.toolCallName, arguments: '' } });
        this.toolOwners.set(event.toolCallId, owner);
        break;
      }
      case 'TOOL_CALL_ARGS': {
        const owner = this.toolOwners.get(event.toolCallId);
        const call = owner && owner.toolCalls.find((item) => item.id === event.toolCallId);
        if (call) {
          call.function.arguments += event.delta;
        }
        break;
      }
      case 'TOOL_CALL_RESULT': {
        const message = { id: event.messageId, role: 'tool', content: event.content, toolCallId: event.toolCallId };
        this.list.push(message);
        this.byId.set(message.id, message);
        break;
      }
      default:
        break;
    }
  }

  ensure(id, role) {
    let message = this.byId.get(id);
    if (!message) {
      message = { id, role };
      this.list.push(message);
      this.byId.set(id, message);
    }
    return message;
  }

  messages() {
    return this.list.map((message) => {
      const copy = { ...message };
      if (copy.role === 'assistant' && copy.content === '') {
        delete copy.content;
      }
      return copy;
    });
  }

  /** The arguments of a tool call, parsed; an empty object when they do not parse. */
  toolArguments(toolCallId) {
    const owner = this.toolOwners.get(toolCallId);
    const call = owner && owner.toolCalls.find((item) => item.id === toolCallId);
    try {
      const parsed = JSON.parse(call ? call.function.arguments : '{}');
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (e) {
      return {};
    }
  }
}
