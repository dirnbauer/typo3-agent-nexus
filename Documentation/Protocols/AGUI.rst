:navigation-title: AG-UI

..  include:: /Includes.rst.txt
..  _protocol-agui:

=====================
AG-UI — agent to user
=====================

A long-running agent that answers only when it is finished looks like a page
that has stopped working. AG-UI turns a run into a stream of typed events, so
the interface shows the agent's reasoning as it happens, renders structured
progress as its own element, and stops to ask a person before anything is
written.

Agent Nexus 4.0 implements **AG-UI 1.0** (17 September 2026), see
:ref:`spec-versions`. Every run declares ``protocolVersion: "1.0"`` on
``RUN_STARTED``. A client that speaks a newer 1.x version is served; a client
of another major version is refused before the run starts.

How a run flows
===============

#.  **Run input.** The client POSTs a ``RunAgentInput``: ``threadId``,
    ``runId``, the whole conversation in ``messages`` and optionally
    ``protocolVersion``, ``state``, ``tools``, ``context`` and
    ``forwardedProps``.
#.  **Stream.** The answer is ``text/event-stream``, one event per ``data:``
    line. The agent reasons (a reasoning span with one reasoning message),
    updates the shared state, streams its answer, shows the plan comparison as
    an ``ACTIVITY_SNAPSHOT`` and proposes the change as a tool call.
#.  **Interrupt.** The run ends with ``RUN_FINISHED`` and the outcome
    ``interrupt``. The interrupt names the tool call and describes the answer
    it expects in ``responseSchema``.
#.  **Resume.** The next run on the same thread carries the answer in
    ``resume``: ``status: "resolved"`` with ``{"approved": true}`` (plus name
    and email for a visitor), ``{"approved": false}``, or
    ``status: "cancelled"``.
#.  **Result.** Only an approval carries the change out. The result comes back
    as ``TOOL_CALL_RESULT`` for the original tool call, followed by
    ``RUN_FINISHED`` with the outcome ``success``. The call is not proposed
    again.

..  code-block:: text

    run 1   RUN_STARTED (protocolVersion 1.0)
            CUSTOM at.webconsulting.agentnexus.provenance
            STEP_STARTED analyse
              REASONING_START → REASONING_MESSAGE_START (role reasoning)
              → REASONING_MESSAGE_CONTENT … → REASONING_MESSAGE_END → REASONING_END
              STATE_SNAPSHOT, STATE_DELTA
            STEP_FINISHED analyse
            STEP_STARTED answer
              TEXT_MESSAGE_START → TEXT_MESSAGE_CONTENT … → TEXT_MESSAGE_END
              ACTIVITY_SNAPSHOT at.webconsulting.agentnexus.plan-comparison
            STEP_FINISHED answer
            STEP_STARTED propose
              TOOL_CALL_START confirm_booking → TOOL_CALL_ARGS … → TOOL_CALL_END
            STEP_FINISHED propose
            RUN_FINISHED outcome {type: interrupt, interrupts: [{id, reason: confirmation,
                                  toolCallId, responseSchema}]}

    run 2   RUN_STARTED
            STEP_STARTED apply
              TOOL_CALL_RESULT (toolCallId of run 1)
              TEXT_MESSAGE_START → TEXT_MESSAGE_CONTENT … → TEXT_MESSAGE_END
            STEP_FINISHED apply
            RUN_FINISHED outcome {type: success}, result

Endpoints
=========

..  list-table::
    :header-rows: 1

    *   -   Method
        -   Path
        -   Purpose
    *   -   POST
        -   ``/api/agent-nexus/ag-ui``
        -   The public AG-UI endpoint (route ``agui.run``, HTTP + SSE). Runs
            the site assistant: recommend a plan, book a consultation. Any
            AG-UI 1.0 client can call it; the assistant widget does too.
    *   -   POST
        -   Backend AJAX route ``agentnexus_agui_run``
        -   The run console's editor agent: SEO metadata, translated page
            titles, a news draft. Backend users only; its interrupts cannot be
            answered through the public endpoint.

The API base path is the ``apiBasePath`` extension setting
(``/api/agent-nexus`` by default).

A request the agent refuses before the run starts gets a JSON error and no
stream, ``{"error": {"code", "reason", "message", "pointer"}}``:

..  list-table::
    :header-rows: 1

    *   -   Status
        -   Reason
        -   When
    *   -   400
        -   ``invalid_json``, ``invalid_input``
        -   The body is not JSON, or a field the specification describes holds
            a value it does not allow. ``pointer`` names the field. Fields the
            specification does not describe are stripped and ignored, never
            echoed.
    *   -   400
        -   ``unsupported_protocol_version``
        -   ``protocolVersion`` is from another major line, for example
            ``2.0``.
    *   -   409
        -   ``run_id_reused``
        -   The ``runId`` was used before. Every run needs a new one.
    *   -   409
        -   ``open_interrupt``
        -   The thread waits for an answer. Answer or cancel the interrupt in
            ``resume`` before you start another run.
    *   -   409
        -   ``unknown_interrupt``, ``no_open_interrupt``,
            ``duplicate_answer``, ``uncovered_interrupt``
        -   ``resume`` answers an interrupt this thread did not raise, one
            that was answered already, one interrupt twice, or leaves one
            unanswered.
    *   -   413
        -   ``input_too_large``
        -   The input is larger than 256 KiB.
    *   -   429
        -   ``rate_limited``
        -   More than 20 runs from one address in 10 minutes.

