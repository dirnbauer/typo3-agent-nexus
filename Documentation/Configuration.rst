:navigation-title: Configuration

..  include:: /Includes.rst.txt
..  _configuration:

=============
Configuration
=============

Three layers, in order of reach: extension configuration decides where the
endpoints live, what the traffic log keeps and whether a model may be used at
all; site settings say where widget records go; and a FlexForm shapes one
element on one page.

..  _configuration-extension:

Extension configuration
=======================

:guilabel:`Admin Tools > Settings > Extension Configuration > agent_nexus`.

Endpoints
---------

..  confval:: apiBasePath
    :type: string
    :Default: /api/agent-nexus

    The path prefix of every protocol endpoint: A2A JSON-RPC and HTTP+JSON,
    AG-UI, A2UI, the UCP REST binding and AP2. The endpoints answer on every
    host of the installation, before site resolution. Change the prefix only if
    a site already uses it for its own pages.

..  confval:: publishWellKnown
    :type: bool
    :Default: 1

    Publish the discovery documents the specifications pin to the host root:
    :file:`/.well-known/agent-card.json` (A2A) and :file:`/.well-known/ucp`
    (UCP). Turn it off if another extension publishes them on the same host.
    The documents stay reachable under :confval:`apiBasePath` either way.

Traffic log and retention
-------------------------

..  confval:: trafficEnabled
    :type: bool
    :Default: 1

    Record one row per protocol exchange — public API calls, widget requests,
    backend console calls and the demo agents' in-process calls — for
    :guilabel:`Agent Nexus > Traffic`.

..  confval:: trafficRetentionDays
    :type: int
    :Default: 14

    The ``agentnexus:cleanup`` command deletes traffic entries older than this.
    ``0`` keeps them until they are deleted by hand.

..  confval:: trafficCaptureBodies
    :type: bool
    :Default: 1

    Record request and response bodies and every streamed event. With it off,
    the log keeps only method, path, status, timing and allow-listed headers.

..  confval:: trafficRedactPersonalData
    :type: bool
    :Default: 1

    Replace names, email addresses, phone numbers and postal addresses in
    recorded bodies before they are stored. Headers are always allow-listed:
    cookies and authorisation headers are never recorded.

..  confval:: objectRetentionDays
    :type: int
    :Default: 90

    The ``agentnexus:cleanup`` command deletes protocol objects — tasks, runs,
    checkout sessions, mandates, surfaces — that have not changed for this long.
    ``0`` keeps them.

Run the cleanup daily. The command is schedulable, so the scheduler's
:guilabel:`Execute console commands` task can run it:

..  code-block:: bash

    vendor/bin/typo3 agentnexus:cleanup
    vendor/bin/typo3 agentnexus:cleanup --dry-run
    vendor/bin/typo3 agentnexus:cleanup --traffic-days=7 --object-days=30

Language model
--------------

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

    The page the protocol objects a widget creates are stored on: A2UI
    surfaces with the data a visitor submitted, AG-UI runs with the leads they
    captured, A2A tasks, UCP checkout sessions and AP2 mandates. ``0`` stores
    each object on the page that created it, which scatters them across the
    site — set a folder.

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
whose JavaScript calls the protocol endpoints, so a setting that guards cost or
shapes a prompt must not be postable by the client: the widget sends its content
element uid inside the protocol's own extension point (A2A message metadata,
AG-UI ``forwardedProps``, a top-level member elsewhere) and the endpoint reads
that record's FlexForm itself.
