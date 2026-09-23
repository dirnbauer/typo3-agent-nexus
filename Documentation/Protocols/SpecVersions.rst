:navigation-title: Specification versions

..  include:: /Includes.rst.txt
..  _spec-versions:

======================
Specification versions
======================

Which version of each specification Agent Nexus implements, compared with the
newest published one. Every value was checked against the primary source on
**23 September 2026**: the specification site and the specification repository,
not secondary write-ups.

Agent Nexus 3.1 implemented what was current when it was written. By September
2026 every one of the five protocols had moved, three of them to their first
stable major version. Agent Nexus 4.0 implements the version in the last column.

..  list-table::
    :header-rows: 1
    :widths: 12 22 22 22 22

    *   -   Protocol
        -   Primary source
        -   Implemented in 3.1
        -   Latest published
        -   Implemented in 4.0
    *   -   A2A
        -   `a2a-protocol.org <https://a2a-protocol.org/v1.0.1/specification/>`__,
            `a2aproject/A2A <https://github.com/a2aproject/A2A>`__
        -   0.3.0
        -   **1.0.1** (28 May 2026)
        -   1.0 (wire version ``1.0``); 0.3 answered on request
    *   -   AG-UI
        -   `docs.ag-ui.com/spec/1.0 <https://docs.ag-ui.com/spec/1.0>`__,
            `ag-ui-protocol/ag-ui <https://github.com/ag-ui-protocol/ag-ui>`__
        -   pre-1.0 event set (no version)
        -   **1.0** (17 September 2026)
        -   1.0
    *   -   A2UI
        -   `a2ui.org <https://a2ui.org>`__,
            `a2ui-project/a2ui <https://github.com/a2ui-project/a2ui>`__
        -   "v1.0" draft shape of early 2026
        -   **v0.9.1** stable (29 May 2026);
            v1.0 release candidate
        -   v0.9.1 by default; v1.0 candidate on request
    *   -   UCP
        -   `ucp.dev <https://ucp.dev/2026-08-25/specification/overview/>`__,
            `Universal-Commerce-Protocol/ucp <https://github.com/Universal-Commerce-Protocol/ucp>`__
        -   none (a home-made "0.1" manifest)
        -   **2026-08-25** (25 August 2026)
        -   2026-08-25, checkout capability over REST
    *   -   AP2
        -   `ap2-protocol.org <https://ap2-protocol.org>`__,
            `google-agentic-commerce/AP2 <https://github.com/google-agentic-commerce/AP2>`__
        -   none (HS256 look-alikes of the v0.1 mandates)
        -   **v0.2.0** (28 April 2026)
        -   v0.2.0 mandates (``mandate.*.1``), sandbox keys
    *   -   MCP
        -   `modelcontextprotocol.io <https://modelcontextprotocol.io/specification/2026-07-28>`__
        -   not implemented
        -   **2026-07-28** (28 July 2026)
        -   not implemented; referenced where the other
            protocols bind to it

What changed, per protocol
==========================

A2A 0.3.0 → 1.0.1
-----------------

*   JSON-RPC methods are renamed: ``message/send`` → ``SendMessage``,
    ``message/stream`` → ``SendStreamingMessage``, ``tasks/get`` → ``GetTask``,
    ``tasks/cancel`` → ``CancelTask``, ``tasks/resubscribe`` →
    ``SubscribeToTask``. ``ListTasks`` is new. 3.1 answered only the first two.
*   The ``kind`` discriminator is gone. A stream event is a wrapper object —
    ``{"task": …}``, ``{"statusUpdate": …}``, ``{"artifactUpdate": …}`` — and a
    Part is ``{"text": …}``, ``{"data": …}``, ``{"url": …}`` or ``{"raw": …}``.
*   Enum values follow ProtoJSON: ``TASK_STATE_WORKING``, ``ROLE_AGENT``.
    ``TaskStatusUpdateEvent.final`` is removed; a stream ends by closing.
*   The Agent Card lists ``supportedInterfaces`` (URL, binding, protocol
    version) instead of ``url``/``preferredTransport``/``protocolVersion``, uses
    ``securityRequirements`` instead of ``security``, and loses
    ``stateTransitionHistory``. It is published at
    ``/.well-known/agent-card.json``; 3.1 served it from an eID only.
*   Clients send ``A2A-Version``; a missing header means 0.3. Unsupported
    versions get ``VersionNotSupportedError`` (``-32009``). The A2A error codes
    ``-32001`` to ``-32009`` carry a ``google.rpc.ErrorInfo`` detail.
*   The HTTP+JSON binding (``POST /message:send``, ``GET /tasks/{id}`` …) is
    new in 4.0; 3.1 had JSON-RPC only.

