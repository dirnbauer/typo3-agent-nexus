/**
 * A2UI renderer: the basic catalogue of A2UI v0.9.1 (stable) and of the v1.0
 * release candidate, in plain DOM.
 *
 * It is the trusted half of A2UI. An agent sends data, never code: a stream of
 * messages (createSurface, updateComponents, updateDataModel, deleteSurface)
 * that describe components by name, and this renderer draws only the
 * components and runs only the functions of the basic catalogue. Every string
 * goes into the page as text; nothing is parsed as HTML.
 *
 * What it implements:
 *  - the message sequence of v0.9.1 and the inline createSurface of v1.0,
 *    several surfaces per mount, components in any order (buffered until the
 *    root arrives);
 *  - dynamic values: literals, {path} (JSON Pointer; relative paths inside a
 *    List template resolve against the item), {call} of the catalogue
 *    functions, formatString with ${…} interpolation and nested calls;
 *  - two-way binding of the inputs, reactive text, `checks` (a failing check
 *    marks its field with aria-invalid and aria-describedby and disables a
 *    Button that carries it), and actions: the spec's action message with an
 *    ISO timestamp and the resolved context, plus the data model in the
 *    transport metadata when the surface asked for it (sendDataModel);
 *  - accessible output: labels bound to inputs, fieldset and legend for
 *    choices, ARIA tabs, a native modal dialog, a polite live region per
 *    surface, focus kept across re-renders.
 *
 * Class names (a2ui-*) are stable; the host styles them (widgets/a2ui.css in
 * the frontend, modules/a2ui.css in the backend) and may add its own classes
 * per role through `options.classes`. No framework, no build step.
 */

const CATALOG_IDS = {
  'v0.9': [
    'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json',
    'https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json',
  ],
  'v1.0': ['https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json'],
};

const DATA_MODEL_MEMBER = { 'v0.9': 'a2uiClientDataModel', 'v1.0': 'a2uiRendererDataModel' };
const CAPABILITIES_MEMBER = { 'v0.9': 'a2uiClientCapabilities', 'v1.0': 'a2uiRendererCapabilities' };

const MESSAGE_TYPES = {
  'v0.9': ['createSurface', 'updateComponents', 'updateDataModel', 'deleteSurface'],
  'v1.0': ['createSurface', 'updateComponents', 'updateDataModel', 'deleteSurface', 'callRendererFunction', 'agentFunctionResponse'],
};

export const DEFAULT_LABELS = {
  required: 'required',
  close: 'Close',
  dialog: 'Dialog',
  invalid: 'Check this field.',
  checkFields: 'Check the highlighted fields.',
  filter: 'Filter the options',
  noMedia: 'Nothing to play yet.',
  sending: 'Sending…',
};

const MAX_DEPTH = 40;
let instances = 0;

/** The version family of a `version` value: "v0.9" (v0.9 and v0.9.1) or "v1.0". */
export function versionFamily(version) {
  if (version === 'v0.9' || version === 'v0.9.1') return 'v0.9';
  if (version === 'v1.0') return 'v1.0';
  return null;
}

/**
 * What a client built on this renderer can advertise, per version:
 * `{ a2uiClientCapabilities: {"v0.9": {...}} }` or the v1.0 equivalent.
 */
export function capabilities(version = 'v0.9.1') {
  const fam = versionFamily(version) || 'v0.9';
  return { [CAPABILITIES_MEMBER[fam]]: { [fam]: { supportedCatalogIds: [CATALOG_IDS[fam][0]] } } };
}

// ---- JSON Pointer ------------------------------------------------------------

