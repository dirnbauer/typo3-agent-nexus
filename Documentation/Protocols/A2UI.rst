:navigation-title: A2UI

..  include:: /Includes.rst.txt
..  _protocol-a2ui:

=========================
A2UI — agent to interface
=========================

An agent that can only answer in text has to describe a form in words. A2UI lets
it describe the form itself — as data, never as code — and lets a trusted client
render it from a catalogue it already knows.

A surface is a *flat* list. Every component carries a unique id, names a
component from the catalogue, holds its own properties, and references its
children by id. Exactly one component has the id ``root``; the renderer rebuilds
the tree from there. Structure and data stay separate: inputs bind to a data
model through JSON Pointer paths and only sync back when an action fires.

How a request flows
===================

#.  **Intent.** The visitor types what they need in one line. Nothing is
    generated yet.
#.  **Generation.** The agent answers with a surface.
#.  **Validation.** Every component is checked against the registry. Unknown
    components and unknown properties are dropped — this is the trust boundary,
    and it is why an agent can shape the page without being able to inject
    anything into it.
#.  **Submission.** The filled surface is posted back and stored as an inquiry.

Endpoints
=========

..  list-table::
    :header-rows: 1

    *   -   Method
        -   Path
        -   Purpose
    *   -   POST
        -   ``/index.php?eID=a2ui_generate``
        -   Turns an intent into a surface.
    *   -   POST
        -   ``/index.php?eID=a2ui_submit``
        -   Stores the completed surface as an inquiry record.

In this installation
====================

The catalogue is the A2UI v1.0 "basic" set mapped onto native backend
components — text and media, inputs, choice pickers, containers, a list
template. The backend playground shows the generated JSON, the live data model
and the last emitted action side by side, so the whole
agent → JSON → native UI → signal loop is visible at once.

Records land in :sql:`tx_agentnexus_a2ui_inquiry`.