AG-UI → 1.0
-----------

*   ``RUN_STARTED`` must carry ``protocolVersion: "1.0"``; ``RunAgentInput``
    may carry the client's version.
*   Reasoning is a span with messages inside it: ``REASONING_START`` →
    ``REASONING_MESSAGE_START`` (``role: "reasoning"``) →
    ``REASONING_MESSAGE_CONTENT`` → ``REASONING_MESSAGE_END`` →
    ``REASONING_END``, every event with a ``messageId``. 3.1 emitted
    ``REASONING_START``/``CONTENT``/``END`` without ids, which a 1.0 client
    rejects.
*   Human approval is an **interrupt**: ``RUN_FINISHED`` ends with
    ``outcome: {"type": "interrupt", "interrupts": […]}`` and the next run
    answers it in ``RunAgentInput.resume``. 3.1 used a home-made ``approval``
    input field.
*   Every object is closed: unknown fields are invalid, optional fields are
    omitted rather than sent as ``null``, ``timestamp`` is an integer. Widget
    extras travel in ``forwardedProps``.
*   ``THINKING_*`` is removed; ``SUBAGENT_*`` and ``RUN_FINISHED.usage`` are new.

A2UI → v0.9.1 (and v1.0 candidate)
----------------------------------

*   The latest *stable* version is v0.9.1. The "v1.0" shape 3.1 emitted is a
    release candidate that is still changing, and it had since removed two
    fields 3.1 used: ``surfaceProperties`` and ``action.event.wantResponse``.
*   v0.9.1 builds a surface from separate messages: ``createSurface`` (with a
    required ``catalogId``), ``updateComponents``, ``updateDataModel`` and
    ``deleteSurface``. The v1.0 candidate may inline components and data in
    ``createSurface``.
*   The basic catalogue is the official one: ``Card`` and ``Button`` take a
    single ``child``, ``TextField`` has ``variant`` (``shortText``,
    ``longText``, ``number``, ``obscured``), ``ChoicePicker`` replaces the
    custom ``ButtonGroup``, validation uses ``checks: [{condition, message}]``.
    3.1's ``Textarea`` and ``ButtonGroup`` are not part of the catalogue.
*   The renderer answers with an ``action`` message
    (``{name, surfaceId, sourceComponentId, timestamp, context}``) and, with
    ``sendDataModel``, the data model in ``a2uiClientDataModel``.

UCP → 2026-08-25
----------------

*   Discovery is a business profile at ``/.well-known/ucp``: ``ucp.version``,
    ``ucp.services`` (keyed by service name, one entry per transport),
    ``ucp.capabilities`` (keyed by capability name) and
    ``ucp.payment_handlers``, plus signing keys in ``keys``.
*   Checkout is a REST resource: ``POST /checkout-sessions``, ``GET``/``PUT``
    ``/checkout-sessions/{id}``, ``POST …/complete`` and ``POST …/cancel``, with
    ``UCP-Agent``, ``Request-Id`` and ``Idempotency-Key`` headers.
*   A session moves ``incomplete`` → ``ready_for_complete`` →
    ``complete_in_progress`` → ``completed`` (or ``canceled``). A human hand-off
    is ``requires_escalation`` with a ``continue_url``. Amounts are integers in
    minor units; ``totals`` must add up.
*   3.1's manifest, its SSE event names (``checkout.started``,
    ``authorization.required`` …) and its "0.1" version were not UCP at all.

AP2 → v0.2.0
------------

*   v0.2 replaced the v0.1 Intent/Cart/Payment mandates with **SD-JWT**
    Checkout and Payment mandates, each in an *open* form (constraints the user
    approved, bound to the agent's key through ``cnf``) and a *closed* form
    (one concrete checkout, signed by the agent as a key-binding JWT). The
    ``vct`` names the type: ``mandate.checkout.open.1``, ``mandate.checkout.1``,
    ``mandate.payment.open.1``, ``mandate.payment.1``.
*   The merchant signs the checkout (``checkout_jwt``, ES256); the closed
    Checkout Mandate carries its hash, and the Payment Mandate's
    ``transaction_id`` is the same hash. Checkout and Payment receipts close the
    loop.
*   Amounts are integers in minor units, signatures are ES256 over P-256 keys.
    3.1 signed HS256 JWTs with a shared secret, which a v0.2 verifier cannot
    accept.

MCP 2026-07-28
--------------

Agent Nexus does not implement MCP. The protocol map names it as the
agent-to-tool layer, and UCP and A2UI both define MCP bindings; the version
shown is the one those bindings would use. The 2026-07-28 revision removed the
``initialize`` handshake and sessions: every request carries its protocol
version in ``_meta``, and ``server/discover`` replaces discovery by handshake.
