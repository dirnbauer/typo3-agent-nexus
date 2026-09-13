:navigation-title: Developer

..  include:: /Includes.rst.txt
..  _developer:

===============
For developers
===============

..  toctree::
    :titlesonly:

    Testing

Layout
======

..  code-block:: text

    Classes/
      A2ui/  Agui/  A2a/  Ucp/  Ap2/   one namespace per protocol
      Agentstack/                      the hub, the catalogue, the seeder
      Shared/                          Http + Llm, used by all five
    Configuration/Sets/                the frontend rendering glue
    Build/Diagrams/                    Mermaid sources for the sequence diagrams

Each protocol namespace follows the same shape: ``Controller`` for the backend
module and its AJAX routes, ``Eid`` for the public endpoints, ``Service`` for the
deterministic agent and its logging, and ``Protocol`` or ``Event`` for the wire
frames.

Extension points
================

**The A2UI catalogue.** ``ComponentRegistry::register()`` adds a component with
its Fluid template, its category, the properties it accepts and whether it is a
container. Anything not registered is dropped at render time, so extending the
catalogue is the only way to extend what an agent may draw.

**The A2A skills.** ``SkillCatalog`` is the single source of truth behind the
Agent Card, the presets and the deterministic agent. Add a skill there and it
appears in all three, including in the public card.

**The protocol descriptions.** ``ProtocolCatalog`` assembles what the Protocol
info element and the hub show. Its numbers are derived from the services that
implement each protocol — never restated — so they cannot drift.

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

The sequence diagrams are build artifacts, not content:

..  code-block:: bash

    npm ci
    npm run diagrams

That renders :file:`Build/Diagrams/*.mmd` into
:file:`Resources/Public/Diagrams/*.svg`, each carrying its own light and dark
palette so it works as a plain ``<img>``. The SVGs are committed, so neither
editors nor the site ever need node or Chromium.

Rendering also writes :file:`Build/diagrams.lock.json`, holding the hash of
every source, of the renderer and of every generated SVG. ``npm run
diagrams:check`` verifies those hashes and is what CI runs, because a re-render
cannot be compared across machines: mermaid sizes a sequence diagram from
measured text, so the available fonts and the Chromium build decide the
geometry. The hash check still catches the two mistakes that matter, a source
edited without re-rendering and a hand-edited SVG, and needs no browser.

Commit the lock file together with the SVGs.
