:navigation-title: AG-UI

..  include:: /Includes.rst.txt
..  _protocol-agui:

=====================
AG-UI — agent to user
=====================

A long-running agent that answers only when it is finished is indistinguishable
from a hung page. AG-UI turns a run into a stream of typed events, so the
interface can show reasoning as it happens, render a tool call as a real
component, and — crucially — stop and ask before anything is written.

Roughly sixteen event types form the stable core, grouped into families:
lifecycle (``RUN_STARTED`` … ``RUN_FINISHED``), text deltas, tool calls, shared
state (snapshots and RFC 6902 patches), reasoning, and an extension hook.

How a request flows
===================

#.  **Run start.** The client opens an SSE stream; the server answers with
    ``RUN_STARTED`` and a thread id.
#.  **Streamed answer.** Reasoning and text arrive as deltas.
#.  **Approval gate.** Before any write the agent emits a confirm tool call and
    stops. Nothing happens without a human decision.
#.  **Apply.** On approval the run resumes, the lead is stored, and
    ``RUN_FINISHED`` closes the stream.

Endpoint
========

..  list-table::
    :header-rows: 1

    *   -   Method
        -   Path
        -   Purpose
    *   -   POST
        -   ``/index.php?eID=agui_assistant``
        -   Streams one run as AG-UI events, including the approval gate.

In this installation
====================

The backend Event Inspector lists every event type with its fields, and the Run
Console plays a full run into a live timeline with the shared-state inspector
and the Approve / Reject gate.

The frontend assistant is the same run in a visitor-facing shell. It writes a
lead to :sql:`tx_agentnexus_agui_lead` only after an approval, and logs every run
to :sql:`tx_agentnexus_agui_run_log` whether it was approved or not.

..  note::

    With ``aguiReallyApply`` off — the default — an approved write is simulated
    and logged rather than executed.
