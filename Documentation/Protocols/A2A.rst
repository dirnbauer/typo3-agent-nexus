:navigation-title: A2A

..  include:: /Includes.rst.txt
..  _protocol-a2a:

====================
A2A — agent to agent
====================

Two agents can only cooperate if one can find out what the other does. A2A makes
that concrete: an agent publishes an **Agent Card** describing its identity,
skills, transport and authentication; another agent reads it and delegates work
over JSON-RPC 2.0, following the task through a defined lifecycle.

The lifecycle matters as much as the transport. A task moves
``submitted`` → ``working`` → ``completed``, and may pause in
``input-required`` when a skill needs more detail — the cooperative equivalent of
a human-in-the-loop gate. Results come back as named **artifacts**, not as chat
messages.

How a request flows
===================

#.  **Discovery.** The calling agent fetches the Agent Card.
#.  **Delegation.** It sends a message over JSON-RPC; the server creates a Task
    with an id and a context id.
#.  **Lifecycle.** Status updates stream the task through ``working`` and,
    where the skill asks for it, ``input-required``.
#.  **Artifacts.** The result is returned as a named artifact, streamable in
    chunks, and the task reaches ``completed``.

Endpoints
=========

..  list-table::
    :header-rows: 1

    *   -   Method
        -   Path
        -   Purpose
    *   -   GET
        -   ``/index.php?eID=a2a_card``
        -   The Agent Card: identity, skills, transport, authentication.
    *   -   POST
        -   ``/index.php?eID=a2a_rpc``
        -   JSON-RPC entry point for ``message/send`` and ``message/stream``.
    *   -   POST
        -   ``/index.php?eID=a2a_concierge``
        -   The visitor-facing concierge.

In this installation
====================

The site agent advertises three skills — summarise a page, draft an outreach
email, plan an onboarding. One of them pauses for input before it finishes, so
the ``input-required`` state is not theoretical.

When no model is available the agent routes by keyword and falls back to
summarising; it never routes to a skill outside the catalogue. Tasks are logged
to :sql:`tx_agentnexus_a2a_task_log`, concierge requests to
:sql:`tx_agentnexus_a2a_request`.

..  note::

    The Agent Card is public and advertises this site to any agent that looks
    for it. Make sure the skills it lists are ones you want published.
