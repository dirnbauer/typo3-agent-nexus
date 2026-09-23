:navigation-title: Protocols

..  include:: /Includes.rst.txt
..  _protocols:

=============
The protocols
=============

Each page describes one protocol: what it is for, what this installation
exposes, and what a request actually does. The same material is available inside
the site — put a :guilabel:`Agent Nexus: Protocol info` element next to a demo
and it renders the sequence diagram, the endpoints and the steps from the live
services.

Which version of each specification is implemented, and what changed since
3.1, is on its own page: :ref:`spec-versions`. Where two specifications
disagree, :ref:`known-spec-conflicts` says how Agent Nexus resolves it.

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: A2UI — agent to interface

        The agent describes an interface; the site renders it from a fixed,
        safe component catalogue.

        ..  card-footer:: :ref:`A2UI <protocol-a2ui>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: AG-UI — agent to user

        A run is a stream of typed events, so the interface can show thinking,
        tool calls and ask for approval.

        ..  card-footer:: :ref:`AG-UI <protocol-agui>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: A2A — agent to agent

        A published Agent Card lets another agent discover this one, delegate a
        task and collect artifacts.

        ..  card-footer:: :ref:`A2A <protocol-a2a>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: UCP — agent to merchant

        A merchant manifest plus a streamed checkout, with a human
        authorization gate before anything is placed.

        ..  card-footer:: :ref:`UCP <protocol-ucp>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: AP2 — agent to payment

        Chained, signed mandates prove a specific purchase was authorized by a
        specific human within limits.

        ..  card-footer:: :ref:`AP2 <protocol-ap2>`
            :button-style: btn btn-secondary stretched-link

..  toctree::
    :hidden:
    :titlesonly:

    SpecVersions
    KnownSpecConflicts
    A2UI
    AGUI
    A2A
    UCP
    AP2
