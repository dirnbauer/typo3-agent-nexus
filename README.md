# Agent Nexus

Five agent protocols, running against your own TYPO3 — at their current
specification versions, not slides about them.

[![CI](https://github.com/dirnbauer/typo3-agent-nexus/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-agent-nexus/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

## What it is

| Protocol | The edge | What it answers |
| --- | --- | --- |
| **A2UI** | agent ↔ interface | How does an agent put a form on a page without shipping code? |
| **AG-UI** | agent ↔ user | How does a person watch an agent work, and approve before it writes? |
| **A2A** | agent ↔ agent | How does another agent discover this one and delegate a task? |
| **UCP** | agent ↔ merchant | How does a shopping agent check out with a merchant? |
| **AP2** | agent ↔ payment | How do you prove a specific person approved a specific purchase? |

Each protocol has public endpoints another agent can call, a backend console
that plays the client and shows the raw wire traffic, and a frontend widget an
editor can place on a page. Two more elements frame them: **Protocol hub** puts
one card per protocol on a landing page, and **Protocol info** explains one
protocol next to its demo, with its sequence diagram, its endpoints and the
steps a request walks through.

The backend adds an **inspector** — every A2A task, AG-UI run, UCP checkout
session, AP2 mandate and A2UI surface with its state history — and a live
**traffic log** of every request, response and streamed event.

Everything runs deterministically by default. A language model is optional, per
protocol, and budgeted. Every write sits behind a human gate, and everything
money-shaped is simulated.

## Specification versions

Checked against each specification's primary source on 23 September 2026.

| Protocol | Implemented | Latest published | Source |
| --- | --- | --- | --- |
| A2A | 1.0 (0.3 on request) | 1.0.1, 28 May 2026 | [a2a-protocol.org](https://a2a-protocol.org/v1.0.1/specification/) |
| AG-UI | 1.0 | 1.0, 17 September 2026 | [docs.ag-ui.com](https://docs.ag-ui.com/spec/1.0) |
| A2UI | v0.9.1 (v1.0 candidate on request) | v0.9.1, 29 May 2026 | [a2ui.org](https://a2ui.org) |
| UCP | 2026-08-25 | 2026-08-25 | [ucp.dev](https://ucp.dev/2026-08-25/specification/overview/) |
| AP2 | v0.2.0 | v0.2.0, 28 April 2026 | [ap2-protocol.org](https://ap2-protocol.org) |
| MCP | not implemented | 2026-07-28 | [modelcontextprotocol.io](https://modelcontextprotocol.io/specification/2026-07-28) |

What changed since 3.1, protocol by protocol, is in
[Documentation/Protocols/SpecVersions.rst](Documentation/Protocols/SpecVersions.rst).
Every payload the extension emits is tested against the official JSON Schema of
its specification, vendored under `Tests/Conformance/Schemas` with its licence
and source.

## Requirements

* TYPO3 v14.3.7+
* PHP 8.4+ with `ext-openssl`
* `typo3/cms-fluid-styled-content` (the frontend elements render through
  `lib.contentElement`)

## Install

```bash
composer require webconsulting/agent-nexus
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

Open **Agent Nexus › Overview**. It says what is ready (routes, storage folder,
model), which specification version each protocol implements, and what ran in
the last 24 hours.

Optional, for real model answers:

```bash
composer require netresearch/nr-llm
```

Upgrading from 3.x is a major step — the endpoints moved, the wire formats
follow the new specifications and the log tables were replaced. Read
[the upgrade notes](Documentation/Installation.rst) first.

## Endpoints

All endpoints answer on every host of the installation, below `/api/agent-nexus`
(the `apiBasePath` setting), plus the discovery documents the specifications pin
to the host root:

```bash
# A2A: discover, then delegate over JSON-RPC 2.0
curl https://example.org/.well-known/agent-card.json
curl -X POST https://example.org/api/agent-nexus/a2a/jsonrpc \
  -H 'Content-Type: application/json' -H 'A2A-Version: 1.0' \
  -d '{"jsonrpc":"2.0","id":1,"method":"SendMessage","params":{"message":{"messageId":"m-1","role":"ROLE_USER","parts":[{"text":"Summarise our pricing page"}]}}}'

# UCP: read the business profile
curl https://example.org/.well-known/ucp
```

The overview module and the Protocol info element list every endpoint of every
protocol with its method, path and binding.

## Configure

*Admin Tools › Settings › Extension Configuration › agent_nexus*

| Setting | Default | What it does |
| --- | --- | --- |
| `apiBasePath` | `/api/agent-nexus` | Path prefix of every protocol endpoint |
| `publishWellKnown` | `1` | Serve `/.well-known/agent-card.json` and `/.well-known/ucp` |
| `trafficEnabled` | `1` | Record the traffic log |
| `trafficRetentionDays` | `14` | `agentnexus:cleanup` deletes older traffic entries |
| `trafficCaptureBodies` | `1` | Record request and response bodies and streamed events |
| `trafficRedactPersonalData` | `1` | Mask names, email addresses, phone numbers and addresses |
| `objectRetentionDays` | `90` | `agentnexus:cleanup` deletes older protocol objects |
| `llmFrontendEnabled` | `1` | Master switch for every frontend model call |
| `llmDailyBudget` | `2.00` | Calls stop once today's spend reaches this (USD); `0` = no cap |
| `llmMaxOutputTokens` | `700` | Hard ceiling per call |
| `<protocol>LlmEnabled` | varies | One toggle per protocol |
| `aguiReallyApply` | `0` | Keep off: approved writes are simulated |
| `ucpReallyApply` | `0` | Keep off: every checkout is simulated |

Schedule the cleanup daily, from cron or the scheduler's *Execute console
commands* task:

```bash
vendor/bin/typo3 agentnexus:cleanup
```

## Use

Build the whole demo site with one command:

```bash
vendor/bin/typo3 agentnexus:seed-site --base=https://example.org/
```

It creates a siteroot with a hub on the home page, `/a2ui`, `/ag-ui`, `/a2a`,
`/ucp` and `/ap2` each carrying an intro, its demo and a protocol info element,
all five demos on `/playground`, `/docs`, and a `data` sysfolder as the storage
pid — then writes the site configuration. It is idempotent: a second run
restores a renamed page's slug and a dragged element's position, and changes
nothing when there is nothing to change. `--dry-run` shows what it would do.

To put the widgets on a site you already have, add one set:

```yaml
# config/sites/<identifier>/config.yaml
dependencies:
  - webconsulting/agent-nexus
```

The widgets take their neutrals from the host theme's shadcn variables, so a
Desiderio site needs nothing extra. The `webconsulting/agent-nexus-desiderio`
set still resolves, but it adds nothing beyond depending on
`webconsulting/agent-nexus` and `webconsulting/desiderio`, and it goes in 5.0:
depend on those two directly.

## Develop

```bash
composer install
composer ci          # cgl, PHPStan level 8, unit + conformance, functional
npm ci && npm run diagrams   # re-render the sequence diagrams + lock file
npm run diagrams:check       # what CI checks; needs no node modules
```

PHPStan runs at level 8 with no baseline and no ignored errors. The functional
tests run every endpoint through the frontend middleware stack and render every
backend screen, on SQLite by default. CI runs PHP 8.4 and 8.5.

## Docs

Full documentation is in [`Documentation/`](Documentation/Index.rst):
installation and upgrading, site setup, configuration, one page per protocol,
the specification versions, the security boundaries and the developer notes.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The vendored specification schemas
under `Tests/Conformance/Schemas` keep their own licences (Apache-2.0, MIT),
stated next to each set.
