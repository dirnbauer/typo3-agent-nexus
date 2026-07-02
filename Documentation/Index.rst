..  _start:

============
Agent Nexus
============

:Extension key:
    agent_nexus

:Package:
    webconsulting/agent-nexus

:Version:
    2.0.0

Agent Nexus is a unified TYPO3 v14 lab for the agent-protocol family — A2UI
(agent ↔ UI), AG-UI (agent ↔ user), A2A (agent ↔ agent), UCP (agent ↔ merchant)
and AP2 (payment authorization). It explains the protocols, demonstrates every
wire frame in backend playgrounds, and ships five visitor-facing plugins that
use a real LLM through ``netresearch/nr-llm`` when available — with
deterministic fallbacks that always work without an API key.

Features
========

Backend modules
---------------

The ``Agent Nexus`` backend hub (``/typo3/module/agent-nexus/overview``)
contains:

*   **Overview** — a field guide: animated protocol map, theory cards, a
    protocol comparison table, a "which protocol do I need?" decision helper,
    per-protocol tabs with pre-rendered Mermaid sequence diagrams, animated
    flow beams, key facts and spec snippets, plus a glossary.
*   **A2UI Playground** — natural-language intent → A2UI v1.0 surface
    (declarative JSON, never code) rendered with trusted native components;
    includes a cost/usage dashboard.
*   **AG-UI Playground** — typed agent events (text deltas, tool calls, state
    patches) streamed over SSE with a human approval gate before any write.
*   **A2A Console** — fetch the Agent Card, delegate a task over JSON-RPC
    ``message/stream``, follow the lifecycle to the artifact.
*   **UCP Console** — a shopping agent discovers the merchant manifest, builds
    a cart, and pauses at the human authorization gate.
*   **AP2 Mandate Studio** — mint a signed Intent Mandate and Cart Mandate,
    verify the chain, tamper-test it.

Frontend plugins
----------------

Editors can place these content elements (CTypes in parentheses):

*   A2UI: Smart Project Inquiry (``agentnexus_inquiry``)
*   AG-UI: AI Site Assistant (``agentnexus_assistant``)
*   A2A: Expert Router (``agentnexus_concierge``)
*   UCP: Package & Quote Builder (``agentnexus_checkout``)
*   AP2: Signed Quote Authorization (``agentnexus_trustedsurface``)

Each plugin is a cacheable Fluid shell plus an ES-module widget talking to
public eID endpoints: ``a2ui_generate``, ``a2ui_submit``, ``agui_assistant``,
``a2a_card``, ``a2a_rpc``, ``a2a_concierge``, ``ucp_manifest``,
``ucp_checkout``, ``ap2_authorize``.

Real model vs. deterministic script
===================================

With ``netresearch/nr-llm`` installed and a provider configured, the useful
parts of each plugin are model-backed; the safety-critical parts never are:

*   **AG-UI Assistant** streams a real answer to the visitor's question token
    by token; the approval gate, apply phase and lead capture stay scripted.
*   **A2A Concierge** routes free-text requests to a catalog skill with a
    visible rationale and writes the artifact for the actual request; the task
    lifecycle stays scripted.
*   **UCP Checkout** writes only the recommendation rationale; cart contents,
    prices and totals are always deterministic.
*   **AP2 Trusted Surface** stays fully deterministic; an optional
    plain-language receipt explanation is off by default.

Every run is labelled with its provenance ("Live model · …" or
"Scripted demo").

The nr-vault secret used by the provider must be flagged *frontend accessible*,
otherwise frontend eID requests cannot read the key and the plugins stay in
scripted mode.

Cost and abuse controls
=======================

*   Extension settings: ``llmFrontendEnabled`` (global switch),
    ``llmDailyBudget`` (USD, deterministic fallback once reached),
    ``llmMaxOutputTokens`` (hard ceiling), and per-protocol toggles
    (``a2uiLlmEnabled``, ``aguiLlmEnabled``, ``a2aLlmEnabled``,
    ``ucpLlmEnabled``, ``ap2LlmEnabled``).
*   Spend is ledgered per protocol in ``tx_agentnexus_llm_usage``. Streamed
    calls bypass nr-llm's usage middleware, so this ledger is authoritative.
*   Per-IP rate limits protect every eID, with a tighter bucket for
    model-backed runs; visitor input is truncated before it reaches a prompt.
*   LLM-relevant FlexForm settings (toggles, system prompt, token limits) are
    loaded server-side from the content element — never accepted from the
    request body.

Installation
============

..  code-block:: bash

    composer require webconsulting/agent-nexus
    vendor/bin/typo3 extension:setup --extension=agent_nexus

Development notes
=================

*   Design tokens live in ``Resources/Public/Css/nexus-tokens.css`` (one
    ``--anx-*`` vocabulary mapped to TYPO3 backend tokens and shadcn host
    tokens); shared primitives in ``nexus-ui.css``.
*   GSAP 3.15 is vendored under ``Resources/Public/JavaScript/Vendor/`` —
    same-origin, CSP-safe, offline-safe; all helpers respect
    ``prefers-reduced-motion``.
*   ``npm run diagrams`` re-renders ``Build/Diagrams/*.mmd`` into theme-aware
    inline-SVG Fluid partials. The generated partials are committed, so
    consumers never need node or Chromium.

Safety
======

UCP and AP2 are demos. They do not initiate real payments: AP2 mandates are
sandbox-signed and UCP orders are simulated unless a project deliberately wires
a real payment integration behind the documented extension settings
(``ucpReallyApply`` and ``aguiReallyApply`` default to off). The A2UI renderer
instantiates only components from its own catalog — model output is data,
never executable code.