function tokens(pointer) {
  if (pointer === '' || pointer === '/') return [];
  return pointer.replace(/^\//, '').split('/').map((t) => t.replace(/~1/g, '/').replace(/~0/g, '~'));
}

function pointerGet(data, pointer) {
  let current = data;
  for (const token of tokens(pointer)) {
    if (current !== null && typeof current === 'object' && Object.prototype.hasOwnProperty.call(current, token)) {
      current = current[token];
    } else {
      return undefined;
    }
  }
  return current;
}

function pointerSet(data, pointer, value) {
  const parts = tokens(pointer);
  if (parts.length === 0) return;
  let current = data;
  for (let i = 0; i < parts.length - 1; i++) {
    const next = current[parts[i]];
    if (next === null || typeof next !== 'object') {
      current[parts[i]] = /^\d+$/.test(parts[i + 1]) ? [] : {};
    }
    current = current[parts[i]];
  }
  current[parts[parts.length - 1]] = value;
}

function pointerDelete(data, pointer) {
  const parts = tokens(pointer);
  if (parts.length === 0) return;
  const parent = pointerGet(data, '/' + parts.slice(0, -1).join('/'));
  const last = parts[parts.length - 1];
  if (Array.isArray(parent) && /^\d+$/.test(last)) {
    parent[Number(last)] = undefined;
  } else if (parent && typeof parent === 'object') {
    delete parent[last];
  }
}

const clone = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const isBinding = (value) => isObject(value) && typeof value.path === 'string' && !('call' in value);
const isCall = (value) => isObject(value) && typeof value.call === 'string';

function toText(value) {
  if (value === null || value === undefined) return '';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

// ---- icons (24 × 24, stroked, drawn for this renderer) ----------------------

const circle = (cx, cy, r) => `M${cx - r} ${cy}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0`;
const rect = (x, y, w, h, r) => `M${x + r} ${y}h${w - 2 * r}a${r} ${r} 0 0 1 ${r} ${r}v${h - 2 * r}a${r} ${r} 0 0 1 ${-r} ${r}h${-(w - 2 * r)}a${r} ${r} 0 0 1 ${-r} ${-r}v${-(h - 2 * r)}a${r} ${r} 0 0 1 ${r} ${-r}z`;
const heart = 'M12 20s-7-4.4-9-8.6C1.6 8.3 3.5 5 6.8 5c2 0 3.4 1.1 5.2 3 1.8-1.9 3.2-3 5.2-3 3.3 0 5.2 3.3 3.8 6.4C19 15.6 12 20 12 20z';
const star = 'M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z';
const bell = 'M6 10a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6M10 20a2 2 0 0 0 4 0';
const eye = `M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z${circle(12, 12, 3)}`;
const speaker = 'M4 9h4l5-4v14l-5-4H4z';
const calendar = `${rect(3, 5, 18, 16, 2)}M3 10h18M8 3v4M16 3v4`;
const slash = 'M3 3l18 18';

const ICONS = {
  accountCircle: [circle(12, 12, 9) + circle(12, 10, 3) + 'M6.2 18.6a7 7 0 0 1 11.6 0'],
  add: ['M12 5v14M5 12h14'],
  arrowBack: ['M19 12H5M11 6l-6 6 6 6'],
  arrowForward: ['M5 12h14M13 6l6 6-6 6'],
  attachFile: ['M16.5 6.5v9a4.5 4.5 0 0 1-9 0V5a3 3 0 0 1 6 0v10a1.5 1.5 0 0 1-3 0V6.5'],
  calendarToday: [calendar],
  call: ['M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2'],
  camera: ['M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z' + circle(12, 13.5, 3.5)],
  check: ['M5 12.5l4.5 4.5L19 7.5'],
  close: ['M6 6l12 12M18 6L6 18'],
  delete: ['M4 7h16M10 11v6M14 11v6M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13M9 7V4h6v3'],
  download: ['M12 4v12M7 11l5 5 5-5M5 20h14'],
  edit: ['M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4'],
  event: [calendar + 'M9 15.5l2 2 4-4'],
  error: [circle(12, 12, 9) + 'M12 7.5v5.5M12 16.5h.01'],
  fastForward: ['M3 6l8 6-8 6zM12 6l8 6-8 6z'],
  favorite: [heart],
  favoriteOff: [heart + slash],
  folder: ['M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z'],
  help: [circle(12, 12, 9) + 'M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7v.5M12 17h.01'],
  home: ['M3 11l9-7 9 7M5 9.5V20h5v-6h4v6h5V9.5'],
  info: [circle(12, 12, 9) + 'M12 11v6M12 7.5h.01'],
  locationOn: ['M12 21s-7-6.1-7-11.5a7 7 0 0 1 14 0C19 14.9 12 21 12 21z' + circle(12, 9.5, 2.5)],
  lock: [rect(5, 11, 14, 10, 2) + 'M8 11V7a4 4 0 0 1 8 0v4'],
  lockOpen: [rect(5, 11, 14, 10, 2) + 'M8 11V7a4 4 0 0 1 7.5-2'],
  mail: [rect(3, 5, 18, 14, 2) + 'M3.5 6l8.5 7 8.5-7'],
  menu: ['M4 6h16M4 12h16M4 18h16'],
  moreVert: [circle(12, 5, 1) + circle(12, 12, 1) + circle(12, 19, 1)],
  moreHoriz: [circle(5, 12, 1) + circle(12, 12, 1) + circle(19, 12, 1)],
  notificationsOff: [bell + slash],
  notifications: [bell],
  pause: ['M8 5v14M16 5v14'],
  payment: [rect(3, 6, 18, 13, 2) + 'M3 10h18M7 15h4'],
  person: [circle(12, 8, 4) + 'M4 21a8 8 0 0 1 16 0'],
  phone: [rect(7, 2.5, 10, 19, 2) + 'M11 18h2'],
  photo: [rect(3, 5, 18, 14, 2) + circle(9, 10, 1.5) + 'M21 16l-5-5-8 8'],
  play: ['M7 5l12 7-12 7z'],
  print: ['M7 9V3h10v6M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7z'],
  refresh: ['M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6'],
  rewind: ['M21 6l-8 6 8 6zM12 6l-8 6 8 6z'],
  search: [circle(11, 11, 6.5) + 'M16 16l5 5'],
  send: ['M4 12l16-8-6 16-3-6.5zM11 13.5L20 4'],
  settings: [circle(12, 12, 3) + 'M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1L7 17M17 7l2.1-2.1'],
  share: [circle(18, 5, 2.5) + circle(6, 12, 2.5) + circle(18, 19, 2.5) + 'M8.2 10.8l7.6-4.4M8.2 13.2l7.6 4.4'],
  shoppingCart: ['M3 4h2l2.4 11h10.8L20 8H6.2' + circle(9, 19.5, 1.5) + circle(17, 19.5, 1.5)],
  skipNext: ['M6 6l9 6-9 6zM18 6v12'],
  skipPrevious: ['M18 6l-9 6 9 6zM6 6v12'],
  star: [star],
  starHalf: [star, { d: 'M12 3.5v13.4l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z', fill: true }],
  starOff: [star + slash],
  stop: [rect(6, 6, 12, 12, 1)],
  upload: ['M12 20V8M7 13l5-5 5 5M5 4h14'],
  visibility: [eye],
  visibilityOff: [eye + slash],
  volumeDown: [speaker + 'M16 9.5a3.5 3.5 0 0 1 0 5'],
  volumeMute: [speaker],
  volumeOff: [speaker + 'M16 9l5 6M21 9l-5 6'],
  volumeUp: [speaker + 'M16 9.5a3.5 3.5 0 0 1 0 5M18.5 7a7 7 0 0 1 0 10'],
  warning: ['M12 3L2 20h20zM12 9.5v4.5M12 17h.01'],
};

const SVG_NS = 'http://www.w3.org/2000/svg';
const SVG_PATH_PATTERN = /^[MmLlHhVvCcSsQqTtAaZz0-9eE\s,.+-]{1,4000}$/;

// ---- functions of the basic catalogue ------------------------------------------

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function formatDateWith(date, pattern, locale) {
  const pad = (n, width = 2) => String(n).padStart(width, '0');
  const month = (style) => new Intl.DateTimeFormat(locale, { month: style }).format(date);
  const weekday = (style) => new Intl.DateTimeFormat(locale, { weekday: style }).format(date);
  const hours12 = date.getHours() % 12 || 12;
  return pattern.replace(/('[^']*'|yyyy|YYYY|yy|YY|y|Y|MMMM|MMM|MM|M|dd|d|EEEE|EEE|E|HH|H|hh|h|mm|m|ss|s|a)/g, (token) => {
    switch (token) {
      case 'yyyy': case 'YYYY': case 'y': case 'Y': return String(date.getFullYear());
      case 'yy': case 'YY': return pad(date.getFullYear() % 100);
      case 'MMMM': return month('long');
      case 'MMM': return month('short');
      case 'MM': return pad(date.getMonth() + 1);
      case 'M': return String(date.getMonth() + 1);
      case 'dd': return pad(date.getDate());
      case 'd': return String(date.getDate());
      case 'EEEE': return weekday('long');
      case 'EEE': case 'E': return weekday('short');
      case 'HH': return pad(date.getHours());
      case 'H': return String(date.getHours());
      case 'hh': return pad(hours12);
      case 'h': return String(hours12);
      case 'mm': return pad(date.getMinutes());
      case 'm': return String(date.getMinutes());
      case 'ss': return pad(date.getSeconds());
      case 's': return String(date.getSeconds());
      case 'a': return date.getHours() < 12 ? 'AM' : 'PM';
      default: return token.slice(1, -1);
    }
  });
}

/** Index of the brace that closes the one opened just before `start`, or -1. */
function closingBrace(text, start) {
  let depth = 1;
  let quote = null;
  for (let i = start; i < text.length; i++) {
    const ch = text[i];
    if (quote) {
      if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"') quote = ch;
    else if (ch === '{') depth++;
    else if (ch === '}' && --depth === 0) return i;
  }
  return -1;
}

/** Split `a: 1, b: ${x}` at top-level commas. */
function splitArguments(text) {
  const parts = [];
  let depth = 0;
  let quote = null;
  let current = '';
  for (const ch of text) {
    if (quote) {
      if (ch === quote) quote = null;
      current += ch;
      continue;
    }
    if (ch === "'" || ch === '"') quote = ch;
    else if (ch === '{' || ch === '(') depth++;
    else if (ch === '}' || ch === ')') depth--;
    if (ch === ',' && depth === 0) {
      parts.push(current);
      current = '';
    } else {
      current += ch;
    }
  }
  if (current.trim() !== '') parts.push(current);
  return parts;
}

// ---- the renderer ---------------------------------------------------------------

export class A2uiRenderer {
  /**
   * @param {HTMLElement} mount where surfaces are drawn
   * @param {object} options
   *   onAction(message, metadata, info) → Promise<Array> of reply messages (or nothing)
   *   onError(message)   a renderer-to-agent error message to report
   *   onChange(surfaceId, dataModel)
   *   onLifecycle(type, surfaceId)  "created" | "updated" | "deleted"
   *   labels, classes, headingBase (level of a surface's top heading), locale
   */
  constructor(mount, options = {}) {
    this.mount = mount;
    this.onAction = options.onAction || null;
    this.onError = options.onError || (() => {});
    this.onChange = options.onChange || (() => {});
    this.onLifecycle = options.onLifecycle || (() => {});
    this.labels = { ...DEFAULT_LABELS, ...(options.labels || {}) };
    this.classes = options.classes || {};
    this.headingBase = options.headingBase || 3;
    this.locale = options.locale || document.documentElement.lang || undefined;
    this.prefix = `a2ui-${++instances}`;
    this.surfaces = new Map();
  }

  /** Process messages in order; surfaces are drawn once at the end. */
  processAll(messages) {
    const touched = new Set();
    for (const message of messages || []) {
      const id = this.apply(message);
      if (id) touched.add(id);
    }
    touched.forEach((id) => this.draw(id));
  }

  process(message) {
    this.processAll([message]);
  }

  dataModel(surfaceId) {
    const state = this.surfaces.get(surfaceId);
    return state ? clone(state.dataModel) : undefined;
  }

  /** Move focus to a surface: its first heading, otherwise its first control. */
  focus(surfaceId) {
    const state = this.surfaces.get(surfaceId);
    if (!state || !state.el.isConnected) return;
    const target = state.el.querySelector('.a2ui-heading')
      || state.el.querySelector('input, select, textarea, button, [tabindex="0"]');
    if (target) {
      if (target.classList.contains('a2ui-heading')) target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: false });
    }
  }

  clear() {
    this.surfaces.forEach((state) => state.el.remove());
    this.surfaces.clear();
  }

  // ---- messages ----------------------------------------------------------------

  /** Apply one message; returns the id of a surface that needs drawing. */
  apply(message) {
    if (!isObject(message)) return null;
    const fam = versionFamily(message.version);
    const types = Object.keys(message).filter((key) => key !== 'version');
    if (!fam || types.length !== 1 || !MESSAGE_TYPES[fam].includes(types[0])) {
      this.report(message.version, 'INVALID_MESSAGE', '', 'A message must carry a known version and exactly one message.');
      return null;
    }
    const type = types[0];
    const body = message[type];
    if (!isObject(body)) return null;

    if (type === 'callRendererFunction') {
      this.onError({
        version: message.version,
        error: { code: 'UNKNOWN_FUNCTION', message: 'This renderer runs no functions for the agent.', functionCallId: String(body.functionCallId || '') },
      });
      return null;
    }
    if (type === 'agentFunctionResponse') return null;

    const surfaceId = typeof body.surfaceId === 'string' ? body.surfaceId : '';
    if (type === 'createSurface') return this.createSurface(message.version, fam, body);

    const state = this.surfaces.get(surfaceId);
    if (!state) {
      this.report(message.version, 'SURFACE_NOT_FOUND', surfaceId, `There is no surface "${surfaceId}".`);
      return null;
    }
    if (type === 'deleteSurface') {
      state.el.remove();
      this.surfaces.delete(surfaceId);
      this.onLifecycle('deleted', surfaceId);
      return null;
    }
    if (type === 'updateComponents') {
      (Array.isArray(body.components) ? body.components : []).forEach((component) => {
        if (isObject(component) && typeof component.id === 'string') state.components.set(component.id, component);
      });
    } else if (type === 'updateDataModel') {
      const path = typeof body.path === 'string' ? body.path : '/';
      const remove = fam === 'v1.0' ? body.value === null : !('value' in body);
      if (path === '/' || path === '') {
        state.dataModel = remove || !isObject(body.value) ? {} : clone(body.value);
      } else if (remove) {
        pointerDelete(state.dataModel, path);
      } else {
        pointerSet(state.dataModel, path, clone(body.value));
      }
      this.onChange(surfaceId, clone(state.dataModel));
    }
    this.onLifecycle('updated', surfaceId);
    return surfaceId;
  }

  createSurface(version, fam, body) {
    const surfaceId = typeof body.surfaceId === 'string' ? body.surfaceId : '';
    if (surfaceId === '') {
      this.report(version, 'VALIDATION_FAILED', '', 'createSurface needs a surfaceId.', '/createSurface/surfaceId');
      return null;
    }
    if (this.surfaces.has(surfaceId)) {
      this.report(version, 'SURFACE_EXISTS', surfaceId, `The surface "${surfaceId}" already exists; delete it first.`);
      return null;
    }
    if (!CATALOG_IDS[fam].includes(body.catalogId)) {
      this.report(version, 'VALIDATION_FAILED', surfaceId, 'This renderer knows only the A2UI basic catalogue.', '/createSurface/catalogId');
      return null;
    }
    const el = document.createElement('div');
    el.className = 'a2ui-surface';
    el.dataset.surfaceId = surfaceId;
    el.dataset.a2uiVersion = version;
    const theme = isObject(body.theme) ? body.theme : {};
    if (typeof theme.primaryColor === 'string' && /^#[0-9a-fA-F]{6}$/.test(theme.primaryColor)) {
      el.style.setProperty('--a2ui-primary', theme.primaryColor);
    }
    const content = document.createElement('div');
    content.className = 'a2ui-surface__content';
    const live = document.createElement('div');
    live.className = 'a2ui-visually-hidden';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    el.append(content, live);
    this.mount.appendChild(el);

    const state = {
      id: surfaceId,
      version,
      fam,
      sendDataModel: body.sendDataModel === true,
      components: new Map(),
      dataModel: isObject(body.dataModel) ? clone(body.dataModel) : {},
      el,
      content,
      live,
      effects: [],
      fields: [],
      touched: new Set(),
      tabs: new Map(),
      attempted: false,
      headingOffset: 0,
    };
    (Array.isArray(body.components) ? body.components : []).forEach((component) => {
      if (isObject(component) && typeof component.id === 'string') state.components.set(component.id, component);
    });
    this.surfaces.set(surfaceId, state);
    this.onLifecycle('created', surfaceId);
    return surfaceId;
  }

  report(version, code, surfaceId, message, path) {
    const error = { code, surfaceId, message };
    if (code === 'VALIDATION_FAILED') error.path = path || '/';
    this.onError({ version: versionFamily(version) ? version : 'v0.9.1', error });
  }

  // ---- drawing -------------------------------------------------------------------

  draw(surfaceId) {
    const state = this.surfaces.get(surfaceId);
    if (!state) return;
    const root = state.components.get('root');
    const active = document.activeElement;
    const focusKey = active && state.el.contains(active) ? active.dataset.a2uiKey : null;
    const selection = focusKey && typeof active.selectionStart === 'number'
      ? [active.selectionStart, active.selectionEnd] : null;

    state.effects = [];
    state.fields = [];
    state.content.replaceChildren();
    if (!root) return;
    state.headingOffset = this.minimumHeading(state);
    state.content.appendChild(this.build(state, root, { base: '', index: null }, { depth: 0, stack: new Set(), parent: null }));
    this.refresh(state);

    if (focusKey) {
      const again = state.el.querySelector(`[data-a2ui-key="${CSS.escape(focusKey)}"]`);
      if (again) {
        again.focus({ preventScroll: true });
        if (selection && typeof again.setSelectionRange === 'function') {
          try { again.setSelectionRange(selection[0], selection[1]); } catch (e) { /* not a text control */ }
        }
      }
    }
  }

  /** Re-run every binding of a surface: text, values of other inputs, checks. */
  refresh(state) {
    state.effects.forEach((effect) => effect());
  }

  build(state, component, scope, ctx) {
    if (!component || typeof component.component !== 'string') return document.createComment(' a2ui: missing component ');
    if (ctx.depth > MAX_DEPTH || ctx.stack.has(component.id + '@' + scope.base)) {
      return document.createComment(' a2ui: loop ');
    }
    const builder = BUILDERS[component.component];
    if (!builder) {
      this.report(state.version, 'VALIDATION_FAILED', state.id, `"${component.component}" is not in the basic catalogue.`, `/components/${component.id}/component`);
      return document.createComment(` a2ui: ${component.component} is not in the catalogue `);
    }
    const inner = { ...ctx, depth: ctx.depth + 1, stack: new Set([...ctx.stack, component.id + '@' + scope.base]), parent: component.component };
    const el = builder.call(this, state, component, scope, inner);
    if (el instanceof Element) {
      this.common(state, el, component, scope, ctx);
    }
    return el;
  }

  /** Children by id, or a template repeated for every item of a list. */
  children(state, list, scope, ctx, wrap) {
    const nodes = [];
    if (Array.isArray(list)) {
      list.forEach((id) => {
        const child = state.components.get(id);
        if (child) nodes.push(this.build(state, child, scope, ctx));
      });
    } else if (isObject(list) && typeof list.componentId === 'string' && typeof list.path === 'string') {
      const path = this.path(list.path, scope);
      const items = pointerGet(state.dataModel, path);
      const template = state.components.get(list.componentId);
      if (Array.isArray(items) && template) {
        items.forEach((item, index) => {
          if (item !== undefined) nodes.push(this.build(state, template, { base: `${path}/${index}`, index }, ctx));
        });
      }
    }
    return wrap ? nodes.map(wrap) : nodes;
  }

  child(state, id, scope, ctx) {
    const component = typeof id === 'string' ? state.components.get(id) : null;
    return component ? this.build(state, component, scope, ctx) : document.createComment(` a2ui: waiting for ${id} `);
  }

  /** accessibility and weight, which every component may carry. */
  common(state, el, component, scope, ctx) {
    const target = el.matches('.a2ui-field, .a2ui-button-wrap')
      ? (el.querySelector('fieldset, input, textarea, select, button') || el)
      : el;
    const a11y = isObject(component.accessibility) ? component.accessibility : null;
    if (a11y) {
      if (a11y.label !== undefined) {
        this.bindText(state, a11y.label, scope, (text) => { if (text) target.setAttribute('aria-label', text); });
      }
      if (a11y.description !== undefined) {
        const description = document.createElement('span');
        description.className = 'a2ui-visually-hidden';
        description.id = this.domId(state, component, scope, 'description');
        el.appendChild(description);
        this.describe(target, description.id);
        this.bindText(state, a11y.description, scope, (text) => { description.textContent = text; });
      }
      if (['polite', 'assertive', 'off'].includes(a11y.live)) el.setAttribute('aria-live', a11y.live);
      if (a11y.hidden !== undefined) {
        state.effects.push(() => {
          if (this.resolve(state, a11y.hidden, scope) === true) el.setAttribute('aria-hidden', 'true');
          else el.removeAttribute('aria-hidden');
        });
      }
    }
    if (typeof component.weight === 'number' && (ctx.parent === 'Row' || ctx.parent === 'Column')) {
      el.style.flexGrow = String(component.weight);
      el.style.flexBasis = '0';
      el.style.minWidth = '0';
    }
  }

  // ---- values --------------------------------------------------------------------

  path(path, scope) {
    if (path.startsWith('/')) return path;
    return (scope.base || '') + '/' + path;
  }

  resolve(state, value, scope) {
    if (isBinding(value)) return pointerGet(state.dataModel, this.path(value.path, scope));
    if (isCall(value)) return this.call(state, value, scope);
    return value;
  }

  args(state, call, scope) {
    const out = {};
    const args = isObject(call.args) ? call.args : {};
    Object.keys(args).forEach((key) => {
      const value = args[key];
      out[key] = Array.isArray(value) ? value.map((item) => this.resolve(state, item, scope)) : this.resolve(state, value, scope);
    });
    return out;
  }

  call(state, call, scope) {
    const a = this.args(state, call, scope);
    const number = (v) => (typeof v === 'number' ? v : Number(v));
    switch (call.call) {
      case 'required':
        return !(a.value === null || a.value === undefined || a.value === '' || (Array.isArray(a.value) && a.value.length === 0));
      case 'regex':
        try { return new RegExp(String(a.pattern)).test(toText(a.value)); } catch (e) { return false; }
      case 'length': {
        const length = toText(a.value).length;
        return (a.min === undefined || length >= a.min) && (a.max === undefined || length <= a.max);
      }
      case 'numeric': {
        const n = number(a.value);
        if (a.value === '' || a.value === null || a.value === undefined || Number.isNaN(n)) return false;
        return (a.min === undefined || n >= a.min) && (a.max === undefined || n <= a.max);
      }
      case 'email':
        return EMAIL.test(toText(a.value));
      case 'formatString':
        return this.interpolate(state, toText(a.value), scope);
      case 'formatNumber':
      case 'formatCurrency': {
        const decimals = a.decimals === undefined ? {} : { minimumFractionDigits: number(a.decimals), maximumFractionDigits: number(a.decimals) };
        const style = call.call === 'formatCurrency' ? { style: 'currency', currency: toText(a.currency) || 'EUR' } : {};
        try {
          return new Intl.NumberFormat(this.locale, { useGrouping: a.grouping !== false, ...decimals, ...style }).format(number(a.value) || 0);
        } catch (e) {
          return toText(a.value);
        }
      }
      case 'formatDate': {
        const date = a.value instanceof Date ? a.value : new Date(toText(a.value));
        return Number.isNaN(date.getTime()) ? '' : formatDateWith(date, toText(a.format) || 'yyyy-MM-dd', this.locale);
      }
      case 'pluralize': {
        let category = 'other';
        try { category = new Intl.PluralRules(this.locale).select(number(a.value) || 0); } catch (e) { /* default */ }
        if (number(a.value) === 0 && a.zero !== undefined) category = 'zero';
        return toText(a[category] !== undefined ? a[category] : a.other);
      }
      case 'and':
        return (Array.isArray(a.values) ? a.values : []).every((v) => this.truthy(v));
      case 'or':
        return (Array.isArray(a.values) ? a.values : []).some((v) => this.truthy(v));
      case 'not':
        return !this.truthy(a.value);
      case '@index':
        return scope.index === null ? undefined : scope.index + (number(a.offset) || 0);
      case 'openUrl':
        return undefined;
      default:
        return undefined;
    }
  }

  truthy(value) {
    return isObject(value) && 'valid' in value ? value.valid === true : value === true;
  }

  /** formatString: ${/abs}, ${rel}, ${fn(arg: value)}, nested ${…}; \${ is a literal. */
  interpolate(state, template, scope) {
    let out = '';
    let i = 0;
    while (i < template.length) {
      if (template.startsWith('\\${', i)) {
        out += '${';
        i += 3;
      } else if (template.startsWith('${', i)) {
        const end = closingBrace(template, i + 2);
        if (end < 0) {
          out += template.slice(i);
          break;
        }
        out += toText(this.expression(state, template.slice(i + 2, end).trim(), scope));
        i = end + 1;
      } else {
        out += template[i];
        i++;
      }
    }
    return out;
  }

  expression(state, text, scope) {
    if (text.startsWith('${') && text.endsWith('}')) return this.expression(state, text.slice(2, -1).trim(), scope);
    const call = /^([@A-Za-z_][\w@]*)\s*\(([\s\S]*)\)$/.exec(text);
    if (call) {
      const args = {};
      splitArguments(call[2]).forEach((part) => {
        const colon = part.indexOf(':');
        if (colon > 0) args[part.slice(0, colon).trim()] = this.literal(state, part.slice(colon + 1).trim(), scope);
      });
      return this.call(state, { call: call[1], args }, scope);
    }
    return this.literal(state, text, scope);
  }

  literal(state, text, scope) {
    if (/^'.*'$|^".*"$/s.test(text)) return text.slice(1, -1);
    if (/^-?\d+(\.\d+)?$/.test(text)) return Number(text);
    if (text === 'true') return true;
    if (text === 'false') return false;
    if (text === 'null') return null;
    if (text.startsWith('${')) return this.expression(state, text, scope);
    return pointerGet(state.dataModel, this.path(text, scope));
  }

  /** Run `apply(text)` now and whenever the value may have changed. */
  bindText(state, value, scope, apply) {
    const update = () => apply(toText(this.resolve(state, value, scope)));
    if (isBinding(value) || isCall(value)) state.effects.push(update);
    update();
  }

  domId(state, component, scope, suffix = '') {
    const base = `${this.prefix}-${state.id}-${component.id}${scope.base.replace(/[^A-Za-z0-9_-]/g, '-')}`;
    return (suffix ? `${base}-${suffix}` : base).replace(/[^A-Za-z0-9_-]/g, '-');
  }

  describe(el, id) {
    const ids = new Set((el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    ids.add(id);
    el.setAttribute('aria-describedby', [...ids].join(' '));
  }

  addClass(el, role) {
    const extra = this.classes[role];
    if (extra) el.classList.add(...String(extra).split(/\s+/).filter(Boolean));
  }

  write(state, component, scope, value) {
    if (!isBinding(component.value)) return;
    pointerSet(state.dataModel, this.path(component.value.path, scope), value);
    this.refresh(state);
    this.onChange(state.id, clone(state.dataModel));
  }

  announce(state, text) {
    state.live.textContent = '';
    window.setTimeout(() => { state.live.textContent = text; }, 50);
  }

  // ---- checks --------------------------------------------------------------------

  failures(state, component, scope) {
    const messages = [];
    const checks = Array.isArray(component.checks) ? component.checks : [];
    checks.forEach((check) => {
      if (!isObject(check)) return;
      const result = this.resolve(state, check.condition, scope);
      const valid = isObject(result) && 'valid' in result ? result.valid === true : result === true;
      if (!valid) {
        const fallback = isObject(result) && typeof result.message === 'string' ? result.message : this.labels.invalid;
        messages.push(typeof check.message === 'string' && check.message !== '' ? check.message : fallback);
      }
    });
    if (typeof component.validationRegexp === 'string' && isBinding(component.value)) {
      try {
        if (!new RegExp(component.validationRegexp).test(toText(this.resolve(state, component.value, scope)))) messages.push(this.labels.invalid);
      } catch (e) { /* an unusable pattern checks nothing */ }
    }
    return messages;
  }

  isRequired(component) {
    return (Array.isArray(component.checks) ? component.checks : []).some((check) => isObject(check)
      && isCall(check.condition) && check.condition.call === 'required'
      && isObject(check.condition.args) && isBinding(check.condition.args.value)
      && isBinding(component.value) && check.condition.args.value.path === component.value.path);
  }

  /**
   * Register an input's checks: errors show once the field was left or a
   * send was attempted, and are tied to the control for assistive tech.
   */
  checkable(state, component, scope, wrap, control) {
    if (!Array.isArray(component.checks) && typeof component.validationRegexp !== 'string') return;
    const key = this.domId(state, component, scope);
    const error = document.createElement('p');
    error.className = 'a2ui-field__error';
    error.id = key + '-error';
    error.hidden = true;
    this.addClass(error, 'error');
    wrap.appendChild(error);
    // A group (a ChoicePicker's fieldset) is described as a whole, its inputs carry aria-invalid.
    const group = control.matches('fieldset');
    const inputs = () => (group ? [...control.querySelectorAll('input:not([type="search"])')] : [control]);
    const field = {
      key,
      path: isBinding(component.value) ? this.path(component.value.path, scope) : null,
      focus: () => { const first = inputs()[0]; if (first) first.focus(); },
      evaluate: () => this.failures(state, component, scope),
    };
    const show = () => {
      const messages = field.evaluate();
      const visible = messages.length > 0 && (state.touched.has(key) || state.attempted);
      error.hidden = !visible;
      error.textContent = visible ? messages.join(' ') : '';
      inputs().forEach((input) => {
        input.setAttribute('aria-invalid', visible ? 'true' : 'false');
        input.classList.toggle('is-invalid', visible);
      });
      if (visible) this.describe(control, error.id);
      wrap.classList.toggle('is-invalid', visible);
      return messages.length === 0;
    };
    field.show = show;
    const leave = () => { state.touched.add(key); show(); };
    control.addEventListener('blur', leave, true);
    control.addEventListener('change', leave);
    state.fields.push(field);
    state.effects.push(show);
  }

  // ---- actions -------------------------------------------------------------------

  async dispatch(state, component, scope, button) {
    const action = isObject(component.action) ? component.action : null;
    if (!action) return;
    if (isObject(action.functionCall)) {
      this.local(state, action.functionCall, scope);
      return;
    }
    if (!isObject(action.event) || typeof action.event.name !== 'string') return;

    const event = action.event;
    const context = {};
    const paths = new Set();
    Object.keys(isObject(event.context) ? event.context : {}).forEach((key) => {
      const value = event.context[key];
      if (isBinding(value)) paths.add(this.path(value.path, scope));
      const resolved = this.resolve(state, value, scope);
      context[key] = resolved === undefined ? null : clone(resolved);
    });

    // The fields whose values this action sends must be valid first.
    const invalid = state.fields.filter((field) => field.path !== null && paths.has(field.path) && field.evaluate().length > 0);
    if (invalid.length > 0) {
      state.attempted = true;
      this.refresh(state);
      invalid[0].focus();
      this.announce(state, this.labels.checkFields);
      return;
    }

    const message = {
      version: state.version,
      action: {
        name: event.name,
        surfaceId: state.id,
        sourceComponentId: component.id,
        timestamp: new Date().toISOString(),
        context,
      },
    };
    if (state.fam === 'v1.0' && event.userMessage !== undefined) {
      message.action.userMessage = toText(this.resolve(state, event.userMessage, scope));
    }
    const metadata = state.sendDataModel
      ? { [DATA_MODEL_MEMBER[state.fam]]: { version: state.version, surfaces: { [state.id]: clone(state.dataModel) } } }
      : {};
    if (!this.onAction) return;

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
      const replies = await this.onAction(message, metadata, { surfaceId: state.id, componentId: component.id });
      if (Array.isArray(replies)) this.processAll(replies);
    } finally {
      if (button.isConnected) {
        button.removeAttribute('aria-busy');
        this.refresh(this.surfaces.get(state.id) || state);
        if (!button.dataset.a2uiBlocked) button.disabled = false;
      }
    }
  }

  local(state, call, scope) {
    if (call.call !== 'openUrl') {
      this.call(state, call, scope);
      return;
    }
    const target = toText(this.resolve(state, isObject(call.args) ? call.args.url : '', scope));
    let url;
    try { url = new URL(target, window.location.href); } catch (e) { return; }
    if (url.protocol === 'https:' || url.protocol === 'http:') {
      window.open(url.href, '_blank', 'noopener,noreferrer');
    }
  }

  // ---- heading levels ------------------------------------------------------------

  /** A surface's own top heading level (h1 = 1), so it can sit under the host's headings. */
  minimumHeading(state) {
    let minimum = 6;
    state.components.forEach((component) => {
      if (component.component !== 'Text') return;
      const variant = /^h([1-5])$/.exec(component.variant || '');
      if (variant) minimum = Math.min(minimum, Number(variant[1]));
      if (typeof component.text === 'string') {
        const markdown = /^(#{1,6})\s/.exec(component.text);
        if (markdown) minimum = Math.min(minimum, markdown[1].length);
      }
    });
    return minimum;
  }

  headingLevel(state, level) {
    return Math.min(6, this.headingBase + (level - state.headingOffset));
  }

  /** Simple Markdown, as text nodes: **bold**, *italic*, `code`, line breaks. */
  inline(parent, text) {
    const pattern = /(\*\*[^*]+\*\*|__[^_]+__|\*[^*\s][^*]*\*|_[^_\s][^_]*_|`[^`]+`|\n)/g;
    let last = 0;
    for (const match of text.matchAll(pattern)) {
      if (match.index > last) parent.appendChild(document.createTextNode(text.slice(last, match.index)));
      const token = match[0];
      if (token === '\n') {
        parent.appendChild(document.createElement('br'));
      } else {
        const tag = token.startsWith('`') ? 'code' : (token.startsWith('**') || token.startsWith('__') ? 'strong' : 'em');
        const trim = tag === 'strong' ? 2 : 1;
        const el = document.createElement(tag);
        el.textContent = token.slice(trim, -trim);
        parent.appendChild(el);
      }
      last = match.index + token.length;
    }
    if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
  }
}

