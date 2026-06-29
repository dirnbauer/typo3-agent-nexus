/**
 * A2UI v1.0 client renderer.
 *
 * This is the "trusted client" half of A2UI: it takes a declarative surface
 * (data, never code) emitted by an agent and renders it with native components.
 * It deliberately knows a fixed catalog — anything the agent asks for that is not
 * in the catalog is ignored, which is what makes rendering agent-authored UI safe.
 *
 * Implements the A2UI v1.0 essentials:
 *  - reconstruct the component tree from the flat adjacency list (root + ids),
 *  - two-way data binding via JSON Pointer paths (absolute and collection-relative),
 *  - List templating: `children: {path, componentId}` iterates an array, each item
 *    in its own collection scope, with `@index` available,
 *  - validation `checks` (required/email/length/numeric/regex) enforced before an
 *    action fires,
 *  - emit `userAction` events back to the host.
 *
 * No framework, no build step — a plain ES module so the extension stays portable.
 */

const CATALOG = ['Text', 'Image', 'Icon', 'Video', 'AudioPlayer', 'TextField', 'Textarea', 'ChoicePicker', 'CheckBox', 'DateTimeInput', 'Slider', 'Button', 'ButtonGroup', 'Divider', 'Column', 'Row', 'Card', 'List', 'Tabs', 'Modal'];

const ROOT_SCOPE = { base: '', index: null };

/** A small built-in icon set (viewBox 0 0 16 16, stroke=currentColor). */
const ICONS = {
  check: '<path d="M3 8.5l3 3 7-7"/>',
  info: '<circle cx="8" cy="8" r="6.5"/><path d="M8 7.5v3.5M8 5h.01"/>',
  warning: '<path d="M8 2 1.5 13.5h13z"/><path d="M8 6.5v3M8 11.5h.01"/>',
  star: '<path d="M8 1.5l1.8 4 4.2.4-3.2 2.8 1 4.3L8 10.8 4.2 13l1-4.3L2 5.9l4.2-.4z"/>',
  arrow: '<path d="M3 8h9M8.5 4.5 12 8l-3.5 3.5"/>',
  user: '<circle cx="8" cy="5.5" r="2.5"/><path d="M3.5 13a4.5 4.5 0 0 1 9 0"/>',
  mail: '<rect x="2" y="3.5" width="12" height="9" rx="1"/><path d="M2.5 4.5 8 8.5l5.5-4"/>',
  phone: '<path d="M4 2.5h2l1 3-1.5 1a8 8 0 0 0 4 4l1-1.5 3 1v2a1 1 0 0 1-1 1A11 11 0 0 1 3 3.5a1 1 0 0 1 1-1z"/>',
  calendar: '<rect x="2.5" y="3" width="11" height="10.5" rx="1"/><path d="M2.5 6h11M5.5 1.5v3M10.5 1.5v3"/>',
  clock: '<circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.5 1.5"/>',
  dot: '<circle cx="8" cy="8" r="2.5" fill="currentColor" stroke="none"/>',
};

function unescapeToken(token) {
  return token.replace(/~1/g, '/').replace(/~0/g, '~');
}

