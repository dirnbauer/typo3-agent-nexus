:navigation-title: Introduction

..  include:: /Includes.rst.txt
..  _introduction:

============
Introduction
============

What it is
==========

Agent Nexus implements five agent protocols against a real TYPO3 installation.
Each one covers a different edge of an agentic system:

..  list-table::
    :header-rows: 1

    *   -   Protocol
        -   The edge
        -   What it answers
    *   -   **A2UI**
        -   agent ↔ interface
        -   How does an agent put a form on a page without shipping code?
    *   -   **AG-UI**
        -   agent ↔ user
        -   How does a person watch an agent work, and approve before it writes?
    *   -   **A2A**
        -   agent ↔ agent
        -   How does another agent discover this one and delegate a task to it?
    *   -   **UCP**
        -   agent ↔ business
        -   How does a shopping agent check out with a business?
    *   -   **AP2**
        -   agent ↔ payment
        -   How do you prove a specific person approved a specific purchase?

Each protocol follows the current published version of its specification — see
:ref:`spec-versions` — and every payload it emits is tested against that
specification's official JSON Schema.

Each protocol gets public endpoints another agent can call, a backend console
that plays the client and shows the raw wire traffic, and a frontend element an
editor can place on a page. Two further elements frame them: **Protocol hub** is
the landing element — one card per protocol with its live health, the endpoints
this installation exposes and a link to the running demo — and **Protocol info**
explains one protocol next to its demo, with the sequence diagram, the endpoints
and the four steps a request walks through.

Two backend modules watch all five: the **inspector** lists every A2A task,
AG-UI run, UCP checkout session, AP2 mandate and A2UI surface with its state
history, and the **traffic log** records every request, response and streamed
event, with personal data masked before anything is stored.

Who it is for
=============

Integrators and developers evaluating what the 2026 agent protocols actually
require of a CMS, and agencies who need something runnable to show a client. The
backend consoles are the lab bench; the frontend elements are what a visitor
would see.

What it is not
==============

*   **Not a payment system.** AP2 mandates are signed with sandbox keys the
    installation generates for itself. They prove that a mandate was not
    changed after signing, not who a person is, and no payment network is
    involved.
*   **Not a shop.** Every UCP checkout is simulated. No order is placed, no
    money moves.
*   **Not dependent on a model.** Without :composer:`netresearch/nr-llm` every
    protocol runs its deterministic script — the same one the functional tests
    assert on. A model makes the demos livelier, never functional.

Design decisions worth knowing
==============================

**A human gate before every write.** AG-UI ends a run with an interrupt and
applies a change only when the next run answers it; the UCP shopping agent
completes a checkout only after the visitor approved it, exactly as approved;
AP2 verifies the checkout and payment mandates before anything is authorised.
Those gates are the point of the protocols, so they are never optional.

**Deterministic where it matters.** A model may write a rationale or an answer.
It never writes a price, a checkout total or a mandate: those come from the
business catalogue and the signing service.

**A fixed component catalogue.** An A2UI surface describes an interface as data.
The renderer only draws components the registry knows and only passes properties
the component declares; everything else is dropped before it reaches the page.
