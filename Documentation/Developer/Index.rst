:navigation-title: Developer

..  include:: /Includes.rst.txt
..  _developer:

===============
For developers
===============

..  toctree::
    :titlesonly:

    Testing
    Rethink

Layout
======

..  code-block:: text

    Classes/
      A2ui/  Agui/  A2a/  Ucp/  Ap2/   one namespace per protocol
      Agentstack/                      overview, inspector, traffic log, catalogue,
                                       the seeder and the upgrade wizards
      Shared/                          what all five use:
        Http/Api/                      the API router and route registry
        Traffic/                       the traffic recorder, its table and filters
        Store/                         the protocol object store
        Llm/                           the optional language model and its ledger
        Backend/                       the frame every backend screen shares
    Configuration/Backend/             the module set and the AJAX routes
    Configuration/Sets/                the frontend rendering glue
    Build/Diagrams/                    Archify sources for the sequence diagrams
    Tests/Conformance/Schemas/         the official specification schemas

Requests
========

One PSR-15 middleware, :php:`\Webconsulting\AgentNexus\Shared\Http\Api\ApiRouter`,
serves every protocol endpoint. It sits in the frontend stack before site
resolution, so the endpoints answer on every host whatever its site
configuration says, and the discovery documents the specifications pin to the
host root (:file:`/.well-known/agent-card.json`, :file:`/.well-known/ucp`) can
be served at all. Everything else lives under the ``apiBasePath`` setting,
:file:`/api/agent-nexus` by default.

A protocol contributes its endpoints with a class implementing
:php:`\Webconsulting\AgentNexus\Shared\Http\Api\RouteProvider`. The interface is
autoconfigured, so the class is all it takes:

..  code-block:: php

    final class MyRoutes implements RouteProvider
    {
        public function routes(): array
        {
            return [
                new Route(
                    id: 'my.things.get',
                    protocol: Protocol::Ucp,
                    methods: ['GET'],
                    path: '/my/things/{id}',
                    handler: ThingsEndpoint::class . '::get',
                    operation: 'get_thing',
                    binding: 'HTTP+JSON',
                    description: 'Returns one thing.',
                ),
            ];
        }
    }

The handler is a public service method that takes the request and returns a
response. The matched route and its parameters are the
:php:`RouteMatch::ATTRIBUTE` request attribute. The overview module and the
"Protocol info" element list the same routes, so an endpoint that is served is
also an endpoint that is documented.

Traffic
=======

The router hands every request it serves to
:php:`\Webconsulting\AgentNexus\Shared\Traffic\TrafficRecorder`, which writes one
row to :sql:`tx_agentnexus_traffic`: the request, then the response, or — for a
Server-Sent-Events response — every frame with the millisecond it left the
server. A stream is not over when the handler returns, so the recorder wraps its
body and writes the row when the stream ends, including when the client hangs
up.

A handler describes its exchange through the
:php:`TrafficCapture::ATTRIBUTE` request attribute: ``describe()`` names the
protocol operation, ``correlate()`` links the exchange to the protocol object it
touched, and ``via()`` marks a request from one of the extension's own widgets.
Backend AJAX routes opt in with an ``agentnexus`` option in
:file:`Configuration/Backend/AjaxRoutes.php`. A demo agent that calls another
protocol in-process records the call with ``recordInProcess()``.

Before anything is stored, headers are allow-listed, personal data in JSON bodies
is masked and bodies are capped (:php:`TrafficRedactor`). Recording never breaks
an exchange: every failure is logged and swallowed.

Protocol objects
================

:php:`\Webconsulting\AgentNexus\Shared\Store\ObjectStore` keeps A2A tasks, AG-UI
runs, UCP checkout sessions, AP2 mandates and A2UI surfaces in
:sql:`tx_agentnexus_object`, one row per object. The payload is the object in
its specification's own JSON shape, so the inspector shows exactly what went
over the wire; the history column records every state it passed through. It is
protocol state as well as a record — A2A answers ``GetTask`` from it and resumes
a task that paused for input, UCP serves ``GET /checkout-sessions/{id}`` from
it, AG-UI checks that an approval answers an interrupt it actually raised.

Backend screens
===============

Every screen is a plain controller with the :php:`#[AsController]` attribute,
built through :php:`\Webconsulting\AgentNexus\Shared\Backend\ModuleFrame`. The
frame sets the title from the module label, the v14 module menu for the
third-level screens, the shortcut, and loads the design-system layers:
:file:`nexus-tokens.css` (the ``--anx-*`` vocabulary, mapped onto the
``--typo3-*`` tokens so light and dark mode follow the backend),
:file:`nexus-ui.css` and :file:`nexus-backend.css`. Standard controls are core
markup; the design system only draws what core has no component for, such as
stream consoles, timelines and protocol chips.

Extension points
================

**The A2UI catalogue.** The component registry is the official basic catalogue
of the implemented A2UI version. A test compares it with the vendored catalogue
schema, so it cannot drift from the specification, and anything a model emits
outside it is dropped before it reaches the page.

**The A2A skills.** ``SkillCatalog`` is the single source of truth behind the
Agent Card, the consoles and the deterministic agent. Add a skill there and it
appears in all three, including in the public card.

**The protocol descriptions.** ``ProtocolCatalog`` assembles what the Protocol
info element and the overview show, and ``SpecificationVersions`` holds the
implemented and latest specification versions. Figures are derived from the
services that implement each protocol — never restated — so they cannot drift.

Streaming
=========

SSE endpoints return a normal PSR-7 response whose body is an
``EventStream``. Because it implements
:php:`\TYPO3\CMS\Core\Http\SelfEmittableStreamInterface` the body flushes each
frame as it is produced in production, while consumers that do not emit — tests,
anything inspecting the response — read the whole stream through
``__toString()``. Nothing calls ``exit``.

Diagrams
========

The protocol sequence diagrams are build artifacts, not content. They are made
with `Archify <https://github.com/tt-a1i/archify>`__ (MIT), which renders a
typed JSON document into a self-contained, interactive HTML file:

..  code-block:: bash

    npm run diagrams

That reads :file:`Build/Diagrams/*.json` (the format is described in
:file:`Build/Diagrams/README.md`), adds the coordinates, validates every
diagram against Archify's ``showcase`` quality profile and writes
:file:`Resources/Public/Diagrams/*.html`. Archify is not an npm package: the
script clones the commit pinned in :file:`Build/render-diagrams.mjs` into
:file:`Build/.archify` on first use, or uses ``ARCHIFY_DIR``. The HTML files
are committed, so neither editors nor the site ever need node or Archify.

The protocol info plugin shows a diagram in an iframe in Archify's embed mode.
:file:`Resources/Public/JavaScript/protocol-diagram.js` passes the page's
theme (``?theme=light|dark``, following desiderio's ``.dark`` class) and sizes
the frame to the diagram; the caption links to the full viewer with its
guided chapters, trace and export. The files are same-origin, which the
height measurement needs and ``X-Frame-Options: SAMEORIGIN`` allows.

Rendering also writes :file:`Build/diagrams.lock.json`, holding the Archify
commit and the hash of every source, of the renderer and of every generated
file. ``npm run diagrams:check`` verifies those hashes and is what CI runs, so
it catches a source edited without re-rendering and a hand-edited diagram
without node modules, Archify or a browser. Rendering is deterministic: a
second run changes nothing.

Commit the lock file together with the HTML files.