A failure after the stream opened ends the run with ``RUN_ERROR``. An answer
that does not fit the ``responseSchema`` (an approval without a valid email,
say) ends the resuming run with ``RUN_ERROR`` and the code
``invalid_answer``; the interrupt stays open for a corrected answer.

The assistant widget
====================

The "AG-UI: AI site assistant" content element is an AG-UI 1.0 client of the
public endpoint. It adds its content element, page and URL, and the task
chosen in the element, as ``forwardedProps.agentNexus``:

..  code-block:: json

    {"agentNexus": {"ce": 73227, "page": 1403, "url": "https://…", "preset": "plan"}}

Only such a run is recorded as a widget run, and only then may the element's
own settings let a live model word the answer: *Answer with the real model* on,
netresearch/nr-llm installed with a provider, ``aguiLlmEnabled`` and
``llmFrontendEnabled`` on, the daily budget not spent, and no more than eight
model runs from one address in 10 minutes. The settings are read from the
content element's record, never from the request. Every other run is scripted,
and the ``provenance`` event says which it was. The approval, the tool
arguments and the write never come from a model.

Runs and the inspector
======================

Every run is stored as a protocol object of kind *Run*: the object id is the
``runId``, the context is the ``threadId``, and the state is ``running``,
``interrupted``, ``finished``, ``error`` or ``cancelled``. The payload holds
the input (without the widget's own extras), the outcome, the interrupts, the
result, the messages and state the run produced, and the number of events it
streamed. The ending is recorded before ``RUN_FINISHED`` is sent, so a resume
can follow at once.

The stored runs are how the endpoint keeps its promises: it never carries out
an action whose interrupt has no answer, never carries it out twice, and never
accepts an answer to an interrupt it did not raise. A visitor's request is the
approved run's ``result``; there is no lead table. The inspector (*Agent
Nexus > Inspector > Runs*) lists every run with its traffic.

..  note::

    With ``aguiReallyApply`` off — the default — an approved change is
    simulated: the result says ``simulated: true``, and nothing is written
    anywhere but the run record.

Try it with curl
================

Start a run. The ids must be new for every run:

..  code-block:: bash

    curl -N https://example.org/api/agent-nexus/ag-ui \
      -H 'Content-Type: application/json' \
      -H 'Accept: text/event-stream' \
      -d '{"threadId":"thread-7f3a","runId":"run-1b2c","protocolVersion":"1.0",
           "messages":[{"id":"msg-1","role":"user","content":"Which plan suits a team of five?"}]}'

The stream ends with the interrupt. Answer it on the same thread with a new
run id, using the ``id`` from ``RUN_FINISHED.outcome.interrupts``:

..  code-block:: bash

    curl -N https://example.org/api/agent-nexus/ag-ui \
      -H 'Content-Type: application/json' \
      -H 'Accept: text/event-stream' \
      -d '{"threadId":"thread-7f3a","runId":"run-3d4e","protocolVersion":"1.0","messages":[],
           "resume":[{"interruptId":"int_…","status":"resolved",
                      "payload":{"approved":true,"name":"Ada Lovelace","email":"ada@example.org"}}]}'

The backend screen *AG-UI > Event reference* prints a ready-made command with
fresh ids for your installation.

In the backend
==============

*AG-UI > Run console* is an AG-UI client in the browser. It runs the editor
agent through the backend route and the site assistant through the public
endpoint, lists every event with its JSON, shows the shared state with each
applied delta, and answers the interrupt: Approve or Reject sends the resume.
Where the interrupt allows ``editedArgs``, the proposal can be edited before
it is approved.

*AG-UI > Event reference* lists the 31 event types by family with their
fields and an example of each, and the ordering rules a stream must follow.

Conformance
===========

One catalogue (``Agui\Protocol\EventType``) describes the event set. The event
factory builds every event from it, the stream verifier
(``Agui\Protocol\EventVerifier``) checks every stream against the 1.0
ordering rules, and the event reference prints both. In Development context
the verifier also checks each stream while it is sent. The tests validate
every event type, every stream the agents produce and every stored run record
against the specification's JSON Schema, and replay the specification's own 68
conformance streams through the verifier.

Where the specification leaves a choice, Agent Nexus decides as follows:

*   An answer to an interrupt the thread did not raise is refused with 409.
    The specification suggests running without the entry; a run whose only
    purpose is that answer has nothing else to do, and a replayed approval
    must never act twice.
*   A new run while an interrupt waits is refused with 409, which the
    specification allows, instead of repeating the interrupt.
*   ``reason`` is ``confirmation``, and ``responseSchema`` follows the
    approve-with-edits pattern: ``approved``, the contact fields a visitor's
    approval needs, and ``editedArgs`` where the proposal may be edited.
*   A live model's usage is reported on ``RUN_FINISHED.usage`` with provider
    and model only: streamed answers carry no token counts, and the protocol
    forbids reporting a count the provider did not give.
