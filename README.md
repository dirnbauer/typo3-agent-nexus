# Agent Nexus

A unified TYPO3 v14 lab for the agent-protocol family: **A2UI** (agent ↔ UI), **AG-UI** (agent ↔ user), **A2A** (agent ↔ agent), **UCP** (agent ↔ merchant) and **AP2** (payment authorization). One backend hub explains the protocols with an animated protocol map, Mermaid sequence diagrams, a comparison table and a decision helper; five playground modules show every wire frame live; five frontend plugins turn the same endpoints into useful, visitor-facing widgets — backed by a **real LLM** through [netresearch/nr-llm](https://github.com/netresearch/t3x-nr-llm) when available, with deterministic fallbacks that always work without an API key.

## What's inside

| Piece | What it does |
|---|---|
| **Overview module** | A field guide: hero with animated protocol map, theory cards, protocol comparison, "which protocol do I need?" decision helper, per-protocol tabs (sequence diagram, flow animation, key facts, spec snippet) and a glossary. |
| **A2UI Playground** | Describe an interface in plain language → the agent answers with an A2UI v1.0 surface (declarative JSON, never code), rendered with native trusted components. Cost/usage dashboard included. |
| **AG-UI Playground** | Watch an agent stream typed events (text deltas, tool calls, state patches) over SSE, with the human approval gate before any write. |
| **A2A Console** | Act as a client agent: fetch the Agent Card, delegate a task over JSON-RPC `message/stream`, follow the task lifecycle to the artifact. |
| **UCP Console** | A shopping agent discovers the merchant manifest, builds a cart and pauses at the human authorization gate. No payment is ever taken. |
| **AP2 Mandate Studio** | Mint a signed Intent Mandate (spending cap) and Cart Mandate, verify the chain, tamper-test it. Sandbox-signed. |
| **5 frontend plugins** | `agentnexus_inquiry`, `agentnexus_assistant`, `agentnexus_concierge`, `agentnexus_checkout`, `agentnexus_trustedsurface` — cacheable Fluid shells + ES-module widgets talking to public eID endpoints. |

## Real model vs. deterministic script

Every plugin works with **no API key** (scripted demo). With nr-llm configured, the useful parts become real — and the safety-critical parts never do:

| Plugin | Model-backed (when enabled) | Always deterministic |
|---|---|---|
| A2UI Inquiry | The generated surface for the visitor's intent | Component catalog hardening, submit/store |
| AG-UI Assistant | The streamed answer to the visitor's actual question (token by token, real `streamChat`) | The approval gate, apply phase, lead capture |
| A2A Concierge | Skill routing (+ visible rationale) and the artifact text | Task lifecycle, agent card, resume mechanics |
| UCP Checkout | The recommendation rationale (grounded in the fixed cart) | Cart contents, prices, totals, authorization gate |
| AP2 Trusted Surface | Optional plain-language receipt explanation (off by default) | Mandates, signatures, chain verification |

Runs are labelled with their provenance ("Live model · …" vs "Scripted demo") so nobody mistakes one for the other.

## Requirements & installation

- TYPO3 `^14.3`, PHP `^8.3`
- Optional for real model output: `netresearch/nr-llm` (+ `netresearch/nr-vault` for the key). **The provider secret must be flagged frontend-accessible in nr-vault**, otherwise frontend eID calls cannot read the key and the plugins stay in scripted mode.

```bash
composer require webconsulting/agent-nexus
vendor/bin/typo3 extension:setup --extension=agent_nexus
```

The backend hub appears as **Agent Nexus** in the module menu (`/typo3/module/agent-nexus/overview`). Add the five content elements anywhere; each has FlexForm settings (scenario, presets, intro texts, accent, LLM behavior).

## Cost & abuse controls (frontend)

Real frontend LLM calls are guarded in depth:

- **Global switch + per-protocol toggles** — extension settings `llmFrontendEnabled`, `a2uiLlmEnabled`, `aguiLlmEnabled`, `a2aLlmEnabled`, `ucpLlmEnabled`, `ap2LlmEnabled` (AP2 off by default).
- **Daily budget** — `llmDailyBudget` (USD): once the day's combined Agent Nexus spend reaches it, plugins fall back to their scripts. Spend is ledgered per protocol in `tx_agentnexus_llm_usage`; streamed calls bypass nr-llm's own usage middleware, so this ledger is authoritative.
- **Token ceiling** — `llmMaxOutputTokens`; per-element FlexForm limits may lower but never exceed it.
- **Rate limits** — per-IP fixed windows on every eID, with a tighter separate bucket for model-backed runs.
- **Input caps** — visitor text is truncated (600 chars) before it reaches a prompt.
- **Server-side settings** — LLM-relevant FlexForm settings (toggles, system prompt, token limits) are loaded server-side from the content element (`ce` uid). Nothing prompt-shaping is accepted from the wire.

## Frontend endpoints (eID)

`a2ui_generate`, `a2ui_submit`, `agui_assistant` (SSE), `a2a_card`, `a2a_rpc`, `a2a_concierge` (SSE), `ucp_manifest`, `ucp_checkout` (SSE), `ap2_authorize`.

## Development

- **Design system**: `Resources/Public/Css/nexus-tokens.css` defines the `--anx-*` vocabulary once, mapped to TYPO3 backend tokens (`.anx--backend`) and shadcn host tokens (`.anx--frontend`). `nexus-ui.css` holds the shared primitives; no hardcoded colors elsewhere.
- **Motion**: GSAP 3.15 is vendored (`Resources/Public/JavaScript/Vendor/gsap.min.js`) — same-origin, CSP-safe, offline-safe. Helpers in `nexus-motion.js` respect `prefers-reduced-motion` and force-finish entrance animations in throttled background tabs.
- **Diagrams**: `npm run diagrams` renders `Build/Diagrams/*.mmd` to theme-aware inline-SVG Fluid partials (colors become CSS variables, message steps get `data-mm-step` hooks). The generated partials are committed — consumers never need node or Chromium.

## Safety notes

Commerce is **always simulated**: `ucpReallyApply`/`aguiReallyApply` default to off, AP2 tokens are sandbox-signed, and no integration ever charges anything. The A2UI renderer only instantiates components from its own catalog — model output is data, never executable code.

## License

GPL-2.0-or-later