// ---- components -----------------------------------------------------------------

function field(renderer, state, component, scope, className) {
  const wrap = document.createElement('div');
  wrap.className = `a2ui-field ${className}`;
  const id = renderer.domId(state, component, scope);
  return { wrap, id };
}

function fieldLabel(renderer, state, component, scope, id, required) {
  const label = document.createElement('label');
  label.className = 'a2ui-field__label';
  label.htmlFor = id;
  renderer.addClass(label, 'label');
  const text = document.createElement('span');
  renderer.bindText(state, component.label, scope, (value) => { text.textContent = value; });
  label.appendChild(text);
  if (required) {
    const mark = document.createElement('span');
    mark.className = 'a2ui-field__required';
    mark.textContent = ` (${renderer.labels.required})`;
    label.appendChild(mark);
  }
  return label;
}

function isoToInput(value, type) {
  const text = toText(value);
  if (text === '') return '';
  if (type === 'time') {
    const match = /(\d{2}:\d{2})/.exec(text);
    return match ? match[1] : '';
  }
  const date = new Date(text);
  if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return type === 'date' ? text : `${text}T00:00`;
  if (Number.isNaN(date.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  const day = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  return type === 'date' ? day : `${day}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function inputToIso(value, type) {
  if (value === '') return '';
  if (type === 'date') return value;
  if (type === 'time') return `${value}:00`;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  const pad = (n) => String(Math.abs(n)).padStart(2, '0');
  const offset = -date.getTimezoneOffset();
  const zone = `${offset >= 0 ? '+' : '-'}${pad(Math.trunc(offset / 60))}:${pad(offset % 60)}`;
  return `${value}:00${zone}`;
}

function safeUrl(value) {
  const text = toText(value).trim();
  if (text === '') return '';
  try {
    const url = new URL(text, window.location.href);
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
  } catch (e) {
    return '';
  }
}

const BUILDERS = {
  Text(state, c, scope) {
    const el = document.createElement('div');
    el.className = 'a2ui-text';
    const render = (text) => {
      el.replaceChildren();
      const heading = /^h([1-5])$/.exec(c.variant || '');
      const markdown = /^(#{1,6})\s+([\s\S]*)$/.exec(text);
      if (heading || markdown) {
        const level = this.headingLevel(state, heading ? Number(heading[1]) : markdown[1].length);
        const h = document.createElement(`h${level}`);
        h.className = 'a2ui-heading';
        this.inline(h, markdown ? markdown[2] : text);
        el.appendChild(h);
        return;
      }
      const p = document.createElement('p');
      p.className = c.variant === 'caption' ? 'a2ui-text__caption' : 'a2ui-text__body';
      this.inline(p, text);
      el.appendChild(p);
    };
    this.bindText(state, c.text, scope, render);
    return el;
  },

  Image(state, c, scope) {
    const img = document.createElement('img');
    img.className = `a2ui-image a2ui-image--${['icon', 'avatar', 'smallFeature', 'mediumFeature', 'largeFeature', 'header'].includes(c.variant) ? c.variant : 'mediumFeature'}`;
    img.loading = 'lazy';
    img.decoding = 'async';
    img.style.objectFit = { contain: 'contain', cover: 'cover', fill: 'fill', none: 'none', scaleDown: 'scale-down' }[c.fit] || 'fill';
    this.bindText(state, c.url, scope, (url) => { img.src = safeUrl(url); });
    this.bindText(state, c.description !== undefined ? c.description : '', scope, (text) => { img.alt = text; });
    return img;
  },

  Icon(state, c, scope) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'a2ui-icon');
    svg.setAttribute('focusable', 'false');
    const labelled = isObject(c.accessibility) && c.accessibility.label !== undefined;
    if (labelled) svg.setAttribute('role', 'img');
    else svg.setAttribute('aria-hidden', 'true');
    const draw = () => {
      svg.replaceChildren();
      let parts = null;
      if (isObject(c.name) && 'svgPath' in c.name) {
        const d = toText(this.resolve(state, c.name.svgPath, scope));
        parts = SVG_PATH_PATTERN.test(d) ? [d] : [];
      } else {
        parts = ICONS[toText(this.resolve(state, c.name, scope))] || ICONS.help;
      }
      parts.forEach((part) => {
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', typeof part === 'string' ? part : part.d);
        if (typeof part !== 'string' && part.fill) path.setAttribute('class', 'a2ui-icon__fill');
        svg.appendChild(path);
      });
    };
    if (isBinding(c.name) || (isObject(c.name) && isBinding(c.name.svgPath))) state.effects.push(draw);
    draw();
    return svg;
  },

  Video(state, c, scope) {
    const wrap = document.createElement('div');
    wrap.className = 'a2ui-media a2ui-video';
    const url = safeUrl(this.resolve(state, c.url, scope));
    if (url === '') {
      wrap.classList.add('a2ui-media--empty');
      wrap.textContent = this.labels.noMedia;
      return wrap;
    }
    const video = document.createElement('video');
    video.controls = true;
    video.preload = 'metadata';
    video.src = url;
    const poster = c.posterUrl !== undefined ? safeUrl(this.resolve(state, c.posterUrl, scope)) : '';
    if (poster) video.poster = poster;
    wrap.appendChild(video);
    return wrap;
  },

  AudioPlayer(state, c, scope) {
    const wrap = document.createElement('figure');
    wrap.className = 'a2ui-media a2ui-audio';
    const url = safeUrl(this.resolve(state, c.url, scope));
    const description = toText(this.resolve(state, c.description, scope));
    if (url === '') {
      wrap.classList.add('a2ui-media--empty');
      wrap.textContent = this.labels.noMedia;
      return wrap;
    }
    const audio = document.createElement('audio');
    audio.controls = true;
    audio.preload = 'metadata';
    audio.src = url;
    wrap.appendChild(audio);
    if (description) {
      const caption = document.createElement('figcaption');
      caption.className = 'a2ui-text__caption';
      caption.textContent = description;
      wrap.appendChild(caption);
      audio.setAttribute('aria-label', description);
    }
    return wrap;
  },

  Row(state, c, scope, ctx) {
    return layout.call(this, state, c, scope, ctx, 'a2ui-row');
  },

  Column(state, c, scope, ctx) {
    return layout.call(this, state, c, scope, ctx, 'a2ui-column');
  },

  List(state, c, scope, ctx) {
    const list = document.createElement('ul');
    list.className = `a2ui-list a2ui-list--${c.direction === 'horizontal' ? 'horizontal' : 'vertical'} a2ui-align--${c.align || 'stretch'}`;
    list.setAttribute('role', 'list');
    this.children(state, c.children, scope, ctx, (node) => {
      const item = document.createElement('li');
      item.className = 'a2ui-list__item';
      item.appendChild(node);
      return item;
    }).forEach((item) => list.appendChild(item));
    return list;
  },

  Card(state, c, scope, ctx) {
    const card = document.createElement('div');
    card.className = 'a2ui-card';
    this.addClass(card, 'card');
    const body = document.createElement('div');
    body.className = 'a2ui-card__body';
    this.addClass(body, 'cardBody');
    body.appendChild(this.child(state, c.child, scope, ctx));
    card.appendChild(body);
    return card;
  },

  Tabs(state, c, scope, ctx) {
    const tabs = Array.isArray(c.tabs) ? c.tabs.filter(isObject) : [];
    const key = c.id + '@' + scope.base;
    const selected = Math.min(state.tabs.get(key) || 0, Math.max(0, tabs.length - 1));
    const wrap = document.createElement('div');
    wrap.className = 'a2ui-tabs';
    const list = document.createElement('div');
    list.className = 'a2ui-tabs__list';
    list.setAttribute('role', 'tablist');
    const buttons = [];
    const panels = [];
    tabs.forEach((tab, index) => {
      const id = this.domId(state, c, scope, `tab-${index}`);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'a2ui-tabs__tab';
      button.id = id;
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-controls', id + '-panel');
      button.dataset.a2uiKey = id;
      this.bindText(state, tab.title, scope, (text) => { button.textContent = text; });
      const panel = document.createElement('div');
      panel.className = 'a2ui-tabs__panel';
      panel.id = id + '-panel';
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', id);
      panel.tabIndex = 0;
      panel.appendChild(this.child(state, tab.child, scope, ctx));
      buttons.push(button);
      panels.push(panel);
    });
    const select = (index, focus) => {
      state.tabs.set(key, index);
      buttons.forEach((button, i) => {
        const active = i === index;
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.tabIndex = active ? 0 : -1;
        button.classList.toggle('is-active', active);
        panels[i].hidden = !active;
      });
      if (focus) buttons[index].focus();
    };
    buttons.forEach((button, index) => {
      button.addEventListener('click', () => select(index, false));
      button.addEventListener('keydown', (event) => {
        const last = buttons.length - 1;
        const target = { ArrowRight: index === last ? 0 : index + 1, ArrowLeft: index === 0 ? last : index - 1, Home: 0, End: last }[event.key];
        if (target !== undefined) {
          event.preventDefault();
          select(target, true);
        }
      });
      list.appendChild(button);
    });
    wrap.appendChild(list);
    panels.forEach((panel) => wrap.appendChild(panel));
    if (buttons.length > 0) select(selected, false);
    return wrap;
  },

  Modal(state, c, scope, ctx) {
    const wrap = document.createElement('div');
    wrap.className = 'a2ui-modal';
    const dialog = document.createElement('dialog');
    dialog.className = 'a2ui-modal__dialog';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'a2ui-modal__close';
    close.setAttribute('aria-label', this.labels.close);
    this.addClass(close, 'button');
    this.addClass(close, 'buttonDefault');
    close.appendChild(BUILDERS.Icon.call(this, state, { id: c.id + '_close', name: 'close' }, scope));
    const body = document.createElement('div');
    body.className = 'a2ui-modal__body';
    body.appendChild(this.child(state, c.content, scope, ctx));
    dialog.append(close, body);
    const heading = body.querySelector('.a2ui-heading');
    if (heading) {
      heading.id = heading.id || this.domId(state, c, scope, 'title');
      dialog.setAttribute('aria-labelledby', heading.id);
    } else {
      dialog.setAttribute('aria-label', this.labels.dialog);
    }
    let opener = null;
    const open = (event) => {
      event.preventDefault();
      event.stopPropagation();
      opener = event.currentTarget;
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
      close.focus();
    };
    close.addEventListener('click', () => (typeof dialog.close === 'function' ? dialog.close() : dialog.removeAttribute('open')));
    dialog.addEventListener('close', () => { if (opener && opener.isConnected) opener.focus(); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog && typeof dialog.close === 'function') dialog.close(); });

    let trigger = this.child(state, c.trigger, scope, { ...ctx, intercept: open });
    if (!(trigger instanceof Element) || !trigger.matches('button, .a2ui-button-wrap')) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'a2ui-button a2ui-button--default';
      button.setAttribute('aria-haspopup', 'dialog');
      button.appendChild(trigger);
      button.addEventListener('click', open);
      trigger = button;
    }
    wrap.append(trigger, dialog);
    return wrap;
  },

  Divider(state, c) {
    if (c.axis === 'vertical') {
      const line = document.createElement('div');
      line.className = 'a2ui-divider a2ui-divider--vertical';
      line.setAttribute('role', 'separator');
      line.setAttribute('aria-orientation', 'vertical');
      return line;
    }
    const hr = document.createElement('hr');
    hr.className = 'a2ui-divider';
    return hr;
  },

  Button(state, c, scope, ctx) {
    const variant = ['primary', 'borderless'].includes(c.variant) ? c.variant : 'default';
    const wrap = document.createElement('div');
    wrap.className = 'a2ui-button-wrap';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `a2ui-button a2ui-button--${variant}`;
    button.dataset.a2uiKey = this.domId(state, c, scope);
    this.addClass(button, 'button');
    this.addClass(button, { primary: 'buttonPrimary', borderless: 'buttonBorderless', default: 'buttonDefault' }[variant]);
    button.appendChild(this.child(state, c.child, scope, { ...ctx, intercept: null }));
    wrap.appendChild(button);

    if (ctx.intercept) {
      button.setAttribute('aria-haspopup', 'dialog');
      button.addEventListener('click', ctx.intercept);
    } else {
      button.addEventListener('click', () => this.dispatch(state, c, scope, button));
    }

    if (Array.isArray(c.checks) && c.checks.length > 0) {
      const hint = document.createElement('p');
      hint.className = 'a2ui-button__hint';
      hint.id = this.domId(state, c, scope, 'hint');
      hint.hidden = true;
      wrap.appendChild(hint);
      state.effects.push(() => {
        const messages = this.failures(state, c, scope);
        const blocked = messages.length > 0;
        button.disabled = blocked;
        button.dataset.a2uiBlocked = blocked ? '1' : '';
        hint.hidden = !blocked;
        hint.textContent = messages.join(' ');
        if (blocked) this.describe(button, hint.id);
      });
    }
    return wrap;
  },

  TextField(state, c, scope) {
    const variant = ['longText', 'number', 'obscured'].includes(c.variant) ? c.variant : 'shortText';
    const { wrap, id } = field(this, state, c, scope, `a2ui-textfield a2ui-textfield--${variant}`);
    const control = document.createElement(variant === 'longText' ? 'textarea' : 'input');
    control.id = id;
    control.className = 'a2ui-field__control';
    control.dataset.a2uiKey = id;
    this.addClass(control, 'input');
    if (variant === 'longText') control.rows = 4;
    else control.type = { number: 'number', obscured: 'password', shortText: 'text' }[variant];
    if (variant === 'number') control.inputMode = 'decimal';
    if (variant === 'obscured') control.autocomplete = 'current-password';
    const required = this.isRequired(c);
    if (required) control.setAttribute('aria-required', 'true');
    if (c.placeholder !== undefined) this.bindText(state, c.placeholder, scope, (text) => { control.placeholder = text; });
    wrap.appendChild(fieldLabel(this, state, c, scope, id, required));
    wrap.appendChild(control);

    const read = () => toText(this.resolve(state, c.value, scope));
    control.value = read();
    state.effects.push(() => { if (document.activeElement !== control && control.value !== read()) control.value = read(); });
    control.addEventListener('input', () => this.write(state, c, scope, control.value));
    this.checkable(state, c, scope, wrap, control);
    return wrap;
  },

  CheckBox(state, c, scope) {
    const { wrap, id } = field(this, state, c, scope, 'a2ui-checkbox');
    const control = document.createElement('input');
    control.type = 'checkbox';
    control.id = id;
    control.className = 'a2ui-checkbox__control';
    control.dataset.a2uiKey = id;
    this.addClass(control, 'check');
    const label = document.createElement('label');
    label.className = 'a2ui-checkbox__label';
    label.htmlFor = id;
    this.addClass(label, 'checkLabel');
    this.bindText(state, c.label, scope, (text) => { label.textContent = text; });
    const row = document.createElement('div');
    row.className = 'a2ui-checkbox__row';
    this.addClass(row, 'checkRow');
    row.append(control, label);
    wrap.appendChild(row);
    const read = () => this.resolve(state, c.value, scope) === true;
    control.checked = read();
    state.effects.push(() => { control.checked = read(); });
    control.addEventListener('change', () => this.write(state, c, scope, control.checked));
    this.checkable(state, c, scope, wrap, control);
    return wrap;
  },

  ChoicePicker(state, c, scope) {
    const multiple = c.variant === 'multipleSelection';
    const chips = c.displayStyle === 'chips';
    const wrap = document.createElement('div');
    wrap.className = `a2ui-field a2ui-choice${chips ? ' a2ui-choice--chips' : ''}`;
    const fieldset = document.createElement('fieldset');
    fieldset.className = 'a2ui-choice__fieldset';
    const id = this.domId(state, c, scope);
    fieldset.dataset.a2uiKey = id;
    const legend = document.createElement('legend');
    legend.className = 'a2ui-field__label';
    this.addClass(legend, 'label');
    const labelValue = c.label !== undefined ? c.label : (isObject(c.accessibility) ? c.accessibility.label : '');
    this.bindText(state, labelValue, scope, (text) => { legend.textContent = text; });
    if (this.isRequired(c)) {
      const mark = document.createElement('span');
      mark.className = 'a2ui-field__required';
      mark.textContent = ` (${this.labels.required})`;
      legend.appendChild(mark);
    }
    fieldset.appendChild(legend);

    const selectedValues = () => {
      const value = this.resolve(state, c.value, scope);
      return Array.isArray(value) ? value.map(String) : (typeof value === 'string' ? [value] : []);
    };
    const options = document.createElement('div');
    options.className = 'a2ui-choice__options';
    const inputs = [];
    (Array.isArray(c.options) ? c.options : []).forEach((option, index) => {
      if (!isObject(option)) return;
      const optionId = `${id}-${index}`;
      const item = document.createElement('div');
      item.className = 'a2ui-choice__option';
      this.addClass(item, 'checkRow');
      const input = document.createElement('input');
      input.type = multiple ? 'checkbox' : 'radio';
      input.name = id;
      input.id = optionId;
      input.value = String(option.value);
      input.className = 'a2ui-choice__control';
      input.dataset.a2uiKey = optionId;
      this.addClass(input, 'check');
      const label = document.createElement('label');
      label.htmlFor = optionId;
      label.className = 'a2ui-choice__label';
      this.addClass(label, 'checkLabel');
      this.bindText(state, option.label, scope, (text) => { label.textContent = text; item.dataset.label = text.toLowerCase(); });
      input.addEventListener('change', () => {
        const next = multiple
          ? inputs.filter((i) => i.checked).map((i) => i.value)
          : [input.value];
        this.write(state, c, scope, next);
      });
      item.append(input, label);
      options.appendChild(item);
      inputs.push(input);
    });
    const sync = () => {
      const values = selectedValues();
      inputs.forEach((input) => { input.checked = values.includes(input.value); });
    };
    sync();
    state.effects.push(sync);

    if (c.filterable === true) {
      const filter = document.createElement('input');
      filter.type = 'search';
      filter.className = 'a2ui-choice__filter';
      filter.setAttribute('aria-label', this.labels.filter);
      filter.placeholder = this.labels.filter;
      filter.dataset.a2uiKey = id + '-filter';
      this.addClass(filter, 'input');
      filter.addEventListener('input', () => {
        const needle = filter.value.trim().toLowerCase();
        options.querySelectorAll('.a2ui-choice__option').forEach((item) => {
          item.hidden = needle !== '' && !(item.dataset.label || '').includes(needle);
        });
      });
      fieldset.appendChild(filter);
    }
    fieldset.appendChild(options);
    wrap.appendChild(fieldset);
    this.checkable(state, c, scope, wrap, fieldset);
    return wrap;
  },

  Slider(state, c, scope) {
    const { wrap, id } = field(this, state, c, scope, 'a2ui-slider');
    const control = document.createElement('input');
    control.type = 'range';
    control.id = id;
    control.className = 'a2ui-slider__control';
    control.dataset.a2uiKey = id;
    const min = typeof c.min === 'number' ? c.min : 0;
    const max = typeof c.max === 'number' ? c.max : 100;
    control.min = String(min);
    control.max = String(max);
    control.step = typeof c.steps === 'number' && c.steps > 0 ? String((max - min) / c.steps) : 'any';
    if (c.label !== undefined) wrap.appendChild(fieldLabel(this, state, c, scope, id, false));
    const output = document.createElement('output');
    output.className = 'a2ui-slider__value';
    output.htmlFor = id;
    const row = document.createElement('div');
    row.className = 'a2ui-slider__row';
    row.append(control, output);
    wrap.appendChild(row);
    const read = () => {
      const value = Number(this.resolve(state, c.value, scope));
      return Number.isFinite(value) ? value : min;
    };
    const sync = () => {
      if (document.activeElement !== control) control.value = String(read());
      output.textContent = control.value;
    };
    sync();
    state.effects.push(sync);
    control.addEventListener('input', () => {
      output.textContent = control.value;
      this.write(state, c, scope, Number(control.value));
    });
    this.checkable(state, c, scope, wrap, control);
    return wrap;
  },

  DateTimeInput(state, c, scope) {
    const type = c.enableTime === true && c.enableDate !== true ? 'time' : (c.enableTime === true ? 'datetime-local' : 'date');
    const { wrap, id } = field(this, state, c, scope, `a2ui-datetime a2ui-datetime--${type}`);
    const control = document.createElement('input');
    control.type = type;
    control.id = id;
    control.className = 'a2ui-field__control';
    control.dataset.a2uiKey = id;
    this.addClass(control, 'input');
    const required = this.isRequired(c);
    if (required) control.setAttribute('aria-required', 'true');
    if (c.label !== undefined) wrap.appendChild(fieldLabel(this, state, c, scope, id, required));
    wrap.appendChild(control);
    if (c.min !== undefined) this.bindText(state, c.min, scope, (text) => { control.min = isoToInput(text, type); });
    if (c.max !== undefined) this.bindText(state, c.max, scope, (text) => { control.max = isoToInput(text, type); });
    const sync = () => {
      const value = isoToInput(this.resolve(state, c.value, scope), type);
      if (document.activeElement !== control && control.value !== value) control.value = value;
    };
    sync();
    state.effects.push(sync);
    control.addEventListener('change', () => this.write(state, c, scope, inputToIso(control.value, type)));
    this.checkable(state, c, scope, wrap, control);
    return wrap;
  },
};

function layout(state, c, scope, ctx, className) {
  const el = document.createElement('div');
  const justify = ['start', 'center', 'end', 'spaceBetween', 'spaceAround', 'spaceEvenly', 'stretch'].includes(c.justify) ? c.justify : 'start';
  const align = ['start', 'center', 'end', 'stretch'].includes(c.align) ? c.align : 'stretch';
  el.className = `${className} a2ui-justify--${justify} a2ui-align--${align}`;
  this.children(state, c.children, scope, ctx).forEach((node) => el.appendChild(node));
  return el;
}

export default A2uiRenderer;