function pointerGet(data, pointer) {
  if (typeof pointer !== 'string' || pointer === '') return undefined;
  const parts = pointer.replace(/^\//, '').split('/').map(unescapeToken);
  let current = data;
  for (const part of parts) {
    if (current && typeof current === 'object' && part in current) current = current[part];
    else return undefined;
  }
  return current;
}

function pointerSet(data, pointer, value) {
  if (typeof pointer !== 'string' || pointer === '') return;
  const parts = pointer.replace(/^\//, '').split('/').map(unescapeToken);
  let current = data;
  for (let i = 0; i < parts.length - 1; i++) {
    if (typeof current[parts[i]] !== 'object' || current[parts[i]] === null) current[parts[i]] = {};
    current = current[parts[i]];
  }
  current[parts[parts.length - 1]] = value;
}

/** Resolve a (possibly collection-relative) path against the current scope. */
function effectivePath(path, scope) {
  if (typeof path !== 'string') return '';
  if (path.startsWith('/')) return path;
  return (scope.base || '') + '/' + path;
}

/** A binding is the object form `{ "path": "/x" }`; a literal is anything else. */
function isBinding(value) {
  return value && typeof value === 'object' && typeof value.path === 'string';
}

export class A2UIClient {
  constructor(options = {}) {
    this.onDataChange = options.onDataChange || (() => {});
    this.onAction = options.onAction || (() => {});
    this.onWarning = options.onWarning || (() => {});
    this.onActionResponse = options.onActionResponse || (() => {});
    this.dataModel = {};
    this.components = new Map();
    this.inputs = [];
    this.modals = {};
    this.mountEl = null;
  }

  /** Render the initial surface. Equivalent to apply() with a createSurface. */
  render(message, mountEl) {
    this.mountEl = mountEl;
    return this.apply(message);
  }

  /**
   * Apply any A2UI v1.0 message to the (already mounted) surface: createSurface,
   * updateComponents, updateDataModel, deleteSurface, actionResponse, callFunction.
   * This is the streaming entry point — a host can pipe a sequence of messages.
   */
  apply(message) {
    message = message || {};
    if (message.updateComponents) return this._updateComponents(message.updateComponents);
    if (message.updateDataModel) return this._updateDataModel(message.updateDataModel);
    if (message.deleteSurface) return this._deleteSurface();
    if (message.actionResponse) return this._actionResponse(message.actionResponse);
    if (message.callFunction) return this._callFunction(message.callFunction);
    return this._createSurface(message.createSurface || message);
  }

  _createSurface(body) {
    this.dataModel = JSON.parse(JSON.stringify(body.dataModel || {}));
    this.components = new Map();
    (body.components || []).forEach((c) => { if (c && c.id) this.components.set(c.id, c); });
    this._rerender();
    this.onDataChange(this.dataModel);
    return { dataModel: this.dataModel };
  }

  _updateComponents(body) {
    (body.components || []).forEach((c) => { if (c && c.id) this.components.set(c.id, c); });
    (body.remove || []).forEach((id) => this.components.delete(id));
    this._rerender();
    this.onDataChange(this.dataModel);
    return { dataModel: this.dataModel };
  }

  _updateDataModel(body) {
    if (body.contents && typeof body.contents === 'object') Object.assign(this.dataModel, body.contents);
    if (typeof body.path === 'string') pointerSet(this.dataModel, body.path, body.value);
    (body.updates || []).forEach((u) => { if (u && typeof u.path === 'string') pointerSet(this.dataModel, u.path, u.value); });
    this._rerender();
    this.onDataChange(this.dataModel);
    return { dataModel: this.dataModel };
  }

  _deleteSurface() {
    this.components = new Map();
    this.dataModel = {};
    if (this.mountEl) this.mountEl.innerHTML = '';
    return { dataModel: {} };
  }

  _actionResponse(body) {
    if (typeof body.responsePath === 'string') {
      pointerSet(this.dataModel, body.responsePath, body.response ?? body.contents);
      this._rerender();
      this.onDataChange(this.dataModel);
    }
    this.onActionResponse(body);
    return { dataModel: this.dataModel };
  }

  _callFunction(body) {
    const call = body.call || body.name;
    const args = body.args || {};
    switch (call) {
      case 'openUrl': if (args.url) window.open(String(args.url), args.target || '_blank', 'noopener'); break;
      case 'openModal': { const d = this.modals[args.id]; if (d && d.showModal) d.showModal(); break; }
      case 'closeModal': { const d = this.modals[args.id]; if (d && d.close) d.close(); break; }
      default: this.onWarning(`Unknown client function "${call}".`);
    }
    return { dataModel: this.dataModel };
  }

  _rerender() {
    if (!this.mountEl) return;
    this.inputs = [];
    this.modals = {};
    this.mountEl.innerHTML = '';
    const root = this.components.get('root');
    if (!root) {
      this.onWarning('Surface has no "root" component.');
      this.mountEl.appendChild(this._notice('This surface has no root component to render.', 'warning'));
      return;
    }
    this.mountEl.appendChild(this._build(root, ROOT_SCOPE));
  }

  // ---- Binding helpers --------------------------------------------

  _resolve(value, scope) {
    if (value && typeof value === 'object' && typeof value.function === 'string') {
      return this._evalFunction(value, scope);
    }
    if (!isBinding(value)) return value;
    if (value.path === '@index') return scope.index ?? 0;
    return pointerGet(this.dataModel, effectivePath(value.path, scope));
  }

  /** A2UI built-in functions for computed/display values. */
  _evalFunction(node, scope) {
    const name = node.function;
    const args = (node.args || []).map((a) => this._resolve(a, scope));
    const num = (v) => (typeof v === 'number' ? v : parseFloat(v));
    try {
      switch (name) {
        case 'formatString': {
          // args[0] is a template; ${/pointer} (or relative) is interpolated.
          const tpl = String(args[0] ?? '');
          return tpl.replace(/\$\{([^}]+)\}/g, (_, p) => {
            const v = pointerGet(this.dataModel, effectivePath(p.trim(), scope));
            return v == null ? '' : String(v);
          });
        }
        case 'formatNumber': {
          const [v, grouping, precision] = args;
          return new Intl.NumberFormat(undefined, {
            useGrouping: grouping !== false,
            ...(precision != null ? { minimumFractionDigits: precision, maximumFractionDigits: precision } : {}),
          }).format(num(v) || 0);
        }
        case 'formatCurrency': {
          const [v, currency] = args;
          return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'EUR' }).format(num(v) || 0);
        }
        case 'formatDate': {
          const [v, fmt] = args;
          const d = v ? new Date(v) : null;
          if (!d || isNaN(d.getTime())) return '';
          return fmt ? this._formatDate(d, String(fmt)) : d.toLocaleDateString();
        }
        case 'pluralize': {
          const [count, forms] = args;
          const n = num(count) || 0;
          if (Array.isArray(forms)) return n === 1 ? forms[0] : (forms[1] ?? forms[0]);
          return String(forms ?? '');
        }
        case 'and': return args.every(Boolean);
        case 'or': return args.some(Boolean);
        case 'not': return !args[0];
        default:
          this.onWarning(`Unknown function "${name}".`);
          return '';
      }
    } catch (e) {
      this.onWarning(`Function "${name}" failed: ${e.message}`);
      return '';
    }
  }

  _formatDate(d, fmt) {
    const p = (n) => String(n).padStart(2, '0');
    return fmt
      .replace(/yyyy/g, d.getFullYear())
      .replace(/MM/g, p(d.getMonth() + 1))
      .replace(/dd/g, p(d.getDate()))
      .replace(/HH/g, p(d.getHours()))
      .replace(/mm/g, p(d.getMinutes()));
  }

  _bind(c, control, eventName, reader, scope) {
    if (!isBinding(c.value) || c.value.path === '@index') return;
    const path = effectivePath(c.value.path, scope);
    control.addEventListener(eventName, () => {
      pointerSet(this.dataModel, path, reader(control));
      this.onDataChange(this.dataModel);
    });
  }

  // ---- Tree -------------------------------------------------------

  _build(component, scope) {
    const type = component.component;
    if (!CATALOG.includes(type)) {
      this.onWarning(`Component "${type}" is not in the catalog — skipped.`);
      return document.createComment(` a2ui: unknown component ${type} `);
    }
    const builder = this[`_build${type}`];
    return builder ? builder.call(this, component, scope) : document.createComment(` a2ui: ${type} `);
  }

  _children(component, scope) {
    const frag = document.createDocumentFragment();
    (Array.isArray(component.children) ? component.children : []).forEach((childId) => {
      const child = this.components.get(childId);
      if (child) frag.appendChild(this._build(child, scope));
    });
    return frag;
  }

  // ---- Containers -------------------------------------------------

  _buildColumn(c, scope) {
    const el = document.createElement('div');
    el.className = 'a2ui-column d-flex flex-column';
    el.style.gap = `${c.gap ?? 0}rem`;
    el.appendChild(this._children(c, scope));
    return el;
  }

  _buildRow(c, scope) {
    const el = document.createElement('div');
    el.className = 'a2ui-row d-flex flex-row' + (c.wrap ? ' flex-wrap' : '') + (c.align ? ` align-items-${c.align}` : '');
    el.style.gap = `${c.gap ?? 1}rem`;
    el.appendChild(this._children(c, scope));
    return el;
  }

  _buildCard(c, scope) {
    const el = document.createElement('div');
    el.className = 'card a2ui-card';
    el.id = this._domId(c, scope);
    if (c.title || c.subtitle) {
      const header = document.createElement('div');
      header.className = 'card-header';
      if (c.title) {
        const h = document.createElement('h3');
        h.className = 'h5 mb-0';
        h.textContent = c.title;
        header.appendChild(h);
      }
      if (c.subtitle) {
        const s = document.createElement('div');
        s.className = 'text-body-secondary small';
        s.textContent = c.subtitle;
        header.appendChild(s);
      }
      el.appendChild(header);
    }
    const body = document.createElement('div');
    body.className = 'card-body';
    body.appendChild(this._children(c, scope));
    el.appendChild(body);
    return el;
  }

  /** List: static children, or `children: {path, componentId}` template iteration. */
  _buildList(c, scope) {
    const el = document.createElement('div');
    el.className = 'a2ui-list d-flex flex-column';
    el.style.gap = `${c.gap ?? 0.5}rem`;

    const children = c.children;
    const isTemplate = children && typeof children === 'object' && !Array.isArray(children) && children.path && children.componentId;

    if (isTemplate) {
      const listPath = effectivePath(children.path, scope);
      const arr = pointerGet(this.dataModel, listPath);
      const items = Array.isArray(arr) ? arr : [];
      const template = this.components.get(children.componentId);
      if (!template) {
        this.onWarning(`List template "${children.componentId}" not found.`);
        return el;
      }
      items.forEach((_, i) => {
        el.appendChild(this._build(template, { base: `${listPath}/${i}`, index: i }));
      });
      if (items.length === 0 && c.emptyText) {
        const empty = document.createElement('div');
        empty.className = 'text-body-secondary small';
        empty.textContent = c.emptyText;
        el.appendChild(empty);
      }
    } else {
      el.appendChild(this._children(c, scope));
    }
    return el;
  }

  // ---- Display ----------------------------------------------------

  _buildText(c, scope) {
    const tagMap = { h1: 'h1', h2: 'h2', h3: 'h3', lead: 'p', muted: 'p' };
    const el = document.createElement(tagMap[c.variant] || 'p');
    if (c.variant === 'lead') el.className = 'lead';
    else if (c.variant === 'muted') el.className = 'text-body-secondary mb-2';
    else if (['h1', 'h2', 'h3'].includes(c.variant)) el.className = { h1: 'h3', h2: 'h4', h3: 'h5' }[c.variant];
    else el.className = 'mb-2';
    if (c.align) el.classList.add(`text-${c.align}`);
    el.textContent = this._resolve(c.text, scope) ?? '';
    return el;
  }

  _buildDivider(c) {
    if (c.label) {
      const wrap = document.createElement('div');
      wrap.className = 'a2ui-divider d-flex align-items-center text-body-secondary my-3';
      wrap.innerHTML = '<hr class="flex-grow-1 my-0"><span class="px-2 small text-uppercase"></span><hr class="flex-grow-1 my-0">';
      wrap.querySelector('span').textContent = c.label;
      return wrap;
    }
    const hr = document.createElement('hr');
    hr.className = 'my-3';
    return hr;
  }

  // ---- Inputs -----------------------------------------------------

  _field(c, control, scope) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    if (c.label && c.component !== 'CheckBox') {
      const label = document.createElement('label');
      label.className = 'form-label';
      label.setAttribute('for', control.id);
      label.textContent = c.label;
      if (c.required) {
        const star = document.createElement('span');
        star.className = 'text-danger';
        star.textContent = ' *';
        label.appendChild(star);
      }
      wrap.appendChild(label);
    }
    wrap.appendChild(control);
    if (c.helpText) {
      const help = document.createElement('div');
      help.className = 'form-text';
      help.textContent = c.helpText;
      wrap.appendChild(help);
    }
    this._registerInput(c, control, wrap, () => control.value);
    return wrap;
  }

  _buildTextField(c, scope) {
    const input = document.createElement('input');
    input.type = c.inputType || 'text';
    input.className = 'form-control';
    input.id = this._domId(c, scope);
    input.value = this._resolve(c.value, scope) ?? '';
    if (c.placeholder) input.placeholder = c.placeholder;
    if (c.maxlength) input.maxLength = c.maxlength;
    if (c.required) input.required = true;
    if (c.disabled) input.disabled = true;
    this._bind(c, input, 'input', (el) => el.value, scope);
    return this._field(c, input, scope);
  }

  _buildTextarea(c, scope) {
    const input = document.createElement('textarea');
    input.className = 'form-control';
    input.id = this._domId(c, scope);
    input.rows = c.rows || 4;
    input.value = this._resolve(c.value, scope) ?? '';
    if (c.placeholder) input.placeholder = c.placeholder;
    if (c.required) input.required = true;
    if (c.disabled) input.disabled = true;
    this._bind(c, input, 'input', (el) => el.value, scope);
    return this._field(c, input, scope);
  }

  _buildChoicePicker(c, scope) {
    const select = document.createElement('select');
    select.className = 'form-select';
    select.id = this._domId(c, scope);
    if (c.multiple) select.multiple = true;
    if (c.required) select.required = true;
    const current = this._resolve(c.value, scope);
    (c.options || []).forEach((opt) => {
      const option = document.createElement('option');
      const value = (opt && typeof opt === 'object') ? opt.value : opt;
      option.value = value;
      option.textContent = (opt && typeof opt === 'object') ? opt.label : opt;
      if (value === current) option.selected = true;
      select.appendChild(option);
    });
    this._bind(c, select, 'change', (el) => el.value, scope);
    return this._field(c, select, scope);
  }

  _buildCheckBox(c, scope) {
    const wrap = document.createElement('div');
    wrap.className = 'form-check mb-3';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'form-check-input';
    input.id = this._domId(c, scope);
    input.checked = !!this._resolve(c.value, scope);
    const label = document.createElement('label');
    label.className = 'form-check-label';
    label.setAttribute('for', input.id);
    label.textContent = c.label || '';
    this._bind(c, input, 'change', (el) => el.checked, scope);
    wrap.appendChild(input);
    wrap.appendChild(label);
    if (c.helpText) {
      const help = document.createElement('div');
      help.className = 'form-text';
      help.textContent = c.helpText;
      wrap.appendChild(help);
    }
    this._registerInput(c, input, wrap, () => input.checked);
    return wrap;
  }

  _buildDateTimeInput(c, scope) {
    const input = document.createElement('input');
    input.type = c.mode === 'datetime' ? 'datetime-local' : (c.mode === 'time' ? 'time' : 'date');
    input.className = 'form-control';
    input.id = this._domId(c, scope);
    input.value = this._resolve(c.value, scope) ?? '';
    if (c.min) input.min = c.min;
    if (c.max) input.max = c.max;
    if (c.required) input.required = true;
    this._bind(c, input, 'input', (el) => el.value, scope);
    return this._field(c, input, scope);
  }

  // ---- Interactive ------------------------------------------------

  _buildButton(c, scope) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `btn btn-${c.variant || 'primary'}`;
    btn.textContent = c.text || c.label || 'Submit';
    if (c.disabled) btn.disabled = true;
    if (c.action) btn.addEventListener('click', () => this._emitAction(c.action, scope));
    wrap.appendChild(btn);
    return wrap;
  }

  _buildButtonGroup(c, scope) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    if (c.label) {
      const label = document.createElement('label');
      label.className = 'form-label d-block';
      label.textContent = c.label;
      wrap.appendChild(label);
    }
    const group = document.createElement('div');
    group.className = 'btn-group';
    group.setAttribute('role', 'group');
    const path = (isBinding(c.value) && c.value.path !== '@index') ? effectivePath(c.value.path, scope) : null;
    const current = this._resolve(c.value, scope);
    (c.options || []).forEach((opt) => {
      const value = (opt && typeof opt === 'object') ? opt.value : opt;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-outline-primary' + (value === current ? ' active' : '');
      btn.textContent = (opt && typeof opt === 'object') ? opt.label : opt;
      btn.addEventListener('click', () => {
        group.querySelectorAll('button').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        if (path) {
          pointerSet(this.dataModel, path, value);
          this.onDataChange(this.dataModel);
        }
      });
      group.appendChild(btn);
    });
    wrap.appendChild(group);
    return wrap;
  }

  // ---- Validation -------------------------------------------------

  _registerInput(c, control, wrap, getValue) {
    const checks = this._collectChecks(c);
    if (checks.length === 0) return;
    const input = { control, wrap, getValue, checks };
    this.inputs.push(input);
    control.addEventListener('input', () => this._clearError(input));
    control.addEventListener('change', () => this._clearError(input));
  }

  _collectChecks(c) {
    const checks = [];
    if (c.required) checks.push({ type: 'required' });
    if (Array.isArray(c.checks)) {
      c.checks.forEach((ck) => { if (ck && typeof ck.type === 'string') checks.push(ck); });
    }
    return checks;
  }

  _checkValue(checks, value) {
    for (const ck of checks) {
      switch (ck.type) {
        case 'required':
          if (value === undefined || value === null || value === '' || value === false) return ck.error || 'This field is required.';
          break;
        case 'email':
          if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value))) return ck.error || 'Please enter a valid email address.';
          break;
        case 'length': {
          const len = String(value ?? '').length;
          if (ck.min != null && len < ck.min) return ck.error || `Must be at least ${ck.min} characters.`;
          if (ck.max != null && len > ck.max) return ck.error || `Must be at most ${ck.max} characters.`;
          break;
        }
        case 'numeric': {
          if (value === '' || value == null) break;
          const n = Number(value);
          if (Number.isNaN(n)) return ck.error || 'Must be a number.';
          if (ck.min != null && n < ck.min) return ck.error || `Must be at least ${ck.min}.`;
          if (ck.max != null && n > ck.max) return ck.error || `Must be at most ${ck.max}.`;
          break;
        }
        case 'regex':
          try {
            if (value && ck.pattern && !new RegExp(ck.pattern).test(String(value))) return ck.error || 'Invalid format.';
          } catch (e) { /* ignore bad pattern */ }
          break;
      }
    }
    return null;
  }

  _validateAll() {
    let firstInvalid = null;
    this.inputs.forEach((input) => {
      this._clearError(input);
      const message = this._checkValue(input.checks, input.getValue());
      if (message) {
        this._showError(input, message);
        if (!firstInvalid) firstInvalid = input.control;
      }
    });
    if (firstInvalid) {
      firstInvalid.focus();
      return false;
    }
    return true;
  }

  _showError(input, message) {
    input.control.classList.add('is-invalid');
    let feedback = input.wrap.querySelector('.invalid-feedback');
    if (!feedback) {
      feedback = document.createElement('div');
      feedback.className = 'invalid-feedback';
      input.wrap.appendChild(feedback);
    }
    feedback.textContent = message;
    feedback.style.display = 'block';
  }

  _clearError(input) {
    input.control.classList.remove('is-invalid');
    const feedback = input.wrap.querySelector('.invalid-feedback');
    if (feedback) feedback.remove();
  }

  // ---- Actions ----------------------------------------------------

  _emitAction(action, scope) {
    if (action.event) {
      if (!this._validateAll()) return; // block the action until the form is valid
      this.onAction({
        type: 'event',
        name: action.event.name,
        context: this._resolveContext(action.event.context, scope),
        ...(action.event.actionId ? { actionId: action.event.actionId } : {}),
      });
    } else if (action.functionCall) {
      const args = this._resolveContext(action.functionCall.args, scope);
      this._callFunction({ call: action.functionCall.call, args }); // run locally (openModal/openUrl/…)
      this.onAction({ type: 'functionCall', call: action.functionCall.call, args });
    } else {
      this.onAction({ type: 'unknown', action });
    }
  }

  _resolveContext(ctx, scope) {
    const out = {};
    Object.keys(ctx || {}).forEach((key) => { out[key] = this._resolve(ctx[key], scope); });
    return out;
  }

  // ---- Media ------------------------------------------------------

  _buildImage(c, scope) {
    const img = document.createElement('img');
    img.className = 'a2ui-image';
    img.src = this._resolve(c.src, scope) || '';
    img.alt = c.alt || '';
    if (c.width) img.width = c.width;
    if (c.height) img.height = c.height;
    img.style.maxWidth = '100%';
    img.style.height = 'auto';
    if (c.rounded) img.style.borderRadius = '8px';
    return img;
  }

  _buildIcon(c) {
    const span = document.createElement('span');
    span.className = 'a2ui-icon';
    span.style.display = 'inline-flex';
    if (c.color) span.style.color = c.color;
    const size = c.size === 'large' ? 24 : (c.size === 'small' ? 12 : 16);
    const path = ICONS[c.name] || ICONS.dot;
    span.innerHTML = `<svg width="${size}" height="${size}" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
    return span;
  }

  _buildVideo(c, scope) {
    const v = document.createElement('video');
    v.className = 'a2ui-video';
    v.src = this._resolve(c.src, scope) || '';
    if (c.poster) v.poster = c.poster;
    v.controls = c.controls !== false;
    if (c.autoplay) { v.autoplay = true; v.muted = true; }
    v.style.maxWidth = '100%';
    return v;
  }

  _buildAudioPlayer(c, scope) {
    const a = document.createElement('audio');
    a.className = 'a2ui-audio';
    a.src = this._resolve(c.src, scope) || '';
    a.controls = c.controls !== false;
    a.style.width = '100%';
    return a;
  }

  _buildSlider(c, scope) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    const input = document.createElement('input');
    input.type = 'range';
    input.className = 'form-range a2ui-slider';
    input.id = this._domId(c, scope);
    input.min = c.min ?? 0;
    input.max = c.max ?? 100;
    input.step = c.step ?? 1;
    input.value = this._resolve(c.value, scope) ?? input.min;

    if (c.label) {
      const label = document.createElement('label');
      label.className = 'form-label';
      label.setAttribute('for', input.id);
      label.textContent = c.label;
      wrap.appendChild(label);
    }
    const out = document.createElement('output');
    out.className = 'a2ui-slider__value';
    out.textContent = input.value;
    input.addEventListener('input', () => { out.textContent = input.value; });

    const row = document.createElement('div');
    row.className = 'd-flex align-items-center gap-2';
    row.appendChild(input);
    row.appendChild(out);
    wrap.appendChild(row);
    if (c.helpText) {
      const help = document.createElement('div');
      help.className = 'form-text';
      help.textContent = c.helpText;
      wrap.appendChild(help);
    }
    this._bind(c, input, 'input', (el) => Number(el.value), scope);
    this._registerInput(c, input, wrap, () => Number(input.value));
    return wrap;
  }

  // ---- Tabs & Modal -----------------------------------------------

  _buildTabs(c, scope) {
    const wrap = document.createElement('div');
    wrap.className = 'a2ui-tabs';
    const nav = document.createElement('div');
    nav.className = 'a2ui-tabs__nav';
    const panels = document.createElement('div');
    panels.className = 'a2ui-tabs__panels';
    const titles = Array.isArray(c.tabs) ? c.tabs : [];
    const ids = Array.isArray(c.children) ? c.children : [];

    ids.forEach((childId, i) => {
      const child = this.components.get(childId);
      if (!child) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'a2ui-tabs__tab' + (i === 0 ? ' active' : '');
      btn.textContent = titles[i] || child.title || `Tab ${i + 1}`;
      const panel = document.createElement('div');
      panel.className = 'a2ui-tabs__panel';
      if (i !== 0) panel.style.display = 'none';
      panel.appendChild(this._build(child, scope));
      btn.addEventListener('click', () => {
        nav.querySelectorAll('.a2ui-tabs__tab').forEach((b) => b.classList.remove('active'));
        panels.querySelectorAll('.a2ui-tabs__panel').forEach((p) => { p.style.display = 'none'; });
        btn.classList.add('active');
        panel.style.display = '';
      });
      nav.appendChild(btn);
      panels.appendChild(panel);
    });
    wrap.appendChild(nav);
    wrap.appendChild(panels);
    return wrap;
  }

  _buildModal(c, scope) {
    const dialog = document.createElement('dialog');
    dialog.className = 'a2ui-modal';
    if (c.title) {
      const h = document.createElement('h3');
      h.className = 'a2ui-modal__title';
      h.textContent = c.title;
      dialog.appendChild(h);
    }
    const body = document.createElement('div');
    body.className = 'a2ui-modal__body';
    body.appendChild(this._children(c, scope));
    dialog.appendChild(body);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn-secondary a2ui-modal__close';
    close.textContent = 'Close';
    close.addEventListener('click', () => { if (dialog.close) dialog.close(); });
    dialog.appendChild(close);

    this.modals[c.id] = dialog;
    if (this._resolve(c.open, scope)) {
      requestAnimationFrame(() => { if (dialog.isConnected && dialog.showModal) dialog.showModal(); });
    }
    return dialog;
  }

  // ---- Utilities --------------------------------------------------

  /** Unique DOM id per rendered instance (template items reuse a component id). */
  _domId(c, scope) {
    return scope.base ? `${c.id}${scope.base.replace(/\//g, '-')}` : c.id;
  }

  _notice(message, level = 'info') {
    const el = document.createElement('div');
    el.className = `alert alert-${level}`;
    el.setAttribute('role', 'alert');
    el.textContent = message;
    return el;
  }
}

export default A2UIClient;
