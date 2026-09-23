:navigation-title: A2UI

..  include:: /Includes.rst.txt
..  _protocol-a2ui:

=========================
A2UI — agent to interface
=========================

An agent that can only answer in text has to describe a form in words. A2UI
lets it describe the form itself — as data, never as code — and lets a trusted
renderer draw it from a catalogue of components the renderer already knows.

Agent Nexus 4.0 implements **A2UI v0.9.1**, the current stable release (29 May
2026), with the official **basic catalogue**. The **v1.0 release candidate** is
available when a client asks for it; it is still changing and no official
renderer supports it yet. See :ref:`spec-versions` for what changed since 3.1,
whose early "v1.0" draft used a catalogue of its own.

A surface is a flat list of components. Every component has a unique ``id``,
names its type from the catalogue and carries its properties; it refers to
other components by id (``child``, ``children``, ``trigger``, ``content``).
Exactly one component has the id ``root``. Structure and data stay apart:
inputs bind to a data model through JSON Pointer paths, and the data model
travels back to the agent only with an action.

How a surface flows
===================

#.  **Request.** A client posts a one-line request (``intent``) to
    ``POST /a2ui/surfaces``, optionally with the version or its renderer
    capabilities.
#.  **Generation.** With a model configured, the model sees the basic
    catalogue of the requested version and one worked example and answers
    with components and a data model. Without one — or when its answer cannot
    be used — the built-in generator picks one of ten forms by keywords.
#.  **Sanitising.** Every component is checked against the catalogue. Unknown
    components and properties are removed, shapes of older drafts are repaired
    (a ``Textarea`` becomes a long ``TextField``, a Button's ``text`` becomes a
    ``Text`` child, ``required: true`` becomes a check, ``wantResponse``
    disappears), references to missing components are cut and whatever the
    root cannot reach is pruned. This is the trust boundary: an agent shapes
    the page without being able to inject anything into it.
#.  **Messages.** The server builds the envelopes — in v0.9.1
    ``createSurface``, ``updateComponents`` and ``updateDataModel``; in v1.0 one
    ``createSurface`` with the components and the data model inline — and
    answers with the list, in order.
#.  **Rendering.** The renderer draws the surface, binds the inputs to the data
    model and runs the checks. A failing check marks its field
    (``aria-invalid``) and disables a Button that carries it.
#.  **Action.** A click on a Button sends the spec's ``action`` message —
    ``{name, surfaceId, sourceComponentId, timestamp, context}`` — to
    ``POST /a2ui/actions``, with the whole data model in the transport metadata
    (``a2uiClientDataModel``; ``a2uiRendererDataModel`` in v1.0) because the
    surface asked for it with ``sendDataModel``.
#.  **Answer.** Actions are fire-and-forget; the agent answers with messages
    for the surface. The main action of a form gets a confirmation view
    (``updateComponents`` replaces the root, ``updateDataModel`` writes the
    reference and the summary the view binds to); *Start over* gets
    ``deleteSurface``.

The sequence diagram of this flow is :file:`Build/Diagrams/a2ui.mmd`.

..  code-block:: text

    POST /a2ui/surfaces   {"intent": "A contact form"}
      ← createSurface      surfaceId, the basic catalogue, sendDataModel: true
      ← updateComponents   Card → Column → Text, TextField …, Row → Button, Button
      ← updateDataModel    path "/", the starting values
    POST /a2ui/actions    {"version", "action": {"name": "sendMessage", …},
                           "metadata": {"a2uiClientDataModel": {…}}}
      ← updateComponents   root → the confirmation card
      ← updateDataModel    path "/confirmation", reference and summary

Versions
========

..  list-table::
    :header-rows: 1
    :widths: 20 40 40

    *   -   Aspect
        -   v0.9.1 (default)
        -   v1.0 (release candidate)
    *   -   ``version``
        -   ``"v0.9.1"`` is sent; ``"v0.9"`` and ``"v0.9.1"`` are accepted
        -   ``"v1.0"``
    *   -   Catalogue id
        -   ``https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json``
        -   ``https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json``
    *   -   Creating a surface
        -   ``createSurface`` (with a ``theme``), then ``updateComponents`` and
            ``updateDataModel``
        -   one ``createSurface`` with ``components`` and ``dataModel``; no
            theme
    *   -   Deleting a value
        -   ``updateDataModel`` without ``value``
        -   ``updateDataModel`` with ``"value": null``
    *   -   Headings
        -   Text ``variant`` ``h1`` … ``h5``
        -   a Markdown heading in the text (``"## Title"``)
    *   -   Data model in the metadata
        -   ``a2uiClientDataModel``
        -   ``a2uiRendererDataModel``
    *   -   Capabilities
        -   ``a2uiClientCapabilities`` with a ``"v0.9"`` entry
        -   ``a2uiRendererCapabilities`` with a ``"v1.0"`` entry

