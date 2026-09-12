:navigation-title: Security

..  include:: /Includes.rst.txt
..  _security:

========
Security
========

Agent Nexus opens nine public endpoints and signs tokens. Both deserve a clear
statement of what they are — and are not.

..  warning::

    Everything money-shaped in this extension is simulated. AP2 mandates are
    signed with a fixed demo key shipped in the source: they prove that a token
    was not tampered with, nothing about who signed it. No payment network, no
    real authorization, no order. Never wire a mandate minted here into anything
    that moves money.

The public endpoints
====================

All nine are :ref:`eID endpoints <t3coreapi:typo3-scripts-eid>`, reachable
without authentication — that is the point, since an external agent has to be
able to discover and call them. What they can do is bounded instead:

*   **Rate limiting per IP.** Every endpoint goes through one shared limiter
    with a fixed window. It fails open when the cache is unavailable: these are
    demo endpoints, and a broken cache must not take the page down with them.
*   **Input caps.** Intents are truncated, oversized payloads are refused with
    413, and every request body is treated as untrusted.
*   **No arbitrary rendering.** An A2UI surface names components; the renderer
    draws only components the registry knows, with only the properties that
    component declares. Unknown components and unknown properties are dropped
    before anything reaches the page.
*   **A human gate before every write.** AG-UI stores a lead only after an
    explicit approval, UCP confirms an order only after one, and both write
    simulated results while ``aguiReallyApply`` / ``ucpReallyApply`` are off.

What ends up in the database
============================

The demos store what visitors submit: inquiry payloads, leads with the contact
details a visitor typed, concierge prompts and answers, simulated orders and
authorizations. That is personal data. Point
:ref:`agentNexus.storagePid <configuration-site>` at a folder you actually
review, and delete it when the demo is over.

Model calls
===========

When a model is enabled, prompts include what the visitor typed and — for the
A2UI generator — the business context configured on the element. Nothing else
from the installation is sent. The spend of every call is written to
:sql:`tx_agentnexus_llm_usage` and shown in the hub, and
:ref:`llmDailyBudget <configuration-extension>` stops the calls once the day's
cap is reached.

Before a public installation
============================

*   Keep ``aguiReallyApply`` and ``ucpReallyApply`` off.
*   Set a storage folder, and decide who may read it.
*   Set a daily budget if a model is enabled.
*   Remember that ``a2a_card``, ``a2a_rpc`` and ``ucp_manifest`` advertise this
    site to any agent that looks. That is intended — make sure the skills and
    catalogue they advertise are ones you are happy to publish.
