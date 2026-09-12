# Agent Nexus

Five agent protocols, running against your own TYPO3 — not slides about them.

[![CI](https://github.com/dirnbauer/typo3-agent-nexus/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-agent-nexus/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

## What it is

| Protocol | The edge | What it answers |
| --- | --- | --- |
| **A2UI** | agent ↔ interface | How does an agent put a form on a page without shipping code? |
| **AG-UI** | agent ↔ user | How does a person watch an agent work, and approve before it writes? |
| **A2A** | agent ↔ agent | How does another agent discover this one and delegate a task? |
| **UCP** | agent ↔ merchant | How does a shopping agent read a catalogue and assemble a cart? |
| **AP2** | agent ↔ payment | How do you prove a specific human authorized a specific purchase? |

Each protocol gets a backend playground that shows the raw wire frames, and a
frontend plugin an editor can place on a page. A sixth element, **Protocol
info**, explains one protocol next to its demo — diagram, endpoints, and the
four steps a request walks through.

Everything runs deterministically by default. A language model is optional, per
protocol, and budgeted. Every write sits behind a human gate, and everything
money-shaped is simulated.

## Requirements

* TYPO3 v14.3.7+
* PHP 8.4+
* `typo3/cms-fluid-styled-content` (the frontend elements render through
  `lib.contentElement`)

## Install

```bash
composer require webconsulting/agent-nexus
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

Open **Agent Nexus › Overview**. The hub says what is ready: endpoints
registered, storage folder present, model reachable, and what each protocol has
done in the last 24 hours.

Optional, for real model answers:

```bash
composer require netresearch/nr-llm
```

## Configure

*Admin Tools › Settings › Extension Configuration › agent_nexus*

| Setting | Default | What it does |
| --- | --- | --- |
| `llmFrontendEnabled` | `1` | Master switch for every frontend model call |
| `llmDailyBudget` | `2.00` | Calls stop once today's spend reaches this (USD); `0` = no cap |
| `llmMaxOutputTokens` | `700` | Hard ceiling per call |
| `<protocol>LlmEnabled` | varies | One toggle per protocol |
| `aguiReallyApply` | `0` | Keep off: approved writes are simulated |
| `ucpReallyApply` | `0` | Keep off: every checkout is simulated |

Streamed calls bypass nr-llm's own budget middleware, so `llmDailyBudget` is the
only brake on that path.

## Use

Build the whole demo site with one command:

```bash
vendor/bin/typo3 agentnexus:seed-site --base=https://example.org/
```

It creates a siteroot with `/a2ui`, `/ag-ui`, `/a2a`, `/ucp`, `/ap2`,
`/playground` and `/docs` — each protocol page carrying an intro, its demo and a
protocol info element — plus a `data` sysfolder as the storage pid, and writes
the site configuration. Everything goes through DataHandler, and it is
idempotent: a second run updates the same records even after an editor renamed
them. Add `--dry-run` to see what it would do.

To put the plugins on a site you already have, add one set:

```yaml
# config/sites/<identifier>/config.yaml
dependencies:
  - webconsulting/agent-nexus
```

Using [Desiderio](https://github.com/dirnbauer/desiderio) (^4.1)? Use
`webconsulting/agent-nexus-desiderio` instead — same markup, framed by the site's
own component collection.

## Develop

```bash
composer install
composer ci          # cgl, PHPStan level 8, unit, functional
npm ci && npm run diagrams   # re-render the sequence diagrams
```

PHPStan runs at level 8 with no baseline. Functional tests run on SQLite by
default and cover all nine endpoints in deterministic mode, the seed command,
the upgrade wizard and the hub.

## Docs

Full documentation is in [`Documentation/`](Documentation/Index.rst):
installation, site setup, configuration, one page per protocol, the security
boundaries, and the developer notes.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