The version is taken from the request's ``version``; otherwise from the
capabilities it carries (as top-level members or inside ``metadata``),
preferring v0.9.1 when both are listed; otherwise v0.9.1. The v0.9.1 prose
spells the catalogue id with ``v0_9_1`` in places; that spelling is accepted
too. Neither the ``surfaceProperties`` of early drafts nor the
``wantResponse``/``actionResponse`` pair exists in either version.

Endpoints
=========

..  list-table::
    :header-rows: 1
    :widths: 10 30 60

    *   -   Method
        -   Path
        -   Purpose
    *   -   POST
        -   ``/api/agent-nexus/a2ui/surfaces``
        -   Turns a request into a surface: ``{"messages": [...], "surfaceId",
            "version", "provenance": {"mode": "llm"|"builtin", "label"},
            "notes": [...]}``. The messages validate as a server-to-client
            list (v0.9.1) or an agent-to-renderer list (v1.0).
    *   -   POST
        -   ``/api/agent-nexus/a2ui/actions``
        -   Takes one renderer-to-agent message — an ``action`` or an ``error``
            report — plus ``metadata``, and answers ``{"messages": [...]}``,
            the official list wrapper.
    *   -   GET
        -   ``/api/agent-nexus/a2ui/catalog``
        -   The agent's capabilities (valid against ``server_capabilities.json``
            and ``agent_capabilities.json``), the endpoints and, per version,
            every component with its properties and every function. Cached
            for five minutes.

The binding is plain JSON over HTTP with A2UI messages inside; it keeps the
transport contract of A2UI (messages in order, one JSON value each, metadata
next to the message). The API base path is the ``apiBasePath`` extension
setting (``/api/agent-nexus`` by default). The A2A and AG-UI transports of
A2UI are not wired here.

Request of ``POST /a2ui/surfaces``:

..  code-block:: json

    {
      "intent": "A contact form",
      "version": "v0.9.1",
      "a2uiClientCapabilities": {"v0.9": {"supportedCatalogIds": ["https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json"]}},
      "locale": "en",
      "agentNexus": {"ce": 73224, "page": 1402, "url": "https://…"}
    }

Only ``intent`` is required. ``agentNexus`` is sent by the widget: the content
element's own settings (business context, confirmation text) are then read
from its record on the server, never from the request.

Refusals
--------

A refusal is an A2UI error message with an HTTP status — the shape a renderer
uses to report errors to an agent: ``VALIDATION_FAILED`` with the JSON Pointer
of the field, or a code of this binding's own.

..  list-table::
    :header-rows: 1
    :widths: 10 35 55

    *   -   Status
        -   ``error.code``
        -   When
    *   -   400
        -   ``BAD_REQUEST``
        -   The body is not a JSON object, or an action request carries a member
            other than ``version``, ``action``/``error``, ``metadata`` and
            ``agentNexus``.
    *   -   404
        -   ``SURFACE_NOT_FOUND``
        -   This agent never created the surface.
    *   -   409
        -   ``SURFACE_ALREADY_SUBMITTED``
        -   The surface was already sent.
    *   -   410
        -   ``SURFACE_DELETED``
        -   The surface was deleted (after *Start over*).
    *   -   413
        -   ``PAYLOAD_TOO_LARGE``
        -   More than 16 KB for a request, 256 KB for an action.
    *   -   422
        -   ``VALIDATION_FAILED``
        -   A missing ``intent``, an action without ``timestamp`` or
            ``context``, a component the surface does not have, an action the
            component does not send, or a message in another version than the
            surface's (``path`` names the field).
    *   -   422
        -   ``UNSUPPORTED_VERSION``, ``UNSUPPORTED_CATALOG``
        -   A version other than v0.9/v0.9.1 or v1.0, or capabilities without
            the basic catalogue.
    *   -   429
        -   ``RATE_LIMITED``
        -   More than 20 requests from one address in 10 minutes
            (``Retry-After: 600``).

The basic catalogue
===================

The components (18 in both versions): ``Text``, ``Image``, ``Icon``,
``Video``, ``AudioPlayer``, ``Row``, ``Column``, ``List``, ``Card``,
``Tabs``, ``Modal``, ``Divider``, ``Button``, ``TextField``, ``CheckBox``,
``ChoicePicker``, ``Slider`` and ``DateTimeInput``. The functions:
``required``, ``regex``, ``length``, ``numeric``, ``email``,
``formatString``, ``formatNumber``, ``formatCurrency``, ``formatDate``,
``pluralize``, ``openUrl``, ``and``, ``or`` and ``not`` — plus ``@index``
inside v1.0 list templates.

