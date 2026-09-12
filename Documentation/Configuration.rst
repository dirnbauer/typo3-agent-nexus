:navigation-title: Configuration

..  include:: /Includes.rst.txt
..  _configuration:

=============
Configuration
=============

Three layers, in order of reach: extension configuration decides whether a model
may be used at all, site settings say where records go, and a FlexForm shapes
one element on one page.

..  _configuration-extension:

Extension configuration
=======================

:guilabel:`Admin Tools > Settings > Extension Configuration > agent_nexus`.

The global switches come first: nothing below them can turn a model on if they
are off.

..  confval:: llmFrontendEnabled
    :type: bool
    :Default: 1

    The master switch for every real model call from the frontend plugins. With
    it off, each plugin falls back to its deterministic script.

..  confval:: llmDailyBudget
    :type: string
    :Default: 2.00

    Frontend model calls stop once today's combined Agent Nexus spend reaches
    this amount in US dollars, and the plugins say so in their provenance line.
    ``0`` disables the cap.

    This guard matters more than it looks: streamed calls bypass nr-llm's own
    budget middleware, so for those this is the only brake.

..  confval:: llmMaxOutputTokens
    :type: int
    :Default: 700

    A hard ceiling per frontend call. A plugin's own setting may ask for fewer
    tokens, never more.

Then one toggle per protocol — :typoscript:`a2uiLlmEnabled`,
:typoscript:`aguiLlmEnabled`, :typoscript:`a2aLlmEnabled`,
:typoscript:`ucpLlmEnabled`, :typoscript:`ap2LlmEnabled` — plus two switches
that keep the demos harmless:

..  confval:: aguiReallyApply
    :type: bool
    :Default: 0

    Keep this off for demos. With it off, an approved AG-UI write is simulated
    and logged instead of executed.

..  confval:: ucpReallyApply
    :type: bool
    :Default: 0

    Keep this off for demos. With it off, every checkout is simulated and no
    order reaches a real system.

..  _configuration-site:

Site settings
=============

Provided by the ``webconsulting/agent-nexus`` site set and written by
:ref:`the seed command <site-setup>`.

..  confval:: agentNexus.storagePid
    :type: int
    :Default: 0

    The page that stores A2UI inquiries, AG-UI leads, A2A requests, UCP orders
    and AP2 authorizations. ``0`` stores each record on the page that triggered
    it, which scatters them across the site — set a folder.

..  confval:: agentNexus.showProtocolInfo
    :type: bool
    :Default: true

    Preselects the three sections on newly created Protocol info elements.

..  _configuration-flexform:

Per-element settings
====================

Each plugin carries a FlexForm with an intro text, a placeholder, an accent
token and the protocol-specific switches — whether to show the raw frames
("under the hood"), whether that element may use a model, and whether to show the
agent's rationale.

Two of those are deliberately server-side only. The plugins are cacheable shells
whose JavaScript posts to eID endpoints, so a setting that guards cost or shapes
a prompt must not be postable by the client: the widget posts its content element
uid and the endpoint reads the record's FlexForm itself.
