/**
 * Traffic log: "Follow live".
 *
 * Polls the agentnexus_traffic_poll AJAX route for entries newer than the
 * newest one on the page, with the page's own filter, and adds them at the top
 * of the table. The status line is an aria-live region, so a screen reader hears
 * how many exchanges arrived without the focus moving. Polling pauses while the
 * tab is hidden and stops for good after an error.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import labels from '~labels/agent_nexus.traffic';

const POLL_INTERVAL_MS = 3000;

class TrafficLive {
  constructor(root) {
    this.root = root;
    this.button = root.querySelector('[data-traffic-live]');
    this.buttonLabel = root.querySelector('[data-traffic-live-label]');
    this.status = root.querySelector('[data-traffic-status]');
    this.latestUid = Number(root.dataset.latestUid || '0');
    this.filter = this.parseFilter(root.dataset.filter);
    this.timer = null;
    this.following = false;
    this.inFlight = false;

    this.button?.addEventListener('click', () => (this.following ? this.stop() : this.start()));
    document.addEventListener('visibilitychange', () => {
      if (!this.following) {
        return;
      }
      if (document.hidden) {
        this.clearTimer();
      } else {
        this.schedule(0);
      }
    });
  }

  parseFilter(json) {
    try {
      const parsed = JSON.parse(json || '{}');
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
      return {};
    }
  }

  start() {
    this.following = true;
    this.button.setAttribute('aria-pressed', 'true');
    this.buttonLabel.textContent = this.button.dataset.labelStop;
    this.announce(labels.get('live.status.on'));
    this.schedule(0);
  }

  stop(message = labels.get('live.status.off')) {
    this.following = false;
    this.clearTimer();
    this.button.setAttribute('aria-pressed', 'false');
    this.buttonLabel.textContent = this.button.dataset.labelFollow;
    this.announce(message);
  }

  schedule(delay) {
    this.clearTimer();
    this.timer = window.setTimeout(() => this.poll(), delay);
  }

  clearTimer() {
    if (this.timer !== null) {
      window.clearTimeout(this.timer);
      this.timer = null;
    }
  }

  async poll() {
    if (!this.following || this.inFlight) {
      return;
    }
    this.inFlight = true;
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.agentnexus_traffic_poll)
        .withQueryArguments({ ...this.filter, after: String(this.latestUid) })
        .get();
      const data = await response.resolve();
      const rows = Array.isArray(data.rows) ? data.rows : [];
      if (rows.length > 0) {
        this.prepend(rows);
        this.latestUid = Number(data.latestUid || this.latestUid);
        this.announce(labels.get('live.new', [rows.length]));
      }
      this.schedule(POLL_INTERVAL_MS);
    } catch {
      this.stop(labels.get('live.error'));
    } finally {
      this.inFlight = false;
    }
  }

  prepend(rows) {
    const table = this.root.querySelector('[data-traffic-table]');
    const empty = this.root.querySelector('[data-traffic-empty]');
    if (table) {
      table.hidden = false;
    }
    if (empty) {
      empty.hidden = true;
    }
    const body = this.root.querySelector('[data-traffic-rows]');
    if (!body) {
      return;
    }
    // The poll answers newest first; insert oldest first so the newest ends on top.
    [...rows].reverse().forEach((row) => body.prepend(this.render(row)));
  }

  render(row) {
    const tr = document.createElement('tr');
    tr.className = 'anx-traffic__row is-new' + (row.isError ? ' is-error' : '');

    const time = document.createElement('time');
    time.dateTime = row.timeIso;
    time.textContent = row.time;
    tr.append(this.cell(time, 'anx-traffic__time'));

    const chip = document.createElement('span');
    chip.className = 'anx-proto-chip anx-accent--' + row.protocol;
    chip.textContent = row.protocolLabel;
    tr.append(this.cell(chip));

    tr.append(this.cell(document.createTextNode(row.channelLabel)));

    const link = document.createElement('a');
    link.href = row.detailUri;
    link.title = labels.get('table.open', [row.uid]);
    const endpoint = document.createElement('code');
    endpoint.textContent = row.endpoint;
    link.append(endpoint);
    tr.append(this.cell(link, 'anx-traffic__request'));

    const operation = document.createElement('td');
    if (row.operation) {
      const code = document.createElement('code');
      code.textContent = row.operation;
      operation.append(code);
    }
    if (row.correlationId) {
      const object = document.createElement('span');
      object.className = 'text-variant anx-traffic__object';
      object.textContent = row.correlationId;
      operation.append(document.createElement('br'), object);
    }
    tr.append(operation);

    const status = document.createElement('td');
    const badge = document.createElement('span');
    badge.className = 'badge badge-' + row.statusBadge;
    badge.textContent = String(row.status);
    status.append(badge);
    if (row.isStream) {
      const stream = document.createElement('span');
      stream.className = 'badge badge-default';
      stream.textContent = labels.get('table.stream');
      status.append(' ', stream);
    }
    tr.append(status);

    tr.append(this.cell(document.createTextNode(row.duration), 'text-end'));
    tr.append(this.cell(document.createTextNode(row.eventCount > 0 ? String(row.eventCount) : '–'), 'text-end'));
    return tr;
  }

  cell(content, className = '') {
    const td = document.createElement('td');
    if (className) {
      td.className = className;
    }
    td.append(content);
    return td;
  }

  announce(message) {
    if (this.status) {
      this.status.textContent = message;
    }
  }
}

document.querySelectorAll('[data-traffic]').forEach((root) => new TrafficLive(root));