The registry (:php:`Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry`)
writes both catalogues out by hand; a conformance test compares every
component, property, enum, default and function argument with the published
catalogue files, so the two cannot drift. The backend screen *Agent Nexus >
A2UI > Basic catalogue* shows every component with a live example.

The renderer
============

:file:`Resources/Public/JavaScript/a2ui-renderer.js` draws surfaces for the
widget, the playground and the catalogue screen: the message sequence of both
versions, bindings (relative paths inside a List template resolve against the
item), the catalogue functions and ``formatString`` interpolation, two-way
binding, checks and actions. Text is always written as text, never parsed as
HTML; Markdown is limited to headings, bold, italic, code and line breaks.
Labels are bound to their inputs, choices are fieldsets with a legend, tabs
follow the ARIA tab pattern, the modal is a native dialog, errors are tied to
their fields with ``aria-describedby``, and focus moves to a new form, to the
confirmation and back to the request field after *Start over*.

Surfaces and the inspector
==========================

Every surface is a protocol object of kind *Surface*: the object id is the
``surfaceId`` (a readable slug and random hex, never reused), the label is the
request and the state is ``created``, then ``submitted`` or ``deleted``. The
payload is ``{version, messages, dataModel, actions}``: every message the agent
sent, the data model as the renderer last reported it and every message the
renderer sent. A widget's surface is stored on the site's storage folder
(``agentNexus.storagePid``) or its page. A submitted inquiry is exactly that
data model; there is no inquiry table any more.

*Agent Nexus > Inspector > Surfaces* lists them, and the traffic log shows each
exchange (``createSurface``, ``action``, ``error``) linked by the surface id.

The playground
==============

*Agent Nexus > A2UI > Playground* plays both ends: describe a form or pick an
example, choose the version, and every message appears numbered next to the
surface drawn from it. Sending the form shows the action with its metadata and
the agent's answer. The playground uses the same binding as the public
endpoints through two backend routes, as the logged-in editor: no rate limit
and no frontend model guard.

The inquiry widget
==================

The "A2UI: Smart inquiry" content element is a client of the public endpoints.
It sends its content element, page and address in ``agentNexus`` and its
renderer capabilities, draws the answer, and sends the form as an action. The
confirmation text is the element's *Success message*; the business context
shapes a model's prompt when one answers.

What is simulated
=================

*   Nothing a visitor sends leaves the installation: the data model is stored
    on the surface, nobody is notified.
*   The built-in generator answers whenever no model may: without
    netresearch/nr-llm, with ``a2uiLlmEnabled`` off, for public requests when
    the frontend guard says no (``llmFrontendEnabled``, the daily budget) or
    when one address has used its eight model requests in 10 minutes. The
    answer says which it was in ``provenance`` and why in ``notes``.
*   Public requests pass ``llmMaxOutputTokens`` to the model. A surface needs
    about 1,000 to 1,500 output tokens; with a lower ceiling a model's answer
    is cut off and the built-in generator answers instead.

Try it with curl
================

..  code-block:: bash

    curl -s https://example.org/api/agent-nexus/a2ui/surfaces \
      -H 'Content-Type: application/json' \
      -d '{"intent": "A contact form", "version": "v0.9.1"}'

Send the form back with the ``surfaceId`` from the answer:

..  code-block:: bash

    curl -s https://example.org/api/agent-nexus/a2ui/actions \
      -H 'Content-Type: application/json' \
      -d '{"version": "v0.9.1",
           "action": {"name": "sendMessage", "surfaceId": "contact-8436bb96",
                      "sourceComponentId": "submit", "timestamp": "2026-09-23T11:30:00Z",
                      "context": {"name": "Ada", "email": "ada@example.org", "message": "Hello"}},
           "metadata": {"a2uiClientDataModel": {"version": "v0.9.1",
                        "surfaces": {"contact-8436bb96": {"name": "Ada", "email": "ada@example.org"}}}}}'

The v1.0 candidate, and what this agent can generate:

..  code-block:: bash

    curl -s https://example.org/api/agent-nexus/a2ui/surfaces \
      -H 'Content-Type: application/json' \
      -d '{"intent": "Book an introductory call", "version": "v1.0"}'

    curl -s https://example.org/api/agent-nexus/a2ui/catalog

On the command line, ``vendor/bin/typo3 a2ui:generate "A contact form"``
prints the messages and validates them; ``--a2ui-version=v1.0`` switches the
version and ``--offline`` skips the model.
