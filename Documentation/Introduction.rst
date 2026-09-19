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
        -   agent ↔ merchant
        -   How does a shopping agent read a catalogue and assemble a cart?
    *   -   **AP2**
        -   agent ↔ payment
        -   How do you prove a specific human authorized a specific purchase?

Each protocol gets a backend playground that shows the raw wire frames, and a
frontend plugin an editor can place on a page. Two further elements frame them:
**Protocol hub** is the landing element — one card per protocol with its live
health, the endpoints this installation exposes and a link to the running demo —
and **Protocol info** explains one protocol next to its demo, with the sequence
diagram, the endpoints and the four steps a request walks through.

Who it is for
=============

Integrators and developers evaluating what the 2026 agent protocols actually
require of a CMS, and agencies who need something runnable to show a client. The
backend playgrounds are the lab bench; the frontend plugins are what a visitor
would see.

What it is not
==============

*   **Not a payment system.** AP2 mandates are signed with a fixed sandbox key.
    They prove token integrity, not identity, and no payment network is
    involved.
*   **Not a shop.** Every UCP checkout is simulated. No order is placed, no
    money moves.
*   **Not dependent on a model.** Without :composer:`netresearch/nr-llm` every
    protocol runs its deterministic script — the same one the functional tests
    assert on. A model makes the demos livelier, never functional.

Design decisions worth knowing
==============================

**A human gate before every write.** AG-UI stops at a confirmation before it
stores a lead; UCP halts at ``authorization.required`` before an order is
confirmed; AP2 verifies the mandate chain before it authorizes anything. Those
gates are the point of the protocols, so they are never optional.

**Deterministic where it matters.** A model may write a rationale or an answer.
It never writes a price, a cart total or a mandate: those come from the merchant
catalogue and the signing service.

**A fixed component catalogue.** An A2UI surface describes an interface as data.
The renderer only draws components the registry knows and only passes properties
the component declares; everything else is dropped before it reaches the page.
