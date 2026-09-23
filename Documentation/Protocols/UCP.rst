:navigation-title: UCP

..  include:: /Includes.rst.txt
..  _protocol-ucp:

=======================
UCP — agent to merchant
=======================

A shopping agent can only buy from a store it can understand. UCP, the
Universal Commerce Protocol, gives every store the same two things: a
**profile** at a fixed address that says what the store supports, and a
**checkout** API the agent drives on the buyer's behalf. The store prices every
line and decides every state; the agent adds items, fills in the buyer and asks
the store to complete.

Agent Nexus 4.0 implements UCP **2026-08-25** as a sandbox business: the
discovery profile and the checkout capability (``dev.ucp.shopping.checkout``)
over the REST binding. It also runs a shopping agent — a UCP platform — that
drives that API for a visitor and reports to the visitor over AG-UI. See
:ref:`spec-versions` for what changed since 3.1, whose "UCP 0.1" manifest and
event names were not UCP at all.

..  warning::

    This is a sandbox. No payment is taken, nothing is delivered and every
    order is simulated. The only payment handler accepts test tokens.

How a purchase flows
====================

#.  **Discovery.** The platform reads ``GET /.well-known/ucp``. The profile
    names the service ``dev.ucp.shopping`` with its REST endpoint
    (``https://<host>/api/agent-nexus/ucp``), the checkout capability and the
    payment handler ``at.webconsulting.sandbox_pay``.
#.  **Create.** ``POST {endpoint}/checkout-sessions`` with item ids and
    quantities. The store answers ``201`` with the priced session. Without the
    buyer's email address the session is ``incomplete``, and an error message
    with the path ``$.buyer.email`` says so.
#.  **Update.** ``PUT {endpoint}/checkout-sessions/{id}`` replaces line items
    and buyer. With an email address the session is ``ready_for_complete``.
#.  **Approval.** The platform shows the priced session to the buyer and waits
    for a yes. UCP requires that a person finalises the checkout through a
    trusted interface unless the AP2 Mandates extension is in use.
#.  **Complete.** ``POST {endpoint}/checkout-sessions/{id}/complete`` with one
    payment instrument. The session passes ``complete_in_progress`` and ends
    ``completed`` with an ``order``.
#.  **Cancel** instead: ``POST {endpoint}/checkout-sessions/{id}/cancel``.

The sequence diagram of this flow is :file:`Build/Diagrams/ucp.mmd`.

Checkout status
---------------

..  list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Status
        -   Meaning in this store
    *   -   ``incomplete``
        -   The store still needs something it can take over the API: the
            buyer's email address, sent with an update.
    *   -   ``ready_for_complete``
        -   Everything is there. The platform may complete once the buyer has
            approved.
    *   -   ``complete_in_progress``
        -   Recorded in the history only: completion runs synchronously.
    *   -   ``completed``
        -   The order is placed (simulated). The session never changes again.
    *   -   ``canceled``
        -   The platform canceled the session. Nothing was ordered.
    *   -   ``requires_escalation``
        -   Never reached: the store sells digital goods and has nothing a
            buyer would have to finish on its own pages.

Amounts are integers in minor units (euro cents). Every price comes from the
product catalogue; a request that carries prices, totals or a status has them
ignored. ``totals`` holds exactly one ``subtotal`` and one ``total``, and every
entry that is not the total adds up to it.

Endpoints
=========

``{api}`` is the API base path, ``/api/agent-nexus`` by default.

..  list-table::
    :header-rows: 1
    :widths: 10 45 45

    *   -   Method
        -   Path
        -   Purpose
    *   -   GET
        -   ``/.well-known/ucp``
        -   The business profile. ``Cache-Control: public, max-age=300``, an
            ``ETag``, ``304`` on revalidation.
    *   -   GET
        -   ``{api}/ucp/profile``
        -   The same profile, also when well-known documents are switched off.
    *   -   GET
        -   ``{api}/ucp/platform-profile``
        -   The demo shopping agent's own profile, named in its ``UCP-Agent``
            header.
    *   -   POST
        -   ``{api}/ucp/checkout-sessions``
        -   ``create_checkout``: opens and prices a session (``201``,
            ``Location``).
    *   -   GET
        -   ``{api}/ucp/checkout-sessions/{id}``
        -   ``get_checkout``: the session as it stands.
    *   -   PUT
        -   ``{api}/ucp/checkout-sessions/{id}``
        -   ``update_checkout``: full replacement of line items, buyer, context
            and attribution.
    *   -   POST
        -   ``{api}/ucp/checkout-sessions/{id}/complete``
        -   ``complete_checkout``: places the order with one instrument.
    *   -   POST
        -   ``{api}/ucp/checkout-sessions/{id}/cancel``
        -   ``cancel_checkout``: cancels a session that is not finished. No
            body.
    *   -   POST
        -   ``{api}/ucp/agent``
        -   The shopping agent: AG-UI 1.0 over Server-Sent Events.

Headers
-------

``UCP-Agent``
    Required on every checkout request, in RFC 8941 dictionary syntax:
    ``profile="https://platform.example/.well-known/ucp"``. The URL must be an
    absolute ``https`` URL (plain ``http`` only on loopback) without
    credentials. A ``version`` parameter or member naming another UCP version
    is answered with ``422 version_unsupported``.
``Request-Id``
    Required, a UUID. It is echoed on the response.
``Idempotency-Key``
    Required on create, update, complete and cancel, a UUID. The first
    response is stored for 24 hours under the key, scoped to the platform
    profile. The same key with the same body (compared by SHA-256 of the raw
    bytes) returns the stored response unchanged; the same key with another
    body is ``409 idempotency_conflict``. When the records cannot be read or
    written the request is refused with ``503`` and nothing changes.

Checkout responses carry ``Cache-Control: no-store``.

Status codes
------------

..  list-table::
    :header-rows: 1
    :widths: 12 88

    *   -   Status
        -   When
    *   -   200 / 201
        -   Every business outcome, with the checkout and its ``messages``: a
            rejected update, a declined payment, a missing email address. A
            create the store cannot serve (an item it does not sell) is ``200``
            with an ``error_response`` and no session.
    *   -   400
        -   ``invalid_profile_url`` (``UCP-Agent``), ``invalid_request``
            (headers, a body that is not a checkout request).
    *   -   404
        -   No session with this id; ``error_response`` with ``not_found``.
    *   -   409
        -   ``idempotency_conflict``; or a change to a ``completed`` or
            ``canceled`` session (``error_response`` with
            ``checkout_not_modifiable``); or an expired session.
    *   -   422
        -   ``version_unsupported``.
    *   -   429
        -   ``rate_limited`` (30 requests per 10 minutes and client), with
            ``Retry-After``.
    *   -   503
        -   ``idempotency_unavailable``, with ``Retry-After``.

Protocol errors have the body ``{"code": …, "content": …}``;
``error_response`` bodies are ``{"ucp": {"version": …, "status": "error"},
"messages": [...]}``.

The sandbox payment handler
===========================

UCP defines no payment handler of its own; every business names its handlers
under a reverse-domain name it controls. This store has one:
``at.webconsulting.sandbox_pay``, instance id ``sandbox_pay``, configuration
``{"environment": "sandbox"}``, instrument type ``sandbox``. An instrument
references it by ``handler_id`` and carries a token credential:

..  list-table::
    :header-rows: 1

    *   -   Token
        -   Result
    *   -   ``sandbox-success``
        -   The payment is accepted; the session completes with an order.
    *   -   ``sandbox-decline``
        -   ``payment_failed``; the session stays ``ready_for_complete``.

More than one instrument in a request, another handler or an unknown token is
``payment_failed`` too. The credential is never repeated in a response. With
``ucpReallyApply`` set nothing changes: there is no real payment integration.

The shopping agent
==================

``POST {api}/ucp/agent`` takes an AG-UI ``RunAgentInput`` and answers with an
AG-UI 1.0 event stream. The checkout widget and the backend console use it;
so can any AG-UI client. The agent is a UCP platform: every UCP request it
makes appears in the stream as a tool call — ``ucp.discover``,
``ucp.create_checkout``, ``ucp.update_checkout``, ``ucp.complete_checkout``,
``ucp.cancel_checkout`` — whose arguments are the request (method, path,
headers, body) and whose result is the response (status and body). The
requests go to the same endpoint methods another platform reaches over HTTP,
in the same PHP process, and are recorded in the traffic log with the channel
``agent``.

The widget adds its context under ``forwardedProps.agentNexus``:

..  code-block:: json

    {"ce": 12, "page": 3, "url": "https://…", "intent": "agency",
     "email": "ada@example.org", "payment": "decline"}

``intent`` is ``pro``, ``agency`` or ``support`` (a client that sends only a
user message gets the wish read from its text), ``email`` fills the buyer and
``payment: "decline"`` makes the agent submit the failing sandbox token.

First run
    Discovery, ``create_checkout``, the reasoning (why these products) and a
    ``CUSTOM`` event ``agentnexus.provenance`` ("Live model" or "Scripted
    demo"). The agent then *proposes* ``ucp.complete_checkout`` — start,
    arguments and end, but no result — sends a ``STATE_SNAPSHOT`` with the
    checkout and finishes with the interrupt outcome: one interrupt with
    ``reason: "confirmation"``, the proposed call's ``toolCallId``, the
    checkout's expiry and a ``responseSchema`` of ``{approved, email}``
    (``email`` required on approval while the session has none).
Second run
    ``resume: [{interruptId, status: "resolved", payload: {approved: true,
    email}}]`` adds the email with ``update_checkout`` when needed, then sends
    the proposed complete request exactly as it was shown — same body, same
    ``Idempotency-Key`` — and closes the proposed tool call with its result.
    ``approved: false`` or ``status: "cancelled"`` closes the proposed call as
    not sent and cancels the session.

The approval is checked against what the agent stored on the checkout (the
checkout's context id is the AG-UI thread id): an answer that names no open
approval of this thread is refused with ``RUN_ERROR`` before anything happens,
a second, different answer is refused, the same answer again is safe (the
requests replay), and anything but ``approved: true`` cancels. A new run on a
thread that still waits for an answer is refused. Before completing, the agent
checks that the total is still the one the visitor approved.

A language model may write the reasoning when the element allows it, the
``LlmGuard`` allows ``ucp`` (``ucpLlmEnabled``, the daily budget) and the
``ucp.llm`` rate limit allows it. The model is given formatted facts only
(titles, "€49.00", "per month") and its text is dropped when it contains a
number the facts do not. Rate limits: 15 runs per 10 minutes and client
(``ucp.agent``), 10 model calls (``ucp.llm``).

With ``trafficRedactPersonalData`` on, personal fields in the tool-call
arguments and results are masked as ``[redacted]``, as they are in the traffic
log.

In this installation
====================

*   Checkout sessions are protocol objects of kind ``checkout``. The payload is
    the checkout JSON as it was last returned; a private member
    ``_agentNexus`` (never sent) holds the agent's pending approval. The label
    is the total and item count, the state history records every status. The
    inspector lists them under :guilabel:`Inspector > Checkout sessions`.
*   Every exchange is in the traffic log, correlated with the checkout id:
    ``api`` for platforms over HTTP, ``widget`` for the widget's agent runs,
    ``agent`` for the calls the agent made in-process.
*   Idempotency records and rate limits live in the ``agentnexus`` cache, which
    belongs to no cache group: only a full cache flush clears them.
*   :guilabel:`UCP > Checkout console` runs the agent like the widget does,
    shows every UCP request and response and the checkout, and calls GET and
    cancel on the REST binding directly. :guilabel:`UCP > Business profile`
    shows the published profile, checked against the rules of 2026-08-25, with
    the catalogue and the sandbox tokens.

Where this deviates from the specification
==========================================

The platform profile is not fetched.
    A business must fetch and validate the profile a ``UCP-Agent`` header
    names and negotiate capabilities with it. A public demo that fetched any
    URL it is given would be an open relay, so the sandbox checks the header's
    syntax only, keeps every capability active and says so in an ``info``
    message with the code ``profile_not_fetched``.
Changes to finished sessions are ``409``.
    UCP leaves the status open; the official conformance suite expects a
    non-200 answer, so completed and canceled sessions refuse every change
    with ``409`` and an ``error_response``.
An unknown session is ``404``.
    With an ``error_response`` body (``not_found``).
No ``continue_url``.
    The store never escalates, and there is no page a buyer could continue on.
``order.permalink_url`` is the session.
    There are no order pages; the permalink is the completed session's
    resource URL. The order capability (``GET /orders/{id}``) is not
    implemented.
``links`` is empty by default.
    The sandbox has no terms of its own. Set ``ucpTermsOfServiceUrl`` and
    ``ucpPrivacyPolicyUrl`` in the extension configuration to publish links.
No signatures.
    The profile publishes no ``keys``, and requests and responses are not
    signed. AP2 mandates are wired in separately; see below.
Malformed bodies are ``400``.
    The reference implementation answers schema errors with ``422``.
``ETag`` and TYPO3's ``.htaccess``
    The profile is served with an ``ETag``, but TYPO3's default ``.htaccess``
    removes ``ETag`` headers (``Header unset ETag``). Remove that line or
    exempt ``/.well-known/ucp`` to keep it.
Only the REST binding.
    UCP also binds checkout to MCP — tools ``create_checkout``,
    ``get_checkout``, ``update_checkout``, ``complete_checkout`` and
    ``cancel_checkout`` whose ``arguments.meta["ucp-agent"]`` carries the
    platform profile — and to A2A, with the checkout in message data parts.
    Neither is implemented.

Adding a payment authorisation check
====================================

``Webconsulting\AgentNexus\Ucp\Checkout\CompletionGuard`` is the one place a
payment authorisation is checked. Every service implementing the interface is
picked up by its tag and asked before the sandbox charges anything; a refusal
leaves the session unchanged. This is where an AP2 checkout mandate
(``ap2.checkout_mandate`` in the complete request) is verified:

..  code-block:: php

    final class Ap2MandateGuard implements CompletionGuard
    {
        public function check(array $checkout, array $request): ?array
        {
            $mandate = $request['ap2']['checkout_mandate'] ?? null;
            return is_string($mandate) && $this->verifier->verify($mandate, $checkout)
                ? null
                : Messages::error('mandate_required', 'Sign the checkout with an AP2 mandate.');
        }
    }

Try it with curl
================

..  code-block:: bash

    HOST=https://webconsulting-typo3-lab.ddev.site
    AGENT='profile="https://platform.example/.well-known/ucp"'
    uuid() { uuidgen | tr A-Z a-z; }

    # The profile
    curl -s $HOST/.well-known/ucp

    # Create a session
    curl -s -X POST $HOST/api/agent-nexus/ucp/checkout-sessions \
      -H "UCP-Agent: $AGENT" -H "Request-Id: $(uuid)" -H "Idempotency-Key: $(uuid)" \
      -H 'Content-Type: application/json' \
      -d '{"line_items":[{"item":{"id":"pro-license"},"quantity":1}]}'

    # Add the buyer (use the id from the answer)
    ID=chk_…
    curl -s -X PUT $HOST/api/agent-nexus/ucp/checkout-sessions/$ID \
      -H "UCP-Agent: $AGENT" -H "Request-Id: $(uuid)" -H "Idempotency-Key: $(uuid)" \
      -H 'Content-Type: application/json' \
      -d '{"line_items":[{"item":{"id":"pro-license"},"quantity":1}],"buyer":{"email":"ada@example.org"}}'

    # Complete it with the sandbox token
    curl -s -X POST $HOST/api/agent-nexus/ucp/checkout-sessions/$ID/complete \
      -H "UCP-Agent: $AGENT" -H "Request-Id: $(uuid)" -H "Idempotency-Key: $(uuid)" \
      -H 'Content-Type: application/json' \
      -d '{"payment":{"instruments":[{"id":"instr_1","handler_id":"sandbox_pay","type":"sandbox","credential":{"type":"token","token":"sandbox-success"}}]}}'

    # Or run the shopping agent and watch the AG-UI stream
    curl -sN -X POST $HOST/api/agent-nexus/ucp/agent -H 'Content-Type: application/json' \
      -d '{"threadId":"t-1","runId":"r-1","messages":[{"id":"m-1","role":"user","content":"Pro licence"}],"forwardedProps":{"agentNexus":{"intent":"pro"}}}'
